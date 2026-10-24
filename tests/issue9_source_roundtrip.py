#!/usr/bin/env python3
"""Native source/SQLite roundtrip; not signed-package/updater acceptance."""
from pathlib import Path
import io, json, subprocess, tarfile, tempfile, shutil

SOURCE = Path(__file__).resolve().parents[1]
BASE = 'a7f99ffdd61eace1b997516a2d91376f5f72f602'
PHP = r'''<?php
declare(strict_types=1);
[$script,$source,$root,$phase]=$argv;
define('CMS_ROOT',$root);require $source.'/system/core/Bootstrap/autoload.php';
use Cms\Core\Auth\{FrontUserAuthenticator,AdminAuthenticator};
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Config\Settings;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Http\Request;
$config=require $root.'/config/app.php';
$p=new PDO($config['database']['dsn'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($phase==='baseline'){
    (new MigrationRunner($p,array_map(static fn($f)=>require $f,glob($source.'/system/migrations/*.php'))))->run();
    $member=(new FrontUserAuthenticator($p))->register('rollback@test.invalid','synthetic-pass','Member');
    (new AdminAuthenticator($p))->createAdmin('owner@test.invalid','synthetic-pass','Owner');
    (new Cms\Core\Auth\MemberRoleService($p))->assignOperator($member,1,true);
    (new Cms\Core\Content\MemberDraftService($p,$root))->create($member,'Historical draft','retained draft body');
}
$_SERVER['REQUEST_URI']='/health';$app=Application::boot($root);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage());exit(1);});
$_SESSION['front_user']=['id'=>1,'email'=>'rollback@test.invalid','display_name'=>'Member'];
$expected=$phase==='candidate'?410:200;
$response=$app->handle(new Request('GET','/workspace'));
if($response->status()!==$expected)throw new RuntimeException('Unexpected workspace status '.$response->status());
if($phase==='candidate' && $app->handle(new Request('POST','/workspace/drafts',[],['title'=>'forged']))->status()!==410)throw new RuntimeException('Retirement POST failed');
$rows=[];foreach(['cms_contents','cms_front_users','cms_admin_users','cms_member_roles','cms_roles','cms_role_capabilities','cms_core_migrations']as$table)$rows[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
echo json_encode(['phase'=>$phase,'status'=>$response->status(),'data_sha256'=>hash('sha256',json_encode($rows))]),PHP_EOL;
'''
with tempfile.TemporaryDirectory(prefix='cms-issue9-roundtrip-') as temp:
    tmp = Path(temp)
    baseline = tmp / 'baseline'; baseline.mkdir()
    archive = subprocess.check_output(['git','archive','--format=tar',BASE],cwd=SOURCE)
    with tarfile.open(fileobj=io.BytesIO(archive)) as t: t.extractall(baseline, filter='data')
    site = tmp / 'site'
    for directory in ['config','storage','content/plugins','content/themes','content/uploads']:
        (site / directory).mkdir(parents=True,exist_ok=True)
    (site/'storage/installed.lock').touch()
    script=tmp/'roundtrip.php';script.write_text(PHP)
    config="<?php return ['app'=>['version'=>'1.2.88','debug'=>true],'database'=>['dsn'=>'sqlite:"+str(site/'site.sqlite')+"'],'seo'=>['robots_index'=>true%s]];"
    (site/'config/app.php').write_text(config%'')
    results=[]
    for phase,source in [('baseline',baseline),('candidate',SOURCE),('rollback',baseline)]:
        if phase=='candidate':
            (site/'config/app.php').write_text(config%",'schema_enabled'=>true,'llms_enabled'=>true,'crawler_retention_days'=>7")
        if (site/'system').exists(): shutil.rmtree(site/'system')
        shutil.copytree(source/'system',site/'system')
        proc=subprocess.run(['php',str(script),str(source),str(site),phase],capture_output=True,text=True)
        if proc.returncode:raise RuntimeError(proc.stdout+proc.stderr)
        row=json.loads(proc.stdout);results.append(row);print(json.dumps(row))
    if len({r['data_sha256'] for r in results})!=1:raise RuntimeError('Identity/grant/content/migration data drift')
    print('PASS: baseline -> candidate -> old source rollback; identities/grants/drafts/migrations identical; new optional settings tolerated. NOT a signed updater test.')
