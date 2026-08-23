<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function recovery_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function recovery_throws(callable $callback, string $message): void
{
    try {
        $callback();
        recovery_check(false, $message);
    } catch (PluginException|RuntimeException) {
        recovery_check(true, $message);
    }
}

function recovery_remove(string $path): void
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

function recovery_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function recovery_zip(string $path, string $id, array $manifestExtra, array $files): string
{
    $manifest = array_replace_recursive([
        'package_type' => 'plugin',
        'plugin_id' => $id,
        'name' => ucfirst($id),
        'version' => '1.0.0',
        'author' => 'Recovery Tests',
        'core' => ['min' => '1.0.0'],
        'php' => '8.0.0',
        'entry' => 'plugin.php',
        'trust_level' => 'api',
        'capabilities' => ['storage.plugin'],
        'required_plugins' => [],
        'optional_dependencies' => [],
        'migrations' => [],
        'data_policy' => ['uninstall' => 'retain'],
        'data_schema_version' => '1.0.0',
    ], $manifestExtra);
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

function migration_file(string $id, string $table, string $upTail = '', string $downTail = ''): string
{
    return "<?php\nreturn [\n" .
        "    'id' => '" . $id . "',\n" .
        "    'affected_objects' => ['table:" . $table . "'],\n" .
        "    'up' => static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE IF NOT EXISTS " . $table . " (id INTEGER PRIMARY KEY)'); " . $upTail . " },\n" .
        "    'down' => static function (PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS " . $table . "'); " . $downTail . " },\n" .
        "];\n";
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute([':name' => $table]);
    return $stmt->fetchColumn() !== false;
}

$root = sys_get_temp_dir() . '/cms-plugin-batch5a-' . bin2hex(random_bytes(4));
recovery_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/plugins', 'content/themes', 'content/uploads'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/plugin.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Recovery Site', 'url' => 'https://recovery.example.test', 'id' => 'recovery-site', 'secret' => 'recovery-secret'];
recovery_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
recovery_write($root . '/storage/installed.lock', '{}');

$installer = new LocalPluginPackageInstaller($root, $pdo);
$tmp = $root . '/storage/tmp';

