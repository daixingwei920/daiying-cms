<?php

declare(strict_types=1);

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Media\PluginMediaReferenceIndex;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Plugin\PluginManager;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CatalogRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function c2b_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function c2b_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c2b_check(false, $message);
    } catch (Throwable) {
        c2b_check(true, $message);
    }
}

function c2b_remove(string $path): void
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

function c2b_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

$root = sys_get_temp_dir() . '/cms-commerce-c2b-index-' . bin2hex(random_bytes(4));
c2b_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads', 'content/plugins/vendor.throwmedia'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/c2b.sqlite', 'username' => '', 'password' => '', 'options' => []];
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$pdo = ConnectionFactory::make(Settings::load($root));
c2b_core_migrations($pdo);
(new AdminAuthenticator($pdo))->createAdmin('c2b@example.test', 'secret-pass', 'C2B Admin');
$adminId = (int) $pdo->query("SELECT id FROM cms_admin_users WHERE email = 'c2b@example.test'")->fetchColumn();

$pdo->exec("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png','c2b.png','2026/08/c2b.png','2026/08/c2b.png',10,'hash-c2b','{}','png',1,1,'C2B','',''," . $adminId . ",'Active','now','now')");
$mediaId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png','c2b-b.png','2026/08/c2b-b.png','2026/08/c2b-b.png',10,'hash-c2b-b','{}','png',1,1,'C2B B','',''," . $adminId . ",'Active','now','now')");
$mediaB = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png','c2b-c.png','2026/08/c2b-c.png','2026/08/c2b-c.png',10,'hash-c2b-c','{}','png',1,1,'C2B C','',''," . $adminId . ",'Active','now','now')");
$mediaC = (int) $pdo->lastInsertId();

file_put_contents($root . '/content/plugins/vendor.throwmedia/plugin.json', json_encode([
    'plugin_id' => 'vendor.throwmedia',
    'name' => 'Throw Media',
    'version' => '1.0.0',
    'author' => 'Tests',
    'core' => ['min' => '1.2.0-rc1'],
    'php' => '8.3.0',
    'entry' => 'plugin.php',
    'capabilities' => ['vendor.throwmedia.view'],
    'media_reference_provider' => 'Vendor\\ThrowMedia\\Provider',
], JSON_PRETTY_PRINT));
file_put_contents($root . '/content/plugins/vendor.throwmedia/plugin.php', "<?php\nfile_put_contents('" . addslashes($root . '/storage/entry-loaded.flag') . "', 'loaded');\nthrow new RuntimeException('disabled plugin entry must not execute during media reference checks');\n");
$pdo->prepare('INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, installed_at, updated_at, source, review_status, capabilities_json, data_policy_json) VALUES (:plugin_id, :name, :version, :author, :status, :trust_level, :installed_at, :updated_at, :source, :review_status, :capabilities_json, :data_policy_json)')
    ->execute([
        ':plugin_id' => 'vendor.throwmedia',
        ':name' => 'Throw Media',
        ':version' => '1.0.0',
        ':author' => 'Tests',
        ':status' => 'Disabled',
        ':trust_level' => 'untrusted',
        ':installed_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
        ':source' => 'local_upload',
        ':review_status' => 'needs_review',
        ':capabilities_json' => json_encode(['vendor.throwmedia.view']),
        ':data_policy_json' => '{}',
    ]);
(new PluginMediaReferenceIndex($pdo))->replaceForReference('vendor.throwmedia', 'test_product', '42', [$mediaId]);
c2b_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads'))->hardDelete($mediaId), 'persistent plugin media index blocks hard delete without loading disabled plugin code');
c2b_check(!is_file($root . '/storage/entry-loaded.flag'), 'disabled plugin entry file was not executed during media reference check');
c2b_throws(static fn () => (new PluginMediaReferenceIndex($pdo))->replaceForReference('../bad', 'test', '1', [$mediaId]), 'plugin media index rejects invalid plugin_id owners');
c2b_throws(static fn () => (new PluginMediaReferenceIndex($pdo, 'vendor.throwmedia'))->replaceForReference('vendor.other', 'test', '1', [$mediaId]), 'owner-scoped plugin media index rejects writes for another plugin_id');
try {
    $pdo->prepare('INSERT INTO cms_plugin_media_references (plugin_id, media_id, reference_type, reference_id, created_at, updated_at) VALUES (:plugin_id, :media_id, :reference_type, :reference_id, :created_at, :updated_at)')
        ->execute([':plugin_id' => 'vendor.throwmedia', ':media_id' => $mediaId, ':reference_type' => 'test_product', ':reference_id' => '42', ':created_at' => 'now', ':updated_at' => 'now']);
    c2b_check(false, 'unique index prevents duplicate plugin media references');
} catch (Throwable) {
    c2b_check(true, 'unique index prevents duplicate plugin media references');
}
$indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'cms_plugin_media_references'")->fetchAll(PDO::FETCH_COLUMN);
c2b_check(in_array('cms_plugin_media_refs_media_idx', $indexes, true), 'plugin media references has media_id lookup index');
c2b_check(in_array('cms_plugin_media_refs_plugin_idx', $indexes, true), 'plugin media references has plugin_id lookup index');
c2b_check(in_array('cms_plugin_media_refs_unique', $indexes, true), 'plugin media references has unique owner/reference index');

