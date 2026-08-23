<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Admin\AdminController;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Support\View;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function independence_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function independence_remove(string $path): void
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

function independence_copy(string $source, string $target): void
{
    if (is_file($source)) {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        copy($source, $target);
        return;
    }
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) {
            continue;
        }
        independence_copy($item->getPathname(), $target . '/' . $item->getBasename());
    }
}

function independence_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function independence_migrate(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function independence_boot(string $root, PDO $pdo): PluginRuntimeRegistry
{
    $runtime = new PluginRuntimeRegistry();
    $manager = new PluginManager(
        $root . '/content/plugins',
        $pdo,
        new FileLogger($root . '/storage/logs/plugin.log'),
        new EventDispatcher(),
        new BlockRegistry(),
        $runtime,
        new OfficialPluginRegistry($root),
        new PluginSecretStore($pdo, 'commerce-independence-test-key'),
    );
    $manager->syncDiscovered();
    $manager->bootEnabled();

    return $runtime;
}

$root = sys_get_temp_dir() . '/cms-commerce-independence-' . bin2hex(random_bytes(4));
independence_remove($root);
foreach (['config', 'content/plugins', 'storage/logs', 'storage/tmp', 'system'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
independence_copy(CMS_SOURCE_ROOT . '/content/plugins/official.commerce', $root . '/content/plugins/official.commerce');
independence_copy(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping', $root . '/content/plugins/official.cj-dropshipping');
independence_copy(CMS_SOURCE_ROOT . '/content/plugins/faq_block', $root . '/content/plugins/faq_block');
independence_copy(CMS_SOURCE_ROOT . '/system/official-plugins.php', $root . '/system/official-plugins.php');
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce-independence.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['security']['encryption_key'] = 'commerce-independence-test-key';
independence_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$pdo = ConnectionFactory::make(Settings::load($root));
independence_migrate($pdo);

$installer = new LocalPluginPackageInstaller($root, $pdo);
$installer->installBundled('official.commerce', 1, true);
$manager = new PluginManager($root . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), null, new OfficialPluginRegistry($root), new PluginSecretStore($pdo, 'commerce-independence-test-key'));
$manager->syncDiscovered();
$manager->setStatus('faq_block', PluginLifecycle::ENABLED);
$runtime = independence_boot($root, $pdo);
View::setAdminPluginMenus($runtime->menus());
$_SERVER['REQUEST_URI'] = '/admin/commerce/products';
$html = View::page('测试', '<h1>测试</h1>');
independence_check(str_contains($html, '商城') && str_contains($html, '商品') && str_contains($html, '库存') && !str_contains($html, 'Commerce 库存'), 'Commerce menu is supplied by the enabled plugin and rendered in Chinese');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$admin = new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/admin.log'), $root);
$_SERVER['REQUEST_URI'] = '/admin/plugins';
$pluginIndex = $admin->pluginIndex()->body();
$_SERVER['REQUEST_URI'] = '/admin/modules';
$moduleIndex = $admin->moduleIndex()->body();
independence_check(str_contains($pluginIndex, '<td>商城</td>') && str_contains($pluginIndex, '<td>CJ Dropshipping</td>') && !str_contains($pluginIndex, '<td>常见问题区块</td>'), 'Plugin management lists business plugins but not content modules');
independence_check(str_contains($moduleIndex, '<td>常见问题区块</td>') && !str_contains($moduleIndex, '<td>商城</td>') && !str_contains($moduleIndex, '<td>CJ Dropshipping</td>'), 'Module management lists content modules separately');

$installer->installBundled('official.cj-dropshipping', 1, true);
$runtime = independence_boot($root, $pdo);
View::setAdminPluginMenus($runtime->menus());
$_SERVER['REQUEST_URI'] = '/admin/cj/settings';
$html = View::page('测试', '<h1>测试</h1>');
independence_check(str_contains($html, 'CJ Dropshipping') && str_contains($html, '商品搜索') && str_contains($html, '模拟履约'), 'CJ menu is supplied by the enabled plugin');

$installer->disableWithDependents('official.commerce', 1, true);
$runtime = independence_boot($root, $pdo);
View::setAdminPluginMenus($runtime->menus());
$_SERVER['REQUEST_URI'] = '/admin';
$html = View::page('测试', '<h1>测试</h1>');
independence_check(!str_contains($html, '/admin/commerce/products') && !str_contains($html, '/admin/cj/settings'), 'Disabling Commerce removes Commerce and dependent CJ menus from Core sidebar');

$cjStatus = (string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn();
independence_check($cjStatus !== PluginLifecycle::QUARANTINED, 'CJ dependency downgrade does not quarantine or white-screen the CMS');

$installer->enable('official.commerce', 1);
$runtime = independence_boot($root, $pdo);
View::setAdminPluginMenus($runtime->menus());
$_SERVER['REQUEST_URI'] = '/admin/commerce/products';
$html = View::page('测试', '<h1>测试</h1>');
independence_check(str_contains($html, '/admin/commerce/products'), 'Re-enabling Commerce restores Commerce plugin menus');

$installer->uninstallCode('official.commerce', 1);
$tablesAfterUninstall = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cms_commerce_%'")->fetchColumn();
independence_check($tablesAfterUninstall > 0, 'Uninstalling Commerce code retains Commerce data tables for reinstall recovery');

independence_remove($root);

if ($failures > 0) {
    exit(1);
}

echo 'Commerce plugin independence tests passed.' . PHP_EOL;
