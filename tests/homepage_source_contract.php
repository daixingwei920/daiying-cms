<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/core/Bootstrap/autoload.php';
use Cms\Core\Homepage\HomepageSelection;
use Cms\Core\Plugin\{PluginRuntimeRegistry,PluginManifest,PluginContext,PluginDataStore,BlockRegistry};
use Cms\Core\Events\EventDispatcher;
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";}
function denied(callable $f,string $m):void{try{$f();}catch(Throwable){ok(true,$m);return;}ok(false,$m);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$m=require dirname(__DIR__).'/system/migrations/2026_10_13_000001_homepage_sources.php';$m->up($db);$h=new HomepageSelection($db);$reg=new PluginRuntimeRegistry();
ok($h->snapshot()['provider']==='core.theme','Default theme remains selected');
$h->select('official.demo:home','official.demo',0);$selected=$h->snapshot();ok($selected['revision']===1&&$h->lease('official.demo')['previous']['provider']==='core.theme','Selection stores previous source, owner, token and revision');
denied(fn()=>$h->select('other:home','other',0),'Stale revision cannot overwrite selection');
$h->lifecycle('official.demo',false);ok($h->snapshot()['provider']==='core.theme','Disable restores previous source');
$h->lifecycle('official.demo',true);ok($h->snapshot()['provider']==='official.demo:home','Enable resumes unchanged leased selection');
$h->lifecycle('official.demo',false);$h->select('core.theme','core',$h->snapshot()['revision']);$h->lifecycle('official.demo',true);ok($h->snapshot()['provider']==='core.theme','Administrator selecting even same source invalidates automatic resume');
$h->select('official.demo:home','official.demo',$h->snapshot()['revision']);$h->select('other:home','other',$h->snapshot()['revision']);ok(!$h->restore('official.demo',$h->snapshot()['revision']),'Restore refuses another owner');
$h->restore('other',$h->snapshot()['revision']);ok($h->snapshot()['provider']==='official.demo:home','Restores earlier plugin source snapshot');
$h->lifecycle('official.demo',false,true);ok($h->snapshot()['provider']==='core.theme'&&$h->lease('official.demo')===null,'Uninstall restores and deletes lease');$h->lifecycle('official.demo',true);ok($h->snapshot()['provider']==='core.theme','Reinstall never automatically takes over');
denied(fn()=>$reg->route('official.demo','GET','/',fn()=>null),'Reserved root remains forbidden');
$manifest=PluginManifest::fromArray(['plugin_id'=>'official.demo','name'=>'demo','version'=>'1.0','author'=>'test','core'=>['min'=>'1.2.83'],'php'=>'>=8.3','entry'=>'plugin.php','trust_level'=>'trusted_php','capabilities'=>[],'optional_capabilities'=>['frontend.homepage','future.unknown']]);ok($manifest->capabilities===['frontend.homepage'],'Only supported optional capabilities negotiated');
$ctx=new PluginContext($manifest,new EventDispatcher(),new BlockRegistry(),new PluginDataStore($db,'official.demo'),$db,$reg,null,false);denied(fn()=>$ctx->registerHomepageRenderer('home','test',fn()=>null),'Untrusted context cannot register homepage');
$ctx=new PluginContext($manifest,new EventDispatcher(),new BlockRegistry(),new PluginDataStore($db,'official.demo'),$db,$reg,null,true, trustedHomepageAccess: true);$ctx->registerHomepageRenderer('home','test',fn()=>null);ok(isset($reg->homepages()['official.demo:home']),'Trusted declared provider registered');$reg->removeHomepageProviders('official.demo');ok($reg->homepages()===[],'Failed plugin boot removes homepage registrations');
$h->select('official.demo:home','official.demo',$h->snapshot()['revision']);$renderer=new \Cms\Core\Homepage\HomepageRenderer($h,$reg,new \Cms\Core\Logging\FileLogger(sys_get_temp_dir().'/cms-homepage-contract.log'));ok($renderer->render(new \Cms\Core\Http\Request('GET','/'))===null,'Unavailable provider falls back');
$reg->homepage('official.demo','home','test',fn()=>throw new RuntimeException('test renderer error'));ok($renderer->render(new \Cms\Core\Http\Request('GET','/'))===null,'Throwing renderer falls back');$reg->removeHomepageProviders('official.demo');$reg->homepage('official.demo','home','test',fn()=>\Cms\Core\Http\Response::redirect('/elsewhere'));ok($renderer->render(new \Cms\Core\Http\Request('GET','/'))===null,'Redirect is not accepted as homepage rendering');
$db->exec("UPDATE cms_homepage_selection SET state_json='broken'");denied(fn()=>$h->snapshot(),'Malformed configuration fails closed');
