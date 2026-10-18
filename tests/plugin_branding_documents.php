<?php

declare(strict_types=1);
require dirname(__DIR__).'/system/core/Bootstrap/autoload.php';
use Cms\Core\Plugin\{PluginDataStore,PluginManifest,PluginBrandingService,PluginPaymentRequestScope,PluginException};
use Cms\Core\Migration\MigrationRunner;
$root=sys_get_temp_dir().'/cms-public-brand-'.bin2hex(random_bytes(4));mkdir($root.'/config',0700,true);file_put_contents($root.'/config/app.php',"<?php return ['site'=>['name'=>'old'],'secret'=>'preserve'];");
if(!defined('CMS_ROOT'))define('CMS_ROOT',$root);
$p=new PDO('sqlite:'.$root.'/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$ms=[];foreach(glob(dirname(__DIR__).'/system/migrations/*.php')as$f)$ms[]=require$f;(new MigrationRunner($p,$ms))->run();$a=new PluginDataStore($p,'example.brand');$b=new PluginDataStore($p,'example.other');$n=0;
function ck(bool $ok,string $msg):void{global $n;if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";$n++;}
$a->mutate('wallet','1',fn($old)=>['balance'=>10]);$a->mutate('wallet','1',fn($old)=>['balance'=>$old['balance']-3]);ck($a->document('wallet','1')['balance']===7,'Atomic document updates persist');ck($b->document('wallet','1')===null,'Documents remain plugin scoped');
try{$a->mutate('wallet','1',function($old){throw new RuntimeException('rollback');});}catch(RuntimeException){}ck($a->document('wallet','1')['balance']===7,'Mutation failure rolls back');
try{$a->mutate('wallet','1',fn($old)=>['oversize'=>str_repeat('x',2097152)]);}catch(PluginException){}ck($a->document('wallet','1')['balance']===7,'Oversized mutation rolls back');
$m=PluginManifest::fromArray(['plugin_id'=>'example.brand','name'=>'Brand','version'=>'1.0.0','author'=>'Test','core'=>['min'=>'1.3.0-alpha.2'],'php'=>'8.3','entry'=>'plugin.php','capabilities'=>['site.brand.write']]);$s=new PluginBrandingService($m,$p,$root);
try{$s->apply('bad','bad','x','valid-key');ck(false,'Scope needed');}catch(PluginException){ck(true,'Unverified caller cannot publish branding');}
$p->exec("INSERT INTO cms_plugins(plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at)VALUES('example.brand','Brand','1.0.0','Test','Enabled','api','[]','now','now')");$p->exec("INSERT INTO cms_admin_users(id,email,password_hash,display_name,created_at,updated_at)VALUES(1,'a@test.invalid','none','Admin','now','now')");$_SESSION['admin_user']=['id'=>1,'capabilities'=>['admin.settings.manage']];$scope=new ReflectionProperty(PluginPaymentRequestScope::class,'current');$scope->setValue(null,['plugin_id'=>'example.brand','method'=>'POST','csrf'=>true,'actor_id'=>null,'admin_id'=>1]);
$im=imagecreatetruecolor(32,32);imagefill($im,0,0,imagecolorallocate($im,30,100,220));ob_start();imagepng($im);$png=ob_get_clean();unset($im);
$r=$s->apply($png,$png,'Brand','valid-key');$r2=$s->apply($png,$png,'Brand','valid-key');$c=require $root.'/config/app.php';ck($r===$r2&&count(glob($root.'/storage/backups/branding/*'))===1,'Brand apply idempotently returns original backed-up receipt');ck($c['secret']==='preserve'&&$c['site']['name']==='Brand','Branding preserves unrelated configuration');
try{$s->apply($png,$png,'Changed','valid-key');ck(false,'Conflict denied');}catch(PluginException){ck(true,'Changed content cannot reuse branding key');}
$scope->setValue(null,['plugin_id'=>'example.brand','method'=>'POST','csrf'=>false,'actor_id'=>null,'admin_id'=>1]);try{$s->apply($png,$png,'Brand','second-key');ck(false,'CSRF needed');}catch(PluginException){ck(true,'Missing verified CSRF denies branding');}
$scope->setValue(null,null);echo "Checks: $n\n";
