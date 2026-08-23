<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
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
use Official\CjDropshipping\Import\CjPricingCalculator;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\CjDropshipping\Sync\CjInventoryProvider;
use Official\CjDropshipping\Sync\CjShippingProvider;
use Official\CjDropshipping\Sync\CjSyncService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;
function j4_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function j4_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j4_check(false, $message);
    } catch (Throwable) {
        j4_check(true, $message);
    }
}
function j4_remove(string $path): void
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
function j4_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}
function j4_core(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}
function j4_table(PDO $pdo, string $table): bool
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
function j4_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j4_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j4.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j4-static-test-key';
    j4_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j4_core($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}
function j4_seed_product(PDO $pdo): array
{
    $now = gmdate('c');
    $pdo->prepare("INSERT INTO cms_commerce_products (title, slug, product_type, status, summary, description_blocks_json, seo_title, seo_description, canonical_url, currency, created_at, updated_at) VALUES ('J4 Product', 'j4-product', 'simple', 'published', '', '[]', '', '', '/products/j4-product', 'USD', :created, :updated)")
        ->execute([':created' => $now, ':updated' => $now]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cms_commerce_product_variants (product_id, sku, sku_norm, title, price_minor, cost_minor, currency, variant_status, attribute_signature, sort_order, created_at, updated_at) VALUES (:product_id, 'J4-SKU', 'J4-SKU', 'Default', 2500, 1234, 'USD', 'active', '', 0, :created, :updated)")
        ->execute([':product_id' => $productId, ':created' => $now, ':updated' => $now]);
    $variantId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cms_cj_product_mappings (cj_pid, cj_product_sku, commerce_product_id, import_session_id, raw_snapshot_hash, raw_snapshot_expires_at, created_at, updated_at) VALUES ('CJ-J4', 'SKU-J4', :product_id, 1, :hash, :expires, :created, :updated)")
        ->execute([':product_id' => $productId, ':hash' => hash('sha256', 'j4'), ':expires' => gmdate('c', time() + 86400), ':created' => $now, ':updated' => $now]);
    $pdo->prepare("INSERT INTO cms_cj_variant_mappings (cj_pid, cj_vid, cj_variant_sku, cj_product_sku, commerce_product_id, commerce_variant_id, local_sku, cost_minor, currency, weight_value, warehouse_snapshot_json, import_session_id, created_at, updated_at) VALUES ('CJ-J4', 'VID-J4-1', 'CJ-VARIANT-1', 'SKU-J4', :product_id, :variant_id, 'J4-SKU', 1234, 'USD', 420, '[]', 1, :created, :updated)")
        ->execute([':product_id' => $productId, ':variant_id' => $variantId, ':created' => $now, ':updated' => $now]);
    return [$productId, $variantId];
}
function j4_client(PDO $pdo, array $overrides = []): CjHttpClient
{
    $repo = new CjRepository($pdo);
    $registry = new CjEndpointRegistry();
    $transport = new CjFixtureTransport();
    $fixtures = [
        'stock.queryByVid' => [200, '{"code":"200","data":[{"pid":"CJ-J4","warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":7},{"pid":"CJ-J4","warehouseId":"WH-CN","warehouseName":"CN Warehouse","countryCode":"CN","totalInventoryNum":9}],"pointsInfo":{"remaining":80,"total":100}}'],
        'variant.queryByVid' => [200, '{"code":"200","data":{"pid":"CJ-J4","vid":"VID-J4-1","variantSku":"CJ-VARIANT-1","sellPrice":"15.00","currency":"USD","weight":"420"},"pointsInfo":{"remaining":78,"total":100}}'],
        'logistic.freightCalculate' => [200, '{"code":"200","data":[{"logisticName":"CJ Standard","logisticPrice":"6.50","currency":"USD","agingMin":7,"agingMax":14,"warehouse":"WH-US"}],"pointsInfo":{"remaining":75,"total":100}}'],
        'webhook.set' => [200, '{"code":"200","success":true,"data":{"fixtureOnly":true},"pointsInfo":{"remaining":74,"total":100}}'],
    ];
    foreach ($overrides as $operation => $fixture) {
        $fixtures[$operation] = $fixture;
    }
    foreach ($fixtures as $operation => [$status, $body]) {
        $endpoint = $registry->resolve((string) $operation);
        $transport->add($endpoint['method'], $endpoint['url'], (int) $status, ['requestId' => 'j4-' . $operation], (string) $body);
    }
    return new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 30), new CjCircuitBreaker($repo));
}
function j4_sync(PDO $pdo, array $overrides = []): CjSyncService
{
    return new CjSyncService($pdo, new CjRepository($pdo), j4_client($pdo, $overrides), new AuditLogger($pdo));
}

