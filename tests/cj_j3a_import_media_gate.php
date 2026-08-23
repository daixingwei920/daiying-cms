<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginAdminRequestContext;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\CjDropshipping\Api\CjCircuitBreaker;
use Official\CjDropshipping\Api\CjEndpointRegistry;
use Official\CjDropshipping\Api\CjErrorMapper;
use Official\CjDropshipping\Api\CjFixtureTransport;
use Official\CjDropshipping\Api\CjHttpClient;
use Official\CjDropshipping\Api\CjPointsBudget;
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjRateLimiter;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Catalog\CjCatalogInput;
use Official\CjDropshipping\Catalog\CjCatalogPreviewService;
use Official\CjDropshipping\Import\CjFixtureMediaTransport;
use Official\CjDropshipping\Import\CjImportService;
use Official\CjDropshipping\Import\CjMediaLocalizer;
use Official\CjDropshipping\Import\CjPricingCalculator;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CatalogRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;
function j3a_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function j3a_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j3a_check(false, $message);
    } catch (Throwable) {
        j3a_check(true, $message);
    }
}
function j3a_remove(string $path): void
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
function j3a_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}
function j3a_core(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}
function j3a_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j3a_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j3a.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j3a-static-test-key';
    j3a_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j3a_core($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}
function j3a_table(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);
    return $stmt->fetchColumn() !== false;
}
function j3a_png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAL0lEQVR42u3OIQEAAAgDMCISjIh0gRg3E/Ornr2kEhAQEBAQEBAQEBAQEBAQSAceN3Lcl8hIkk0AAAAASUVORK5CYII=') ?: '';
}
function j3a_tiny_png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=') ?: '';
}
function j3a_catalog(PDO $pdo, array $variants = []): CjCatalogPreviewService
{
    $variants = $variants ?: [
        ['pid' => 'CJ100001', 'productSku' => 'SKU-LAMP', 'vid' => 'VID100001', 'variantSku' => 'VID-LAMP-BLACK', 'variantNameEn' => 'Black', 'sellPrice' => '12.34', 'currency' => 'USD', 'weight' => '420'],
        ['pid' => 'CJ100001', 'productSku' => 'SKU-LAMP', 'vid' => 'VID100002', 'variantSku' => 'VID-LAMP-WHITE', 'variantNameEn' => 'White', 'sellPrice' => '13.00', 'currency' => 'USD', 'weight' => '430'],
        ['pid' => 'CJ100001', 'productSku' => 'SKU-LAMP', 'vid' => 'VID100003', 'variantSku' => 'VID-LAMP-BLUE', 'variantNameEn' => 'Blue', 'sellPrice' => '14.00', 'currency' => 'USD', 'weight' => '440'],
    ];
    $repo = new CjRepository($pdo);
    $registry = new CjEndpointRegistry();
    $transport = new CjFixtureTransport();
    $fixtures = [
        'product.query' => '{"code":"200","data":{"pid":"CJ100001","productSku":"SKU-LAMP","productName":"Fixture Lamp Updated","description":"<p>Safe update</p><script>bad()</script>","sellPrice":"12.34","maxPrice":"15.00","currency":"USD","weight":"420"},"pointsInfo":{"remaining":90,"total":100}}',
        'variant.query' => json_encode(['code' => '200', 'data' => $variants, 'pointsInfo' => ['remaining' => 89, 'total' => 100]], JSON_UNESCAPED_SLASHES),
        'stock.queryByVid' => '{"code":"200","data":[{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":17}],"pointsInfo":{"remaining":88,"total":100}}',
        'warehouse.detail' => '{"code":"200","data":{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US"},"pointsInfo":{"remaining":87,"total":100}}',
    ];
    foreach ($fixtures as $operation => $body) {
        $endpoint = $registry->resolve($operation);
        $transport->add($endpoint['method'], $endpoint['url'], 200, ['requestId' => 'j3a-' . $operation], (string) $body);
    }
    return new CjCatalogPreviewService(new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 1), new CjCircuitBreaker($repo)), $repo, new CjCatalogInput(), 300);
}
function j3a_importer(PDO $pdo, ?CjCatalogPreviewService $catalog = null): CjImportService
{
    return new CjImportService(new CjRepository($pdo), $catalog ?? j3a_catalog($pdo), new CatalogService($pdo, new CatalogRepository($pdo), new CatalogValidator(), new OutboxEventRepository($pdo), new AuditLogger($pdo)), new AuditLogger($pdo));
}
function j3a_counts(PDO $pdo): array
{
    $tables = ['cms_commerce_products', 'cms_commerce_product_variants', 'cms_cj_product_mappings', 'cms_cj_variant_mappings', 'cms_cj_media_jobs', 'cms_cj_tasks'];
    $out = [];
    foreach ($tables as $table) {
        $out[$table] = j3a_table($pdo, $table) ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : 0;
    }
    return $out;
}

