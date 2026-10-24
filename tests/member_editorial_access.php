<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Auth\FrontUserAuthenticator;
use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;

$root = sys_get_temp_dir() . '/cms-member-access-' . bin2hex(random_bytes(6));
mkdir($root . '/content/uploads', 0700, true);
register_shutdown_function(static function () use ($root): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});
$database = ['dsn' => 'sqlite:' . $root . '/site.sqlite'];
if ($file = getenv('CMS_MYSQL_TEST_CONFIG')) {
    $config = require $file;
    if (($config['host'] ?? '') !== '127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/', $config['database'] ?? '')) throw new RuntimeException('Disposable isolated MySQL database required.');
    $database = ['dsn' => 'mysql:host=127.0.0.1;port=' . (int)$config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', 'username' => $config['user'], 'password' => $config['password']];
}
$pdo = new PDO($database['dsn'], $database['username'] ?? '', $database['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$migrationFiles = array_filter(
    glob(dirname(__DIR__) . '/system/migrations/*.php'),
    static fn (string $file): bool => !str_ends_with($file, '2026_09_07_000001_official_plugins_registry.php')
        && !str_ends_with($file, '2026_09_08_000007_official_plugins_registry_sync.php')
);
$migrations = array_map(static fn (string $file) => require $file, $migrationFiles);
(new MigrationRunner($pdo, $migrations))->run();
(new MigrationRunner($pdo, $migrations))->run();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};

$front = new FrontUserAuthenticator($pdo);
$firstMember = $front->register('operator@example.invalid', 'member-test-password', 'Operator');
$secondMember = $front->register('other@example.invalid', 'member-test-password', 'Other');
$adminAuth = new AdminAuthenticator($pdo);
$adminAuth->createAdmin('owner@example.invalid', 'owner-test-password', 'Owner');
$adminAuth->createAdmin('other-admin@example.invalid', 'admin-test-password', 'Other Admin');
$admin = $pdo->query('SELECT id FROM cms_admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO cms_member_roles(member_id,role_id,assigned_by,assigned_at) VALUES (?, 'ai_operator', ?, 'historical')")->execute([$firstMember,$admin]);
$pdo->exec("INSERT INTO cms_contents(content_type,title,slug,status,blocks_json,meta_json,owner_member_id,created_at,updated_at) VALUES ('article','Historical draft','historical-draft','draft','[]','{}',1,'historical','historical')");
$tables = ['cms_front_users','cms_admin_users','cms_member_roles','cms_contents','cms_roles','cms_role_capabilities','cms_core_migrations'];
$snapshot = static function () use ($pdo,$tables): array { $rows=[]; foreach($tables as $table) $rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(); return $rows; };
$before=$snapshot();
$settings = Settings::fromArray(['database'=>$database]);
$appClass=new ReflectionClass(Cms\Core\Bootstrap\Application::class);
$app=$appClass->newInstanceWithoutConstructor();
foreach(['rootPath'=>$root,'settings'=>$settings,'logger'=>new FileLogger($root.'/app.log'),'router'=>new Cms\Core\Routing\Router(),'installed'=>true,'pluginRuntime'=>new Cms\Core\Plugin\PluginRuntimeRegistry()] as $name=>$value) $appClass->getProperty($name)->setValue($app,$value);
foreach(['', '/cms'] as $base) {
    Cms\Core\Routing\BasePath::setCurrent($base);
    foreach(['anonymous','member','historical-operator','admin'] as $identity) {
        $_SESSION=[];
        if(in_array($identity,['member','historical-operator'],true)) $_SESSION['front_user']=['id'=>$firstMember,'email'=>'operator@example.invalid','display_name'=>'Operator'];
        if($identity==='admin') $_SESSION['admin_user']=['id'=>(int)$admin,'email'=>'owner@example.invalid','display_name'=>'Owner'];
        foreach(['/admin/member-access','/admin/member-access/assign','/workspace','/workspace/drafts/new','/workspace/drafts','/workspace/drafts/1','/workspace/seo'] as $path) {
            foreach(['GET','POST','HEAD','PATCH','DELETE'] as $method) {
                $response=$app->handle(new Request($method,$base.$path,[],['_csrf'=>CsrfToken::get(),'member_id'=>$secondMember,'enabled'=>'1','title'=>'forged','body'=>'overwrite']));
                $check($response->status()===410 && ($response->headers()['Cache-Control']??'')==='private, no-store', "$base $identity $method $path retired");
            }
        }
    }
}
Cms\Core\Routing\BasePath::setCurrent('');
$check($snapshot()===$before,'Historical identities, grants, drafts and migration records unchanged by all retired requests');
$check(!class_exists(Cms\Core\Auth\MemberAccessController::class) && !class_exists(Cms\Core\Auth\MemberRoleService::class) && !class_exists(Cms\Core\Content\MemberDraftService::class),'Retired services no longer autoload');
$caps=Cms\Core\Auth\CapabilityRegistry::all();
$check(!isset($caps['content.own_draft']) && !isset($caps['seo.read']) && isset($caps['content.publish'],$caps['users.manage']),'Retired capabilities removed; administrator capability registry retained');
$_SESSION=[];
$check($front->attempt('operator@example.invalid','member-test-password','127.0.0.1') && $front->user()['id']===$firstMember,'Normal member login retained with same identity');
$front->logout(); $check($front->user()===null,'Normal member logout retained');
$check($adminAuth->attempt('owner@example.invalid','owner-test-password','127.0.0.1') && (int)$adminAuth->user()['id']===(int)$admin,'Administrator login retained');
$check(!str_contains(Cms\Core\Support\View::page('Admin','test'),'/admin/member-access'),'Retired menu absent');
echo "Member retirement tests PASS\n";
