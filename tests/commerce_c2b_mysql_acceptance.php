<?php

declare(strict_types=1);

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Install\InstallController;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\PluginMediaReferenceIndex;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Media\CommerceMediaReferenceProvider;
use Official\Commerce\Repository\CatalogRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.php';

$failures = 0;
$mysqlInfo = [];

function c2b_mysql_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function c2b_mysql_fail(string $message): never
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}

function c2b_mysql_remove(string $path): void
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

function c2b_mysql_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/') || str_starts_with($relative, '.git/') || str_ends_with($relative, '.zip')) {
            continue;
        }
        $dest = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
        } else {
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            copy((string) $item->getPathname(), $dest);
        }
    }
}

/** @param list<string> $args @param array<string,string> $env */
function c2b_mysql_process(array $args, array $env = [], string $stdin = ''): string
{
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $baseEnv = [];
    foreach ($_SERVER as $key => $value) {
        if (is_scalar($value)) {
            $baseEnv[(string) $key] = (string) $value;
        }
    }
    $process = proc_open($args, $descriptor, $pipes, CMS_SOURCE_ROOT, array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start MySQL client.');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException(trim((string) $stderr));
    }
    return (string) $stdout;
}

function c2b_mysql_quote(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function c2b_mysql_identifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

/** @return list<object> */
function c2b_core_migrations_excluding(string $exclude = ''): array
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        if ($exclude !== '' && str_contains($file, $exclude)) {
            continue;
        }
        $migrations[] = require $file;
    }
    return $migrations;
}

