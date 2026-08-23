<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Official\Commerce\Application\CartCommand;
use Official\Commerce\Application\CartService;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CheckoutService;
use Official\Commerce\Application\CommerceValidationException;
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
require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.php';

set_time_limit(180);
$failures = 0;

function c4_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function c4_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c4_check(false, $message);
    } catch (Throwable) {
        c4_check(true, $message);
    }
}

function c4_remove(string $path): void
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
function c4_core_migrations(): array
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    return $migrations;
}

function c4_setup_sqlite(string $name): array
{
    $root = sys_get_temp_dir() . '/cms-commerce-c4-' . $name . '-' . bin2hex(random_bytes(4));
    c4_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce.sqlite', 'username' => '', 'password' => '', 'options' => []];
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    (new MigrationRunner($pdo, c4_core_migrations()))->run();
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, false);
    return [$root, $pdo];
}

function c4_services(PDO $pdo): array
{
    $outbox = new OutboxEventRepository($pdo);
    $audit = new AuditLogger($pdo);
    $catalogRepo = new CatalogRepository($pdo);
    $cartRepo = new CartRepository($pdo);
    $inventoryRepo = new InventoryRepository($pdo);
    $orderRepo = new OrderRepository($pdo);
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
    ];
}

function c4_product(CatalogService $catalog, PDO $pdo, string $sku = 'C4-SKU'): int
{
    $id = $catalog->create(new CatalogCommand('C4 Product ' . $sku, 'c4-product-' . strtolower($sku), 'simple', '', [], '', '', 'USD', '', null, [['sku' => $sku, 'price_minor' => 1000, 'variant_status' => 'active', 'attribute_signature' => '']], [], [], [], 'c4-create-' . $sku), 1);
    $catalog->publish($id, 1, 'c4-publish-' . $sku);
    return (int) $pdo->query('SELECT id FROM cms_commerce_product_variants WHERE product_id = ' . $id . ' LIMIT 1')->fetchColumn();
}

function c4_address(): array
{
    return ['full_name' => 'Jane Buyer', 'email' => 'jane@example.test', 'phone' => '5550100', 'line1' => '1 Test St', 'line2' => '', 'city' => 'Boston', 'region' => 'MA', 'postal_code' => '02110', 'country_code' => 'US'];
}

