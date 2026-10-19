<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/core/Bootstrap/autoload.php';
use Cms\Core\Payment\{PaymentService,PaymentRepository,PaymentProviderInterface,PaymentProviderRedirectPolicyInterface,PaymentProviderRegistry,PaymentProviderSettingsRepository,PaymentResult,PaymentException};
$p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$ms=array_map(static fn($f)=>require$f,glob(dirname(__DIR__).'/system/migrations/*.php'));(new Cms\Core\Migration\MigrationRunner($p,$ms))->run();
$provider=new class implements PaymentProviderInterface,PaymentProviderRedirectPolicyInterface{
 public int $calls=0;public function providerId():string{return 'test.checkout';}public function displayName():string{return 'Recovery fixture';}public function capabilities():array{return ['payment.create'];}
 public function isSafeRedirectUrl(string $u):bool{$a=parse_url($u);return is_array($a)&&($a['scheme']??'')==='https'&&($a['host']??'')==='pay.example.test'&&!isset($a['user'])&&!isset($a['pass']);}
 public function createPayment(object $c):PaymentResult{$this->calls++;return new PaymentResult(true,'checkout_created','Fixture',false,'cs_fixture_'.$this->calls,['status'=>'pending','provider_payment_id'=>'cs_fixture_'.$this->calls,'checkout_url'=>'https://pay.example.test/c/session#fidTrustedFragment']);}
 public function capturePayment(object $c):PaymentResult{throw new RuntimeException('No network');}public function cancelPayment(object $c):PaymentResult{throw new RuntimeException('No network');}public function refundPayment(object $c):PaymentResult{throw new RuntimeException('Refund forbidden');}public function getPaymentStatus(object $c):PaymentResult{throw new RuntimeException('No network');}
};
PaymentProviderRegistry::register($provider->providerId(),$provider);$key='checkout-recovery-fixture-encryption';(new PaymentProviderSettingsRepository($p,$key))->save($provider->providerId(),'Fixture','enabled');$repo=new PaymentRepository($p);$s=new PaymentService($p,$repo,$key);$checks=0;
function ck(bool$v,string$m):void{global$checks;if(!$v)throw new RuntimeException($m);$checks++;echo "PASS $m\n";}
function rejected(callable$f,string$m):void{try{$f();}catch(PaymentException){ck(true,$m);return;}throw new RuntimeException($m);}
$a=$s->createProviderPayment('checkout_test','one',$provider->providerId(),100,'USD','once');$stored=$repo->payment((int)$a['id']);$url=$a['_provider_checkout_url'];ck($s->checkoutUrl($stored)===$url,'Exact fragment is restored from server encryption');ck(!str_contains($stored['metadata_json'],'fidTrustedFragment'),'Exact fragment is absent from plaintext metadata');
$b=$s->createProviderPayment('checkout_test','one',$provider->providerId(),100,'USD','once');ck($provider->calls===1&&$s->checkoutUrl($b)===$url,'Repeated create restores the original Checkout without another provider call');
$other=$stored;$other['request_hash']=hash('sha256','other');rejected(fn()=>$s->checkoutUrl($other),'Recovery ciphertext is bound to the order request hash');rejected(fn()=>(new PaymentService($p,$repo,'wrong-fixture-encryption'))->checkoutUrl($stored),'Wrong encryption key fails closed');
$bad=$stored;$m=json_decode($bad['metadata_json'],true);$m['checkout_resume']='invalid';$bad['metadata_json']=json_encode($m);rejected(fn()=>$s->checkoutUrl($bad),'Malformed recovery ciphertext fails closed');
$paid=$stored;$paid['status']='paid';ck($s->checkoutUrl($paid)==='','Completed payments do not resume checkout');
$bad=$stored;$bad['metadata_json']=json_encode(['checkout_url'=>'https://evil.example/c/pay']);ck($s->checkoutUrl($bad)==='','Provider redirect policy rejects persisted external URLs');$bad['metadata_json']=json_encode(['checkout_url'=>'https://pay.example.test/[redacted]']);ck($s->checkoutUrl($bad)==='','Redacted legacy URL cannot be used for payment');
ck((int)$p->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn()===1,'Recovery retains one financial order');ck((int)$p->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn()===0,'No refund request or record');echo "Checks: $checks; network requests: 0; refunds: 0\n";
