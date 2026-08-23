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
function j3_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function j3_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j3_check(false, $message);
    } catch (Throwable) {
        j3_check(true, $message);
    }
}
function j3_remove(string $path): void
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
function j3_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}
function j3_core(PDO $pdo): void
{
    $m = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $m[] = require $file;
    }
    (new MigrationRunner($pdo, $m))->run();
}
function j3_table(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $s->execute([':table' => $table]);
        return (int) $s->fetchColumn() === 1;
    }
    $s = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $s->execute([':table' => $table]);
    return $s->fetchColumn() !== false;
}
function j3_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j3_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j3.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j3-static-test-key';
    j3_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j3_core($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}
function j3_png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAL0lEQVR42u3OIQEAAAgDMCISjIh0gRg3E/Ornr2kEhAQEBAQEBAQEBAQEBAQSAceN3Lcl8hIkk0AAAAASUVORK5CYII=') ?: '';
}
function j3_catalog(PDO $pdo): CjCatalogPreviewService
{
    $repo = new CjRepository($pdo);
    $r = new CjEndpointRegistry();
    $t = new CjFixtureTransport();
    $fixtures = [
        'product.query' => '{"code":"200","data":{"pid":"CJ100001","productSku":"SKU-LAMP","productName":"<b>Fixture Lamp</b><script>x</script>","description":"<p>Clean</p><script>x</script><p onclick=\"bad()\">Light</p>","sellPrice":"12.34","maxPrice":"15.00","currency":"USD","weight":"420"},"pointsInfo":{"remaining":90,"total":100}}',
        'variant.query' => '{"code":"200","data":[{"pid":"CJ100001","productSku":"SKU-LAMP","vid":"VID100001","variantSku":"DUP-SKU","variantNameEn":"Black","sellPrice":"12.34","currency":"USD","weight":"420"},{"pid":"CJ100001","productSku":"SKU-LAMP","vid":"VID100002","variantSku":"VID-LAMP-WHITE","variantNameEn":"White","sellPrice":"13.00","currency":"USD","weight":"430"}],"pointsInfo":{"remaining":89,"total":100}}',
        'stock.queryByVid' => '{"code":"200","data":[{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":17}],"pointsInfo":{"remaining":88,"total":100}}',
        'warehouse.detail' => '{"code":"200","data":{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US"},"pointsInfo":{"remaining":87,"total":100}}',
    ];
    foreach ($fixtures as $op => $body) {
        $e = $r->resolve($op);
        $t->add($e['method'], $e['url'], 200, ['requestId' => 'j3-' . $op], $body);
    }
    return new CjCatalogPreviewService(new CjHttpClient($r, $t, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 1), new CjCircuitBreaker($repo)), $repo, new CjCatalogInput(), 300);
}
function j3_importer(PDO $pdo): CjImportService
{
    $commerceRepo = new CatalogRepository($pdo);
    return new CjImportService(new CjRepository($pdo), j3_catalog($pdo), new CatalogService($pdo, $commerceRepo, new CatalogValidator(), new OutboxEventRepository($pdo), new AuditLogger($pdo)), new AuditLogger($pdo));
}
function j3_counts(PDO $pdo): array
{
    $tables = ['cms_commerce_products', 'cms_commerce_product_variants', 'cms_cj_product_mappings', 'cms_cj_variant_mappings'];
    $out = [];
    foreach ($tables as $table) {
        $out[$table] = j3_table($pdo, $table) ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : 0;
    }
    return $out;
}

[$root, $pdo] = j3_root('cms-cj-j3');
$repo = new CjRepository($pdo);
$importer = j3_importer($pdo);
$calc = new CjPricingCalculator();