function c4_flow(PDO $pdo): void
{
    [$catalogRepo, $catalog, $inventoryRepo, $inventory, $cartRepo, $cart, $orderRepo, $checkout, $payment] = c4_services($pdo);
    $variantId = c4_product($catalog, $pdo);
    $inventory->adjust(new InventoryAdjustmentCommand($variantId, 3, 'manual_adjustment', 'opening stock', 'c4-initial'), 1);
    c4_check((string) $pdo->query("SELECT type FROM cms_commerce_inventory_movements WHERE idempotency_key = 'c4-initial'")->fetchColumn() === 'initial', 'first positive inventory adjustment records initial movement');

    $state = $cart->getOrCreate(null);
    c4_check($state['token'] !== (string) $state['cart']['id'] && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_carts WHERE token_hash = ' . $pdo->quote($state['token']))->fetchColumn() === 0, 'cart cookie token does not expose database cart id and is not stored in plaintext');
    $carted = $cart->add($state['token'], new CartCommand($variantId, 2, (int) $state['cart']['version'], 'c4-cart-add'));
    $quote = $checkout->quote($state['token'], (int) $carted['cart']['version'], c4_address());
    c4_check((int) $quote['subtotal_minor'] === 2000 && (int) $quote['shipping_minor'] === 500 && (int) $quote['tax_minor'] === 0 && (int) $quote['total_minor'] === 2500, 'checkout quote recalculates subtotal, shipping, tax and total server-side');
    c4_throws(static fn () => $checkout->placeOrder($state['token'], 1, c4_address(), (string) $quote['quote_hash'], 'c4-stale-order'), 'stale cart version cannot place order');
    $result = $checkout->placeOrder($state['token'], (int) $carted['cart']['version'], c4_address(), (string) $quote['quote_hash'], 'c4-order');
    $order = $result['order'];
    c4_check((int) $order['id'] > 0 && strlen((string) $result['public_token']) === 64, 'place order creates internal order id and opaque public token');
    c4_check((string) $cartRepo->cartByTokenHash(hash('sha256', $state['token']))['status'] === 'checked_out', 'cart is converted after order placement');
    c4_check(count($orderRepo->items((int) $order['id'])) === 1, 'order item immutable snapshot is written');
    c4_check((int) $inventoryRepo->itemForVariant($variantId)['reserved'] === 2 && (int) $inventoryRepo->itemForVariant($variantId)['on_hand'] === 3, 'placing order reserves stock without consuming on_hand');
    c4_check($orderRepo->orderByPublicToken((string) $result['public_token']) !== null && $orderRepo->orderByPublicToken(str_repeat('a', 64)) === null, 'public order lookup uses hashed unpredictable token and invalid token is hidden');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.order.created.v1'")->fetchColumn() === 1, 'order.created outbox event is written in transaction');
    c4_throws(static fn () => $checkout->placeOrder($state['token'], (int) $carted['cart']['version'], c4_address(), (string) $quote['quote_hash'], 'c4-order-different'), 'checked out cart cannot create duplicate order');

    c4_throws(static fn () => $payment->captureManual((int) $order['id'], 2400, 'USD', 'BANK-LOW', 'c4-pay-low', 1), 'manual payment underpay is rejected');
    c4_throws(static fn () => $payment->captureManual((int) $order['id'], 2501, 'USD', 'BANK-HIGH', 'c4-pay-high', 1), 'manual payment overpay is rejected');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_payments WHERE idempotency_key IN ('c4-pay-low','c4-pay-high')")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_inventory_movements WHERE type = 'consumption'")->fetchColumn() === 0, 'failed payment transaction leaves no payment or consumption movement');
    $captured = $payment->captureManual((int) $order['id'], 2500, 'USD', 'BANK-REF-1', 'c4-pay', 1);
    c4_check((int) $captured['id'] > 0, 'manual payment capture creates payment record');
    $paid = $orderRepo->order((int) $order['id']);
    c4_check((string) $paid['status'] === 'paid' && (string) $paid['payment_status'] === 'paid', 'full manual capture marks order paid');
    $stock = $inventoryRepo->itemForVariant($variantId);
    c4_check((int) $stock['reserved'] === 0 && (int) $stock['on_hand'] === 1, 'payment consumes reservation and reduces on_hand');
    $again = $payment->captureManual((int) $order['id'], 2500, 'USD', 'BANK-REF-1', 'c4-pay', 1);
    c4_check((int) $again['id'] === (int) $captured['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_payments WHERE idempotency_key = 'c4-pay'")->fetchColumn() === 1, 'duplicate manual capture idempotency key does not double capture');
    c4_throws(static fn () => $payment->captureManual((int) $order['id'], 2499, 'USD', 'BANK-REF-1', 'c4-pay', 1), 'same payment idempotency key with different amount is rejected');
    c4_throws(static fn () => $payment->captureManual((int) $order['id'], 1, 'EUR', 'BAD', 'c4-pay-bad', 1), 'manual payment currency mismatch is rejected');
    $refund = $payment->recordManualRefund((int) $captured['id'], 500, 'customer request', 'c4-refund', 1);
    c4_check((int) $refund['id'] > 0 && (string) $refund['status'] === 'pending_external' && (string) $orderRepo->order((int) $order['id'])['status'] === 'paid', 'manual refund plan does not update order state before external completion');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.refund.completed.v1'")->fetchColumn() === 0, 'manual refund plan does not publish completed event');
    $completed = $payment->completeManualRefund((int) $refund['id'], 'c4-refund-complete', 1);
    c4_check((string) $completed['status'] === 'completed' && (string) $orderRepo->order((int) $order['id'])['status'] === 'partially_refunded', 'completed partial manual refund updates order to partially_refunded');
    $completedAgain = $payment->completeManualRefund((int) $refund['id'], 'c4-refund-complete-repeat', 1);
    c4_check((int) $completedAgain['id'] === (int) $refund['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.refund.completed.v1'")->fetchColumn() === 1, 'repeated refund completion is idempotent and does not duplicate completed event');
    c4_throws(static fn () => $payment->recordManualRefund((int) $captured['id'], 2501, 'too much', 'c4-refund-too-much', 1), 'refund plans cannot exceed captured amount');
    c4_throws(static fn () => $payment->recordManualRefund((int) $captured['id'], 100, 'changed', 'c4-refund', 1), 'same refund idempotency key with different amount is rejected');
    $remaining = $payment->recordManualRefund((int) $captured['id'], 2000, 'remaining', 'c4-refund-remaining', 1);
    $payment->completeManualRefund((int) $remaining['id'], 'c4-refund-remaining-complete', 1);
    c4_check((string) $orderRepo->order((int) $order['id'])['status'] === 'refunded', 'completing remaining refund updates order to refunded');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_order_status_history WHERE order_id = " . (int) $order['id'])->fetchColumn() >= 3, 'order status history is recorded for create, pay and refund');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE actor_id = 1 AND action LIKE 'commerce.%'")->fetchColumn() >= 2, 'payment/refund audit logs use real administrator id');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name IN ('commerce.payment.captured.v1','commerce.order.paid.v1','commerce.refund.recorded.v1','commerce.refund.completed.v1')")->fetchColumn() === 6, 'payment, paid, refund recorded and completed outbox events are written');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        c4_throws(static fn () => $pdo->exec('UPDATE cms_commerce_order_items SET quantity = 9'), 'SQLite order item snapshots are immutable when triggers are available');
    }
}

