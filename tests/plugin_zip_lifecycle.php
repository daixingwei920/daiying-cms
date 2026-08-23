<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PackageScanner;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginRiskBoundaryPolicy;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function plugin_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function plugin_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function plugin_write(string $path, string $content): string
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
    return $path;
}

function plugin_assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
        plugin_check(false, $message);
    } catch (PluginException|RuntimeException) {
        plugin_check(true, $message);
    }
}

function plugin_controller_upload_preview(AdminController $controller, string $zipPath, string $csrf, string $returnTo = '/admin/plugins'): \Cms\Core\Http\Response
{
    $uploadCopy = sys_get_temp_dir() . '/cms-plugin-upload-' . bin2hex(random_bytes(4)) . '.zip';
    copy($zipPath, $uploadCopy);
    $_POST = ['_csrf' => $csrf, 'return_to' => $returnTo];
    $_FILES = [
        'plugin_zip' => [
            'name' => basename($zipPath),
            'type' => 'application/zip',
            'tmp_name' => $uploadCopy,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($uploadCopy),
        ],
    ];
    try {
        return $controller->pluginLocalPreview();
    } finally {
        $_POST = [];
        $_FILES = [];
        if (is_file($uploadCopy)) {
            unlink($uploadCopy);
        }
    }
}