j3_check(j3_table($pdo, 'cms_cj_import_sessions') && j3_table($pdo, 'cms_cj_product_mappings') && j3_table($pdo, 'cms_cj_media_jobs'), '004 migration creates CJ import, mapping, pricing and media job tables');
$pdo->exec("INSERT INTO cms_commerce_product_variants (product_id, sku, sku_norm, price_minor, currency, variant_status, attribute_signature, created_at, updated_at) VALUES (0, 'DUP-SKU', 'DUP-SKU', 100, 'USD', 'active', '', 'now', 'now')");
$baseImport = [
    'pid' => 'CJ100001',
    'vids' => ['VID100001', 'VID100002'],
    'target_country' => 'US',
    'warehouse_preference' => 'WH-US',
    'logistics_preference' => 'standard',
    'pricing_rule' => ['fixed_markup_minor' => '500', 'percent_markup_bps' => '1000', 'minimum_price_minor' => '1999', 'ending' => '99'],
    'image_urls' => ['https://img.cjdropshipping.com/fixture/lamp-primary.png', 'https://img.cjdropshipping.com/fixture/lamp-gallery.png'],
    'sync_requested' => true,
    'idempotency_key' => 'j3-import-1',
];
$result = $importer->importDraft($baseImport, 7);
$productId = (int) $result['commerce_product_id'];
$product = $pdo->query('SELECT * FROM cms_commerce_products WHERE id = ' . $productId)->fetch();
j3_check((string) $product['status'] === 'draft', 'CJ import creates Commerce product as draft only');
j3_check((int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_product_variants WHERE product_id = ' . $productId)->fetchColumn() === 2, 'imports all selected CJ variants as Commerce SKUs');
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_product_variants WHERE product_id = {$productId} AND sku = 'DUP-SKU-CJ1'")->fetchColumn() === 1, 'local SKU conflict is safely renamed while preserving CJ mapping');
j3_check((int) $pdo->query('SELECT COUNT(*) FROM cms_cj_product_mappings')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_variant_mappings')->fetchColumn() === 2, 'writes CJ PID and VID mappings outside Commerce core identifiers');
j3_check((int) $pdo->query('SELECT COUNT(*) FROM cms_cj_pricing_rules')->fetchColumn() === 2, 'records pricing rule version, input cost and calculated price snapshots');
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_tasks WHERE task_type = 'cj.sync.imported_product'")->fetchColumn() === 1, 'queues optional post-import sync task after transaction');
j3_check(!str_contains((string) $product['title'], '<script') && !str_contains((string) $product['description_blocks_json'], '<script'), 'cleans imported title and description before Commerce draft creation');
$again = $importer->importDraft($baseImport, 7);
j3_check((int) $again['commerce_product_id'] === $productId && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_products')->fetchColumn() === 1, 'same idempotency key returns same import result without duplicate product');
j3_throws(static fn () => $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3-import-dup'], 7), 'duplicate PID default create_draft is rejected instead of silently copying');
$existing = $importer->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'j3-import-existing', 'mode' => 'only_new_skus'], 7);
j3_check($existing['status'] === 'no_new_skus' && (int) $existing['commerce_product_id'] === $productId, 'duplicate PID only_new_skus reports no new SKU without copying');

$before = j3_counts($pdo);
j3_throws(static fn () => $importer->importDraft(['pid' => 'CJ100002', 'vids' => ['VID100001'], 'idempotency_key' => 'j3-map-fail', 'simulate_mapping_failure' => true], 7), 'mapping write failure bubbles up');
j3_check($before === j3_counts($pdo) && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_import_sessions WHERE idempotency_key = 'j3-map-fail' AND status = 'failed_recoverable'")->fetchColumn() === 1, 'mapping failure rolls back Commerce product and CJ mapping together while retaining failure evidence');
j3_throws(static fn () => $importer->importDraft(['pid' => 'CJ100003', 'vids' => [], 'idempotency_key' => 'j3-no-sku'], 7), 'rejects import without selected SKU');
j3_check($calc->calculate(1234, 'USD', ['fixed_markup_minor' => '500', 'ending' => '99'])['price_minor'] === 1799, 'pricing fixed markup and .99 ending use integer math');
j3_check($calc->calculate(1000, 'USD', ['percent_markup_bps' => '2500'])['price_minor'] === 1250, 'pricing percentage markup uses integer basis points');
j3_check($calc->calculate(1000, 'USD', ['target_margin_bps' => '5000'])['price_minor'] === 2000, 'pricing target margin uses integer division');
foreach ([['target_margin_bps' => '10000'], ['fixed_markup_minor' => '-1'], ['percent_markup_bps' => '1.5']] as $bad) {
    j3_throws(static fn () => $calc->calculate(1000, 'USD', $bad), 'rejects invalid pricing boundary');
}

$mediaTransport = new CjFixtureMediaTransport();
$mediaTransport->add('https://img.cjdropshipping.com/fixture/lamp-primary.png', 200, ['Content-Type' => 'image/png'], j3_png());
$mediaTransport->add('https://img.cjdropshipping.com/fixture/lamp-gallery.png', 200, ['Content-Type' => 'image/png'], j3_png() . 'x');
$localizer = new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $mediaTransport, 1048576, new CatalogRepository($pdo));
$localized = $localizer->processQueued();
j3_check($localized['completed'] === 2 && (int) $pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn() === 1, 'Fixture media localizes through Core Media after import transaction with content-hash dedupe');
j3_check((int) $pdo->query('SELECT COALESCE(main_media_id, 0) FROM cms_commerce_products WHERE id = ' . $productId)->fetchColumn() > 0 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_product_media WHERE product_id = ' . $productId)->fetchColumn() >= 2, 'localized CJ media is attached to Commerce draft main image and gallery');
$pdo->exec('DELETE FROM cms_commerce_product_media WHERE product_id = ' . $productId);
$pdo->exec('UPDATE cms_commerce_products SET main_media_id = NULL WHERE id = ' . $productId);
$repaired = $localizer->repairCompletedAttachments();
j3_check($repaired >= 2 && (int) $pdo->query('SELECT COALESCE(main_media_id, 0) FROM cms_commerce_products WHERE id = ' . $productId)->fetchColumn() > 0 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_product_media WHERE product_id = ' . $productId)->fetchColumn() >= 2, 'completed CJ media jobs can repair missing Commerce image attachments');
$repo->enqueueMediaJobs([['import_session_id' => (int) $result['import_session_id'], 'commerce_product_id' => $productId, 'cj_pid' => 'CJ100001', 'source_url' => 'https://img.cjdropshipping.com/missing.png', 'role' => 'gallery']]);
(new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), new CjFixtureMediaTransport()))->processQueued();
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_media_jobs WHERE source_url_hash = '" . hash('sha256', 'https://img.cjdropshipping.com/missing.png') . "' AND status = 'partial'")->fetchColumn() === 1, 'missing media fixture records partial failure without real network request');
$repo->enqueueMediaJobs([['import_session_id' => (int) $result['import_session_id'], 'commerce_product_id' => $productId, 'cj_pid' => 'CJ100001', 'source_url' => 'http://img.cjdropshipping.com/bad.png', 'role' => 'gallery']]);
(new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $mediaTransport))->processQueued();
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_media_jobs WHERE status = 'partial'")->fetchColumn() >= 1, 'non-HTTPS gallery image fails as partial without deleting draft');
$repo->enqueueMediaJobs([['import_session_id' => (int) $result['import_session_id'], 'commerce_product_id' => $productId, 'cj_pid' => 'CJ100001', 'source_url' => 'https://img.cjdropshipping.com/private.png', 'role' => 'primary']]);
$private = new CjFixtureMediaTransport();
$private->add('https://img.cjdropshipping.com/private.png', 200, ['Content-Type' => 'image/png'], j3_png(), null, ['127.0.0.1']);
(new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $private))->processQueued();
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_media_jobs WHERE status = 'failed_primary'")->fetchColumn() >= 1 && (string) $pdo->query('SELECT status FROM cms_commerce_products WHERE id = ' . $productId)->fetchColumn() === 'draft', 'primary image SSRF failure keeps product draft');
$repo->enqueueMediaJobs([['import_session_id' => (int) $result['import_session_id'], 'commerce_product_id' => $productId, 'cj_pid' => 'CJ100001', 'source_url' => 'https://img.cjdropshipping.com/fake.jpg', 'role' => 'gallery']]);
$fake = new CjFixtureMediaTransport();
$fake->add('https://img.cjdropshipping.com/fake.jpg', 200, ['Content-Type' => 'image/jpeg'], '<?php');
(new CjMediaLocalizer($repo, new MediaLibrary($pdo, $root . '/content/uploads'), $fake))->processQueued();
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_media_jobs WHERE error_code = 'media_failed'")->fetchColumn() >= 2, 'fake MIME or damaged image is rejected safely');

$repo->purgeExpiredCatalogCache();
$pdo->exec("INSERT INTO cms_cj_catalog_cache (cache_key, operation, normalized_params_json, payload_hash, payload_json, schema_version, expires_at, fetched_at, created_at, updated_at) VALUES ('expired','search','{}','h','{}','j2.v1','2000-01-01T00:00:00+00:00','now','now','now')");
j3_check($repo->purgeExpiredCatalogCache() === 1, 'expired J2 catalog cache is purged and treated as miss');

$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j3-static-test-key')))->bootEnabled();
$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
j3_check(isset($routes['POST /admin/cj/import']) && $routes['POST /admin/cj/import']->capability === 'cj.products.import' && $routes['POST /admin/cj/import']->csrf, 'import route uses POST, cj.products.import and CSRF');
$bodyBeforeForbidden = (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_products')->fetchColumn();
$forbidden = $routes['POST /admin/cj/import']->handler->__invoke(new Cms\Core\Http\Request('POST', '/admin/cj/import', [], ['pid' => 'CJX', 'vids' => ['VIDX']], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 7, ['cj.catalog.search'], 'c', 'r', '127.0.0.1')]));
j3_check($forbidden->status() === 403 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_products')->fetchColumn() === $bodyBeforeForbidden, 'missing cj.products.import capability creates no data');

$installer = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo);
$installer->disableWithDependents('official.cj-dropshipping', 7, true);
j3_check(j3_table($pdo, 'cms_cj_product_mappings') && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_product_mappings')->fetchColumn() === 1, 'ordinary disable preserves CJ mappings');
$repo->purgeOwnData('PURGE official.cj-dropshipping');
j3_check((int) $pdo->query('SELECT COUNT(*) FROM cms_cj_product_mappings')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_products')->fetchColumn() >= 1, 'permanent CJ purge deletes CJ data but not Commerce products');
j3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation LIKE 'order.%'")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_orders")->fetchColumn() === 0, 'J3 creates no CJ orders, payments, webhooks or fulfillment records');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j3_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j3_core($mysql);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.commerce', 1, true);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.cj-dropshipping', 1, true);
            $mysqlResult = j3_importer($mysql)->importDraft(['pid' => 'CJ100001', 'vids' => ['VID100001'], 'idempotency_key' => 'mysql-j3', 'pricing_rule' => ['fixed_markup_minor' => '100']], 9);
            j3_check((string) $mysql->query('SELECT status FROM cms_commerce_products WHERE id = ' . (int) $mysqlResult['commerce_product_id'])->fetchColumn() === 'draft' && j3_table($mysql, 'cms_cj_import_sessions'), 'real MySQL/MariaDB imports CJ product as Commerce draft and creates J3 schema');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $e) {
        $mysqlStatus = 'failed';
        j3_check(false, 'MySQL J3 import validation failed: ' . $e->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j3_check(false, 'pdo_mysql extension is required for CJ J3 MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;

j3_remove($root);
if ($failures > 0) {
    echo '[RESULT] CJ J3 import checks failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] CJ J3 import checks passed.' . PHP_EOL;