(new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', $adminId, true);
$runtime = new PluginRuntimeRegistry();
$manager = new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, ''));
$manager->bootEnabled();
$repo = new CatalogRepository($pdo);
$service = new CatalogService($pdo, $repo, new CatalogValidator(), new OutboxEventRepository($pdo), new Cms\Core\Audit\AuditLogger($pdo));
$productId = $service->create(new CatalogCommand(
    'C2B Indexed Product',
    'c2b-indexed-product',
    'simple',
    'Summary',
    [['type' => 'paragraph', 'data' => ['text' => 'Indexed']]],
    '',
    '',
    'USD',
    '',
    $mediaId,
    [['sku' => 'C2B-IDX-1', 'price_minor' => 1000, 'variant_status' => 'active', 'media_id' => $mediaId]],
    [],
    [],
    [['media_id' => $mediaId, 'role' => 'gallery']],
    'c2b-index-create'
), $adminId);
c2b_check((new PluginMediaReferenceIndex($pdo))->referenceCount($mediaId) >= 2, 'Commerce product save synchronizes persistent media reference index');
$service->update($productId, new CatalogCommand(
    'C2B Indexed Product',
    'c2b-indexed-product',
    'simple',
    'Summary',
    [['type' => 'paragraph', 'data' => ['text' => 'Indexed updated']]],
    '',
    '',
    'USD',
    '',
    $mediaB,
    [['sku' => 'C2B-IDX-1', 'price_minor' => 1000, 'variant_status' => 'active', 'media_id' => $mediaC]],
    [],
    [],
    [['media_id' => $mediaC, 'role' => 'gallery']],
    'c2b-index-update'
), $adminId);
$index = new PluginMediaReferenceIndex($pdo);
c2b_check($index->referenceCount($mediaId) === 1, 'Commerce product update removes stale main/gallery/variant references for old media');
c2b_check($index->referenceCount($mediaB) === 1, 'Commerce product update creates new main image reference');
c2b_check($index->referenceCount($mediaC) === 1, 'Commerce product update creates gallery/variant image reference without duplicate rows');
$service->publish($productId, $adminId, 'c2b-index-publish');
$service->archive($productId, $adminId, 'c2b-index-archive');
c2b_check($index->referenceCount($mediaB) === 1 && $index->referenceCount($mediaC) === 1, 'archived Commerce products retain media references');
(new LocalPluginPackageInstaller($root, $pdo))->disableWithDependents('official.commerce', $adminId, true);
c2b_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads'))->hardDelete($mediaB), 'Commerce references still block hard delete after Commerce is disabled');
c2b_check($repo->product($productId) !== null, 'Commerce data remains after disable while persistent media index protects references');
(new LocalPluginPackageInstaller($root, $pdo))->uninstallCode('official.commerce', $adminId);
c2b_check((new PluginMediaReferenceIndex($pdo))->referenceCount($mediaB) === 1, 'uninstalling Commerce code retains persistent media references');
$preview = (new LocalPluginPackageInstaller($root, $pdo))->purgePreview('official.commerce');
c2b_check((int) ($preview['plugin_media_references'] ?? 0) >= 2, 'permanent purge preview includes plugin media reference count');
(new LocalPluginPackageInstaller($root, $pdo))->purge('official.commerce', $adminId, 'PURGE official.commerce');
c2b_check((int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_media_references WHERE plugin_id = 'official.commerce'")->fetchColumn() === 0, 'confirmed permanent purge removes Commerce plugin media references');

c2b_remove($root);
if ($failures > 0) {
    exit(1);
}