function plugin_preview_token(string $html): string
{
    if (preg_match('/name="token" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('Preview token was not rendered.');
    }

    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function plugin_zip(string $path, string $id, array $manifestExtra = [], array $files = []): string
{
    $manifest = array_replace_recursive([
        'package_type' => 'plugin',
        'plugin_id' => $id,
        'name' => ucfirst($id),
        'version' => '1.0.0',
        'author' => 'Tests',
        'core' => ['min' => '1.0.0'],
        'php' => '8.0.0',
        'entry' => 'plugin.php',
        'trust_level' => 'api',
        'capabilities' => ['blocks.register', 'storage.plugin'],
        'required_plugins' => [],
        'optional_dependencies' => [],
        'migrations' => [],
        'data_policy' => ['uninstall' => 'retain'],
        'data_schema_version' => '1.0.0',
    ], $manifestExtra);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($id . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->addFromString($id . '/plugin.php', $files['plugin.php'] ?? "<?php\nreturn static function (\$context): void { \$context->registerBlock('demo_card', 'Demo Card'); };\n");
    foreach ($files as $name => $content) {
        if ($name === 'plugin.php') {
            continue;
        }
        $zip->addFromString($id . '/' . $name, $content);
    }
    $zip->close();
    return $path;
}

function plugin_bad_zip(string $path, array $entries): string
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return $path;
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-plugin-batch5-' . bin2hex(random_bytes(4));
plugin_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'content/plugins', 'content/themes', 'content/uploads', 'system/core', 'system/admin', 'system/recovery', 'system/migrations'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/plugin.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Plugin Site', 'url' => 'https://plugin.example.test', 'id' => 'plugin-site', 'secret' => 'plugin-secret'];
plugin_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
file_put_contents($root . '/storage/installed.lock', '{}');

$installer = new LocalPluginPackageInstaller($root, $pdo);
$tmp = $root . '/storage/tmp';

$plan = $installer->preview(plugin_zip($tmp . '/demo.zip', 'demo_plugin'), 1);
plugin_check($plan['plugin_id'] === 'demo_plugin' && $plan['source'] === 'local_unreviewed' && $plan['risk_label'] === '本地安装、未经官方市场审核' && $plan['scan']['status'] === 'passed', 'previews valid local plugin ZIP with unreviewed risk marker');
plugin_check(($plan['risk_boundary']['mode'] ?? '') === 'restricted_api' && ($plan['risk_boundary']['raw_database_access'] ?? true) === false && str_contains((string) ($plan['risk_boundary']['admin_notice'] ?? ''), '不承诺硬沙箱'), 'local plugin preview declares the restricted API versus trusted PHP risk boundary');
plugin_check((PluginRiskBoundaryPolicy::describe(['trust_level' => 'trusted_php'], 'bundled_official', 'official_trusted')['raw_database_access'] ?? false) === true, 'trusted PHP risk boundary is reserved for official reviewed bundled plugins');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/trusted-local.zip', 'trusted_local_plugin', ['trust_level' => 'trusted_php']), 1), 'local ZIP plugins cannot claim trusted PHP status');
$install = $installer->install((string) $plan['token'], 1, false);
$row = $pdo->query("SELECT * FROM cms_plugins WHERE plugin_id = 'demo_plugin'")->fetch();
plugin_check($install['status'] === 'Installed' && is_dir($root . '/content/plugins/demo_plugin') && (string) $row['status'] === PluginLifecycle::INSTALLED, 'installs valid plugin ZIP atomically and keeps it disabled by default');

$tamperPlan = $installer->preview(plugin_zip($tmp . '/tamper.zip', 'tamper_plugin'), 1);
$tamperStmt = $pdo->prepare('SELECT staging_dir FROM cms_plugin_install_previews WHERE token = :token LIMIT 1');
$tamperStmt->execute([':token' => (string) $tamperPlan['token']]);
$tamperStaging = (string) $tamperStmt->fetchColumn();
$tamperStmt->closeCursor();
plugin_write($tamperStaging . '/tamper_plugin/plugin.php', "<?php\nreturn static function (): void { eval('tampered'); };\n");
plugin_assert_throws(static fn () => $installer->install((string) $tamperPlan['token'], 1, false), 'rejects plugin install when preview staging files changed before install');
plugin_check(!is_dir($root . '/content/plugins/tamper_plugin'), 'leaves no installed plugin after tampered preview staging is rejected');

plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/same.zip', 'demo_plugin'), 1), 'blocks same plugin ID overwrite and requires upgrade flow');
$pdo->prepare("INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at,source,review_status,dependencies_json) VALUES ('dep_plugin','Dep','1.2.0','Tests','Enabled','api','[]','now','now','local_unreviewed','unreviewed','[]')")->execute();
$depPlan = $installer->preview(plugin_zip($tmp . '/needs.zip', 'needs_dep', ['required_plugins' => [['plugin_id' => 'dep_plugin', 'min_version' => '1.0.0', 'max_version' => '2.0.0']]]), 1);
$installer->install((string) $depPlan['token'], 1, true);
plugin_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'needs_dep'")->fetchColumn() === PluginLifecycle::ENABLED, 'installs and enables plugin with satisfied dependency version constraints');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/missing-dep.zip', 'missing_dep', ['required_plugins' => [['plugin_id' => 'none', 'min_version' => '1.0.0']]]), 1), 'blocks missing dependencies');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/bad-dep-version.zip', 'bad_dep_version', ['required_plugins' => [['plugin_id' => 'dep_plugin', 'min_version' => '9.0.0']]]), 1), 'blocks dependency version mismatches');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/cycle.zip', 'cycle_dep', ['required_plugins' => [['plugin_id' => 'cycle_dep']]]), 1), 'detects circular dependencies');

plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/traversal.zip', ['evil/../plugin.json' => '{}']), 1), 'rejects ZIP path traversal');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/absolute.zip', ['/evil/plugin.json' => '{}']), 1), 'rejects absolute ZIP paths');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/backslash.zip', ['evil\\..\\plugin.json' => '{}']), 1), 'rejects Windows backslash traversal');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/corewrite.zip', ['system/core/Plugin/pwn.php' => '<?php']), 1), 'rejects packages writing Core paths');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/multi.zip', ['one/plugin.json' => '{}', 'two/plugin.json' => '{}']), 1), 'rejects multiple plugin roots');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/badmanifest.zip', ['bad/plugin.php' => '<?php']), 1), 'rejects missing manifests');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/reserved.zip', 'core'), 1), 'rejects reserved plugin IDs');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/core-new.zip', 'core_new_req', ['core' => ['min' => '9.0.0']]), 1), 'rejects incompatible CMS core versions');
plugin_assert_throws(static fn () => $installer->preview(plugin_zip($tmp . '/php-new.zip', 'php_new_req', ['php' => '99.0.0']), 1), 'rejects incompatible PHP versions');
$tooMany = [];
for ($i = 0; $i < 505; $i++) {
    $tooMany['many/file' . $i . '.txt'] = 'x';
}
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/toomany.zip', $tooMany), 1), 'rejects ZIPs with too many files');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/deep.zip', ['a/b/c/d/e/f/g/h/i/file.txt' => 'x']), 1), 'rejects overly deep ZIP directories');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/case.zip', ['case_plugin/plugin.json' => '{}', 'CASE_PLUGIN/plugin.json' => '{}']), 1), 'rejects case or normalized filename collisions');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/nested.zip', ['nested_plugin/plugin.json' => '{}', 'nested_plugin/a.zip' => 'PK']), 1), 'blocks nested ZIP packages');