[$root, $pdo] = j3a_root('cms-cj-j3a');
$repo = new CjRepository($pdo);
$importer = j3a_importer($pdo);

j3a_check(json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true)['version'] === '1.0.0-rc1', 'CJ plugin version is 1.0.0-rc1');
j3a_check(j3a_table($pdo, 'cms_cj_import_sessions') && j3a_table($pdo, 'cms_cj_source_snapshots'), '005 migration keeps import sessions and source snapshots available');
$indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name IN ('cms_cj_import_sessions','cms_cj_variant_mappings')")->fetchAll(PDO::FETCH_COLUMN);
j3a_check(in_array('ux_cj_import_sessions_key', $indexes, true) && in_array('idx_cj_import_sessions_request', $indexes, true) && in_array('ux_cj_variant_mappings_vid', $indexes, true), 'database-level idempotency and VID uniqueness constraints exist');

$partial = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3a-partial', 'pricing_rule' => ['fixed_markup_minor' => '100']], 11);
$productId = (int) $partial['commerce_product_id'];
j3a_check((string) $pdo->query('SELECT status FROM cms_commerce_products WHERE id = ' . $productId)->fetchColumn() === 'draft', 'initial import creates draft only');
$added = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001', 'VID100002', 'VID100003'], 'idempotency_key' => 'j3a-only-new', 'mode' => 'only_new_skus', 'pricing_rule' => ['fixed_markup_minor' => '200']], 11);
j3a_check($added['status'] === 'new_skus_added' && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_product_variants WHERE product_id = ' . $productId)->fetchColumn() === 3, 'only_new_skus really appends only unmapped remote VIDs to existing draft');
j3a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_variant_mappings WHERE cj_vid = 'VID100001'")->fetchColumn() === 1, 'existing VID is not duplicated during only_new_skus');
$pdo->exec("UPDATE cms_commerce_products SET title = 'Local Locked Title' WHERE id = " . $productId);
$updated = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3a-update', 'mode' => 'update_existing_draft', 'sync_title' => true, 'sync_description' => true, 'pricing_rule' => ['fixed_markup_minor' => '300']], 11);
j3a_check($updated['status'] === 'draft_updated' && (string) $pdo->query('SELECT title FROM cms_commerce_products WHERE id = ' . $productId)->fetchColumn() === 'Fixture Lamp Updated', 'update_existing_draft updates explicitly allowed draft fields');
$copy = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3a-copy', 'mode' => 'create_copy'], 11);
j3a_check((int) $copy['commerce_product_id'] !== $productId && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_product_mappings WHERE cj_pid LIKE 'CJ100001#COPY%'")->fetchColumn() === 1, 'create_copy requires explicit mode and creates independent mapping keys');
$same = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3a-copy', 'mode' => 'create_copy'], 11);
j3a_check((int) $same['commerce_product_id'] === (int) $copy['commerce_product_id'], 'same idempotency key replays same import result');
j3a_throws(static fn () => $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100002'], 'idempotency_key' => 'j3a-copy', 'mode' => 'create_copy'], 11), 'same idempotency key with different request is rejected');
(new CatalogService($pdo, new CatalogRepository($pdo), new CatalogValidator(), new OutboxEventRepository($pdo), new AuditLogger($pdo)))->publish($productId, 11, 'j3a-publish-setup');
j3a_throws(static fn () => $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100002'], 'idempotency_key' => 'j3a-update-published', 'mode' => 'update_existing_draft', 'sync_title' => true], 11), 'published Commerce product is rejected for automatic CJ update');

$before = j3a_counts($pdo);
$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j3a-static-test-key')))->bootEnabled();
$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
j3a_check(isset($routes['POST /admin/cj/import']) && $routes['POST /admin/cj/import']->capability === 'cj.products.import' && $routes['POST /admin/cj/import']->csrf, 'import route is POST with cj.products.import and CSRF');
j3a_check(isset($routes['POST /admin/cj/media/retry']) && $routes['POST /admin/cj/media/retry']->capability === 'cj.products.import' && $routes['POST /admin/cj/media/retry']->csrf, 'media retry route is POST with cj.products.import and CSRF');
j3a_check(isset($routes['GET /admin/cj/catalog']) && $routes['GET /admin/cj/catalog']->capability === 'cj.catalog.search' && !$routes['GET /admin/cj/catalog']->csrf, 'catalog search remains read-only and cannot import');
$forbidden = $routes['POST /admin/cj/import']->handler->__invoke(new Cms\Core\Http\Request('POST', '/admin/cj/import', [], ['pid' => 'CJX', 'vids' => ['VIDX']], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 11, ['cj.catalog.search'], 'c', 'r', '127.0.0.1')]));
j3a_check($forbidden->status() === 403 && $before['cms_commerce_products'] === j3a_counts($pdo)['cms_commerce_products'], 'missing cj.products.import creates zero Commerce or CJ data');

$sessionId = (int) $partial['import_session_id'];
$repo->enqueueMediaJobs([['import_session_id' => $sessionId, 'commerce_product_id' => $productId, 'cj_pid' => 'CJ100001', 'source_url' => 'https://img.cjdropshipping.com/redirect.png', 'role' => 'gallery']]);
$transport = new CjFixtureMediaTransport();
$transport->add('https://img.cjdropshipping.com/redirect.png', 302, ['Location' => 'https://img.cjdropshipping.com/final.png'], '');
$transport->add('https://img.cjdropshipping.com/final.png', 200, ['Content-Type' => 'image/png'], j3a_png() . 'GPS');
(new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $transport))->processQueued();
j3a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_media_jobs WHERE source_url_hash = '" . hash('sha256', 'https://img.cjdropshipping.com/redirect.png') . "' AND status = 'completed'")->fetchColumn() === 1, 'allowed redirect is rechecked and localized through Core Media');
$hash = (string) $pdo->query('SELECT sha256_hash FROM cms_media ORDER BY id DESC LIMIT 1')->fetchColumn();
j3a_check($hash !== hash('sha256', j3a_png() . 'GPS'), 'sanitized media hash is based on stripped safe file content');

$badCases = [
    ['https://img.cjdropshipping.com/private-hop.png', 302, ['Location' => 'https://img.cjdropshipping.com/private-final.png'], '', 'https://img.cjdropshipping.com/private-final.png', 200, ['Content-Type' => 'image/png'], j3a_png(), ['127.0.0.1'], 'redirect private IP is rejected'],
    ['https://img.cjdropshipping.com/mime.png', 200, ['Content-Type' => 'image/jpeg'], j3a_png(), null, 0, [], '', [], 'MIME header mismatch is rejected'],
    ['https://img.cjdropshipping.com/timeout.png', 200, ['Content-Type' => 'image/png', 'X-Fixture-Timeout' => '1'], j3a_png(), null, 0, [], '', [], 'download timeout is rejected'],
    ['https://img.cjdropshipping.com/large.png', 200, ['Content-Type' => 'image/png'], str_repeat('x', 1048577), null, 0, [], '', [], 'oversized response is rejected'],
    ['https://img.cjdropshipping.com/broken.png', 200, ['Content-Type' => 'image/png'], "\x89PNG\r\n\x1a\nbroken", null, 0, [], '', [], 'damaged image is rejected'],
    ['https://img.cjdropshipping.com/tiny-placeholder.png', 200, ['Content-Type' => 'image/png'], j3a_tiny_png(), null, 0, [], '', [], '1x1 placeholder product image is rejected'],
];
foreach ($badCases as $i => $case) {
    [$url, $status, $headers, $body, $nextUrl, $nextStatus, $nextHeaders, $nextBody, $nextIps, $message] = $case;
    $repo->enqueueMediaJobs([['import_session_id' => $sessionId, 'commerce_product_id' => $productId, 'cj_pid' => 'CJ100001', 'source_url' => $url, 'role' => $i === 0 ? 'primary' : 'gallery']]);
    $bad = new CjFixtureMediaTransport();
    $bad->add($url, $status, $headers, $body);
    if (is_string($nextUrl)) {
        $bad->add($nextUrl, $nextStatus, $nextHeaders, $nextBody, null, $nextIps);
    }
    (new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $bad))->processQueued();
    j3a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_media_jobs WHERE source_url_hash = '" . hash('sha256', $url) . "' AND status IN ('partial','failed_primary')")->fetchColumn() === 1, $message);
}
j3a_check((string) $pdo->query('SELECT status FROM cms_cj_import_sessions WHERE id = ' . $sessionId)->fetchColumn() === 'blocked', 'primary image failure moves import session to blocked while Commerce product remains draft/published setup untouched');

