<?php
declare(strict_types=1);
// Disposable local fixtures only; MySQL requires explicit empty loopback test configuration.
$source = dirname(__DIR__);
define('CMS_ROOT', $source);
require $source . '/system/core/Bootstrap/autoload.php';
$baseline = getenv('CMS_INSTALL_BASELINE_CONTROLLER');
if ($baseline) { require $baseline; }
use Cms\Core\Install\InstallController;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Auth\AdminAuthenticator;
$root = sys_get_temp_dir() . '/cms-install-closeout-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
foreach (['config', 'storage', 'storage/logs', 'storage/database', 'content'] as $dir) { mkdir($root . '/' . $dir, 0755, true); }
copy($source . '/config/app.example.php',$root . '/config/app.example.php');
symlink($source . '/system', $root . '/system');
symlink($source . '/content/themes', $root . '/content/themes');
$settings = require $source . '/config/app.example.php';
$settings['database'] = ['dsn'=>'sqlite:' . $root . '/storage/database/cms.sqlite','username'=>'','password'=>'','options'=>[]];
$settings['app']['secure_cookies'] = false;
file_put_contents($root . '/config/app.php', '<?php return ' . var_export($settings, true) . ';');
$_SESSION = [];
$mysqlFile=getenv('CMS_INSTALL_SAFETY_MYSQL_CONFIG');
if ($mysqlFile) {
    $c=require $mysqlFile;
    if (($c['host']??'')!=='127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/',$c['database']??'')) throw new RuntimeException('Disposable loopback fixture required.');
    $settings['database']=['dsn'=>'mysql:host=127.0.0.1;dbname='.$c['database'].';charset=utf8mb4','username'=>$c['user'],'password'=>$c['password'],'options'=>[]];
    $probe=new PDO($settings['database']['dsn'],$c['user'],$c['password']);
    if ((int)$probe->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()!==0) throw new RuntimeException('Empty fixture required; no database cleanup is performed.');
    file_put_contents($root.'/config/app.php','<?php return '.var_export($settings,true).';');
}
$body = ['_csrf'=>CsrfToken::get(),'db_driver'=>'sqlite','sqlite_path'=>'storage/database/cms.sqlite','site_name'=>'Closeout fixture','site_url'=>'http://127.0.0.1','email'=>'first@example.invalid','password'=>'Isolation-only-123456','display_name'=>'Fixture'];
if ($mysqlFile) { $body=array_replace($body,['db_driver'=>'mysql','mysql_host'=>'127.0.0.1','mysql_database'=>$c['database'],'mysql_username'=>$c['user'],'mysql_password'=>$c['password']]); }
function check_closeout(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "[PASS] $name\n"; }
$controller = new InstallController($root, Settings::fromArray($settings), new FileLogger($root . '/storage/logs/test.log'));
try {
    $bad=$body; $bad['_csrf']='invalid';
    check_closeout($controller->store(new Request('POST','/install',[],$bad))->status()===400,'CSRF remains enforced');
    rename($root.'/config/app.php',$root.'/config/app.saved.php');
    mkdir($root.'/config/app.php'); // Deterministic configuration-write failure, not a production permission change.
    $configFailure=$controller->store(new Request('POST','/install',[],$body));
    $probe=new PDO($settings['database']['dsn'],$settings['database']['username'],$settings['database']['password']);
    check_closeout($configFailure->status()===500 && (int)$probe->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn()===0 && !is_file($root.'/storage/installed.lock'),'configuration failure rolls back administrator and never completes');
    rmdir($root.'/config/app.php');
    rename($root.'/config/app.saved.php',$root.'/config/app.php');
    mkdir($root . '/storage/installed.lock'); // Admin commits, but completion lock cannot be written.
    $beforeConfig=hash_file('sha256',$root . '/config/app.php');
    $r=$controller->store(new Request('POST','/install',[],$body));
    $p=new PDO($settings['database']['dsn'],$settings['database']['username'],$settings['database']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admins=(int)$p->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn();
    $events=(int)$p->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action='install.completed'")->fetchColumn();
    $body['email']='second@example.invalid';
    $retry=$controller->store(new Request('POST','/install',[],$body));
    $after=(int)$p->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn();
    if ($baseline) { $alternate=$body; $alternate['sqlite_path']='storage/database/alternate.sqlite'; $alt=$controller->store(new Request('POST','/install',[],$alternate)); echo json_encode(['first_status'=>$r->status(),'first_admin_count'=>$admins,'premature_completed_events'=>$events,'retry_status'=>$retry->status(),'retry_admin_count'=>$after,'alternate_database_status'=>$alt->status(),'alternate_database_created'=>is_file($root.'/storage/database/alternate.sqlite'),'error_log'=>file_get_contents($root.'/storage/logs/test.log')]),"\n"; exit; }
    check_closeout($r->status()===500 && !str_contains($r->body(),'<form') && !is_file($root.'/storage/installed.lock') && $admins===1,'post-commit lock failure is not success');
    check_closeout($events===0,'failed install has no completed audit event');
    check_closeout($retry->status()===409 && $after===1,'different-email retry cannot recreate admin');
    check_closeout(hash_file('sha256',$root.'/config/app.php')!==$beforeConfig && (require $root.'/config/app.php')['site']['id']!=='','post-commit failure retains selected database and site identity');
    check_closeout((new AdminAuthenticator($p))->verifyCredentials('first@example.invalid','Isolation-only-123456','127.0.0.1')!==null,'existing admin password preserved');
    file_put_contents($root.'/config/app.php','<?php return '.var_export($settings,true).';');
    check_closeout($controller->store(new Request('POST','/install',[],$body))->status()===409 && (int)$p->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn()===1,'legacy partial target with no site identity blocks existing-admin recreation');
    $settings['site']['id']='existing-site-identity';
    file_put_contents($root.'/config/app.php','<?php return '.var_export($settings,true).';');
    $body['sqlite_path']='storage/database/other.sqlite';
    check_closeout($controller->show()->status()===409 && !str_contains($controller->show()->body(),'<form'),'configured site has no public install form');
    check_closeout($controller->store(new Request('POST','/install',[],$body))->status()===409 && !file_exists($root.'/storage/database/other.sqlite'),'cannot replace configured site with a new DB');
    file_put_contents($root.'/storage/installed.lock','fixture');
    check_closeout($controller->show()->status()===302 && $controller->store(new Request('POST','/install',[],$body))->status()===302,'completed installation rejects repetition');
    check_closeout((int)$p->query('SELECT COUNT(*) FROM cms_core_migrations')->fetchColumn()===49,'all frozen migrations applied');
} finally {
    // Remove only this random test directory; do not follow source symlinks.
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){ if($f->isLink() || !$f->isDir())unlink($f->getPathname()); else rmdir($f->getPathname()); }
    rmdir($root);
}
