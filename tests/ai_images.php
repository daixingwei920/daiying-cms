<?php

declare(strict_types=1);
require __DIR__.'/../system/core/Bootstrap/autoload.php';
use Cms\Core\Ai\{AiImageService,AiService,AiException,OpenAiImageProvider,SiteAiSettingsRepository,AiRequest};
use Cms\Core\Config\Settings;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Queue\QueueService;

$fail=0;$checks=0;
function check(bool $ok,string $label): void {global $fail,$checks;$checks++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$fail++;}
function denied(callable $fn,string $reason): void {try{$fn();check(false,$reason);}catch(AiException $e){check($e->reason()===$reason,$reason);} }
$root=sys_get_temp_dir().'/cms-ai-images-'.bin2hex(random_bytes(5));mkdir($root,0700,true);
$pdo=new PDO('sqlite:'.$root.'/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$migrations=[]; foreach(glob(__DIR__.'/../system/migrations/*.php') as $file) $migrations[]=require $file;
(new MigrationRunner($pdo,$migrations))->run();
$pdo->exec("INSERT INTO cms_front_users(email,password_hash,display_name,status,created_at,updated_at)VALUES('image@test.invalid','none','Image User','active','now','now')");$uid=(int)$pdo->lastInsertId();
$settings=Settings::fromArray(['security'=>['encryption_key'=>'isolated-image-fixture-encryption']]);
$repo=new SiteAiSettingsRepository($pdo,'isolated-image-fixture-encryption');
$conf=['enabled'=>true,'provider'=>'openai','base_url'=>'https://api.openai.com/v1','model'=>'gpt-4.1-mini','image_model'=>'gpt-image-1.5','timeout_seconds'=>120,'temperature'=>0.7,'max_tokens'=>100,'image_daily_limit'=>100,'image_minute_limit'=>60];
$repo->save($conf,'fixture-image-secret');
$im=imagecreatetruecolor(64,64);imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));imagefilledrectangle($im,10,10,50,50,imagecolorallocate($im,10,15,20));ob_start();imagepng($im);$png=ob_get_clean();unset($im);
$calls=0;$last=[];$reject=false;$unknown=false;
$adapter=new OpenAiImageProvider(static function($url,$headers,$payload,$timeout)use(&$calls,&$last,&$reject,&$unknown,$png):array{
 $calls++;$last=[$url,$payload,$timeout];if($unknown)throw new RuntimeException('secret must not escape');
 if($reject)return ['status'=>400,'body'=>'{"error":{"message":"fixture-image-secret"}}'];
 return ['status'=>200,'body'=>json_encode(['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>3,'output_tokens'=>4,'total_tokens'=>7]])];
});
$svc=new AiImageService($pdo,$settings,$root,$adapter);$svc->registerWorker();
$r=['prompt'=>'精确显示中文：戴影','background'=>'transparent'];
$j=$svc->submit('core',$uid,'first',$r);$again=$svc->submit('core',$uid,'first',$r);
check($j['job_id']===$again['job_id']&&$calls===0,'Submission is async and idempotent');
denied(fn()=>$svc->submit('core',$uid,'first',['prompt'=>'different']),'idempotency_conflict');
denied(fn()=>$svc->submit('core',$uid,'override',$r+['timeout_seconds'=>300]),'request_invalid');
(new QueueService($pdo))->process();$done=$svc->find($j['job_id'],'core',$uid);
check($done['status']==='completed'&&$calls===1,'Native queue completes a single dispatch');
check($last[2]===120,'Admin Timeout remains 120 seconds at transport boundary');
check(json_decode($last[1],true)['prompt']==='精确显示中文：戴影','Chinese prompt bytes preserved (not model typography acceptance)');
check(!str_contains(json_encode($done),'fixture-image-secret'),'Public job never exposes key');
$queueJson=$pdo->query('SELECT payload_json FROM cms_core_queue_jobs LIMIT 1')->fetchColumn();
check(!str_contains($queueJson,'fixture-image-secret')&&!str_contains($queueJson,'Bearer'),'Queue contains ID only');
check($svc->imageBytes($j['job_id'],'core',$uid)!=='','Original belongs to CMS media');
$stored=imagecreatefromstring($svc->imageBytes($j['job_id'],'core',$uid));
check(imagecolorsforindex($stored,imagecolorat($stored,0,0))['alpha']===127,'CMS ingest preserves transparent PNG alpha');
unset($stored);
check((new MediaLibrary($pdo,$root.'/content/uploads'))->viewModel((int)$done['media_id'])['available']===false,'Private original has no public media URL');
try{(new MediaLibrary($pdo,$root.'/content/uploads'))->fileForResponse((int)$done['media_id']);check(false,'Private original access blocked');}catch(Throwable){check(true,'Public media library cannot read private source');}
denied(fn()=>$svc->find($j['job_id'],'other',$uid),'not_found');
denied(fn()=>$svc->imageBytes($j['job_id'],'core',$uid+1),'not_found');
$svc->work($j['job_id']);check($calls===1&&$pdo->query('SELECT COUNT(*) FROM cms_ai_usage_ledger')->fetchColumn()==1,'Repeated worker never duplicates provider or usage ledger');
$edit=$svc->submit('core',$uid,'edit',['prompt'=>'更换红色布局','operation'=>'image.edit','source_job'=>$j['job_id']]);$svc->work($edit['job_id']);
check($svc->find($edit['job_id'],'core',$uid)['status']==='completed'&&str_ends_with($last[0],'/edits')&&$last[1]['image'] instanceof CURLFile,'Image edit sends multipart CMS source');
$reject=true;$failed=$svc->submit('core',$uid,'failed',$r);$svc->work($failed['job_id']);
check($svc->find($failed['job_id'],'core',$uid)['status']==='failed','Explicit rejection is terminal');$reject=false;
$unknown=true;$u=$svc->submit('core',$uid,'unknown',$r);$svc->work($u['job_id']);$n=$calls;$svc->recover();$svc->work($u['job_id']);
check($svc->find($u['job_id'],'core',$uid)['status']==='uncertain'&&$calls===$n,'Ambiguous transport never automatically replays');$unknown=false;
$stale=$svc->submit('core',$uid,'stale',$r);$pdo->prepare("UPDATE cms_ai_image_jobs SET status='dispatching',updated_at='2000-01-01T00:00:00+00:00' WHERE job_id=?")->execute([$stale['job_id']]);$n=$calls;$svc->recover();
check($svc->find($stale['job_id'],'core',$uid)['status']==='uncertain'&&$calls===$n,'Crashed dispatch becomes uncertain without charging again');
$resume=$svc->submit('core',$uid,'resume',$r);$pdo->prepare("UPDATE cms_ai_image_jobs SET status='response_ready' WHERE job_id=?")->execute([$resume['job_id']]);
file_put_contents($root.'/storage/ai-images/'.$resume['job_id'].'.json',json_encode(['png'=>base64_encode($png),'usage'=>[]]));$n=$calls;$svc->recover();
check($svc->find($resume['job_id'],'core',$uid)['status']==='completed'&&$calls===$n,'Persisted response recovers media import without another call');
$conf['provider']='deepseek';$repo->save($conf,'');denied(fn()=>$svc->submit('core',$uid,'deepseek',$r),'image_model_unsupported');
$conf['provider']='openai';$conf['image_model']='gpt-4.1-mini';$repo->save($conf,'');denied(fn()=>$svc->submit('core',$uid,'text-model',$r),'image_model_unsupported');
$conf['image_model']='gpt-image-1.5';$conf['base_url']='https://proxy.invalid/v1';$repo->save($conf,'');denied(fn()=>$svc->submit('core',$uid,'proxy',$r),'image_model_unsupported');
$conf['base_url']='https://api.openai.com/v1';$conf['image_daily_limit']=1;$repo->save($conf,'');denied(fn()=>$svc->submit('core',$uid,'quota',$r),'quota_exceeded');
check((int)$pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn()===0,'Generation failure never creates a payment');
check((new AiService($pdo,$settings))->getConfig()['timeout_seconds']===120,'Saved Timeout unchanged');
check($done['estimated_cost_micro']===null,'Unknown cost remains null, never shown as zero');
check((new \Cms\Core\Ai\AiModel('x','x',['text_generation']))->supports(['text.generate']),'Canonical text capability aliases legacy');
denied(fn()=>(new AiService($pdo,$settings))->request(new AiRequest([['role'=>'user','content'=>'x']],['image.generate'])),'capability_unsupported');
check((int)$pdo->query('SELECT COUNT(*) FROM cms_ai_audit_events')->fetchColumn()>5,'Lifecycle audit events recorded');
(new QueueService($pdo))->enqueue(new \Cms\Core\Queue\QueueJob('other.plugin',['x'=>1]));
(new QueueService($pdo))->process(100,'ai.image');
check($pdo->query("SELECT status FROM cms_core_queue_jobs WHERE job_type='other.plugin'")->fetchColumn()==='pending','Image worker never consumes unrelated plugin jobs');

file_put_contents($root.'/result.json',json_encode(['checks'=>$checks,'failures'=>$fail]));
echo "Fixture: $root\nChecks: $checks failures: $fail\n";exit($fail?1:0);