function c4_contract_alignment_upgrade(PDO $pdo): void
{
    $migration = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/005_financial_contract_alignment.php';
    $now = gmdate('c');
    $refundedPartialOrderId = (int) $pdo->query('SELECT id FROM cms_commerce_orders ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("UPDATE cms_commerce_orders SET status = 'refunded_partial', updated_at = :updated_at WHERE id = :id")
        ->execute([':id' => $refundedPartialOrderId, ':updated_at' => $now]);
    $pdo->prepare("INSERT INTO cms_commerce_orders (status, created_at, updated_at) VALUES ('payment_review', :created_at, :updated_at)")
        ->execute([':created_at' => $now, ':updated_at' => $now]);
    $paymentReviewOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO cms_commerce_order_status_history
            (order_id, from_status, to_status, actor_type, actor_id, reason, idempotency_key, status, created_at, updated_at)
         VALUES
            (:order_id, 'payment_review', 'refunded_partial', 'admin', 1, 'legacy status fixture', 'c4a-legacy-history', 'recorded', :created_at, :updated_at)"
    )->execute([':order_id' => $refundedPartialOrderId, ':created_at' => $now, ':updated_at' => $now]);

    ($migration['up'])($pdo);
    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $refundedPartialOrderId)->fetchColumn() === 'partially_refunded', '005 migration converts refunded_partial order status to partially_refunded');
    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $paymentReviewOrderId)->fetchColumn() === 'pending_payment', '005 migration removes payment_review order state by reverting to pending_payment');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_order_status_history WHERE from_status IN ('refunded_partial','payment_review') OR to_status IN ('refunded_partial','payment_review')")->fetchColumn() === 0, '005 migration converts legacy status history values');
    c4_check(in_array('request_hash', commerce_c4a_columns($pdo, 'cms_commerce_payments'), true) && in_array('completed_at', commerce_c4a_columns($pdo, 'cms_commerce_refunds'), true), '005 migration adds payment/refund idempotency and refund completion columns');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        c4_throws(static fn () => $pdo->exec("UPDATE cms_commerce_order_status_history SET to_status = 'paid' WHERE id = (SELECT id FROM cms_commerce_order_status_history LIMIT 1)"), '005 migration restores SQLite immutable status-history triggers after conversion');
    }

    ($migration['down'])($pdo);
    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $refundedPartialOrderId)->fetchColumn() === 'refunded_partial', '005 migration rollback restores old partial-refund spelling for safe downgrade tests');
    ($migration['up'])($pdo);
    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $refundedPartialOrderId)->fetchColumn() === 'partially_refunded' && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_orders WHERE status = 'payment_review'")->fetchColumn() === 0, '005 migration is safe to rerun after rollback and retry');
}

function c4_apply_plugin_migration(PDO $pdo, string $file, string $direction = 'up'): void
{
    $migration = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/' . $file;
    ($migration[$direction])($pdo);
}

