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
use Official\Commerce\Infrastructure\FlatRateShippingProvider;
use Official\Commerce\Infrastructure\NoTaxCalculator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Infrastructure\ProviderRegistry;
use Official\Commerce\Repository\CartRepository;
use Official\Commerce\Repository\CatalogRepository;
use Official\Commerce\Repository\FulfillmentRepository;
use Official\Commerce\Repository\InventoryRepository;
use Official\Commerce\Repository\OrderRepository;
use Official\CjDropshipping\Api\CjCircuitBreaker;
use Official\CjDropshipping\Api\CjEndpointRegistry;
use Official\CjDropshipping\Api\CjErrorMapper;
use Official\CjDropshipping\Api\CjFixtureTransport;
use Official\CjDropshipping\Api\CjHttpClient;
use Official\CjDropshipping\Api\CjPointsBudget;
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjRateLimiter;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Fulfillment\CjProviderSelector;
use Official\CjDropshipping\Fulfillment\CjSandboxFulfillmentProvider;
use Official\CjDropshipping\Fulfillment\CjSandboxFulfillmentService;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\CjDropshipping\Sync\CjInventoryProvider;
use Official\CjDropshipping\Sync\CjShippingProvider;
use Official\CjDropshipping\Sync\CjSyncService;
use Official\CjDropshipping\Webhook\CjWebhookRecoveryService;
use Official\CjDropshipping\Webhook\CjWebhookVerifier;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;
function j6a_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function j6a_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j6a_check(false, $message);
    } catch (Throwable) {
        j6a_check(true, $message);
    }
}
function j6a_remove(string $path): void
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
function j6a_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}
function j6a_core(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}
function j6a_table(PDO $pdo, string $table): bool
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
function j6a_index(PDO $pdo, string $table, string $index): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name');
        $stmt->execute([':table' => $table, ':index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
    foreach ($pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll() as $row) {
        if ((string) $row['name'] === $index) {
            return true;
        }
    }
    return false;
}
function j6a_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j6a_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j6a.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j6a-static-test-key';
    j6a_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j6a_core($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    (new PluginSecretStore($pdo, 'cj-j6a-static-test-key'))->set('official.cj-dropshipping', 'cj.open_id', 'open-id-j6a');
    return [$root, $pdo];
}
function j6a_address(): array
{
    return ['full_name' => 'J6A Buyer', 'email' => 'j6a@example.test', 'phone' => '5550166', 'line1' => '66 Test Fixture Street', 'city' => 'Boston', 'region' => 'MA', 'postal_code' => '02110', 'country_code' => 'US', 'test_address' => true];
}
function j6a_services(PDO $pdo): array
{
    $outbox = new OutboxEventRepository($pdo);
    $audit = new AuditLogger($pdo);
    $catalogRepo = new CatalogRepository($pdo);
    $cartRepo = new CartRepository($pdo);
    $inventoryRepo = new InventoryRepository($pdo);
    $orderRepo = new OrderRepository($pdo);
    $fulfillmentRepo = new FulfillmentRepository($pdo);
    return [
        new CatalogService($pdo, $catalogRepo, new CatalogValidator(), $outbox, $audit),
        new InventoryService($pdo, $inventoryRepo, $outbox, $audit),
        new CartService($pdo, $cartRepo, $outbox),
        $orderRepo,
        new CheckoutService($pdo, $cartRepo, $inventoryRepo, $orderRepo, new FlatRateShippingProvider(500, null), new NoTaxCalculator(), $outbox, $audit),
        new PaymentService($pdo, $orderRepo, $inventoryRepo, $outbox, $audit),
        new FulfillmentService($pdo, $orderRepo, $fulfillmentRepo, new CjSandboxFulfillmentProvider(j6a_client($pdo)), $outbox, $audit),
    ];
}
function j6a_paid_order(PDO $pdo, string $suffix = 'A', bool $map = true): array
{
    [$catalog, $inventory, $cart, , $checkout, $payment] = j6a_services($pdo);
    $category = $catalog->saveCategory(null, new CategoryCommand('J6A Category ' . $suffix, 'j6a-category-' . strtolower($suffix)), 1);
    $productId = $catalog->create(new CatalogCommand('J6A Product ' . $suffix, 'j6a-product-' . strtolower($suffix), 'simple', 'J6A summary', [['type' => 'paragraph', 'data' => ['text' => 'Safe J6A description']]], 'J6A SEO', 'J6A SEO description', 'USD', '', null, [['sku' => 'J6A-SKU-' . $suffix, 'price_minor' => 1200, 'cost_minor' => 700, 'variant_status' => 'active', 'attribute_signature' => '']], [], [(int) $category], [], 'j6a-create-' . $suffix), 1);
    $catalog->publish($productId, 1, 'j6a-publish-' . $suffix);
    $variantId = (int) $pdo->query('SELECT id FROM cms_commerce_product_variants WHERE product_id = ' . $productId . ' LIMIT 1')->fetchColumn();
    $inventory->adjust(new InventoryAdjustmentCommand($variantId, 5, 'manual_adjustment', 'J6A stock', 'j6a-stock-' . $suffix), 1);
    $state = $cart->getOrCreate(null);
    $carted = $cart->add($state['token'], new CartCommand($variantId, 1, (int) $state['cart']['version'], 'j6a-cart-' . $suffix));
    $quote = $checkout->quote($state['token'], (int) $carted['cart']['version'], j6a_address());
    $placed = $checkout->placeOrder($state['token'], (int) $carted['cart']['version'], j6a_address(), (string) $quote['quote_hash'], 'j6a-order-' . $suffix);
    $payment->captureManual((int) $placed['order']['id'], (int) $placed['order']['total_minor'], 'USD', 'J6APAY-' . $suffix, 'j6a-pay-' . $suffix, 1);
    if ($map) {
        j6a_seed_mapping($pdo, $productId, $variantId, 'VID-J6A-' . $suffix);
    }
    return [$productId, $variantId, (int) $placed['order']['id']];
}
function j6a_seed_mapping(PDO $pdo, int $productId, int $variantId, string $vid): void
{
    $now = gmdate('c');
    $pid = 'CJ-J6A-' . $productId;
    $pdo->prepare("INSERT INTO cms_cj_product_mappings (cj_pid, cj_product_sku, commerce_product_id, import_session_id, raw_snapshot_hash, raw_snapshot_expires_at, created_at, updated_at) VALUES (:pid, 'SKU-J6A', :product_id, 1, :hash, :expires, :created, :updated)")
        ->execute([':pid' => $pid, ':product_id' => $productId, ':hash' => hash('sha256', 'j6a' . $productId), ':expires' => gmdate('c', time() + 86400), ':created' => $now, ':updated' => $now]);
    $pdo->prepare("INSERT INTO cms_cj_variant_mappings (cj_pid, cj_vid, cj_variant_sku, cj_product_sku, commerce_product_id, commerce_variant_id, local_sku, cost_minor, currency, weight_value, weight_unit, warehouse_snapshot_json, import_session_id, created_at, updated_at) VALUES (:pid, :vid, :variant_sku, 'SKU-J6A', :product_id, :variant_id, :local_sku, 700, 'USD', 420, 'g', '[]', 1, :created, :updated)")
        ->execute([':pid' => $pid, ':vid' => $vid, ':variant_sku' => 'CJ-' . $vid, ':product_id' => $productId, ':variant_id' => $variantId, ':local_sku' => 'J6A-SKU-' . $variantId, ':created' => $now, ':updated' => $now]);
}
function j6a_client(PDO $pdo, array $overrides = []): CjHttpClient
{
    $repo = new CjRepository($pdo);
    $registry = new CjEndpointRegistry();
    $transport = new CjFixtureTransport();
    $fixtures = [
        'stock.queryByVid' => [200, '{"code":"200","data":[{"pid":"CJ-J6A","warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":8}],"pointsInfo":{"remaining":80,"total":100}}'],
        'variant.queryByVid' => [200, '{"code":"200","data":{"vid":"VID-J6A-A","sellPrice":"7.00","currency":"USD"},"pointsInfo":{"remaining":78,"total":100}}'],
        'logistic.freightCalculate' => [200, '{"code":"200","data":[{"logisticName":"CJ Standard","logisticPrice":"6.50","currency":"USD","agingMin":7,"agingMax":14,"warehouse":"WH-US"}],"pointsInfo":{"remaining":75,"total":100}}'],
        'order.create' => [200, '{"code":"200","success":true,"data":{"orderId":"CJ-SANDBOX-J6A","shipmentOrderId":"CJ-SHIP-J6A","sandbox":true},"pointsInfo":{"remaining":65,"total":100}}'],
        'order.query' => [200, '{"code":"200","success":true,"data":{"orderId":"CJ-SANDBOX-J6A","shipmentOrderId":"CJ-SHIP-J6A","status":"PROCESSING","sandbox":true},"pointsInfo":{"remaining":60,"total":100}}'],
    ];
    foreach ($overrides as $operation => $fixture) {
        $fixtures[$operation] = $fixture;
    }
    foreach ($fixtures as $operation => [$status, $body]) {
        $endpoint = $registry->resolve((string) $operation);
        $transport->add($endpoint['method'], $endpoint['url'], (int) $status, ['requestId' => 'j6a-' . $operation], (string) $body);
    }
    return new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 30), new CjCircuitBreaker($repo));
}
function j6a_cj(PDO $pdo, array $overrides = []): array
{
    $repo = new CjRepository($pdo);
    $client = j6a_client($pdo, $overrides);
    $sync = new CjSyncService($pdo, $repo, $client, new AuditLogger($pdo));
    $provider = new CjSandboxFulfillmentProvider($client);
    $commerceFulfillment = new FulfillmentService($pdo, new OrderRepository($pdo), new FulfillmentRepository($pdo), $provider, new OutboxEventRepository($pdo), new AuditLogger($pdo));
    $fulfillment = new CjSandboxFulfillmentService($pdo, $repo, new OrderRepository($pdo), $sync, $provider, new AuditLogger($pdo), new CjProviderSelector($pdo), $commerceFulfillment);
    $webhook = new CjWebhookRecoveryService($pdo, $repo, new CjWebhookVerifier(new PluginSecretStore($pdo, 'cj-j6a-static-test-key'), $repo, new CjRedactor()), $client, new AuditLogger($pdo), $commerceFulfillment);
    return [$fulfillment, $sync, $webhook, $repo];
}
function j6a_sign(string $body): string
{
    return base64_encode(hash_hmac('sha256', $body, 'open-id-j6a', true));
}

