<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
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
use Official\CjDropshipping\Fulfillment\CjSandboxFulfillmentProvider;
use Official\CjDropshipping\Fulfillment\CjSandboxFulfillmentService;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\CjDropshipping\Sync\CjSyncService;
use Official\Commerce\Application\CartCommand;
use Official\Commerce\Application\CartService;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CategoryCommand;
use Official\Commerce\Application\CheckoutService;
use Official\Commerce\Application\InventoryAdjustmentCommand;
use Official\Commerce\Application\InventoryService;
use Official\Commerce\Application\PaymentService;
use Official\Commerce\Infrastructure\FlatRateShippingProvider;
use Official\Commerce\Infrastructure\NoTaxCalculator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CartRepository;
use Official\Commerce\Repository\CatalogRepository;
use Official\Commerce\Repository\InventoryRepository;
use Official\Commerce\Repository\OrderRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;
function j5_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function j5_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j5_check(false, $message);
    } catch (Throwable) {
        j5_check(true, $message);
    }
}
function j5_remove(string $path): void
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
function j5_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}
function j5_core(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}
function j5_table(PDO $pdo, string $table): bool
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
function j5_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j5_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j5.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j5-static-test-key';
    j5_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j5_core($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}
function j5_address(): array
{
    return ['full_name' => 'J5 Buyer', 'email' => 'j5@example.test', 'phone' => '5550105', 'line1' => '5 Test St', 'city' => 'Boston', 'region' => 'MA', 'postal_code' => '02110', 'country_code' => 'US'];
}
function j5_services(PDO $pdo): array
{
    $outbox = new OutboxEventRepository($pdo);
    $audit = new AuditLogger($pdo);
    $catalogRepo = new CatalogRepository($pdo);
    $cartRepo = new CartRepository($pdo);
    $inventoryRepo = new InventoryRepository($pdo);
    $orderRepo = new OrderRepository($pdo);
    return [
        new CatalogService($pdo, $catalogRepo, new CatalogValidator(), $outbox, $audit),
        new InventoryService($pdo, $inventoryRepo, $outbox, $audit),
        new CartService($pdo, $cartRepo, $outbox),
        $orderRepo,
        new CheckoutService($pdo, $cartRepo, $inventoryRepo, $orderRepo, new FlatRateShippingProvider(500, null), new NoTaxCalculator(), $outbox, $audit),
        new PaymentService($pdo, $orderRepo, $inventoryRepo, $outbox, $audit),
    ];
}
function j5_paid_order(PDO $pdo, string $suffix = 'A', bool $pay = true): array
{
    [$catalog, $inventory, $cart, , $checkout, $payment] = j5_services($pdo);
    $category = $catalog->saveCategory(null, new CategoryCommand('J5 Category ' . $suffix, 'j5-category-' . strtolower($suffix)), 1);
    $productId = $catalog->create(new CatalogCommand('J5 Product ' . $suffix, 'j5-product-' . strtolower($suffix), 'simple', 'J5 summary', [['type' => 'paragraph', 'data' => ['text' => 'Safe J5 description']]], 'J5 SEO', 'J5 SEO description', 'USD', '', null, [['sku' => 'J5-SKU-' . $suffix, 'price_minor' => 1200, 'cost_minor' => 700, 'variant_status' => 'active', 'attribute_signature' => '']], [], [(int) $category], [], 'j5-create-' . $suffix), 1);
    $catalog->publish($productId, 1, 'j5-publish-' . $suffix);
    $variantId = (int) $pdo->query('SELECT id FROM cms_commerce_product_variants WHERE product_id = ' . $productId . ' LIMIT 1')->fetchColumn();
    $inventory->adjust(new InventoryAdjustmentCommand($variantId, 5, 'manual_adjustment', 'J5 stock', 'j5-stock-' . $suffix), 1);
    $state = $cart->getOrCreate(null);
    $carted = $cart->add($state['token'], new CartCommand($variantId, 1, (int) $state['cart']['version'], 'j5-cart-' . $suffix));
    $quote = $checkout->quote($state['token'], (int) $carted['cart']['version'], j5_address());
    $placed = $checkout->placeOrder($state['token'], (int) $carted['cart']['version'], j5_address(), (string) $quote['quote_hash'], 'j5-order-' . $suffix);
    if ($pay) {
        $payment->captureManual((int) $placed['order']['id'], (int) $placed['order']['total_minor'], 'USD', 'J5PAY-' . $suffix, 'j5-pay-' . $suffix, 1);
    }
    return [$productId, $variantId, (int) $placed['order']['id']];
}
function j5_seed_mapping(PDO $pdo, int $productId, int $variantId, string $vid = 'VID-J5-1'): void
{
    $now = gmdate('c');
    $pdo->prepare("INSERT INTO cms_cj_product_mappings (cj_pid, cj_product_sku, commerce_product_id, import_session_id, raw_snapshot_hash, raw_snapshot_expires_at, created_at, updated_at) VALUES (:pid, 'SKU-J5', :product_id, 1, :hash, :expires, :created, :updated)")
        ->execute([':pid' => 'CJ-J5-' . $productId, ':product_id' => $productId, ':hash' => hash('sha256', 'j5' . $productId), ':expires' => gmdate('c', time() + 86400), ':created' => $now, ':updated' => $now]);
    $pdo->prepare("INSERT INTO cms_cj_variant_mappings (cj_pid, cj_vid, cj_variant_sku, cj_product_sku, commerce_product_id, commerce_variant_id, local_sku, cost_minor, currency, weight_value, weight_unit, warehouse_snapshot_json, import_session_id, created_at, updated_at) VALUES (:pid, :vid, 'CJ-VARIANT-J5', 'SKU-J5', :product_id, :variant_id, 'J5-SKU', 700, 'USD', 420, 'g', '[]', 1, :created, :updated)")
        ->execute([':pid' => 'CJ-J5-' . $productId, ':vid' => $vid, ':product_id' => $productId, ':variant_id' => $variantId, ':created' => $now, ':updated' => $now]);
}
function j5_client(PDO $pdo, array $overrides = []): CjHttpClient
{
    $repo = new CjRepository($pdo);
    $registry = new CjEndpointRegistry();
    $transport = new CjFixtureTransport();
    $fixtures = [
        'stock.queryByVid' => [200, '{"code":"200","data":[{"pid":"CJ-J5","warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":8}],"pointsInfo":{"remaining":80,"total":100}}'],
        'variant.queryByVid' => [200, '{"code":"200","data":{"vid":"VID-J5-1","sellPrice":"7.00","currency":"USD"},"pointsInfo":{"remaining":78,"total":100}}'],
        'logistic.freightCalculate' => [200, '{"code":"200","data":[{"logisticName":"CJ Standard","logisticPrice":"6.50","currency":"USD","agingMin":7,"agingMax":14,"warehouse":"WH-US"}],"pointsInfo":{"remaining":75,"total":100}}'],
        'order.create' => [200, '{"code":"200","success":true,"data":{"orderId":"CJ-SANDBOX-ORDER","shipmentOrderId":"CJ-SANDBOX-SHIP","sandbox":true},"pointsInfo":{"remaining":65,"total":100}}'],
    ];
    foreach ($overrides as $operation => $fixture) {
        $fixtures[$operation] = $fixture;
    }
    foreach ($fixtures as $operation => [$status, $body]) {
        $endpoint = $registry->resolve((string) $operation);
        $transport->add($endpoint['method'], $endpoint['url'], (int) $status, ['requestId' => 'j5-' . $operation], (string) $body);
    }
    return new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 30), new CjCircuitBreaker($repo));
}
function j5_cj(PDO $pdo, array $overrides = []): CjSandboxFulfillmentService
{
    $repo = new CjRepository($pdo);
    $client = j5_client($pdo, $overrides);
    $sync = new CjSyncService($pdo, $repo, $client, new AuditLogger($pdo));
    return new CjSandboxFulfillmentService($pdo, $repo, new OrderRepository($pdo), $sync, new CjSandboxFulfillmentProvider($client), new AuditLogger($pdo));
}

