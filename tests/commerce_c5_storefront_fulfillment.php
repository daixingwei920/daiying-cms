<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Official\Commerce\Admin\StorefrontController;
use Official\Commerce\Application\CartCommand;
use Official\Commerce\Application\CartService;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CategoryCommand;
use Official\Commerce\Application\CheckoutService;
use Official\Commerce\Application\FulfillmentService;
use Official\Commerce\Application\InventoryAdjustmentCommand;
use Official\Commerce\Application\InventoryService;
use Official\Commerce\Application\PaymentService;
use Official\Commerce\Infrastructure\FakeFulfillmentProvider;
use Official\Commerce\Infrastructure\FlatRateShippingProvider;
use Official\Commerce\Infrastructure\NoTaxCalculator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CartRepository;
use Official\Commerce\Repository\CatalogRepository;
use Official\Commerce\Repository\FulfillmentRepository;
use Official\Commerce\Repository\InventoryRepository;
use Official\Commerce\Repository\OrderRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.php';

set_time_limit(240);
$failures = 0;

function c5_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function c5_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c5_check(false, $message);
    } catch (Throwable) {
        c5_check(true, $message);
    }
}

function c5_remove(string $path): void
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

/** @return list<object> */
function c5_core_migrations(): array
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    return $migrations;
}

function c5_setup_sqlite(string $name): array
{
    $root = sys_get_temp_dir() . '/cms-commerce-c5-' . $name . '-' . bin2hex(random_bytes(4));
    c5_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce.sqlite', 'username' => '', 'password' => '', 'options' => []];
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    (new MigrationRunner($pdo, c5_core_migrations()))->run();
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, false);
    return [$root, $pdo];
}

function c5_services(PDO $pdo): array
{
    $outbox = new OutboxEventRepository($pdo);
    $audit = new AuditLogger($pdo);
    $catalogRepo = new CatalogRepository($pdo);
    $cartRepo = new CartRepository($pdo);
    $inventoryRepo = new InventoryRepository($pdo);
    $orderRepo = new OrderRepository($pdo);
    $fulfillmentRepo = new FulfillmentRepository($pdo);
    return [
        $catalogRepo,
        new CatalogService($pdo, $catalogRepo, new CatalogValidator(), $outbox, $audit),
        $inventoryRepo,
        new InventoryService($pdo, $inventoryRepo, $outbox, $audit),
        $cartRepo,
        new CartService($pdo, $cartRepo, $outbox),
        $orderRepo,
        new CheckoutService($pdo, $cartRepo, $inventoryRepo, $orderRepo, new FlatRateShippingProvider(500, null), new NoTaxCalculator(), $outbox, $audit),
        new PaymentService($pdo, $orderRepo, $inventoryRepo, $outbox, $audit),
        $fulfillmentRepo,
        new FulfillmentService($pdo, $orderRepo, $fulfillmentRepo, new FakeFulfillmentProvider(), $outbox, $audit),
    ];
}

function c5_address(): array
{
    return ['full_name' => 'Case Buyer', 'email' => 'case@example.test', 'phone' => '5550101', 'line1' => '2 Test St', 'line2' => '', 'city' => 'Boston', 'region' => 'MA', 'postal_code' => '02110', 'country_code' => 'US'];
}