$halfPlan = $installer->preview(recovery_zip($tmp . '/half.zip', 'half_fail', ['migrations' => ['001.php']], ['001.php' => migration_file('001_create', 'plugin_half_fail_items', "throw new RuntimeException('boom after ddl');")]), 1);
recovery_throws(static fn () => $installer->install((string) $halfPlan['token'], 1, false, true), 'captures current migration failure and starts rollback');
recovery_check(!table_exists($pdo, 'plugin_half_fail_items') && !is_dir($root . '/content/plugins/half_fail'), 'rolls back MySQL-style nontransactional DDL with explicit down before code cleanup');
recovery_check((string) $pdo->query("SELECT status FROM cms_plugin_migrations WHERE plugin_id = 'half_fail' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'rolled_back', 'retains rolled_back migration evidence after recovered install failure');

$successPlan = $installer->preview(recovery_zip($tmp . '/half-success.zip', 'half_fail', ['migrations' => ['001.php']], ['001.php' => migration_file('001_create', 'plugin_half_fail_items')]), 1);
$installer->install((string) $successPlan['token'], 1, false, true);
recovery_check(table_exists($pdo, 'plugin_half_fail_items') && (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'half_fail' AND status = 'applied'")->fetchColumn() === 1, 'retries install successfully without duplicate migration application');

$reversePlan = $installer->preview(recovery_zip($tmp . '/reverse.zip', 'reverse_fail', ['migrations' => ['001.php', '002.php']], [
    '001.php' => migration_file('001_first', 'plugin_reverse_fail_first'),
    '002.php' => migration_file('002_second', 'plugin_reverse_fail_second', "throw new RuntimeException('second failed');"),
]), 1);
recovery_throws(static fn () => $installer->install((string) $reversePlan['token'], 1, false, true), 'stops after failed migration and rolls back completed migrations');
recovery_check(!table_exists($pdo, 'plugin_reverse_fail_first') && !table_exists($pdo, 'plugin_reverse_fail_second'), 'rolls back completed migrations in reverse order');

$rbPlan = $installer->preview(recovery_zip($tmp . '/rollback-fail.zip', 'rollback_fail', ['migrations' => ['001.php']], ['001.php' => migration_file('001_bad_down', 'plugin_rollback_fail_items', "throw new RuntimeException('up failed');", "throw new RuntimeException('down failed');")]), 1);
recovery_throws(static fn () => $installer->install((string) $rbPlan['token'], 1, false, true), 'surfaces rollback failure as recoverable install failure');
$rbRow = $pdo->query("SELECT status, last_error FROM cms_plugins WHERE plugin_id = 'rollback_fail'")->fetch();
recovery_check(is_dir($root . '/content/plugins/rollback_fail') && (string) $rbRow['status'] === PluginLifecycle::INSTALL_FAILED_RECOVERABLE, 'keeps plugin code and registration evidence after rollback failure');
recovery_check((string) $pdo->query("SELECT status FROM cms_plugin_migrations WHERE plugin_id = 'rollback_fail' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'rollback_failed', 'records rollback_failed migration status');
recovery_throws(static fn () => $installer->enable('rollback_fail', 1), 'blocks enabling recoverable failed plugin');
$recoverOne = $installer->recoverFailedInstall('rollback_fail', 1);
$recoverTwo = $installer->recoverFailedInstall('rollback_fail', 1);
recovery_check($recoverOne['status'] === 'failed_recoverable' && $recoverTwo['status'] === 'failed_recoverable', 'repeated recovery attempts are idempotent and retain evidence when rollback keeps failing');

$checksumPlan = $installer->preview(recovery_zip($tmp . '/checksum.zip', 'checksum_plugin', ['migrations' => ['001.php']], ['001.php' => migration_file('001_checksum', 'plugin_checksum_plugin_items')]), 1);
$installer->install((string) $checksumPlan['token'], 1, false, true);
recovery_write($root . '/content/plugins/checksum_plugin/001.php', migration_file('001_checksum', 'plugin_checksum_plugin_items', '$pdo->exec("SELECT 1");'));
$ref = new ReflectionMethod(LocalPluginPackageInstaller::class, 'runMigrations');
recovery_throws(static fn () => $ref->invoke($installer, $root . '/content/plugins/checksum_plugin', ['plugin_id' => 'checksum_plugin', 'version' => '1.0.0', 'migrations' => ['001.php']]), 'rejects checksum changes for already applied migrations');

recovery_throws(static fn () => $installer->preview(recovery_zip($tmp . '/core-object.zip', 'core_object', ['migrations' => ['001.php']], ['001.php' => migration_file('001_core', 'cms_plugins')]), 1), 'rejects migrations that declare Core object modification');
recovery_throws(static fn () => $installer->preview(recovery_zip($tmp . '/irreversible.zip', 'irreversible_plugin', ['migrations' => ['001.php']], ['001.php' => "<?php\nreturn static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE plugin_irreversible_plugin_items (id INTEGER)'); };\n"]), 1), 'rejects irreversible migrations when no database restore point exists');

$pdo->exec("INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at,source,review_status,dependencies_json) VALUES ('task_plugin','Task','1.0.0','Tests','Enabled','api','[]','now','now','local_unreviewed','unreviewed','[]')");
$pdo->exec("INSERT INTO cms_plugin_tasks (plugin_id, task_name, status, payload_json, created_at, updated_at) VALUES ('task_plugin','running','Running','{}','now','now')");
$taskId = (int) $pdo->lastInsertId();
$installer->disableWithDependents('task_plugin', 1, true);
$task = $pdo->query("SELECT status, cancel_requested_at FROM cms_plugin_tasks WHERE id = " . $taskId)->fetch();
recovery_check((string) $task['status'] === 'CancelRequested' && (string) $task['cancel_requested_at'] !== '', 'marks Running tasks cancel_requested instead of pretending they are paused');
recovery_check($installer->workerCheckpoint('task_plugin', $taskId) === 'cancelled' && (string) $pdo->query("SELECT status FROM cms_plugin_tasks WHERE id = " . $taskId)->fetchColumn() === 'Cancelled', 'worker safe checkpoint exits and marks task cancelled');

$pdo->exec("INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at,source,review_status,dependencies_json) VALUES ('purge_plugin','Purge','1.0.0','Tests','Dormant','api','[]','now','now','local_unreviewed','unreviewed','[]')");
$pdo->exec("INSERT INTO cms_plugin_data (plugin_id,data_type,data_key,payload_json,created_at,updated_at) VALUES ('purge_plugin','setting','color','{}','now','now')");
$pdo->exec("INSERT INTO cms_plugin_tasks (plugin_id, task_name, status, payload_json, created_at, updated_at) VALUES ('purge_plugin','queued','Queued','{}','now','now')");
$pdo->exec("INSERT INTO cms_plugin_migrations (plugin_id, plugin_version, migration_id, checksum, status, affected_objects_json, created_at, updated_at) VALUES ('purge_plugin','1.0.0','001','abc','applied','[\"table:plugin_purge_plugin_items\"]','now','now')");
$pdo->exec("INSERT INTO cms_contents (content_type,title,slug,status,blocks_json,meta_json,created_at,updated_at,published_at) VALUES ('article','Purge','purge','published','[{\"type\":\"x\",\"plugin_id\":\"purge_plugin\",\"data\":{\"keep\":true}}]','{}','now','now','now')");
$preview = $installer->purgePreview('purge_plugin');
recovery_check($preview['plugin_data_records'] === 1 && $preview['plugin_tasks'] === 1 && $preview['plugin_settings'] === 1 && $preview['plugin_block_content_count'] === 1 && $preview['raw_block_data_retained_by_default'] === true, 'permanent purge preview shows data, tasks, settings, migrations and missing-extension impact');
$installer->purge('purge_plugin', 1, 'PURGE purge_plugin');
recovery_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'plugin.purged'")->fetchColumn() > 0, 'permanent purge writes audit log');
recovery_check((int) $pdo->query("SELECT COUNT(*) FROM cms_contents WHERE blocks_json LIKE '%purge_plugin%'")->fetchColumn() === 1, 'permanent purge keeps original block data by default');

recovery_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " plugin migration recovery checks failed.\n");
    exit(1);
}

echo "Plugin migration recovery tests passed.\n";