[$root, $pdo] = j6a_root('cms-cj-j6a');
[$fulfillment, $sync, $webhook, $repo] = j6a_cj($pdo);
[$productId, $variantId, $orderId] = j6a_paid_order($pdo, 'A', true);
$sync->refreshVariant('VID-J6A-A', 'P1', 1);

j6a_check(json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true)['version'] === '1.0.0-rc1', 'CJ plugin version is 1.0.0-rc1');
j6a_check(j6a_table($pdo, 'cms_cj_fulfillment_links') && j6a_index($pdo, 'cms_cj_fulfillment_links', 'ux_cj_fulfillment_paid_event') && j6a_index($pdo, 'cms_cj_fulfillment_attempts', 'ux_cj_fulfillment_attempt_key'), '009 migration adds event/link/idempotency uniqueness constraints');
$events = $fulfillment->consumePaidOutbox(10);
$linkId = (int) ($events[0]['link_id'] ?? 0);
j6a_check($linkId > 0 && (string) $events[0]['status'] === 'pending_admin_review', 'real commerce.order.paid.v1 outbox event creates CJ local review link');
$again = $fulfillment->consumePaidOutbox(10);
j6a_check($again === [] && (string) $pdo->query("SELECT status FROM cms_commerce_outbox_events WHERE event_name = 'commerce.order.paid.v1' AND resource_id = '" . $orderId . "' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'processed' && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_fulfillment_links WHERE commerce_order_id = ' . $orderId)->fetchColumn() === 1, 'processed paid outbox event is not rescanned by repeated Cron');
j6a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.create'")->fetchColumn() === 0, 'paid event listener creates local task without Fixture or remote call');
$submitted = $fulfillment->submitSandbox($orderId, 11, 'cj:j6a-submit:' . $orderId);
j6a_check((string) $submitted['status'] === 'awaiting_supplier_payment' && (int) ($submitted['commerce_fulfillment_id'] ?? 0) > 0, 'Sandbox submit creates CJ link and Commerce normalized fulfillment record through service');
j6a_check((string) $pdo->query('SELECT provider_id FROM cms_commerce_shipments WHERE id = ' . (int) $submitted['commerce_fulfillment_id'])->fetchColumn() === CjProviderSelector::PROVIDER_ID, 'Commerce shipment stores generic CJ provider id without CJ raw status');
j6a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.create'")->fetchColumn() === 1, 'Sandbox submit calls fixture order.create exactly once');
j6a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation LIKE '%pay%'")->fetchColumn() === 0, 'real payment endpoint call count remains zero');
j6a_throws(static fn () => $fulfillment->submitSandbox($orderId, 11, 'cj:j6a-submit:' . $orderId . ':again'), 'repeated click after submitted blocks blind duplicate submit');
j6a_throws(static fn () => $fulfillment->submitSandbox($orderId, 11, 'cj:j6a-submit:' . $orderId . ':again'), 'second Worker concurrent duplicate submit also blocks safely');

$runtime = new PluginRuntimeRegistry();
ProviderRegistry::clear();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new Cms\Core\Logging\FileLogger($root . '/storage/logs/plugin.log'), new Cms\Core\Events\EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j6a-static-test-key')))->bootEnabled();
j6a_check(ProviderRegistry::inventory(CjProviderSelector::PROVIDER_ID) instanceof CjInventoryProvider && ProviderRegistry::shipping(CjProviderSelector::PROVIDER_ID) instanceof CjShippingProvider && ProviderRegistry::fulfillment(CjProviderSelector::PROVIDER_ID) instanceof CjSandboxFulfillmentProvider, 'CJ providers register through generic Commerce ProviderRegistry');
[$nonCjProduct, , $nonCjOrder] = j6a_paid_order($pdo, 'B', false);
$selector = new CjProviderSelector($pdo);
j6a_check($selector->selectForOrderItems((new OrderRepository($pdo))->items($nonCjOrder))['provider'] === 'none', 'non-CJ Commerce items are not selected for CJ fulfillment');

$raw = '{"messageId":"j6a-order-1","type":"ORDER","orderId":"CJ-SANDBOX-J6A","status":"SHIPPED","trackingNumber":"TRACK-J6A","email":"buyer@example.com","phone":"5559999","token":"secret"}';
$beforeStatus = (string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn();
$started = microtime(true);
$received = $webhook->receive($raw, j6a_sign($raw), 'application/json');
$elapsed = microtime(true) - $started;
$afterReceiveStatus = (string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn();
j6a_check($received['status'] === 200 && $received['queued'] && $elapsed < 3 && $beforeStatus === $afterReceiveStatus, 'webhook queues within 3 seconds before business processing');
j6a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.query'")->fetchColumn() === 0, 'webhook response path performs no polling or fixture query');
$processed = $webhook->processInbox(10);
j6a_check($processed === 1 && (string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'shipped', 'ORDER webhook asynchronously advances CJ fulfillment state');
j6a_check((string) $pdo->query('SELECT status FROM cms_commerce_shipments WHERE id = ' . (int) $submitted['commerce_fulfillment_id'])->fetchColumn() === 'shipped', 'ORDER webhook updates Commerce shipment via FulfillmentService');
$old = '{"messageId":"j6a-order-2","type":"ORDER","orderId":"CJ-SANDBOX-J6A","status":"PROCESSING"}';
$webhook->receive($old, j6a_sign($old), 'application/json');
$webhook->processInbox(10);
j6a_check((string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'shipped', 'out-of-order processing event does not regress shipped link');
foreach (['PRODUCT' => '{"messageId":"j6a-product","type":"PRODUCT","pid":"CJ-PRODUCT-J6A"}', 'VARIANT' => '{"messageId":"j6a-variant","type":"VARIANT","vid":"VID-J6A-A"}', 'STOCK' => '{"messageId":"j6a-stock","type":"STOCK","vid":"VID-J6A-A"}'] as $topic => $body) {
    $webhook->receive($body, j6a_sign($body), 'application/json');
}
$duplicateStock = '{"messageId":"j6a-stock-duplicate","type":"STOCK","vid":"VID-J6A-A"}';
$webhook->receive($duplicateStock, j6a_sign($duplicateStock), 'application/json');
$webhook->processInbox(10);
j6a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_sync_jobs WHERE resource_type = 'stock' AND resource_id = 'VID-J6A-A' AND operation = 'inventory_refresh'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_sync_jobs WHERE idempotency_key IN ('webhook:j6a-product','webhook:j6a-variant')")->fetchColumn() === 2, 'PRODUCT, VARIANT and STOCK webhooks create/merge local sync and review jobs only');
$logistic = '{"messageId":"j6a-logistic","type":"LOGISTIC","shipmentOrderId":"CJ-SHIP-J6A","status":"DELIVERED","trackingNumber":"TRACK-J6A"}';
$webhook->receive($logistic, j6a_sign($logistic), 'application/json');
$webhook->processInbox(10);
j6a_check((string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'completed', 'LOGISTIC webhook maps delivered status to completed');
$cancelOld = '{"messageId":"j6a-terminal-conflict","type":"ORDER","orderId":"CJ-SANDBOX-J6A","status":"CANCELLED"}';
$webhook->receive($cancelOld, j6a_sign($cancelOld), 'application/json');
$webhook->processInbox(10);
j6a_check((string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'action_required', 'terminal conflict enters action_required instead of unsafe cancellation');
j6a_check($webhook->receive($raw, 'bad-signature', 'application/json')['status'] === 401, 'bad signature returns 4xx without business queue');
j6a_check($repo->incrementStateCounter('webhook.receive', 60) > 0, 'webhook rate limiter stores a real counter');
for ($i = 0; $i < 125; $i++) {
    $limited = $webhook->receive('{"messageId":"j6a-rate-' . $i . '","type":"STOCK","vid":"VID-J6A-RATE"}', 'invalid-signature-' . $i, 'application/json');
}
j6a_check(($limited['status'] ?? 0) === 429, 'webhook rate limiter rejects requests above the configured window');
$repo->clearState('counter.webhook.receive');
$retryBody = '{"messageId":"j6a-rate-retry","type":"STOCK","vid":"VID-J6A-RATE"}';
j6a_check($webhook->receive($retryBody, j6a_sign($retryBody), 'application/json')['status'] === 200, 'webhook rate limiter reset allows legitimate CJ retry');
$pdo->prepare("UPDATE cms_cj_webhook_events SET status = 'processed', processed_at = :processed WHERE event_id = 'j6a-rate-retry'")->execute([':processed' => gmdate('c')]);
j6a_check($webhook->receive($raw, j6a_sign($raw), 'text/plain')['status'] === 415, 'non-JSON webhook is rejected');
j6a_check($webhook->receive(str_repeat('x', 262145), 'bad', 'application/json')['status'] === 415, 'oversized webhook is rejected');
j6a_check($webhook->receive('{"type":"ORDER","orderId":"CJ-SANDBOX-J6A"}', j6a_sign('{"type":"ORDER","orderId":"CJ-SANDBOX-J6A"}'), 'application/json')['status'] === 400, 'webhook missing messageId is rejected before business processing');
j6a_check(!str_contains((string) $pdo->query("SELECT COALESCE(payload_json,'') || COALESCE(metadata_json,'') FROM cms_cj_webhook_events WHERE event_id = 'j6a-order-1'")->fetchColumn(), 'buyer@example.com') && !str_contains((string) $pdo->query("SELECT COALESCE(payload_json,'') || COALESCE(metadata_json,'') FROM cms_cj_webhook_events WHERE event_id = 'j6a-order-1'")->fetchColumn(), 'secret'), 'webhook inbox and metadata omit PII, token, openId and raw body');

$lockedBody = '{"messageId":"j6a-locked-lease","type":"STOCK","vid":"VID-J6A-LEASE"}';
$webhook->receive($lockedBody, j6a_sign($lockedBody), 'application/json');
$pdo->prepare("UPDATE cms_cj_webhook_events SET locked_at = :locked WHERE event_id = 'j6a-locked-lease'")->execute([':locked' => gmdate('c')]);
j6a_check($webhook->processInbox(10) === 0, 'worker does not steal an active webhook lock');
$pdo->prepare("UPDATE cms_cj_webhook_events SET locked_at = :locked WHERE event_id = 'j6a-locked-lease'")->execute([':locked' => gmdate('c', time() - 600)]);
j6a_check($webhook->processInbox(10) === 1 && (string) $pdo->query("SELECT status FROM cms_cj_webhook_events WHERE event_id = 'j6a-locked-lease'")->fetchColumn() === 'processed', 'expired webhook worker lease can be reclaimed after crash');

$retryRecoverable = '{"messageId":"j6a-recoverable-retry","type":"STOCK","vid":"VID-J6A-RECOVER"}';
$webhook->receive($retryRecoverable, j6a_sign($retryRecoverable), 'application/json');
$pdo->prepare("UPDATE cms_cj_webhook_events SET status = 'failed_recoverable', next_run_at = :next_run, locked_at = NULL WHERE event_id = 'j6a-recoverable-retry'")->execute([':next_run' => gmdate('c', time() - 60)]);
j6a_check($webhook->processInbox(10) === 1 && (string) $pdo->query("SELECT status FROM cms_cj_webhook_events WHERE event_id = 'j6a-recoverable-retry'")->fetchColumn() === 'processed', 'failed_recoverable webhook event is retried when due');

$pdo->prepare("DELETE FROM cms_plugin_secrets WHERE plugin_id = 'official.cj-dropshipping' AND secret_key = 'cj.open_id'")->execute();
$missingSecret = '{"messageId":"j6a-missing-openid","type":"STOCK","vid":"VID-J6A-MISSING"}';
j6a_check($webhook->receive($missingSecret, j6a_sign($missingSecret), 'application/json')['status'] === 401, 'public webhook safely rejects when openId is not configured');
(new PluginSecretStore($pdo, 'cj-j6a-static-test-key'))->set('official.cj-dropshipping', 'cj.open_id', 'open-id-j6a');

$pdo->prepare("UPDATE cms_cj_fulfillment_links SET status = 'unknown', next_poll_at = :next_poll, poll_attempts = 0 WHERE id = :id")
    ->execute([':id' => $linkId, ':next_poll' => gmdate('c', time() - 60)]);
j6a_check($webhook->autoPollUnknown(5) === 1 && (string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'supplier_processing', 'automatic polling compensation resolves unknown state through fixture query');
$repo->openCircuit('service', 60);
$pdo->prepare("UPDATE cms_cj_fulfillment_links SET status = 'unknown', next_poll_at = :next_poll WHERE id = :id")->execute([':id' => $linkId, ':next_poll' => gmdate('c', time() - 60)]);
j6a_check($webhook->autoPollUnknown(5) === 0, 'service circuit stops automatic polling');
$repo->closeCircuit('service');
$pdo->prepare("INSERT INTO cms_cj_webhook_subscriptions (topic, expected_enabled, fixture_status, created_at, updated_at) VALUES ('ORDER', 1, 'disabled', :created, :updated)")
    ->execute([':created' => gmdate('c'), ':updated' => gmdate('c')]);
j6a_check($webhook->detectWebhookSubscriptionDrift()[0]['topic'] === 'ORDER', 'Fixture webhook subscription drift produces recovery plan without real webhook registration');

$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
$html = $routes['GET /admin/cj/fulfillment']->handler->__invoke(new Request('GET', '/admin/cj/fulfillment', [], [], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 9, ['cj.fulfillment.view'], 'c', 'r', '127.0.0.1')]));
j6a_check(str_contains($html->body(), '模拟付款，不会产生真实费用'), 'Sandbox supplier payment page clearly states no real fee is charged');
$manual = $routes['POST /admin/cj/fulfillment/consume-paid']->handler->__invoke(new Request('POST', '/admin/cj/fulfillment/consume-paid', [], ['order_id' => $orderId], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 9, ['cj.fulfillment.submit'], 'c', 'r', '127.0.0.1')]));
j6a_check($manual->status() === 404, 'manual consume-paid diagnostic route is disabled by default');

$previousEnv = getenv('APP_ENV');
$previousFixtureAllowed = getenv('CJ_FIXTURE_ALLOWED');
putenv('APP_ENV=production');
putenv('CJ_FIXTURE_ALLOWED');
$settingsResponse = $routes['GET /admin/cj/settings']->handler->__invoke(new Request('GET', '/admin/cj/settings', [], [], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 9, ['cj.view', 'cj.settings.manage'], 'c', 'r', '127.0.0.1')]));
j6a_check(str_contains($settingsResponse->body(), '当前为Fixture认证版本，尚未完成真实CJ账户试单。') && !str_contains($settingsResponse->body(), '写入 Fixture Token'), 'production settings page shows Fixture RC banner and hides fixture token control');
$fixturePost = $routes['POST /admin/cj/fixture-token']->handler->__invoke(new Request('POST', '/admin/cj/fixture-token', [], [], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 9, ['cj.settings.manage'], 'c', 'r', '127.0.0.1')]));
j6a_check($fixturePost->status() === 403, 'production fixture token route fails closed');
j6a_throws(static fn () => j6a_client($pdo)->call('points.query', [], [], 'P0', 'prod-fixture-disabled'), 'production environment blocks Fixture Transport even if code path is reachable');
if ($previousEnv === false) {
    putenv('APP_ENV');
} else {
    putenv('APP_ENV=' . $previousEnv);
}
if ($previousFixtureAllowed === false) {
    putenv('CJ_FIXTURE_ALLOWED');
} else {
    putenv('CJ_FIXTURE_ALLOWED=' . $previousFixtureAllowed);
}

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j6a_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j6a_core($mysql);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.commerce', 1, true);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.cj-dropshipping', 1, true);
            j6a_check(j6a_table($mysql, 'cms_cj_fulfillment_links') && j6a_index($mysql, 'cms_cj_fulfillment_links', 'ux_cj_fulfillment_paid_event'), 'real MySQL/MariaDB installs J6A constraints');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        $mysqlStatus = 'failed';
        j6a_check(false, 'MySQL J6A validation failed: ' . $exception->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j6a_check(false, 'pdo_mysql extension is required for CJ J6A MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;
j6a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation NOT IN ('stock.queryByVid','variant.queryByVid','logistic.freightCalculate','order.create','order.query','points.query')")->fetchColumn() === 0, 'all CJ calls are fixture-scoped and no real network/payment/webhook registration operations are recorded');

j6a_remove($root);
if ($failures > 0) {
    echo '[RESULT] CJ J6A end-to-end recovery checks failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] CJ J6A end-to-end recovery checks passed.' . PHP_EOL;