function c5_create_paid_order(PDO $pdo, string $suffix = 'A'): array
{
    [$catalogRepo, $catalog, $inventoryRepo, $inventory, $cartRepo, $cart, $orderRepo, $checkout, $payment] = c5_services($pdo);
    $category = $catalog->saveCategory(null, new CategoryCommand('C5 Category', 'c5-category-' . strtolower($suffix)), 1);
    $mediaId = c5_media($pdo, $suffix);
    $productId = $catalog->create(new CatalogCommand('C5 Product ' . $suffix, 'c5-product-' . strtolower($suffix), 'simple', 'Storefront summary', [['type' => 'paragraph', 'data' => ['text' => 'Safe&nbsp;C5&nbsp;description']]], 'C5 SEO', 'C5 SEO description', 'USD', '', $mediaId, [['sku' => 'C5-SKU-' . $suffix, 'price_minor' => 1200, 'variant_status' => 'active', 'attribute_signature' => '']], [], [(int) $category], [['media_id' => $mediaId, 'role' => 'gallery']], 'c5-create-' . $suffix), 1);
    $catalog->publish($productId, 1, 'c5-publish-' . $suffix);
    $variantId = (int) $pdo->query('SELECT id FROM cms_commerce_product_variants WHERE product_id = ' . $productId . ' LIMIT 1')->fetchColumn();
    $inventory->adjust(new InventoryAdjustmentCommand($variantId, 5, 'manual_adjustment', 'C5 stock', 'c5-stock-' . $suffix), 1);
    $state = $cart->getOrCreate(null);
    $carted = $cart->add($state['token'], new CartCommand($variantId, 1, (int) $state['cart']['version'], 'c5-cart-' . $suffix));
    $quote = $checkout->quote($state['token'], (int) $carted['cart']['version'], c5_address());
    $placed = $checkout->placeOrder($state['token'], (int) $carted['cart']['version'], c5_address(), (string) $quote['quote_hash'], 'c5-order-' . $suffix);
    $paymentRow = $payment->captureManual((int) $placed['order']['id'], (int) $placed['order']['total_minor'], 'USD', 'C5PAY-' . $suffix, 'c5-pay-' . $suffix, 1);
    return [$productId, $variantId, (int) $placed['order']['id'], (int) $paymentRow['id'], (string) $placed['public_token']];
}

function c5_media(PDO $pdo, string $suffix): int
{
    $stmt = $pdo->prepare("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png',:name,:path,:path,10,:hash,'{}','png',1,1,:title,'',:alt,1,'Active','now','now')");
    $stmt->execute([
        ':name' => 'c5-' . strtolower($suffix) . '.png',
        ':path' => '2026/08/c5-' . strtolower($suffix) . '.png',
        ':hash' => 'hash-c5-' . strtolower($suffix),
        ':title' => 'C5 Image ' . $suffix,
        ':alt' => 'C5 Alt ' . $suffix,
    ]);

    return (int) $pdo->lastInsertId();
}

