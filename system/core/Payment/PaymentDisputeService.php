<?php
declare(strict_types=1);
namespace Cms\Core\Payment;
use PDO;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Audit\AuditLogger;
use Cms\Core\Notification\NotificationService;
/** Verified ingress and a deliberately small read-only business snapshot. */
final class PaymentDisputeService {
 public function __construct(private readonly PDO $db,private readonly string $settingsKey='',private readonly ?EventDispatcher $events=null){}
 private function one(string $sql,array $args=[]):?array {$s=$this->db->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
 private function run(string $sql,array $args=[]):void {$s=$this->db->prepare($sql);$s->execute($args);}
 private function id(mixed $id):string {if(!is_string($id)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D',$id))throw new PaymentException('Invalid dispute reference.');return $id;}
 private function audit(string $action,string $provider,int $payment=0):void {
  try{(new AuditLogger($this->db))->record('system',null,'payment.dispute.'.$action,['provider_id'=>$provider,'payment_id'=>$payment]);
   if(in_array($action,['rejected','verification_pending','notification_failed'],true))(new NotificationService($this->db))->create('支付争议需要核对','争议验证或同步未完成。请检查服务端审核日志。',['source_type'=>'payment','severity'=>'warning','dedupe_key'=>'dispute-'.$action.'-'.$provider.'-'.$payment]);
  }catch(\Throwable){error_log('Core payment dispute audit unavailable');}
 }
 private function normalize(array $d):array {
  foreach(['provider_id','merchant_id','provider_payment_id','subject_type','subject_id','dispute_id'] as $k)$d[$k]=$this->id($d[$k]??null);
  $d['state']=in_array($d['state']??'', ['open','under_review','closed'],true)?$d['state']:'unknown';
  $d['outcome']=in_array($d['outcome']??'', ['won','lost'],true)?$d['outcome']:'unknown';
  if($d['state']!=='closed')$d['outcome']='unknown';
  return $d;
 }
 private function bind(array $d,array $payment,string $provider,string $merchant):void {
  if($d['provider_id']!==$provider||$d['merchant_id']!==$merchant||$payment['provider_id']!==$provider||$d['provider_payment_id']!==$payment['remote_id']||$d['subject_type']!==$payment['subject_type']||$d['subject_id']!==$payment['subject_id'])throw new PaymentException('Dispute payment association mismatch.');
 }
 public function receiveWebhook(string $providerId,string $raw,array $server):array {
  $paymentId=0;
  try {
   if(PaymentTransaction::isActive($this->db))throw new PaymentException('Dispute ingress requires its own transaction.');
   if(strlen($raw)>262144)throw new PaymentException('Dispute payload too large.');
   $provider=PaymentProviderRegistry::get($providerId);
   if(!$provider instanceof PaymentDisputeProviderInterface)throw new PaymentException('Provider has no verified dispute contract.');
   $settings=new PaymentProviderSettingsRepository($this->db,$this->settingsKey);$setting=$settings->setting($providerId);
   if(!$setting||$setting['status']!=='enabled')throw new PaymentException('Dispute provider disabled.');
   $config=json_decode($setting['public_config_json'],true,512,JSON_THROW_ON_ERROR);$merchant=$this->id($config['merchant_id']??null);$secrets=$settings->secrets($providerId);
   $event=$provider->verifyDisputeWebhook($raw,$server,$config,$secrets);$eventId=$this->id($event['event_id']??null);$d=$this->normalize($event);
   $repo=new PaymentRepository($this->db);$payment=$repo->paymentByRemote($providerId,$d['provider_payment_id']);if(!$payment)throw new PaymentException('Dispute payment not found.');
   $this->bind($d,$payment,$providerId,$merchant);$paymentId=(int)$payment['id'];
   $old=$this->one('SELECT * FROM cms_payment_disputes WHERE provider_id=? AND dispute_id=?',[$providerId,$d['dispute_id']]);
   $expected=(int)($old['revision']??0);$reliable=$provider->hasReliableDisputeSequence()&&is_int($d['sequence']??null)&&$d['sequence']>0;$verificationPending=false;
   if(!$reliable) {
    try{$current=$this->normalize($provider->fetchDisputeSnapshot($payment,$d['dispute_id'],$config,$secrets));$this->bind($current,$payment,$providerId,$merchant);if($current['dispute_id']!==$d['dispute_id'])throw new PaymentException('Dispute lookup association mismatch.');$d=$current;}
    catch(\Throwable){$d['state']='unknown';$d['outcome']='unknown';$verificationPending=true;}
    $d['sequence']=null;
   }
   PaymentTransaction::beginImmediate($this->db);
   try {
    // Serialize this payment with refunds and other dispute ingresses. No network under this lock.
    $locked=$this->one('SELECT * FROM cms_payments WHERE id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$paymentId]);$this->bind($d,$locked??[],$providerId,$merchant);
    $eventRow=$this->one('SELECT * FROM cms_payment_dispute_events WHERE provider_id=? AND event_id=?',[$providerId,$eventId]);$hash=hash('sha256',$raw);
    if($eventRow&&(!hash_equals($eventRow['payload_hash'],$hash)||(int)$eventRow['payment_id']!==$paymentId||$eventRow['dispute_id']!==$d['dispute_id']))throw new PaymentException('Dispute event payload conflict.');
    $old=$this->one('SELECT * FROM cms_payment_disputes WHERE provider_id=? AND dispute_id=?',[$providerId,$d['dispute_id']]);
    if($old&&((int)$old['payment_id']!==$paymentId||$old['merchant_id']!==$merchant))throw new PaymentException('Dispute identity already bound elsewhere.');
    $duplicate=$eventRow&&$eventRow['status']!=='verification_pending';
    if(!$reliable&&!$duplicate&&(int)($old['revision']??0)!==$expected)throw new PaymentException('Dispute changed during lookup; retry verification.',409);
    $receipt=(new PaymentService($this->db,$repo,$this->settingsKey))->recordWebhookReceipt($providerId,$eventId,$raw,'received',['payment_id'=>$paymentId]);
    $applied=false;
    if(!$duplicate){
     $stale=$reliable&&$old&&$old['provider_sequence']!==null&&$d['sequence']<=(int)$old['provider_sequence'];
     if($stale&&$d['sequence']===(int)$old['provider_sequence']&&($d['state']!==$old['state']||$d['outcome']!==$old['outcome']))throw new PaymentException('Dispute sequence conflict.');
     if(!$stale){
      // A final loss is terminal; a later favorable callback cannot erase it.
      if($old&&$old['state']==='closed'&&$old['outcome']==='lost'){$d['state']='closed';$d['outcome']='lost';}
      if($old)$this->run('UPDATE cms_payment_disputes SET state=?,outcome=?,provider_sequence=?,revision=revision+1,verified_at=? WHERE provider_id=? AND dispute_id=?',[$d['state'],$d['outcome'],$d['sequence'],gmdate('c'),$providerId,$d['dispute_id']]);
      else $this->run('INSERT INTO cms_payment_disputes (provider_id,dispute_id,payment_id,merchant_id,state,outcome,provider_sequence,revision,verified_at) VALUES (?,?,?,?,?,?,?,1,?)',[$providerId,$d['dispute_id'],$paymentId,$merchant,$d['state'],$d['outcome'],$d['sequence'],gmdate('c')]);
      $applied=true;
     }
     $status=$verificationPending?'verification_pending':($stale?'stale':'applied');
     if($eventRow)$this->run('UPDATE cms_payment_dispute_events SET status=? WHERE provider_id=? AND event_id=?',[$status,$providerId,$eventId]);
     else $this->run('INSERT INTO cms_payment_dispute_events VALUES (?,?,?,?,?,?,?)',[$providerId,$eventId,$hash,$paymentId,$d['dispute_id'],$status,gmdate('c')]);
     if(!$repo->updateWebhookReceiptStatus((int)$receipt['id'],'processed'))throw new PaymentException('Dispute receipt state conflict.');
     $this->audit($verificationPending?'verification_pending':($stale?'stale':'applied'),$providerId,$paymentId);
    }
    PaymentTransaction::commit($this->db);
   }catch(\Throwable $e){if(PaymentTransaction::isActive($this->db))PaymentTransaction::rollback($this->db);throw $e;}
   // Repeated notifications are harmless: consumers read the snapshot, never event fields.
   try{$this->events?->dispatch(new PaymentDisputeChangedEvent($paymentId,$providerId));}catch(\Throwable){$this->audit('notification_failed',$providerId,$paymentId);}
   return ['payment_id'=>$paymentId,'event_id'=>$eventId,'duplicate'=>(bool)$duplicate,'applied'=>$applied,'verification_pending'=>$verificationPending];
  }catch(\Throwable $e){$this->audit('rejected',$providerId,$paymentId);if($e instanceof PaymentException)throw $e;throw new PaymentException('Dispute verification unavailable.',503);}
 }
 /** Read-only data; a caller's transaction locks the payment row to coordinate restoration with refunds/events. */
 public function snapshot(int $paymentId):array {
  $lock=PaymentTransaction::isActive($this->db)&&$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
  $p=$this->one('SELECT id,provider_id,subject_type,subject_id,status,paid_at,amount_minor,currency FROM cms_payments WHERE id=?'.$lock,[$paymentId]);if(!$p)throw new PaymentException('Payment not found.');
  $stmt=$this->db->prepare('SELECT dispute_id,state,outcome,revision,verified_at FROM cms_payment_disputes WHERE payment_id=? AND provider_id=? ORDER BY dispute_id'.$lock);$stmt->execute([$paymentId,$p['provider_id']]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
  $blocking=false;foreach($rows as $row)if($row['state']!=='closed'||$row['outcome']!=='won')$blocking=true;
  $money=$this->db->prepare('SELECT id,status,amount_minor FROM cms_payments WHERE subject_type=? AND subject_id=? AND currency=? ORDER BY id'.$lock);$money->execute([$p['subject_type'],$p['subject_id'],$p['currency']]);$paid=0;$refunded=0;
  foreach($money->fetchAll(PDO::FETCH_ASSOC) as $row){if(!in_array($row['status'],['paid','partially_refunded','refunded'],true))continue;$paid+=(int)$row['amount_minor'];$q=$this->db->prepare("SELECT amount_minor FROM cms_payment_refunds WHERE payment_id=? AND status='completed'".$lock);$q->execute([$row['id']]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $refund)$refunded+=(int)$refund['amount_minor'];}
  return ['payment_id'=>$paymentId,'provider_id'=>$p['provider_id'],'subject_type'=>$p['subject_type'],'subject_id'=>$p['subject_id'],'payment_status'=>$p['status'],'paid_at'=>$p['paid_at'],'amount_minor'=>(int)$p['amount_minor'],'currency'=>$p['currency'],'net_paid_minor'=>max(0,$paid-$refunded),'refunded_minor'=>$refunded,'available'=>true,'blocking'=>$blocking,'disputes'=>$rows,'revision'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
 }
}
