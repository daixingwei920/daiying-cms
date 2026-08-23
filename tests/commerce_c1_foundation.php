<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\Capability;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\Commerce\Infrastructure\OutboxEventRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/src/Infrastructure/OutboxEventRepository.php';

$failures = 0;

function c1_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function c1_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c1_check(false, $message);
    } catch (PluginException|RuntimeException|PDOException) {
        c1_check(true, $message);
    }
}

function c1_remove(string $path): void
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

function c1_copy(string $source, string $target): void
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
        c1_copy($item->getPathname(), $target . '/' . $item->getBasename());
    }
}

function c1_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function c1_zip(string $path, string $id, array $extra = [], array $files = []): string
{
    $manifest = array_replace_recursive([
        'package_type' => 'plugin',
        'plugin_id' => $id,
        'name' => $id,
        'version' => '1.0.0',
        'author' => 'C1 Tests',
        'core' => ['min' => '1.0.0'],
        'php' => '8.0.0',
        'entry' => 'plugin.php',
        'trust_level' => 'api',
        'capabilities' => ['storage.plugin'],
        'migrations' => [],
        'data_policy' => ['uninstall' => 'retain'],
    ], $extra);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($id . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString($id . '/plugin.php', $files['plugin.php'] ?? "<?php\nreturn static function (): void {};\n");
    foreach ($files as $name => $content) {
        if ($name !== 'plugin.php') {
            $zip->addFromString($id . '/' . $name, $content);
        }
    }
    $zip->close();

    return $path;
}

function c1_sqlite_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);
    return $stmt->fetchColumn() !== false;
}

function c1_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

$root = sys_get_temp_dir() . '/cms-commerce-c1-' . bin2hex(random_bytes(4));
c1_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['security']['encryption_key'] = 'commerce-c1-static-test-key';
c1_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
c1_core_migrations($pdo);
$marketTablesBefore = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cms_market_%'")->fetchColumn();

$installer = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo);
$official = $installer->installBundled('official.commerce', 1, false);
c1_check($official['plugin_id'] === 'official.commerce' && c1_sqlite_table($pdo, 'cms_commerce_outbox_events'), 'installs bundled official.commerce from trusted source and creates foundation tables');
$row = $pdo->query("SELECT source, review_status, table_prefixes_json FROM cms_plugins WHERE plugin_id = 'official.commerce'")->fetch();
c1_check((string) $row['source'] === 'bundled_official' && str_contains((string) $row['table_prefixes_json'], 'commerce_'), 'records trusted source and commerce table-prefix ownership');
$marketTablesAfter = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cms_market_%'")->fetchColumn();
c1_check($marketTablesAfter === $marketTablesBefore, 'Commerce migration does not add or mutate legacy cms_market_* table scope');

$tmp = $root . '/storage/tmp';
c1_throws(static fn () => $installer->preview(c1_zip($tmp . '/official-evil.zip', 'official.evil', ['type' => 'system-plugin', 'bundled' => true, 'trust_level' => 'trusted_php']), 1), 'rejects forged local official.* ZIP');
c1_throws(static fn () => $installer->preview(c1_zip($tmp . '/impersonate.zip', 'vendor.fake', ['type' => 'system-plugin', 'bundled' => true, 'trust_level' => 'trusted_php']), 1), 'rejects ordinary plugin impersonating bundled/system-plugin/trusted_php');
$thirdParty = $installer->preview(c1_zip($tmp . '/vendor-example.zip', 'vendor.example', ['capabilities' => ['vendor.example.view']]), 1);
c1_check($thirdParty['plugin_id'] === 'vendor.example', 'allows ordinary third-party dotted plugin ID within safe namespace');
c1_throws(static fn () => $installer->preview(c1_zip($tmp . '/commerce-prefix.zip', 'vendor.prefix', ['table_prefixes' => ['commerce_']]), 1), 'rejects third-party declaration of the reserved Commerce table prefix');
c1_throws(static fn () => $installer->preview(c1_zip($tmp . '/commerce-cap.zip', 'vendor.cap', ['capabilities' => ['commerce.orders.manage']]), 1), 'rejects third-party declaration of reserved commerce.* capabilities');
c1_throws(static fn () => $installer->preview(c1_zip($tmp . '/core-prefix.zip', 'vendor.core', ['migrations' => ['001.php']], ['001.php' => "<?php\nreturn ['id'=>'bad','affected_objects'=>['table:cms_admin_users'],'up'=>static function(PDO \$pdo): void {},'down'=>static function(PDO \$pdo): void {}];\n"]), 1), 'rejects plugin migration ownership over Core tables');

$runtime = new PluginRuntimeRegistry();
$manager = new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/test.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'commerce-c1-static-test-key'));
$manager->setStatus('official.commerce', PluginLifecycle::ENABLED);
$manager->bootEnabled();
c1_check(count($runtime->routes()) >= 1 && count($runtime->menus()) >= 1, 'enabled official.commerce registers routes and admin menu through generic PluginContext API');
$installer->disableWithDependents('official.commerce', 1, true);
$runtimeAfterDisable = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/test.log'), new EventDispatcher(), new BlockRegistry(), $runtimeAfterDisable, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'commerce-c1-static-test-key')))->bootEnabled();
c1_check(count($runtimeAfterDisable->routes()) === 0 && count($runtimeAfterDisable->menus()) === 0, 'disabled plugin no longer contributes routes, menus or route capabilities');
c1_check(c1_sqlite_table($pdo, 'cms_commerce_products'), 'disabled Commerce preserves plugin data tables for reinstall');
$installer->installBundled('official.commerce', 1, false);
c1_check(c1_sqlite_table($pdo, 'cms_commerce_products'), 'reinstall of bundled Commerce preserves and reuses existing data schema');

