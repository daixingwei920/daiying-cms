<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginLifecycle;
use Official\CjDropshipping\Repository\CjRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;
function delivery_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function delivery_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}
function delivery_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $relative = str_replace('\\', '/', substr((string) $item->getPathname(), strlen($source) + 1));
        if (str_starts_with($relative, '.git/') || str_starts_with($relative, 'storage/') || str_starts_with($relative, 'content/uploads/') || $relative === 'official-cj-dropshipping-v1.0.0-rc1.zip') {
            continue;
        }
        $dest = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
            continue;
        }
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }
        copy((string) $item->getPathname(), $dest);
    }
}
function delivery_core(PDO $pdo, string $root): void
{
    $migrations = [];
    foreach (glob($root . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}
function delivery_table(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}
function delivery_seed_site_from_zip(string $name, array $database): array
{
    $zipPath = CMS_SOURCE_ROOT . '/official-cj-dropshipping-v1.0.0-rc1.zip';
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    delivery_remove($root);
    delivery_copy(CMS_SOURCE_ROOT, $root);
    delivery_remove($root . '/content/plugins/official.cj-dropshipping');
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $zip->extractTo($root . '/content/plugins');
    $zip->close();
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            mkdir($root . '/' . $dir, 0755, true);
        }
    }
    $config = require $root . '/config/app.php';
    $config['database'] = $database;
    $config['security']['encryption_key'] = 'cj-delivery-static-key';
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    delivery_core($pdo, $root);
    return [$root, $pdo];
}
function delivery_validate_manifest(string $zipPath): void
{
    $sidecar = trim((string) file_get_contents($zipPath . '.sha256'));
    $expected = strtok($sidecar, ' ');
    delivery_check($expected === hash_file('sha256', $zipPath), 'ZIP SHA-256 matches sidecar');
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $manifestJson = (string) $zip->getFromName('official.cj-dropshipping/package-manifest.json');
    $manifest = json_decode($manifestJson, true);
    delivery_check(is_array($manifest) && ($manifest['plugin_id'] ?? '') === 'official.cj-dropshipping' && ($manifest['version'] ?? '') === '1.0.0-rc1', 'package manifest is present inside ZIP');
    foreach (($manifest['files'] ?? []) as $file) {
        $body = $zip->getFromName((string) $file['path']);
        delivery_check($body !== false && strlen((string) $body) === (int) $file['size'] && hash('sha256', (string) $body) === (string) $file['sha256'], 'package manifest matches ' . (string) $file['path']);
    }
    $zip->close();
}
function delivery_lifecycle(PDO $pdo, string $root, string $label): void
{
    $installer = new LocalPluginPackageInstaller($root, $pdo);
    $installer->installBundled('official.commerce', 1, true);
    $installer->installBundled('official.cj-dropshipping', 1, true);
    $row = $pdo->query("SELECT plugin_id, version, status, capabilities_json, dependencies_json FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetch();
    delivery_check(is_array($row) && (string) $row['version'] === '1.0.0-rc1' && (string) $row['status'] === PluginLifecycle::ENABLED, $label . ' installs final ZIP CJ plugin as enabled rc1');
    delivery_check(str_contains((string) $row['capabilities_json'], 'cj.data.purge') && str_contains((string) $row['dependencies_json'], 'official.commerce'), $label . ' validates CJ permissions and Commerce dependency');
    delivery_check(delivery_table($pdo, 'cms_cj_connections') && delivery_table($pdo, 'cms_cj_fulfillment_links') && delivery_table($pdo, 'cms_cj_webhook_events'), $label . ' executes CJ migrations from final ZIP');
    $applied = (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'official.cj-dropshipping' AND status = 'applied'")->fetchColumn();
    $installer->enable('official.cj-dropshipping', 1);
    delivery_check((int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'official.cj-dropshipping' AND status = 'applied'")->fetchColumn() === $applied, $label . ' repeated enable does not repeat migrations');
    $installer->disableWithDependents('official.cj-dropshipping', 1, true);
    delivery_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::DISABLED && delivery_table($pdo, 'cms_cj_connections'), $label . ' disable preserves CJ data');
    $installer->enable('official.cj-dropshipping', 1);
    delivery_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED, $label . ' re-enable restores CJ plugin from ZIP files');
    $installer->uninstallCode('official.cj-dropshipping', 1);
    delivery_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::DORMANT && delivery_table($pdo, 'cms_cj_connections'), $label . ' ordinary code uninstall retains CJ data');
    $zip = new ZipArchive();
    $zip->open(CMS_SOURCE_ROOT . '/official-cj-dropshipping-v1.0.0-rc1.zip');
    $zip->extractTo($root . '/content/plugins');
    $zip->close();
    $installer->installBundled('official.cj-dropshipping', 1, true);
    delivery_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED, $label . ' reinstall from final ZIP restores dormant plugin');
    $now = gmdate('c');
    $pdo->prepare("INSERT INTO cms_cj_tasks (task_type, resource_type, resource_id, priority, status, idempotency_key, created_at, updated_at) VALUES ('delivery.purge.proof', 'delivery', 'proof', 100, 'queued', :key, :created, :updated)")
        ->execute([':key' => 'delivery-purge-' . bin2hex(random_bytes(4)), ':created' => $now, ':updated' => $now]);
    if (!class_exists(CjRepository::class)) {
        require_once $root . '/content/plugins/official.cj-dropshipping/src/Repository/CjRepository.php';
    }
    (new CjRepository($pdo))->purgeOwnData('PURGE official.cj-dropshipping');
    $installer->purge('official.cj-dropshipping', 1, 'PURGE official.cj-dropshipping');
    delivery_check((int) $pdo->query('SELECT COUNT(*) FROM cms_cj_tasks')->fetchColumn() === 0 && delivery_table($pdo, 'cms_commerce_products'), $label . ' permanent purge removes CJ data only and preserves Commerce tables');
}

$zipPath = CMS_SOURCE_ROOT . '/official-cj-dropshipping-v1.0.0-rc1.zip';
delivery_validate_manifest($zipPath);

[$sqliteRoot, $sqlite] = delivery_seed_site_from_zip('cms-cj-delivery-sqlite', ['dsn' => 'sqlite:' . sys_get_temp_dir() . '/cj-delivery-' . bin2hex(random_bytes(4)) . '.sqlite', 'username' => '', 'password' => '', 'options' => []]);
delivery_lifecycle($sqlite, $sqliteRoot, 'SQLite final ZIP');
delivery_remove($sqliteRoot);

if (!extension_loaded('pdo_mysql')) {
    delivery_check(false, 'pdo_mysql extension is required for final ZIP MySQL validation');
} else {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_delivery_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            [$mysqlRoot, $mysql] = delivery_seed_site_from_zip('cms-cj-delivery-mysql', ['dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'username' => 'root', 'password' => '', 'options' => []]);
            delivery_lifecycle($mysql, $mysqlRoot, 'MySQL final ZIP');
            delivery_remove($mysqlRoot);
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        delivery_check(false, 'MySQL final ZIP validation failed: ' . $exception->getMessage());
    }
}

if ($failures > 0) {
    echo '[RESULT] CJ V1 release delivery checks failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ V1 release delivery checks passed.' . PHP_EOL;
