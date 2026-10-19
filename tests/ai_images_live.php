<?php
/** Explicit opt-in, run on the authorized host. Never prints/persists production secrets. */
declare(strict_types=1);
require __DIR__.'/../system/core/Bootstrap/autoload.php';
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Ai\{AiImageService,SiteAiSettingsRepository};
if(getenv('DAIYING_AI_IMAGE_LIVE_TEST')!=='1'||PHP_SAPI!=='cli')throw new RuntimeException('Live opt-in required.');
$site=$argv[1]??'';$isolated=$argv[2]??'';
if(!is_dir($site)||!str_starts_with($isolated,sys_get_temp_dir().'/')||file_exists($isolated))throw new RuntimeException('Fresh isolated /tmp root required.');
mkdir($isolated,0700,true);
$siteSettings=Settings::load($site);
// Use a direct read, not SiteAiSettingsRepository on production (its ctor performs backfills).
$live=ConnectionFactory::make($siteSettings);
$row=$live->query('SELECT * FROM cms_core_ai_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if(!is_array($row)||$row['provider']!=='openai'||$row['base_url']!=='https://api.openai.com/v1'||(int)$row['timeout_seconds']!==120)throw new RuntimeException('Production config drift.');
$pdo=new PDO('sqlite:'.$isolated.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$migrations=[];foreach(glob(__DIR__.'/../system/migrations/*.php')as$file)$migrations[]=require$file;(new MigrationRunner($pdo,$migrations))->run();
$pdo->exec('DELETE FROM cms_core_ai_settings');
register_shutdown_function(static function() use ($pdo): void { $pdo->exec("UPDATE cms_core_ai_settings SET api_key_ciphertext='' WHERE id=1"); $pdo->exec('VACUUM'); });
$columns=array_keys($row);$pdo->prepare('INSERT INTO cms_core_ai_settings ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
$key=(string)$siteSettings->get('security.encryption_key','');
$settings=Settings::fromArray(['security'=>['encryption_key'=>$key]]);
$repo=new SiteAiSettingsRepository($pdo,$key);$config=$repo->current();$config['image_model']='gpt-image-1.5';$repo->save($config,'');
$pdo->exec("INSERT INTO cms_front_users(email,password_hash,display_name,status,created_at,updated_at)VALUES('image-live@test.invalid','none','Isolated image test','active','now','now')");$user=(int)$pdo->lastInsertId();
$service=new AiImageService($pdo,$settings,$isolated);
$start=microtime(true);$j=$service->submit('core',$user,'live-chinese-generate',['prompt'=>'A clean brand wordmark. Render exactly these two Chinese characters: 戴影. Correct Chinese stroke structure. Black ink Chinese brush calligraphy centered, no additional text, transparent background.','background'=>'transparent']);$service->work($j['job_id']);$j=$service->find($j['job_id'],'core',$user);
$result=['generation'=>$j,'generation_seconds'=>round(microtime(true)-$start,2),'timeout_seconds'=>$config['timeout_seconds'],'text_model_unchanged'=>$config['model'],'production_changed'=>false];
if($j['status']==='completed') {
 file_put_contents($isolated.'/generated.png',$service->imageBytes($j['job_id'],'core',$user));
 $start=microtime(true);$e=$service->submit('core',$user,'live-chinese-edit',['prompt'=>'Keep the exact two Chinese characters 戴影 and their correct strokes. Change the calligraphy color to dark red. Preserve the composition and transparent background.','operation'=>'image.edit','source_job'=>$j['job_id'],'background'=>'transparent']);$service->work($e['job_id']);$e=$service->find($e['job_id'],'core',$user);
 $result['edit']=$e;$result['edit_seconds']=round(microtime(true)-$start,2);
 if($e['status']==='completed')file_put_contents($isolated.'/edited.png',$service->imageBytes($e['job_id'],'core',$user));
}
// The temporary DB has the encrypted key; remove ciphertext immediately after the test.
$pdo->exec("UPDATE cms_core_ai_settings SET api_key_ciphertext='' WHERE id=1");$pdo->exec('VACUUM');
file_put_contents($isolated.'/result.json',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