if (method_exists(ZipArchive::class, 'setExternalAttributesName')) {
    $symlink = $tmp . '/symlink.zip';
    $zip = new ZipArchive();
    $zip->open($symlink, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('link_plugin/plugin.json', '{}');
    $zip->addFromString('link_plugin/link', 'target');
    $zip->setExternalAttributesName('link_plugin/link', ZipArchive::OPSYS_UNIX, 0120000 << 16);
    $zip->close();
    $symlinkFindings = (new PackageScanner())->scanZip($symlink);
    plugin_check(count(array_filter($symlinkFindings, static fn (array $finding): bool => ($finding['code'] ?? '') === 'link_or_special_file' && ($finding['severity'] ?? '') === 'critical')) >= 1, 'plugin package scanner reports symlink entries before install');
    plugin_assert_throws(static fn () => $installer->preview($symlink, 1), 'rejects symlink entries in ZIPs');
} else {
    plugin_check(true, 'skips symlink ZIP check when ZipArchive external attributes API is unavailable');
}

$review = $installer->preview(plugin_zip($tmp . '/review.zip', 'review_plugin', [], ['plugin.php' => "<?php\nreturn static function(): void { eval(''); };\n"]), 1);
plugin_check($review['scan']['status'] === 'needs_review', 'marks risky local plugin as needs_review');
plugin_assert_throws(static fn () => $installer->install((string) $review['token'], 1, false, false), 'requires explicit confirmation for needs_review packages');
$installer->install((string) $review['token'], 1, false, true);
plugin_check(is_dir($root . '/content/plugins/review_plugin'), 'allows needs_review package after explicit administrator confirmation');
plugin_assert_throws(static fn () => $installer->preview(plugin_bad_zip($tmp . '/official.zip', ['market-package.json' => '{}', 'official/plugin.json' => '{}']), 1), 'keeps official signed package verification on the official market flow');

$failPlan = $installer->preview(plugin_zip($tmp . '/fail.zip', 'fail_plugin', ['migrations' => ['install.php']], ['install.php' => "<?php\nreturn [\n    'id' => 'install',\n    'affected_objects' => ['table:plugin_fail_plugin_items'],\n    'up' => static function (PDO \$pdo): void { throw new RuntimeException('fail'); },\n    'down' => static function (PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS plugin_fail_plugin_items'); },\n];\n"]), 1);
plugin_assert_throws(static fn () => $installer->install((string) $failPlan['token'], 1), 'rolls back atomically when install migrations fail');
plugin_check(!is_dir($root . '/content/plugins/fail_plugin') && $pdo->query("SELECT COUNT(*) FROM cms_plugins WHERE plugin_id = 'fail_plugin'")->fetchColumn() == 0, 'leaves no half-installed plugin after failure');
plugin_write($root . '/storage/plugin-installs/install.lock', 'locked');
plugin_assert_throws(static fn () => $installer->install((string) $installer->preview(plugin_zip($tmp . '/locked.zip', 'locked_plugin'), 1)['token'], 1), 'prevents concurrent installs with install lock');
unlink($root . '/storage/plugin-installs/install.lock');

$pdo->exec("INSERT INTO cms_plugin_tasks (plugin_id, task_name, status, payload_json, created_at, updated_at) VALUES ('dep_plugin','job','Queued','{}','now','now')");
$disabled = $installer->disableWithDependents('dep_plugin', 1, true);
plugin_check(in_array('needs_dep', $disabled['paused_dependents'], true) && (string) $pdo->query("SELECT status FROM cms_plugin_tasks WHERE plugin_id = 'dep_plugin'")->fetchColumn() === 'Paused', 'disables dependency plugin with linked dependent pause and task pause');
plugin_assert_throws(static fn () => $installer->enable('needs_dep', 1), 'blocks enabling plugin while dependency is disabled');
$installer->enable('dep_plugin', 1);
plugin_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'needs_dep'")->fetchColumn() === PluginLifecycle::DISABLED, 'does not automatically re-enable dependents after dependency is restored');

$pdo->prepare("INSERT INTO cms_plugin_data (plugin_id,data_type,data_key,payload_json,created_at,updated_at) VALUES ('demo_plugin','setting','color','{\"value\":\"blue\"}','now','now')")->execute();
$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$contentId = $repo->create('article', 'Plugin Block', 'plugin-block', [['type' => 'demo_card', 'plugin_id' => 'demo_plugin', 'data' => ['text' => 'Keep me']]], 'published');
$installer->uninstallCode('demo_plugin', 1);
$demo = $pdo->query("SELECT status, dormant_data_json FROM cms_plugins WHERE plugin_id = 'demo_plugin'")->fetch();
$content = $repo->find($contentId);
plugin_check((string) $demo['status'] === PluginLifecycle::DORMANT && !is_dir($root . '/content/plugins/demo_plugin') && $pdo->query("SELECT COUNT(*) FROM cms_plugin_data WHERE plugin_id = 'demo_plugin'")->fetchColumn() == 1, 'uninstalls code while retaining plugin data');
plugin_check(($content['blocks'][0]['type'] ?? '') === 'missing-extension' && ($content['blocks'][0]['original']['data']['text'] ?? '') === 'Keep me', 'preserves plugin blocks as missing-extension after code removal');
$rePlan = $installer->preview(plugin_zip($tmp . '/demo-reinstall.zip', 'demo_plugin', ['version' => '1.1.0', 'data_schema_version' => '1.1.0']), 1);
$installer->install((string) $rePlan['token'], 1);
plugin_check(is_dir($root . '/content/plugins/demo_plugin') && $pdo->query("SELECT COUNT(*) FROM cms_plugin_data WHERE plugin_id = 'demo_plugin'")->fetchColumn() == 1, 'reinstalls same plugin ID and restores retained settings/data');
$installer->uninstallCode('demo_plugin', 1);
plugin_assert_throws(static fn () => $installer->purge('demo_plugin', 1, 'wrong'), 'requires second confirmation for permanent purge');
$installer->purge('demo_plugin', 1, 'PURGE demo_plugin');
plugin_check($pdo->query("SELECT COUNT(*) FROM cms_plugins WHERE plugin_id = 'demo_plugin'")->fetchColumn() == 0 && $pdo->query("SELECT COUNT(*) FROM cms_plugin_data WHERE plugin_id = 'demo_plugin'")->fetchColumn() == 0, 'permanently purges plugin only after confirmation');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$csrf = CsrfToken::get();
$controller = new AdminController($settings, new FileLogger($root . '/storage/logs/app.log'), $root);
$beforeStatus = (string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'dep_plugin'")->fetchColumn();
$getStatusChange = $controller->pluginStatus(new Request('GET', '/admin/plugins/status', [], [
    '_csrf' => $csrf,
    'plugin_id' => 'dep_plugin',
    'status' => PluginLifecycle::DISABLED,
    'return_to' => '/admin/plugins',
]));
$afterStatus = (string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'dep_plugin'")->fetchColumn();
$getHeaders = $getStatusChange->headers();
plugin_check(
    $getStatusChange->status() === 405
    && ($getHeaders['Allow'] ?? '') === 'POST'
    && ($getHeaders['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($getStatusChange->body(), '插件状态变更必须通过 POST 请求提交')
    && $afterStatus === $beforeStatus,
    'plugin status changes reject non-POST requests without changing plugin state'
);
$uiZip = plugin_zip($tmp . '/ui-upload.zip', 'ui_upload_plugin');
$previewResponse = plugin_controller_upload_preview($controller, $uiZip, $csrf);
plugin_check($previewResponse->status() === 200 && str_contains($previewResponse->body(), '本地插件安装预检') && str_contains($previewResponse->body(), 'ui_upload_plugin') && str_contains($previewResponse->body(), '运行边界') && str_contains($previewResponse->body(), '受限 API 插件') && str_contains($previewResponse->body(), '确认安装'), 'admin ZIP upload UI previews a valid local plugin with explicit risk boundary without 500');
$uiInstall = $controller->pluginLocalInstall(new Request('POST', '/admin/plugins/local-install', [], [
    '_csrf' => $csrf,
    'token' => plugin_preview_token($previewResponse->body()),
    'return_to' => '/admin/plugins',
]));
plugin_check($uiInstall->status() === 302 && is_dir($root . '/content/plugins/ui_upload_plugin') && $pdo->query("SELECT COUNT(*) FROM cms_plugins WHERE plugin_id = 'ui_upload_plugin'")->fetchColumn() == 1, 'admin ZIP upload UI confirms local plugin installation');
foreach (['official-commerce-v1.0.0-rc1.zip', 'official-cj-dropshipping-v1.0.0-rc1.zip'] as $artifact) {
    $artifactPath = CMS_SOURCE_ROOT . '/' . $artifact;
    if (!is_file($artifactPath)) {
        plugin_check(true, 'skips ' . $artifact . ' admin upload preview because artifact is not present');
        continue;
    }
    $officialPreview = plugin_controller_upload_preview($controller, $artifactPath, $csrf);
    plugin_check($officialPreview->status() === 400 && str_contains($officialPreview->body(), '插件安装预检失败') && !str_contains($officialPreview->body(), 'Internal Server Error'), $artifact . ' reaches admin upload preflight safely without 500');
}
$badCsrf = $controller->pluginLocalInstall(new Request('POST', '/admin/plugins/local-install', [], ['_csrf' => 'bad', 'token' => 'x']));
unset($_SESSION['admin_user']);
$noAuth = $controller->pluginUninstall(new Request('POST', '/admin/plugins/uninstall', [], ['_csrf' => $csrf, 'plugin_id' => 'dep_plugin']));
plugin_check($badCsrf->status() === 403 && $noAuth->status() === 302, 'enforces CSRF and administrator permission for plugin ZIP lifecycle actions');

plugin_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " plugin ZIP lifecycle checks failed.\n");
    exit(1);
}

echo "Plugin ZIP lifecycle tests passed.\n";
