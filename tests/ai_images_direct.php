<?php
declare(strict_types=1);
require __DIR__.'/../system/core/Bootstrap/autoload.php';
use Cms\Core\Ai\{AiImageService,OpenAiImageProvider,SiteAiSettingsRepository,AiException};
use Cms\Core\Config\Settings;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\{PluginManifest,PluginAiImageService,PluginPaymentRequestScope,PluginException};
use Cms\Core\Queue\{QueueService,QueueJob,QueueHandlerRegistry};
$checks=0;function ck(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function scope(int $user,callable $fn,string $method='POST',bool $csrf=true):mixed{$r=new ReflectionProperty(PluginPaymentRequestScope::class,'current');$old=$r->getValue();$r->setValue(null,['plugin_id'=>'example.direct','actor_id'=>$user,'method'=>$method,'csrf'=>$csrf,'admin_id'=>null]);try{return $fn();}finally{$r->setValue(null,$old);}}
function deny(callable $fn,string $reason):void{try{$fn();throw new RuntimeException('Expected denial');}catch(AiException $e){ck($e->reason()===$reason,$reason);}catch(PluginException $e){ck($e->getMessage()===$reason,$reason);}}
$root=sys_get_temp_dir().'/cms-direct-images-'.bin2hex(random_bytes(4));mkdir($root,0700);
$p=new PDO('sqlite:'.$root.'/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$ms=[];foreach(glob(__DIR__.'/../system/migrations/*.php')as$f)$ms[]=require$f;(new MigrationRunner($p,$ms))->run();
foreach([1,2]as$id)$p->prepare("INSERT INTO cms_front_users(id,email,password_hash,display_name,status,created_at,updated_at)VALUES(?,?,'none','Direct test','active','now','now')")->execute([$id,"direct$id@test.invalid"]);
$m=PluginManifest::fromArray(['plugin_id'=>'example.direct','name'=>'Direct','version'=>'1.0.0','author'=>'Test','core'=>['min'=>'1.2.87'],'php'=>'8.3','entry'=>'plugin.php','capabilities'=>['ai.use','ai.image']]);
$p->exec("INSERT INTO cms_plugins(plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at)VALUES('example.direct','Direct','1.0.0','Test','Enabled','api','[]','now','now')");
$settings=Settings::fromArray(['security'=>['encryption_key'=>'isolated-direct-key']]);$config=new SiteAiSettingsRepository($p,'isolated-direct-key');$config->save(['enabled'=>true,'provider'=>'openai','base_url'=>'https://api.openai.com/v1','model'=>'gpt-4.1-mini','image_model'=>'gpt-image-1.5','timeout_seconds'=>120,'max_tokens'=>100,'temperature'=>0.7,'image_daily_limit'=>100,'image_minute_limit'=>60],'isolated-fixture-secret');
$im=imagecreatetruecolor(64,64);imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));imagefilledrectangle($im,10,10,50,50,imagecolorallocate($im,20,30,40));ob_start();imagepng($im);$png=ob_get_clean();unset($im);
$calls=0;$timeout=null;$unknown=false;$adapter=new OpenAiImageProvider(static function($url,$headers,$body,$seconds)use(&$calls,&$timeout,&$unknown,$png){$calls++;$timeout=$seconds;if($unknown)throw new RuntimeException('isolated-fixture-secret');return ['status'=>200,'body'=>json_encode(['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>3,'output_tokens'=>4]])];});
$images=new AiImageService($p,$settings,$root,$adapter);$sdk=new PluginAiImageService($m,$p,$images);
$job=scope(1,fn()=>$sdk->submit('direct-once',['prompt'=>'戴影 CMS']));ck($calls===0,'Existing submit remains asynchronous');
deny(fn()=>scope(2,fn()=>$sdk->execute($job['job_id'])),'not_found');deny(fn()=>scope(1,fn()=>$sdk->execute($job['job_id']),'GET'),'capability_denied');deny(fn()=>scope(1,fn()=>$sdk->execute($job['job_id']),'POST',false),'capability_denied');
$done=scope(1,fn()=>$sdk->execute($job['job_id']));ck($done['status']==='completed'&&$calls===1&&$timeout===120,'Scoped direct execution completes without any worker and preserves timeout');
scope(1,fn()=>$sdk->execute($job['job_id']));ck($calls===1&&(int)$p->query('SELECT COUNT(*) FROM cms_ai_usage_ledger')->fetchColumn()===1,'Completed direct request is idempotent');
ck($p->query("SELECT status FROM cms_core_queue_jobs WHERE job_type='ai.image' LIMIT 1")->fetchColumn()==='done','Direct execution settles its queue record');
ck(!str_contains(json_encode($done),'isolated-fixture-secret'),'Direct response contains no API credential');
$stale=scope(1,fn()=>$sdk->submit('stale',['prompt'=>'stale']));$other=scope(2,fn()=>$sdk->submit('other-stale',['prompt'=>'other']));
$p->exec("UPDATE cms_ai_image_jobs SET status='dispatching',updated_at='2000-01-01T00:00:00+00:00' WHERE status='queued'");
$recovered=scope(1,fn()=>$sdk->execute($stale['job_id']));ck($recovered['status']==='uncertain'&&$calls===1,'Interrupted dispatch is marked uncertain without provider replay');
ck($images->find($other['job_id'],'example.direct',2)['status']==='dispatching','Scoped recovery leaves another member untouched');
$receipt=scope(1,fn()=>$sdk->submit('receipt',['prompt'=>'receipt']));$p->prepare("UPDATE cms_ai_image_jobs SET status='response_ready' WHERE job_id=?")->execute([$receipt['job_id']]);file_put_contents($root.'/storage/ai-images/'.$receipt['job_id'].'.json',json_encode(['png'=>base64_encode($png),'usage'=>[]]));
ck(scope(1,fn()=>$sdk->execute($receipt['job_id']))['status']==='completed'&&$calls===1,'Receipt recovery saves media without another AI call');
$unknown=true;$ambiguous=scope(1,fn()=>$sdk->submit('unknown',['prompt'=>'unknown']));scope(1,fn()=>$sdk->execute($ambiguous['job_id']));$before=$calls;scope(1,fn()=>$sdk->execute($ambiguous['job_id']));ck($calls===$before&&$images->find($ambiguous['job_id'],'example.direct',1)['status']==='uncertain','Ambiguous transport never retries through direct endpoint');
$p->exec("UPDATE cms_plugins SET status='Disabled' WHERE plugin_id='example.direct'");deny(fn()=>scope(1,fn()=>$sdk->execute($job['job_id'])),'capability_denied');
$queue=new QueueService($p);$seen=[];QueueHandlerRegistry::register('example.design',static function($payload)use(&$seen){$seen[]=$payload['job_id'];});
$queue->enqueue(new QueueJob('example.design',['job_id'=>'other'],'other.plugin',1));$queue->enqueue(new QueueJob('example.design',['job_id'=>'other'],'example.direct',1));$id=$queue->enqueue(new QueueJob('example.design',['job_id'=>'mine'],'example.direct',1));
$result=$queue->processMatching(new QueueJob('example.design',['job_id'=>'mine'],'example.direct'));ck($result['processed']===1&&$seen===['mine'],'Matching execution selects exact owner, type and payload');
ck($queue->processMatching(new QueueJob('example.design',['job_id'=>'mine'],'example.direct'))['processed']===0,'Matching execution does not replay done work');
$future=$queue->enqueue(new QueueJob('example.design',['job_id'=>'future'],'example.direct',1,'2999-01-01T00:00:00+00:00'));ck($queue->processMatching(new QueueJob('example.design',['job_id'=>'future'],'example.direct'))['processed']===0,'Matching execution respects not_before');
ck((int)$p->query("SELECT COUNT(*) FROM cms_core_queue_jobs WHERE job_type='example.design' AND status='pending'")->fetchColumn()===3,'Unrelated and future work remain pending');
$parallel=new PDO('sqlite:'.$root.'/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$parallel->exec('PRAGMA busy_timeout=100');$parallelWrite=false;
QueueHandlerRegistry::register('example.unlocked',static function()use($parallel,&$parallelWrite){$parallel->exec("INSERT INTO cms_core_queue_jobs(job_type,owner,payload_json,status,attempts,max_attempts,not_before,created_at,updated_at)VALUES('example.side','test','{}','pending',0,1,'now','now','now')");$parallelWrite=true;});
$queue->enqueue(new QueueJob('example.unlocked',[],'example.direct',1));$result=$queue->processMatching(new QueueJob('example.unlocked',[],'example.direct'));ck($result['succeeded']===1&&$parallelWrite,'Direct handler leaves SQLite available for concurrent page requests');
ck((int)$p->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn()===0,'No payments or refunds');
echo "Checks: $checks; external requests: 0\n";