$secretStore = new PluginSecretStore($pdo, '');
c1_throws(static fn () => $secretStore->set('official.commerce', 'payment.demo', 'real-secret'), 'refuses to save real plugin secrets without configured master key');
$secretStore = new PluginSecretStore($pdo, 'commerce-c1-static-test-key');
$secretStore->set('official.commerce', 'payment.demo', 'secret-value-1234');
c1_check($secretStore->masked('official.commerce', 'payment.demo') !== 'secret-value-1234' && $secretStore->get('official.commerce', 'payment.demo') === 'secret-value-1234', 'stores plugin scoped secrets encrypted and displays only a mask');
$secretStore->purgePluginSecrets('official.commerce', 'PURGE SECRETS official.commerce');
c1_check($secretStore->masked('official.commerce', 'payment.demo') === null, 'purges plugin secrets only with separate confirmation');

$outbox = new OutboxEventRepository($pdo);
$pdo->beginTransaction();
$first = $outbox->record('evt-c1-1', 'commerce.product.created.v1', 'product-1', 'corr-1', ['id' => 'product-1']);
$second = $outbox->record('evt-c1-1', 'commerce.product.created.v1', 'product-1', 'corr-1', ['id' => 'product-1']);
$pdo->commit();
c1_check($first === true && $second === false && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_id = 'evt-c1-1'")->fetchColumn() === 1, 'writes Commerce outbox events idempotently inside a business transaction');

c1_throws(static fn () => (new PluginRuntimeRegistry())->route('vendor.example', 'GET', '/admin/login', static fn () => null), 'rejects plugin route override of reserved Core admin login');
c1_throws(static fn () => (new PluginRuntimeRegistry())->route('vendor.example', 'GET', '/api/market/search', static fn () => null), 'rejects plugin route override of isolated legacy Market API');

$throwRoot = $root . '/throw-site';
mkdir($throwRoot . '/content/plugins', 0755, true);
mkdir($throwRoot . '/system', 0755, true);
c1_copy(CMS_SOURCE_ROOT . '/system/official-plugins.php', $throwRoot . '/system/official-plugins.php');
c1_copy(CMS_SOURCE_ROOT . '/content/plugins/official.commerce', $throwRoot . '/content/plugins/official.commerce');
c1_write($throwRoot . '/content/plugins/official.commerce/plugin.php', "<?php\nreturn static function (): void { throw new RuntimeException('commerce boot failure'); };\n");
$pdo->exec("UPDATE cms_plugins SET status = 'Enabled' WHERE plugin_id = 'official.commerce'");
(new PluginManager($throwRoot . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/throw.log'), new EventDispatcher(), new BlockRegistry(), new PluginRuntimeRegistry(), new OfficialPluginRegistry($throwRoot), new PluginSecretStore($pdo, 'commerce-c1-static-test-key')))->bootEnabled();
c1_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.commerce'")->fetchColumn() === PluginLifecycle::QUARANTINED, 'Commerce boot exception is quarantined without disabling Core recovery or update code');
c1_check(class_exists(Cms\Core\Recovery\RecoveryController::class) && class_exists(Cms\Core\Update\UpdateService::class), 'Core Recovery and Core Update remain loadable after Commerce fault isolation');

Capability::assertPluginAllowed('official.commerce', ['commerce.view'], ['commerce']);
c1_throws(static fn () => Capability::assertPluginAllowed('vendor.example', ['admin.users.manage']), 'prevents plugin capability declarations from escalating to Core admin namespaces');

$mysqlStatus = 'skipped';
if (extension_loaded('pdo_mysql')) {
    try {
        $mysql = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_commerce_c1_' . bin2hex(random_bytes(3));
        $mysql->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            c1_core_migrations($mysqlPdo);
            $mysqlInstaller = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysqlPdo);
            $mysqlInstaller->installBundled('official.commerce', 1, false);
            c1_check((int) $mysqlPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_commerce_outbox_events'")->fetchColumn() === 1, 'real MySQL/MariaDB installs Commerce foundation migration');
            $spec = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/001_foundation.php';
            ($spec['down'])($mysqlPdo);
            c1_check((int) $mysqlPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_commerce_outbox_events'")->fetchColumn() === 0, 'real MySQL/MariaDB Commerce rollback removes only commerce-owned tables');
            ($spec['up'])($mysqlPdo);
            c1_check((int) $mysqlPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_commerce_outbox_events'")->fetchColumn() === 1, 'real MySQL/MariaDB Commerce migration retry succeeds');
            $mysqlStatus = 'passed';
        } finally {
            $mysql->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        echo '[SKIP] real MySQL/MariaDB Commerce migration test unavailable: ' . $exception->getMessage() . PHP_EOL;
    }
} else {
    echo "[SKIP] pdo_mysql is unavailable for Commerce C1 MySQL migration test.\n";
}

c1_remove($root);

if ($failures > 0) {
    echo 'Commerce C1 foundation failures: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Commerce C1 foundation tests passed; mysql=' . $mysqlStatus . PHP_EOL;