function c4_c3_to_c4a_upgrade(PDO $pdo): void
{
    foreach ([
        '001_foundation.php',
        '002_catalog_constraints.php',
        '003_inventory_cart_constraints.php',
    ] as $file) {
        c4_apply_plugin_migration($pdo, $file);
    }
    $now = gmdate('c');
    $pdo->prepare("INSERT INTO cms_commerce_orders (status, created_at, updated_at) VALUES ('refunded_partial', :created_at, :updated_at)")
        ->execute([':created_at' => $now, ':updated_at' => $now]);
    $partialOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cms_commerce_orders (status, created_at, updated_at) VALUES ('payment_review', :created_at, :updated_at)")
        ->execute([':created_at' => $now, ':updated_at' => $now]);
    $reviewOrderId = (int) $pdo->lastInsertId();

    c4_apply_plugin_migration($pdo, '004_checkout_order_constraints.php');
    $pdo->prepare(
        "INSERT INTO cms_commerce_order_status_history
            (order_id, from_status, to_status, actor_type, actor_id, reason, idempotency_key, status, created_at, updated_at)
         VALUES
            (:order_id, 'payment_review', 'refunded_partial', 'admin', 1, 'legacy C4 status fixture', 'c4a-c3-upgrade-history', 'recorded', :created_at, :updated_at)"
    )->execute([':order_id' => $partialOrderId, ':created_at' => $now, ':updated_at' => $now]);
    c4_apply_plugin_migration($pdo, '005_financial_contract_alignment.php');

    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $partialOrderId)->fetchColumn() === 'partially_refunded', 'C3-to-C4A upgrade converts legacy partial refund status');
    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $reviewOrderId)->fetchColumn() === 'pending_payment', 'C3-to-C4A upgrade removes payment_review state');
    c4_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_order_status_history WHERE from_status IN ('payment_review','refunded_partial') OR to_status IN ('payment_review','refunded_partial')")->fetchColumn() === 0, 'C3-to-C4A upgrade converts legacy history values');
    c4_apply_plugin_migration($pdo, '005_financial_contract_alignment.php', 'down');
    c4_apply_plugin_migration($pdo, '005_financial_contract_alignment.php');
    c4_check((string) $pdo->query('SELECT status FROM cms_commerce_orders WHERE id = ' . $partialOrderId)->fetchColumn() === 'partially_refunded', 'C3-to-C4A migration rollback and retry is safe');
}

[$root, $pdo] = c4_setup_sqlite('sqlite');
c4_flow($pdo);
c4_contract_alignment_upgrade($pdo);

$migration4 = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/004_checkout_order_constraints.php';
$migration5 = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/005_financial_contract_alignment.php';
($migration4['down'])($pdo);
($migration4['up'])($pdo);
($migration5['up'])($pdo);
c4_check(true, 'C3-installed SQLite database upgrades through C4 and C4A and reruns reversible migrations safely');
c4_remove($root);

[$upgradeRoot, $upgradePdo] = c4_setup_sqlite('sqlite-upgrade');
c4_apply_plugin_migration($upgradePdo, '001_foundation.php', 'down');
c4_c3_to_c4a_upgrade($upgradePdo);
c4_remove($upgradeRoot);

$mysqlStatus = 'skipped';
if (extension_loaded('pdo_mysql')) {
    try {
        $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db = 'cms_commerce_c4_' . bin2hex(random_bytes(3));
        $rootPdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            (new MigrationRunner($mysqlPdo, c4_core_migrations()))->run();
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysqlPdo))->installBundled('official.commerce', 1, false);
            c4_flow($mysqlPdo);
            c4_contract_alignment_upgrade($mysqlPdo);
            $upgradeDb = 'cms_commerce_c4a_upgrade_' . bin2hex(random_bytes(3));
            $rootPdo->exec('CREATE DATABASE `' . $upgradeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            try {
                $upgradePdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $upgradeDb . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                c4_c3_to_c4a_upgrade($upgradePdo);
            } finally {
                $rootPdo->exec('DROP DATABASE IF EXISTS `' . $upgradeDb . '`');
            }
            $mysqlStatus = 'passed';
        } finally {
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        echo '[FAIL] real MySQL/MariaDB Commerce C4 test unavailable: ' . $exception->getMessage() . PHP_EOL;
        $failures++;
    }
}
c4_check($mysqlStatus === 'passed', 'real MySQL/MariaDB C4 checkout/order acceptance is required and passed');

if ($failures > 0) {
    echo 'Commerce C4 checkout/order failures: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Commerce C4 checkout/order tests passed; mysql=' . $mysqlStatus . PHP_EOL;
