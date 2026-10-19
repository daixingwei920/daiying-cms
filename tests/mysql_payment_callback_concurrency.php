<?php
/** Disposable MySQL ledger only: no provider is registered, and no refund API is called. */
declare(strict_types=1);
$f=getenv('CMS_MYSQL_TEST_CONFIG');if(!$f){echo 'NOT VERIFIED: explicit MySQL fixture required',PHP_EOL;exit(2);} $c=require$f;
if(($c['host']??'')!=='127.0.0.1'||!preg_match('/^cms_mysql_[a-z0-9_]+$/',$c['database']??'')||!function_exists('pcntl_fork'))throw new RuntimeException('Disposable MySQL and pcntl required');
require dirname(__DIR__).'/system/core/Bootstrap/autoload.php';
use Cms\Core\Payment\{PaymentService,PaymentRepository};
function connection(array$c):PDO{return new PDO('mysql:host=127.0.0.1;port='.(int)($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
function apply(PDO$p,string$prefix,string$event,string$status):void{$s=new PaymentService($p,new PaymentRepository($p));$raw=json_encode(['event_id'=>$event,'event_type'=>'refund.updated','provider_payment_id'=>'cs_'.$prefix,'refund'=>['provider_refund_id'=>'re_'.$prefix,'amount_minor'=>100,'status'=>$status,'reason'=>'Synthetic historical notification']],JSON_THROW_ON_ERROR);$r=$s->recordWebhookReceipt('test.callback',$event,$raw);$s->applyWebhookPaymentStatus('test.callback',(int)$r['id'],$raw);}
$p=connection($c);$r=new PaymentRepository($p);$prefix='mysql_'.bin2hex(random_bytes(5));$id=$r->insertPayment(['subject_type'=>'callback_test','subject_id'=>$prefix,'provider_id'=>'test.callback','remote_id'=>'cs_'.$prefix,'reference'=>$prefix,'status'=>'paid','amount_minor'=>100,'currency'=>'USD','idempotency_key'=>'pay_'.$prefix,'request_hash'=>hash('sha256',$prefix)]);
apply($p,$prefix,'pending_'.$prefix,'pending');if($r->refundedMinorForPayment($id)!==0)throw new RuntimeException('Pending callback reduced net amount');echo 'PASS MySQL pending event preserves paid amount',PHP_EOL;
$p=null;$r=null;$children=[];for($i=0;$i<6;$i++){$pid=pcntl_fork();if($pid===0){try{apply(connection($c),$prefix,'complete_'.$prefix.'_'.$i,'completed');exit(0);}catch(Throwable$e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}}$children[]=$pid;}
foreach($children as$pid){pcntl_waitpid($pid,$s);if(pcntl_wexitstatus($s)!==0)throw new RuntimeException('MySQL callback child failed');}
$p=connection($c);$r=new PaymentRepository($p);if(count($r->refundsForPayment($id))!==1||$r->refundedMinorForPayment($id)!==100)throw new RuntimeException('Duplicate financial entry');echo 'PASS Six MySQL concurrent callbacks settle one historical refund record',PHP_EOL;
apply($p,$prefix,'complete_'.$prefix.'_0','completed');apply($p,$prefix,'late_'.$prefix,'pending');if(count($r->refundsForPayment($id))!==1||$r->payment($id)['status']!=='refunded')throw new RuntimeException('Replay or delayed pending regressed state');echo 'PASS MySQL replay and delayed pending preserve final accounting',PHP_EOL;
echo 'Checks: 3; network requests: 0; provider refund calls: 0',PHP_EOL;