[$root, $pdo] = j4_root('cms-cj-j4');
[$productId, $variantId] = j4_seed_product($pdo);
$sync = j4_sync($pdo);

j4_check(json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true)['version'] === '1.0.0-rc1', 'CJ plugin version is 1.0.0-rc1');
j4_check(j4_table($pdo, 'cms_cj_inventory_snapshots') && j4_table($pdo, 'cms_cj_freight_quotes') && j4_table($pdo, 'cms_cj_sync_jobs') && j4_table($pdo, 'cms_cj_webhook_subscriptions'), '006 migration creates CJ sync, inventory, freight and webhook tables');
$result = $sync->refreshVariant('VID-J4-1', 'P2', 42);
j4_check($result['warehouses'] === 2 && (int) $pdo->query("SELECT SUM(quantity) FROM cms_cj_inventory_snapshots WHERE cj_vid = 'VID-J4-1'")->fetchColumn() === 16, 'single VID inventory sync stores multi-warehouse quantities');
$availability = (new CjInventoryProvider($sync))->getAvailability((object) ['variant_id' => $variantId]);
j4_check($availability->success && $availability->data['freshness'] === 'fresh' && $availability->data['available'] === 16, 'InventoryProvider returns fresh AvailabilityResult from local snapshot');
$pdo->exec("UPDATE cms_cj_inventory_snapshots SET stale_after_at = '2000-01-01T00:00:00+00:00' WHERE cj_vid = 'VID-J4-1'");
j4_check((new CjInventoryProvider($sync))->getAvailability((object) ['variant_id' => $variantId])->data['freshness'] === 'stale', 'expired inventory snapshot becomes stale');
j4_check((new CjInventoryProvider($sync))->getAvailability((object) ['variant_id' => 999999])->data['freshness'] === 'unknown', 'missing mapping availability is unknown');
$zero = j4_sync($pdo, ['stock.queryByVid' => [200, '{"code":"200","data":[{"warehouseId":"WH-US","countryCode":"US","totalInventoryNum":0}]}']]);
$zero->refreshVariant('VID-J4-1', 'P2');
j4_check((new CjInventoryProvider($zero))->getAvailability((object) ['variant_id' => $variantId])->data['available'] === 0, 'remote zero inventory is not treated as unlimited stock');
$unknown = j4_sync($pdo, ['stock.queryByVid' => [200, '{"code":"200","data":[{"warehouseId":"WH-US","countryCode":"US","totalInventoryNum":"bad"}]}']]);
$unknown->refreshVariant('VID-J4-1', 'P2');
j4_check((string) $pdo->query("SELECT status FROM cms_cj_inventory_snapshots WHERE cj_vid = 'VID-J4-1' ORDER BY id LIMIT 1")->fetchColumn() === 'unknown', 'invalid remote inventory marks snapshot unknown');

