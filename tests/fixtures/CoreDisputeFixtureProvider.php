<?php
declare(strict_types=1);
use Cms\Core\Payment\{PaymentProviderInterface,PaymentDisputeProviderInterface,PaymentResult,PaymentException};
/** Isolated no-network provider. Never shipped as a payment gateway. */
final class CoreDisputeFixtureProvider implements PaymentProviderInterface,PaymentDisputeProviderInterface {
 public string $createState='paid';public bool $reliable=true;public bool $lookupFails=false;public array $current=[];public int $creates=0,$lookups=0;public ?Closure $lookupHook=null;
 public function providerId():string{return 'test.core-dispute';}public function displayName():string{return 'Isolated dispute fixture';}
 public function capabilities():array{return ['payment.create','payment.capture','payment.refund','payment.cancel','payment.status'];}
 public function createPayment(object $c):PaymentResult{$this->creates++;return new PaymentResult(true,'fixture','No network',false,'',['status'=>$this->createState,'provider_payment_id'=>'remote-'.hash('sha256',$c->idempotency_key)]);}
 public function capturePayment(object $c):PaymentResult{return new PaymentResult(true,'fixture','No network',false,'',['status'=>'paid','provider_payment_id'=>$c->provider_payment_id]);}
 public function cancelPayment(object $c):PaymentResult{return new PaymentResult(true,'fixture','No network',false,'',['status'=>'cancelled','provider_payment_id'=>$c->provider_payment_id]);}
 public function refundPayment(object $c):PaymentResult{return new PaymentResult(true,'fixture','No network',false,'',['status'=>'completed','provider_refund_id'=>'refund-'.hash('sha256',$c->idempotency_key)]);}
 public function getPaymentStatus(object $c):PaymentResult{return new PaymentResult(true,'fixture','No network',false,'',['status'=>'paid','provider_payment_id'=>$c->provider_payment_id]);}
 public function isDisputeWebhook(string $rawPayload):bool{return (json_decode($rawPayload,true)['event_type']??'')==='dispute';}
 public function hasReliableDisputeSequence():bool{return $this->reliable;}
 public function verifyDisputeWebhook(string $rawPayload,array $server,array $publicConfig,array $secrets):array {
  $t=$server['HTTP_X_CMS_PAYMENT_TIMESTAMP']??'';$sig=$server['HTTP_X_CMS_PAYMENT_SIGNATURE']??'';$key=$secrets['webhook_secret']??'';
  if(!preg_match('/^[0-9]{10,12}$/D',$t)||abs(time()-(int)$t)>300||$key===''||!preg_match('/^[a-f0-9]{64}$/D',$sig)||!hash_equals(hash_hmac('sha256',$t.'.'.$rawPayload,$key),$sig))throw new PaymentException('Fixture dispute authentication rejected.');
  $data=json_decode($rawPayload,true,512,JSON_THROW_ON_ERROR);if(($server['HTTP_X_CMS_PAYMENT_EVENT']??'')!==($data['event_id']??''))throw new PaymentException('Fixture event header mismatch.');return $data;
 }
 public function fetchDisputeSnapshot(array $payment,string $disputeId,array $publicConfig,array $secrets):array {
  $this->lookups++;if($this->lookupFails)throw new RuntimeException('Isolated lookup unavailable');$data=$this->current[$disputeId]??throw new RuntimeException('No authoritative fixture snapshot');if($this->lookupHook){$hook=$this->lookupHook;$this->lookupHook=null;$hook();}return $data;
 }
}