[$root, $pdo] = j5_root('cms-cj-j5');
[$productId, $variantId, $orderId] = j5_paid_order($pdo, 'A', true);
j5_seed_mapping($pdo, $productId, $variantId);
$service = j5_cj($pdo);
(new CjSyncService($pdo, new CjRepository($pdo), j5_client($pdo), new AuditLogger($pdo)))->refreshVariant('VID-J5-1', 'P2', 1);

j5_check(json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true)['version'] === '1.0.0-rc1', 'CJ plugin version is 1.0.0-rc1');
j5_check(j5_table($pdo, 'cms_cj_fulfillment_links') && j5_table($pdo, 'cms_cj_fulfillment_attempts'), '007 migration creates CJ fulfillment link and attempt tables');
$link = $service->consumePaidOrderEvent($orderId, 'paid-event-1');
j5_check((string) $link['status'] === 'pending_admin_review', 'paid order event creates pending CJ admin review link');
$again = $service->consumePaidOrderEvent($orderId, 'paid-event-1');
j5_check((int) $again['id'] === (int) $link['id'] && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_fulfillment_links WHERE commerce_order_id = ' . $orderId)->fetchColumn() === 1, 'duplicate paid event produces one CJ fulfillment link');
$submitted = $service->submitSandbox($orderId, 7, 'cj:create-order:' . $orderId . ':1');
j5_check((string) $submitted['status'] === 'awaiting_supplier_payment' && (string) $submitted['cj_order_id'] === 'CJ-SANDBOX-ORDER', 'Sandbox submit creates CJ order link with isSandbox fixture result');
j5_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.create'")->fetchColumn() === 1, 'Sandbox submit calls only fixture order.create once');
$repeat = $service->submitSandbox($orderId, 7, 'cj:create-order:' . $orderId . ':1');
j5_check((int) $repeat['id'] === (int) $submitted['id'] && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_fulfillment_attempts')->fetchColumn() === 1, 'repeated Sandbox submit idempotently returns existing link');
$paid = $service->markSandboxSupplierPaid((int) $submitted['id'], 7, 'cj:sandbox-pay:' . (int) $submitted['id'] . ':1');
j5_check((string) $paid['status'] === 'supplier_paid' && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_payments WHERE provider_id LIKE 'cj%'")->fetchColumn() === 0, 'Sandbox supplier payment is separated from local customer payment records');
j5_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action LIKE 'cj.fulfillment.%'")->fetchColumn() >= 2, 'CJ fulfillment writes administrator audit logs');

[, , $unpaidOrderId] = j5_paid_order($pdo, 'B', false);
j5_throws(static fn () => $service->consumePaidOrderEvent($unpaidOrderId, 'unpaid'), 'unpaid Commerce order is not submitted to CJ');
[$noMapProduct, , $noMapOrder] = j5_paid_order($pdo, 'C', true);
$service->consumePaidOrderEvent($noMapOrder, 'no-map');
j5_throws(static fn () => $service->submitSandbox($noMapOrder, 7, 'cj:create-order:' . $noMapOrder . ':1'), 'order without CJ VID mapping cannot submit to CJ Sandbox');
[$costProduct, $costVariant, $costOrder] = j5_paid_order($pdo, 'D', true);
j5_seed_mapping($pdo, $costProduct, $costVariant, 'VID-J5-COST');
(new CjSyncService($pdo, new CjRepository($pdo), j5_client($pdo), new AuditLogger($pdo)))->refreshVariant('VID-J5-COST', 'P2', 1);
$pdo->prepare("INSERT INTO cms_cj_cost_changes (cj_vid, commerce_variant_id, old_cost_minor, new_cost_minor, currency, delta_minor, delta_bps, status, observed_at, source_hash, created_at, updated_at) VALUES ('VID-J5-COST', :variant, 700, 1200, 'USD', 500, 7142, 'manual_review', :observed, :hash, :created, :updated)")
    ->execute([':variant' => $costVariant, ':observed' => gmdate('c'), ':hash' => hash('sha256', 'cost'), ':created' => gmdate('c'), ':updated' => gmdate('c')]);
$service->consumePaidOrderEvent($costOrder, 'cost');
j5_throws(static fn () => $service->submitSandbox($costOrder, 7, 'cj:create-order:' . $costOrder . ':1'), 'cost review blocks automatic Sandbox submit');

[$zeroProduct, $zeroVariant, $zeroOrder] = j5_paid_order($pdo, 'E', true);
j5_seed_mapping($pdo, $zeroProduct, $zeroVariant, 'VID-J5-ZERO');
(new CjSyncService($pdo, new CjRepository($pdo), j5_client($pdo, ['stock.queryByVid' => [200, '{"code":"200","data":[{"warehouseId":"WH-US","countryCode":"US","totalInventoryNum":0}]}']]), new AuditLogger($pdo)))->refreshVariant('VID-J5-ZERO', 'P2', 1);
$service->consumePaidOrderEvent($zeroOrder, 'zero');
j5_throws(static fn () => $service->submitSandbox($zeroOrder, 7, 'cj:create-order:' . $zeroOrder . ':1'), 'stale or insufficient CJ inventory blocks Sandbox submit');

[$timeoutProduct, $timeoutVariant, $timeoutOrder] = j5_paid_order($pdo, 'F', true);
j5_seed_mapping($pdo, $timeoutProduct, $timeoutVariant, 'VID-J5-TIMEOUT');
(new CjSyncService($pdo, new CjRepository($pdo), j5_client($pdo), new AuditLogger($pdo)))->refreshVariant('VID-J5-TIMEOUT', 'P2', 1);
$timeoutService = j5_cj($pdo, ['order.create' => [200, '{"code":"timeout","message":"timeout"}']]);
$timeoutService->consumePaidOrderEvent($timeoutOrder, 'timeout');
$unknown = $timeoutService->submitSandbox($timeoutOrder, 7, 'cj:create-order:' . $timeoutOrder . ':1');
j5_check((string) $unknown['status'] === 'unknown', 'CJ timeout marks Sandbox submit unknown instead of assuming no order was created');
j5_throws(static fn () => $timeoutService->submitSandbox($timeoutOrder, 7, 'cj:create-order:' . $timeoutOrder . ':2'), 'unknown CJ submit blocks blind retry before remote query');

$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new Cms\Core\Logging\FileLogger($root . '/storage/logs/plugin.log'), new Cms\Core\Events\EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j5-static-test-key')))->bootEnabled();
$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
j5_check(isset($routes['GET /admin/cj/fulfillment']) && $routes['GET /admin/cj/fulfillment']->capability === 'cj.fulfillment.view', 'CJ fulfillment queue route uses view capability');
j5_check(isset($routes['POST /admin/cj/fulfillment/submit-sandbox']) && $routes['POST /admin/cj/fulfillment/submit-sandbox']->capability === 'cj.fulfillment.submit' && $routes['POST /admin/cj/fulfillment/submit-sandbox']->csrf, 'Sandbox submit route is POST with cj.fulfillment.submit and CSRF');
j5_check(isset($routes['POST /admin/cj/fulfillment/sandbox-paid']) && $routes['POST /admin/cj/fulfillment/sandbox-paid']->capability === 'cj.fulfillment.pay' && $routes['POST /admin/cj/fulfillment/sandbox-paid']->csrf, 'Sandbox supplier payment route is POST with cj.fulfillment.pay and CSRF');
$before = (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_fulfillment_attempts')->fetchColumn();
$forbidden = $routes['POST /admin/cj/fulfillment/submit-sandbox']->handler->__invoke(new Request('POST', '/admin/cj/fulfillment/submit-sandbox', [], ['order_id' => $orderId, 'idempotency_key' => 'forbidden'], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 7, ['cj.fulfillment.view'], 'c', 'r', '127.0.0.1')]));
j5_check($forbidden->status() === 403 && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_fulfillment_attempts')->fetchColumn() === $before, 'missing submit capability creates zero CJ fulfillment writes');
j5_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation NOT IN ('stock.queryByVid','variant.queryByVid','logistic.freightCalculate','order.create')")->fetchColumn() === 0, 'J5 uses only fixture catalog, inventory, freight and Sandbox order operations');
j5_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_fulfillment_links WHERE mode <> 'sandbox'")->fetchColumn() === 0, 'J5 creates only sandbox fulfillment links');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j5_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j5_core($mysql);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.commerce', 1, true);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.cj-dropshipping', 1, true);
            [$mysqlProduct, $mysqlVariant, $mysqlOrder] = j5_paid_order($mysql, 'M', true);
            j5_seed_mapping($mysql, $mysqlProduct, $mysqlVariant);
            (new CjSyncService($mysql, new CjRepository($mysql), j5_client($mysql), new AuditLogger($mysql)))->refreshVariant('VID-J5-1', 'P2', 1);
            $mysqlService = j5_cj($mysql);
            $mysqlService->consumePaidOrderEvent($mysqlOrder, 'mysql-paid');
            $mysqlLink = $mysqlService->submitSandbox($mysqlOrder, 7, 'cj:create-order:' . $mysqlOrder . ':1');
            j5_check(j5_table($mysql, 'cms_cj_fulfillment_links') && (string) $mysqlLink['status'] === 'awaiting_supplier_payment', 'real MySQL/MariaDB validates J5 Sandbox fulfillment schema and submit');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        $mysqlStatus = 'failed';
        j5_check(false, 'MySQL J5 validation failed: ' . $exception->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j5_check(false, 'pdo_mysql extension is required for CJ J5 MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;

j5_remove($root);
if ($failures > 0) {
    echo '[RESULT] CJ J5 sandbox fulfillment checks failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] CJ J5 sandbox fulfillment checks passed.' . PHP_EOL;