$draftReady = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3a-ready', 'mode' => 'create_copy', 'image_urls' => ['https://img.cjdropshipping.com/ready.png']], 11);
$readyTransport = new CjFixtureMediaTransport();
$readyTransport->add('https://img.cjdropshipping.com/ready.png', 200, ['Content-Type' => 'image/png'], j3a_png());
(new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $readyTransport))->processQueued();
j3a_check((string) $pdo->query('SELECT status FROM cms_cj_import_sessions WHERE id = ' . (int) $draftReady['import_session_id'])->fetchColumn() === 'ready_for_review' && (string) $pdo->query('SELECT status FROM cms_commerce_products WHERE id = ' . (int) $draftReady['commerce_product_id'])->fetchColumn() === 'draft', 'successful media gate reaches ready_for_review while Commerce product remains draft');

$snapshot = $pdo->query('SELECT snapshot_json, payload_size, snapshot_hash FROM cms_cj_source_snapshots ORDER BY id DESC LIMIT 1')->fetch();
j3a_check(is_array($snapshot) && (int) $snapshot['payload_size'] > 0 && (string) $snapshot['snapshot_hash'] === hash('sha256', (string) $snapshot['snapshot_json']) && !preg_match('/api[_-]?key|access[_-]?token|refresh[_-]?token|open[_-]?id|Cookie/i', (string) $snapshot['snapshot_json']), 'source snapshots are hashed, size-limited and redacted');
$pdo->exec("UPDATE cms_cj_source_snapshots SET expires_at = '2000-01-01T00:00:00+00:00'");
j3a_check($repo->purgeExpiredSourceSnapshots() >= 1, 'expired source snapshots are purged');
j3a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation LIKE 'order.%'")->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_orders')->fetchColumn() === 0, 'J3A creates no CJ orders, payments, webhooks or fulfillment records');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j3a_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j3a_core($mysql);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.commerce', 1, true);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.cj-dropshipping', 1, true);
            $mysqlResult = j3a_importer($mysql)->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'mysql-j3a'], 12);
            j3a_check((string) $mysql->query('SELECT status FROM cms_commerce_products WHERE id = ' . (int) $mysqlResult['commerce_product_id'])->fetchColumn() === 'draft' && j3a_table($mysql, 'cms_cj_import_sessions'), 'real MySQL/MariaDB validates J3A import schema and draft gate');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        $mysqlStatus = 'failed';
        j3a_check(false, 'MySQL J3A validation failed: ' . $exception->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j3a_check(false, 'pdo_mysql extension is required for CJ J3A MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;

j3a_remove($root);
if ($failures > 0) {
    echo '[RESULT] CJ J3A import/media gate checks failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] CJ J3A import/media gate checks passed.' . PHP_EOL;