function c2b_plugin_zip(string $path, string $id, string $migrationBody): string
{
    $manifest = [
        'package_type' => 'plugin',
        'plugin_id' => $id,
        'name' => 'C2B Failure Probe',
        'version' => '1.0.0',
        'author' => 'Tests',
        'core' => ['min' => '1.2.0-rc1'],
        'php' => '8.3.0',
        'entry' => 'plugin.php',
        'capabilities' => ['vendor.failc2b.view'],
        'migrations' => ['migrations/001_probe.php'],
        'data_policy' => ['uninstall' => 'retain'],
    ];
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($id . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString($id . '/plugin.php', "<?php\nreturn static function (\$context): void {};\n");
    $zip->addFromString($id . '/migrations/001_probe.php', $migrationBody);
    $zip->close();
    return $path;
}

function c2b_service(PDO $pdo): array
{
    $repo = new CatalogRepository($pdo);
    return [$repo, new CatalogService($pdo, $repo, new CatalogValidator(), new OutboxEventRepository($pdo), new Cms\Core\Audit\AuditLogger($pdo))];
}

if (!extension_loaded('pdo_mysql')) {
    c2b_mysql_fail('pdo_mysql is unavailable; real MySQL/MariaDB C2B acceptance cannot run.');
}
$mysql = trim((string) (shell_exec('command -v mysql') ?: ''));
if ($mysql === '') {
    c2b_mysql_fail('mysql client is unavailable; real MySQL/MariaDB C2B acceptance cannot run.');
}
try {
    c2b_mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-N', '-e', 'SELECT 1']);
} catch (Throwable $exception) {
    c2b_mysql_fail('local MySQL/MariaDB is unavailable: ' . $exception->getMessage());
}

$rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mysqlInfo['version'] = (string) $rootPdo->query('SELECT VERSION()')->fetchColumn();
$mysqlInfo['comment'] = (string) $rootPdo->query("SHOW VARIABLES LIKE 'version_comment'")->fetch(PDO::FETCH_ASSOC)['Value'];
$mysqlInfo['driver'] = (string) $rootPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$sslRow = $rootPdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
$mysqlInfo['ssl_cipher'] = is_array($sslRow) ? (string) ($sslRow['Value'] ?? '') : '';

SessionManager::start(false);
echo '[INFO] MySQL/MariaDB product=' . $mysqlInfo['comment'] . ' version=' . $mysqlInfo['version'] . ' driver=' . $mysqlInfo['driver'] . ' tls=' . ($mysqlInfo['ssl_cipher'] !== '' ? $mysqlInfo['ssl_cipher'] : 'none') . PHP_EOL;
$db = 'cms_c2b_' . bin2hex(random_bytes(4));
$dbC2a = 'cms_c2b_up_' . bin2hex(random_bytes(4));
$dbUser = 'c2bu_' . bin2hex(random_bytes(5));
$dbPassword = 'C2b-' . bin2hex(random_bytes(8)) . '!';
$root = sys_get_temp_dir() . '/cms-commerce-c2b-mysql-' . bin2hex(random_bytes(4));
c2b_mysql_remove($root);
c2b_mysql_copy(CMS_SOURCE_ROOT, $root);
foreach (['storage/logs', 'storage/cache', 'storage/tmp', 'storage/database', 'storage/plugin-installs/uploads', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        mkdir($root . '/' . $dir, 0755, true);
    }
}

try {
    c2b_mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e', 'CREATE DATABASE ' . c2b_mysql_identifier($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE ' . c2b_mysql_identifier($dbC2a) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci']);
    c2b_mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e',
        'CREATE USER ' . c2b_mysql_quote($dbUser) . "@'127.0.0.1' IDENTIFIED BY " . c2b_mysql_quote($dbPassword) . '; ' .
        'GRANT ALL PRIVILEGES ON ' . c2b_mysql_identifier($db) . '.* TO ' . c2b_mysql_quote($dbUser) . "@'127.0.0.1'; " .
        'GRANT ALL PRIVILEGES ON ' . c2b_mysql_identifier($dbC2a) . '.* TO ' . c2b_mysql_quote($dbUser) . "@'127.0.0.1';"
    ]);

    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => '', 'username' => '', 'password' => '', 'options' => []];
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($root . '/system/core-manifest.json', json_encode(ManifestBuilder::build($root . '/system/core'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $install = new InstallController($root, Settings::load($root), new FileLogger($root . '/storage/logs/app.log'));
    $response = $install->store(new Request('POST', '/install', [], [
        '_csrf' => CsrfToken::get(),
        'db_driver' => 'mysql',
        'mysql_host' => '127.0.0.1',
        'mysql_port' => '3306',
        'mysql_database' => $db,
        'mysql_username' => $dbUser,
        'mysql_password' => $dbPassword,
        'site_name' => 'Commerce C2B MySQL',
        'site_url' => 'https://commerce-c2b.example.test',
        'email' => 'admin@example.test',
        'display_name' => 'C2B Admin',
        'password' => 'c2b-mysql-secret',
        'site_id' => 'commerce-c2b-mysql',
        'site_secret' => 'commerce-c2b-secret',
        'install_action' => 'install',
    ]));
    c2b_mysql_check($response->status() === 302 && is_file($root . '/storage/installed.lock'), 'fresh CMS installs into empty real MySQL/MariaDB database');
    $settings = Settings::load($root);
    $pdo = ConnectionFactory::make($settings);
    $adminId = (int) $pdo->query("SELECT id FROM cms_admin_users WHERE email = 'admin@example.test'")->fetchColumn();
    $logger = new FileLogger($root . '/storage/logs/app.log');
    $manager = new PluginManager($root . '/content/plugins', $pdo, $logger, new EventDispatcher(), new BlockRegistry(), new PluginRuntimeRegistry(), new OfficialPluginRegistry($root));
    $manager->syncDiscovered();
    $installer = new LocalPluginPackageInstaller($root, $pdo);
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_commerce_products'")->fetchColumn() === 0, 'bundled Commerce is discovered before plugin tables exist');
    $installer->enable('official.commerce', $adminId);
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_commerce_products'")->fetchColumn() === 1, 'enabling bundled official.commerce executes plugin migrations');
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_plugin_media_references'")->fetchColumn() === 1, 'fresh MySQL install has cms_plugin_media_references table');
    $appliedBefore = (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'official.commerce' AND status = 'applied'")->fetchColumn();
    $installer->enable('official.commerce', $adminId);
    $appliedAfter = (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'official.commerce' AND status = 'applied'")->fetchColumn();
    c2b_mysql_check($appliedBefore === $appliedAfter && $appliedAfter >= 2, 'repeated enable does not duplicate Commerce migrations');

    $cols = $pdo->query("SHOW COLUMNS FROM cms_plugin_media_references")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['plugin_id', 'media_id', 'reference_type', 'reference_id'] as $column) {
        c2b_mysql_check(in_array($column, $cols, true), 'cms_plugin_media_references has column ' . $column);
    }
    $indexes = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_plugin_media_references'")->fetchAll(PDO::FETCH_COLUMN);
    c2b_mysql_check(in_array('cms_plugin_media_refs_unique', $indexes, true), 'MySQL plugin media references has unique owner/reference index');
    c2b_mysql_check(in_array('cms_plugin_media_refs_media_idx', $indexes, true), 'MySQL plugin media references has media_id index');
    c2b_mysql_check(in_array('cms_plugin_media_refs_plugin_idx', $indexes, true), 'MySQL plugin media references has plugin_id index');

    foreach ([['a.png', 'hash-a'], ['b.png', 'hash-b'], ['c.png', 'hash-c']] as $media) {
        $pdo->prepare("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png',:name,:relative_path,:storage_key,10,:hash,'{}','png',1,1,:title,'','',:admin,'Active','now','now')")
            ->execute([':name' => $media[0], ':relative_path' => '2026/08/' . $media[0], ':storage_key' => '2026/08/' . $media[0], ':hash' => $media[1], ':title' => $media[0], ':admin' => $adminId]);
    }
    $mediaIds = $pdo->query('SELECT id FROM cms_media ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    [$mediaA, $mediaB, $mediaC] = array_map('intval', array_slice($mediaIds, -3));
    [$repo, $service] = c2b_service($pdo);
    $productId = $service->create(new CatalogCommand('MySQL C2B Product', 'mysql-c2b-product', 'simple', 'Summary', [['type' => 'paragraph', 'data' => ['text' => 'Body']]], '', '', 'USD', '', $mediaA, [['sku' => 'MYSQL-C2B-1', 'price_minor' => 1200, 'variant_status' => 'active', 'media_id' => $mediaA]], [], [], [['media_id' => $mediaA, 'role' => 'gallery']], 'mysql-c2b-create'), $adminId);
    $service->publish($productId, $adminId, 'mysql-c2b-publish');
    c2b_mysql_check((string) $repo->product($productId)['status'] === 'published', 'MySQL creates and publishes Commerce product');
    c2b_mysql_check((int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'commerce.product.created' ORDER BY id DESC LIMIT 1")->fetchColumn() === $adminId, 'MySQL Commerce audit records real administrator id');
    c2b_mysql_check((new PluginMediaReferenceIndex($pdo))->referenceCount($mediaA) === 1, 'MySQL Commerce product writes media reference index');
    $service->update($productId, new CatalogCommand('MySQL C2B Product', 'mysql-c2b-product', 'simple', 'Summary', [['type' => 'paragraph', 'data' => ['text' => 'Body 2']]], '', '', 'USD', '', $mediaB, [['sku' => 'MYSQL-C2B-1', 'price_minor' => 1200, 'variant_status' => 'active', 'media_id' => $mediaC]], [], [], [['media_id' => $mediaC, 'role' => 'gallery']], 'mysql-c2b-update'), $adminId);
    $index = new PluginMediaReferenceIndex($pdo);
    c2b_mysql_check($index->referenceCount($mediaA) === 0 && $index->referenceCount($mediaB) === 1 && $index->referenceCount($mediaC) === 1, 'MySQL media reference index removes stale refs and creates new main/gallery/variant refs');
    $installer->disableWithDependents('official.commerce', $adminId, true);
    c2b_mysql_check($index->referenceCount($mediaB) === 1, 'MySQL disable preserves Commerce media references');
    $installer->enable('official.commerce', $adminId);
    (new CommerceMediaReferenceProvider($pdo))->repairIndex();
    c2b_mysql_check($index->referenceCount($mediaB) === 1 && $index->referenceCount($mediaC) === 1, 'MySQL re-enable validates and repairs media reference index');

    $failUp = <<<'PHP'
<?php
return [
  'id' => 'vendor_failc2b_001',
  'affected_objects' => ['table:plugin_vendor_failc2b_marker'],
  'up' => static function (PDO $pdo): void { $pdo->exec('CREATE TABLE cms_plugin_vendor_failc2b_marker (id INTEGER NOT NULL)'); throw new RuntimeException('intentional fail'); },
  'down' => static function (PDO $pdo): void { $pdo->exec('DROP TABLE IF EXISTS cms_plugin_vendor_failc2b_marker'); },
];
PHP;
    $okUp = <<<'PHP'
<?php
return [
  'id' => 'vendor_failc2b_001',
  'affected_objects' => ['table:plugin_vendor_failc2b_marker'],
  'up' => static function (PDO $pdo): void { $pdo->exec('CREATE TABLE IF NOT EXISTS cms_plugin_vendor_failc2b_marker (id INTEGER NOT NULL)'); },
  'down' => static function (PDO $pdo): void { $pdo->exec('DROP TABLE IF EXISTS cms_plugin_vendor_failc2b_marker'); },
];
PHP;
    $badZip = c2b_plugin_zip($root . '/storage/tmp/fail-c2b.zip', 'vendor.failc2b', $failUp);
    $plan = $installer->preview($badZip, $adminId);
    try {
        $installer->install((string) $plan['token'], $adminId, true, true);
        c2b_mysql_check(false, 'MySQL failing plugin migration is rejected');
    } catch (Throwable) {
        c2b_mysql_check(true, 'MySQL failing plugin migration is rejected');
    }
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM cms_plugins WHERE plugin_id = 'vendor.failc2b' AND status = 'Enabled'")->fetchColumn() === 0, 'MySQL failed plugin is not marked Enabled');
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_plugin_vendor_failc2b_marker'")->fetchColumn() === 0, 'MySQL failed migration rolls back created table');
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'vendor.failc2b' AND status = 'rolled_back'")->fetchColumn() === 1, 'MySQL failed migration keeps rollback evidence');
    $okZip = c2b_plugin_zip($root . '/storage/tmp/fail-c2b-ok.zip', 'vendor.failc2b', $okUp);
    $plan = $installer->preview($okZip, $adminId);
    $installer->install((string) $plan['token'], $adminId, true, true);
    c2b_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM cms_plugins WHERE plugin_id = 'vendor.failc2b' AND status = 'Enabled'")->fetchColumn() === 1, 'MySQL fixed plugin migration retries safely and enables plugin');

    $pdoC2a = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $dbC2a . ';charset=utf8mb4', $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdoC2a, c2b_core_migrations_excluding('2026_08_15_000002_plugin_media_references')))->run();
    (new AdminAuthenticator($pdoC2a))->createAdmin('up@example.test', 'secret-pass', 'Upgrade Admin');
    $upAdmin = (int) $pdoC2a->query("SELECT id FROM cms_admin_users WHERE email = 'up@example.test'")->fetchColumn();
    (new LocalPluginPackageInstaller($root, $pdoC2a))->installBundled('official.commerce', $upAdmin, true);
    $pdoC2a->exec("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png','upgrade.png','2026/08/upgrade.png','2026/08/upgrade.png',10,'hash-up','{}','png',1,1,'Upgrade','',''," . $upAdmin . ",'Active','now','now')");
    $upMedia = (int) $pdoC2a->lastInsertId();
    $pdoC2a->exec("INSERT INTO cms_commerce_products (title, slug, product_type, status, summary, description_blocks_json, seo_title, seo_description, canonical_url, external_url, external_domain, currency, main_media_id, created_at, updated_at) VALUES ('Upgrade Product','upgrade-product','simple','published','','[]','','','/products/upgrade-product','','','USD'," . $upMedia . ",'now','now')");
    $upProduct = (int) $pdoC2a->lastInsertId();
    $pdoC2a->exec("INSERT INTO cms_commerce_product_media (product_id, variant_id, media_id, role, sort_order, created_at, updated_at) VALUES (" . $upProduct . ", NULL, " . $upMedia . ", 'primary', 0, 'now', 'now')");
    (require CMS_SOURCE_ROOT . '/system/migrations/2026_08_15_000002_plugin_media_references.php')->up($pdoC2a);
    (new CommerceMediaReferenceProvider($pdoC2a))->repairIndex();
    c2b_mysql_check((new PluginMediaReferenceIndex($pdoC2a))->referenceCount($upMedia) === 1, 'MySQL C2A-installed state upgrades to C2B and repairs media references');
} finally {
    try {
        c2b_mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e', 'DROP USER IF EXISTS ' . c2b_mysql_quote($dbUser) . "@'127.0.0.1'; DROP DATABASE IF EXISTS " . c2b_mysql_identifier($db) . '; DROP DATABASE IF EXISTS ' . c2b_mysql_identifier($dbC2a)]);
    } catch (Throwable) {
    }
    c2b_mysql_remove($root);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " Commerce C2B MySQL acceptance checks failed.\n");
    exit(1);
}

echo 'Commerce C2B MySQL acceptance tests passed; mysql=passed product=' . $mysqlInfo['comment'] . ' version=' . $mysqlInfo['version'] . ' driver=' . $mysqlInfo['driver'] . ' tls=' . ($mysqlInfo['ssl_cipher'] !== '' ? $mysqlInfo['ssl_cipher'] : 'none') . PHP_EOL;