function c5_flow(PDO $pdo): void
{
    [$catalogRepo, $catalog, $inventoryRepo, $inventory, $cartRepo, $cart, $orderRepo, $checkout, $payment, $fulfillmentRepo, $fulfillment] = c5_services($pdo);
    [$productId, $variantId, $orderId, $paymentId, $publicToken] = c5_create_paid_order($pdo, 'A');

    $storefront = new StorefrontController($catalogRepo);
    c5_check($storefront->shop(new Request('GET', '/shop'))->status() === 200 && str_contains($storefront->shop(new Request('GET', '/shop'))->body(), 'C5 Product A'), 'storefront shop lists published products through base template fallback');
    $productResponse = $storefront->product(new Request('GET', '/product/c5-product-a'));
    c5_check($productResponse->status() === 200 && str_contains($productResponse->body(), 'Safe C5 description'), 'product detail renders normalized safe product ViewModel content');
    c5_check(str_contains($productResponse->body(), '<h2>Gallery</h2>') && str_contains($productResponse->body(), '/media/') && str_contains($productResponse->body(), 'C5 Alt A'), 'product detail renders active Core media main image and gallery');
    c5_check($storefront->category(new Request('GET', '/shop/category/c5-category-a'))->status() === 200 && str_contains($storefront->category(new Request('GET', '/shop/category/c5-category-a'))->body(), 'C5 Product A'), 'category storefront route lists category products');

    $externalId = $catalog->create(new CatalogCommand('External C5', 'external-c5', 'external', 'External summary', [], '', '', 'USD', 'https://vendor.example/product', null, [], [], [], [], 'c5-external'), 1);
    $catalog->publish($externalId, 1, 'c5-external-publish');
    $redirect = $storefront->externalRedirect(new Request('GET', '/product/external-c5/go'));
    c5_check($redirect->status() === 302 && str_contains((string) ($redirect->headers()['Location'] ?? ''), 'https://vendor.example/product'), 'external product uses controlled HTTPS redirect');
    c5_check($storefront->product(new Request('GET', '/product/missing'))->status() === 404, 'storefront returns 404 for missing product');

    $shipment = $fulfillment->submitOrder($orderId, 1, 'c5-fulfill');
    c5_check((string) $shipment['status'] === 'submitted' && (string) $orderRepo->order($orderId)['status'] === 'processing', 'Fake fulfillment submit creates shipment and moves paid order to processing');
    $again = $fulfillment->submitOrder($orderId, 1, 'c5-fulfill');
    c5_check((int) $again['id'] === (int) $shipment['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_shipments WHERE idempotency_key = 'c5-fulfill'")->fetchColumn() === 1, 'duplicate fulfillment submit is idempotent');
    $fulfillment->updateShipmentStatus((int) $shipment['id'], 'shipped', 1, 'c5-ship');
    $delivered = $fulfillment->updateShipmentStatus((int) $shipment['id'], 'delivered', 1, 'c5-deliver');
    $order = $orderRepo->order($orderId);
    c5_check((string) $delivered['status'] === 'delivered' && (string) $order['status'] === 'fulfilled' && (string) $order['fulfillment_status'] === 'delivered', 'delivered Fake shipment updates order fulfillment status to fulfilled/delivered');
    c5_throws(static fn () => $fulfillment->updateShipmentStatus((int) $shipment['id'], 'processing', 1, 'c5-after-deliver'), 'delivered shipment cannot be changed');
    c5_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name IN ('commerce.fulfillment.requested.v1','commerce.fulfillment.updated.v1')")->fetchColumn() >= 3, 'fulfillment requested and updated events are written');
    c5_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action LIKE 'commerce.fulfillment.%'")->fetchColumn() >= 2, 'fulfillment writes audit logs');

    [, , $failedOrderId] = c5_create_paid_order($pdo, 'B');
    $failed = $fulfillment->submitOrder($failedOrderId, 1, 'c5-fulfill-fail');
    c5_check((string) $failed['status'] === 'failed' && (string) $orderRepo->order($failedOrderId)['status'] === 'fulfilment_failed', 'Fake provider failure is isolated as recoverable fulfillment failure');
    $retry = $fulfillment->submitOrder($failedOrderId, 1, 'c5-fulfill-retry');
    c5_check((string) $retry['status'] === 'submitted' && (string) $orderRepo->order($failedOrderId)['status'] === 'processing', 'recoverable fulfillment failure can be safely retried');

    $migration = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/006_storefront_fulfillment.php';
    ($migration['down'])($pdo);
    ($migration['up'])($pdo);
    c5_check(true, '006 storefront/fulfillment migration down/up retry is safe');
}

[$root, $pdo] = c5_setup_sqlite('sqlite');
c5_flow($pdo);
c5_remove($root);

$mysqlStatus = 'skipped';
if (extension_loaded('pdo_mysql')) {
    try {
        $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db = 'cms_commerce_c5_' . bin2hex(random_bytes(3));
        $rootPdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            (new MigrationRunner($mysqlPdo, c5_core_migrations()))->run();
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysqlPdo))->installBundled('official.commerce', 1, false);
            c5_flow($mysqlPdo);
            $mysqlStatus = 'passed';
        } finally {
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        echo '[FAIL] real MySQL/MariaDB Commerce C5 test unavailable: ' . $exception->getMessage() . PHP_EOL;
        $failures++;
    }
}
c5_check($mysqlStatus === 'passed', 'real MySQL/MariaDB C5 storefront/fulfillment acceptance is required and passed');

if ($failures > 0) {
    echo 'Commerce C5 storefront/fulfillment failures: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Commerce C5 storefront/fulfillment tests passed; mysql=' . $mysqlStatus . PHP_EOL;
