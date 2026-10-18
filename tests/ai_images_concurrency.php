<?php

declare(strict_types=1);
require __DIR__.'/../system/core/Bootstrap/autoload.php';
use Cms\Core\Ai\{AiImageService,OpenAiImageProvider,SiteAiSettingsRepository,AiException};
use Cms\Core\Config\Settings;
use Cms\Core\Migration\MigrationRunner;
if(!function_exists('pcntl_fork')){fwrite(STDERR,'NOT VERIFIED: pcntl required'.PHP_EOL);exit(2);}
$root=sys_get_temp_dir().'/cms-image-concurrent-'.bin2hex(random_bytes(4));mkdir($root,0700,true);
function db(string $root):PDO{return new PDO('sqlite:'.$root.'/db.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>10]);}
$pdo=db($root);$ms=[];foreach(glob(__DIR__.'/../system/migrations/*.php')as$f)$ms[]=require$f;(new MigrationRunner($pdo,$ms))->run();
$pdo->exec("INSERT INTO cms_front_users(id,email,password_hash,display_name,status,created_at,updated_at)VALUES(1,'concurrent@test.invalid','none','Concurrent','active','now','now')");
$settings=Settings::fromArray(['security'=>['encryption_key'=>'concurrent-fixture']]);(new SiteAiSettingsRepository($pdo,'concurrent-fixture'))->save(['enabled'=>true,'provider'=>'openai','base_url'=>'https://api.openai.com/v1','model'=>'gpt-4.1-mini','image_model'=>'gpt-image-1.5','timeout_seconds'=>120,'max_tokens'=>100,'temperature'=>0.7,'image_daily_limit'=>1,'image_minute_limit'=>60],'fixture-key');
$im=imagecreatetruecolor(16,16);ob_start();imagepng($im);$png=ob_get_clean();unset($im);
$pdo=null;$children=[];
for($i=0;$i<6;$i++){
 $pid=pcntl_fork();if($pid===0){try{$pdo=db($root);$adapter=new OpenAiImageProvider(static function()use($root,$png){file_put_contents($root.'/calls','call\n',FILE_APPEND|LOCK_EX);usleep(350000);return ['status'=>200,'body'=>json_encode(['data'=>[['b64_json'=>base64_encode($png)]]])];});$s=new AiImageService($pdo,$settings,$root,$adapter);$j=$s->submit('core',1,'same',['prompt'=>'shared']);$s->work($j['job_id']);file_put_contents($root.'/child-'.getmypid(),$j['job_id']);exit(0);}catch(Throwable$e){file_put_contents($root.'/error-'.getmypid(),$e->getMessage());exit(1);}}$children[]=$pid;
}
foreach($children as$pid){pcntl_waitpid($pid,$status);if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('Concurrent child failed.');}
$pdo=db($root);$ids=array_unique(array_map('file_get_contents',glob($root.'/child-*')));
if(count($ids)!==1||file_get_contents($root.'/calls')!=='call\n'||(int)$pdo->query('SELECT COUNT(*) FROM cms_ai_usage_ledger')->fetchColumn()!==1)throw new RuntimeException('Concurrent duplicate dispatch.');
echo 'PASS 6 simultaneous submit/work processes: one job, one provider call, one ledger row'.PHP_EOL;
$service=new AiImageService($pdo,$settings,$root);
try{$service->submit('core',1,'second',['prompt'=>'new']);throw new RuntimeException('Quota bypass.');}catch(AiException$e){if($e->reason()!=='quota_exceeded')throw$e;}
echo 'PASS concurrent reservation preserves daily user quota'.PHP_EOL;