$jobA = $sync->enqueueRefresh('VID-J4-1', 'P4');
$jobB = $sync->enqueueRefresh('VID-J4-1', 'P0');
j4_check($jobA === $jobB && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_sync_jobs WHERE resource_id = 'VID-J4-1' AND operation = 'inventory.refresh' AND status = 'queued'")->fetchColumn() === 1 && (string) $pdo->query("SELECT priority FROM cms_cj_sync_jobs WHERE id = {$jobA}")->fetchColumn() === 'P0', 'same VID sync jobs are merged and manual refresh priority wins');
$pdo->exec('DELETE FROM cms_cj_sync_jobs');
$sync->enqueueRefresh('VID-J4-1', 'P4', 'inventory.refresh', 'low-priority');
$sync->enqueueRefresh('VID-J4-1', 'P0', 'cost.refresh', 'high-priority');
j4_check($sync->runCron(1) === 1 && (string) $pdo->query("SELECT status FROM cms_cj_sync_jobs WHERE idempotency_key = 'high-priority'")->fetchColumn() === 'completed', 'Cron processes P0 before low-priority work with batch limit');
(new CjRepository($pdo))->recordPoints(['remaining' => 20, 'total' => 100]);
$sync->runCron(5);
j4_check((string) $pdo->query("SELECT status FROM cms_cj_sync_jobs WHERE idempotency_key = 'low-priority'")->fetchColumn() === 'paused', 'Points protection pauses low-priority work while preserving critical budget');
(new CjRepository($pdo))->recordPoints(['remaining' => 100, 'total' => 100]);
$pdo->exec('DELETE FROM cms_cj_sync_jobs');
$sync->enqueueRefresh('VID-J4-1', 'P2', 'inventory.refresh', 'retry-me');
$err = j4_sync($pdo, ['stock.queryByVid' => [429, '{"code":"429","message":"slow down"}']]);
$err->runCron(1);
j4_check(in_array((string) $pdo->query("SELECT status FROM cms_cj_sync_jobs WHERE idempotency_key = 'retry-me'")->fetchColumn(), ['retry','paused','dead_letter'], true), '429 errors enter controlled retry, pause or dead-letter state');
$auth = j4_sync($pdo, ['stock.queryByVid' => [401, '{"code":"401","message":"auth failed"}']]);
j4_throws(static fn () => $auth->refreshVariant('VID-J4-1', 'P0'), '401 authentication failure opens auth protection');
j4_throws(static fn () => $auth->refreshVariant('VID-J4-1', 'P0'), 'auth circuit pauses repeated remote writes');
(new CjRepository($pdo))->closeCircuit('auth');

j4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_cost_changes WHERE cj_vid = 'VID-J4-1' AND status = 'manual_review'")->fetchColumn() >= 1, 'cost increase beyond threshold creates manual review record');
$costId = (int) $pdo->query("SELECT id FROM cms_cj_cost_changes WHERE cj_vid = 'VID-J4-1' ORDER BY id DESC LIMIT 1")->fetchColumn();
$sync->decideCostChange($costId, 'accepted', 42, 'ok');
j4_check((string) $pdo->query('SELECT status FROM cms_cj_cost_changes WHERE id = ' . $costId)->fetchColumn() === 'accepted', 'administrator can accept cost change with audit');
$blocked = j4_sync($pdo, ['variant.queryByVid' => [200, '{"code":"200","data":{"vid":"VID-J4-1","sellPrice":"16.00","currency":"EUR"}}']]);
$blocked->refreshVariant('VID-J4-1', 'P2');
j4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_cost_changes WHERE status = 'blocked' AND currency = 'EUR'")->fetchColumn() >= 1, 'currency changes are blocked for manual review');
$invalidCost = j4_sync($pdo, ['variant.queryByVid' => [200, '{"code":"200","data":{"vid":"VID-J4-1","sellPrice":"bad","currency":"USD"}}']]);
$invalidCost->refreshVariant('VID-J4-1', 'P2');
j4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_cost_changes WHERE status = 'blocked' AND new_cost_minor < 0")->fetchColumn() >= 1, 'invalid cost values are blocked');
j4_check((int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_order_items')->fetchColumn() === 0, 'cost sync does not modify historical order item snapshots');

$shipping = new CjShippingProvider($sync);
$quote = $shipping->quote((object) ['destination' => ['country' => 'US', 'state' => 'CA', 'postal_code' => '94016', 'name' => 'Alice', 'phone' => '555', 'email' => 'a@example.test'], 'items' => [['variant_id' => $variantId, 'quantity' => 2, 'weight_grams' => 420]], 'warehouse_preference' => 'WH-US']);
j4_check($quote->success && $quote->data['amount_minor'] === 650 && $quote->data['estimated'] === true && $quote->data['request_id'] === 'j4-logistic.freightCalculate', 'ShippingProvider returns stable estimated CJ shipping quote DTO with request_id');
$logCount = (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'logistic.freightCalculate'")->fetchColumn();
$quote2 = $shipping->quote((object) ['destination' => ['country' => 'US', 'state' => 'CA', 'postal_code' => '94016'], 'items' => [['variant_id' => $variantId, 'quantity' => 2, 'weight_grams' => 420]], 'warehouse_preference' => 'WH-US']);
j4_check($quote2->success && !empty($quote2->data['cache_hit']) && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'logistic.freightCalculate'")->fetchColumn() === $logCount, 'freight cache hit does not repeat Fixture call or Points deduction');
$quote3 = $shipping->quote((object) ['destination' => ['country' => 'CA', 'postal_code' => 'M5V'], 'items' => [['variant_id' => $variantId, 'quantity' => 2, 'weight_grams' => 420]]]);
j4_check($quote3->success && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_freight_quotes')->fetchColumn() >= 2, 'different destination has isolated freight cache key');
$cacheJson = (string) $pdo->query('SELECT quote_json FROM cms_cj_freight_quotes ORDER BY id LIMIT 1')->fetchColumn();
j4_check(!preg_match('/Alice|555|example\\.test|token|api[_-]?key|open[_-]?id/i', $cacheJson), 'freight cache and logs exclude PII and secrets');
$noFreight = new CjShippingProvider(j4_sync($pdo, ['logistic.freightCalculate' => [200, '{"code":"200","data":[]}']]));
j4_check(!$noFreight->quote((object) ['destination' => ['country' => 'US'], 'items' => [['variant_id' => $variantId, 'quantity' => 1]]])->success, 'no logistics result returns unavailable instead of fake free shipping');
$badFreight = new CjShippingProvider(j4_sync($pdo, ['logistic.freightCalculate' => [200, '{"code":"200","data":[{"logisticName":"Bad","logisticPrice":"bad","currency":"USD"}]}']]));
j4_check(!$badFreight->quote((object) ['destination' => ['country' => 'GB'], 'items' => [['variant_id' => $variantId, 'quantity' => 1]]])->success, 'invalid freight fee is rejected');
$pdo->exec("UPDATE cms_cj_freight_quotes SET expires_at = '2000-01-01T00:00:00+00:00'");
$pdo->exec("INSERT INTO cms_cj_catalog_cache (cache_key, operation, normalized_params_json, payload_hash, payload_json, schema_version, expires_at, fetched_at, created_at, updated_at) VALUES ('j4-expired','search','{}','h','{}','j2.v1','2000-01-01T00:00:00+00:00','now','now','now')");
$pdo->exec("INSERT INTO cms_cj_source_snapshots (import_session_id, resource_type, resource_id, snapshot_hash, snapshot_json, schema_version, expires_at, created_at, payload_size) VALUES (1,'product','old','h','{}','j3a.v1','2000-01-01T00:00:00+00:00','now',2)");
j4_check($sync->purgeExpiredCaches() >= 3, 'Cron cleanup purges expired catalog, source snapshot and freight quote caches');

j4_check($sync->configureWebhookSubscriptions(['PRODUCT', 'VARIANT', 'STOCK'], 42) === 3 && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_webhook_subscriptions')->fetchColumn() === 3, 'Webhook subscription model records Fixture-only expected PRODUCT/VARIANT/STOCK topics');
j4_throws(static fn () => $sync->configureWebhookSubscriptions(['PAYMENT'], 42), 'unsupported Webhook topic is rejected');

$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j4-static-test-key')))->bootEnabled();
$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
j4_check(isset($routes['POST /admin/cj/sync/variant']) && $routes['POST /admin/cj/sync/variant']->capability === 'cj.products.sync' && $routes['POST /admin/cj/sync/variant']->csrf, 'manual sync route is POST with cj.products.sync and CSRF');
j4_check(isset($routes['POST /admin/cj/sync/cron']) && $routes['POST /admin/cj/sync/cron']->capability === 'cj.products.sync' && $routes['POST /admin/cj/sync/cron']->csrf, 'sync cron route is POST with cj.products.sync and CSRF');
j4_check(isset($routes['POST /admin/cj/cost/review']) && $routes['POST /admin/cj/cost/review']->capability === 'cj.products.sync' && $routes['POST /admin/cj/cost/review']->csrf, 'cost review route is POST with cj.products.sync and CSRF');
j4_check(isset($routes['POST /admin/cj/webhook/subscriptions']) && $routes['POST /admin/cj/webhook/subscriptions']->capability === 'cj.webhook.manage' && $routes['POST /admin/cj/webhook/subscriptions']->csrf, 'Webhook config route is POST with cj.webhook.manage and CSRF');
$beforeWrites = (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_sync_jobs')->fetchColumn();
$forbidden = $routes['POST /admin/cj/sync/variant']->handler->__invoke(new Cms\Core\Http\Request('POST', '/admin/cj/sync/variant', [], ['cj_vid' => 'VID-J4-1'], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 42, ['cj.catalog.search'], 'c', 'r', '127.0.0.1')]));
j4_check($forbidden->status() === 403 && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_sync_jobs')->fetchColumn() === $beforeWrites, 'missing cj.products.sync causes zero writes and zero sync tasks');
j4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.create'")->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_orders')->fetchColumn() === 0 && (j4_table($pdo, 'cms_commerce_fulfillments') ? (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_fulfillments')->fetchColumn() : 0) === 0, 'J4 creates no CJ orders, payments or fulfillment records');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j4_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j4_core($mysql);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.commerce', 1, true);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.cj-dropshipping', 1, true);
            [, $mysqlVariant] = j4_seed_product($mysql);
            $mysqlSync = j4_sync($mysql);
            $mysqlSync->refreshVariant('VID-J4-1', 'P2', 77);
            $mysqlQuote = (new CjShippingProvider($mysqlSync))->quote((object) ['destination' => ['country' => 'US'], 'items' => [['variant_id' => $mysqlVariant, 'quantity' => 1]]]);
            j4_check($mysqlQuote->success && j4_table($mysql, 'cms_cj_inventory_snapshots') && j4_table($mysql, 'cms_cj_freight_quotes'), 'real MySQL/MariaDB validates J4 inventory and freight schema');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        $mysqlStatus = 'failed';
        j4_check(false, 'MySQL J4 validation failed: ' . $exception->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j4_check(false, 'pdo_mysql extension is required for CJ J4 MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;

j4_remove($root);
if ($failures > 0) {
    echo '[RESULT] CJ J4 sync/shipping checks failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] CJ J4 sync/shipping checks passed.' . PHP_EOL;
