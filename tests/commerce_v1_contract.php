<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceRepository.php';

use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Payment\PaymentRepository;
use Daiying\Commerce\CommerceRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/content/plugins/official.commerce/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$official = require $root . '/system/official-plugins.php';
$parsed = PluginManifest::fromArray($manifest);

$assert($parsed->id === 'official.commerce', 'Commerce uses the official.commerce plugin id.');
$assert($parsed->trustLevel === 'trusted_php', 'Commerce is a trusted official plugin because it owns order tables.');
$assert(($official['official.commerce']['table_prefixes'] ?? []) === ['commerce_'], 'Official registry grants only the commerce_ table prefix.');
$assert(!in_array('payment.create', $parsed->capabilities, true), 'Commerce uses Core PaymentService without claiming a foreign payment capability namespace.');
$assert(!in_array('network.external', $parsed->capabilities, true), 'Commerce core does not need external network access in V1 phase 1.');

$migration = require $root . '/content/plugins/official.commerce/migrations/001_commerce_core.php';
$assert(in_array('table:commerce_orders', $migration['affected_objects'] ?? [], true), 'Migration declares the commerce order table.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
($migration['up'])($pdo);
$pdo->exec('CREATE TABLE cms_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_type VARCHAR(96) NOT NULL,
    subject_id VARCHAR(191) NOT NULL,
    provider_id VARCHAR(96) NOT NULL,
    remote_id VARCHAR(191) NOT NULL,
    reference VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    amount_minor INTEGER NOT NULL,
    currency VARCHAR(3) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    metadata_json TEXT NOT NULL,
    authorized_at VARCHAR(64),
    paid_at VARCHAR(64),
    failed_at VARCHAR(64),
    cancelled_at VARCHAR(64),
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL
)');
$pdo->exec('CREATE TABLE cms_payment_refunds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id INTEGER NOT NULL,
    provider_id VARCHAR(96) NOT NULL,
    remote_id VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    amount_minor INTEGER NOT NULL,
    currency VARCHAR(3) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    metadata_json TEXT NOT NULL,
    completed_at VARCHAR(64),
    failed_at VARCHAR(64),
    cancelled_at VARCHAR(64),
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL
)');

$repo = new CommerceRepository($pdo);
$productId = $repo->saveProduct([
    'name' => '测试商品',
    'sku' => 'TEST-001',
    'status' => 'active',
    'price_minor' => 36000,
    'currency' => 'CNY',
    'stock_quantity' => 5,
    'source_url' => 'https://example.com/item/1',
    'brand' => 'Daiying',
    'model' => 'V1',
    'specs' => "颜色: 黑色\n容量: 128GB",
]);
$repo->saveAction(['product_id' => $productId, 'action_type' => 'site_checkout', 'label' => '立即购买']);
$product = $repo->product($productId);

$assert(is_array($product), 'Product can be created.');
$assert((int) ($product['available_quantity'] ?? 0) === 5, 'New product starts with full available stock.');
$assert(($product['specs']['颜色'] ?? '') === '黑色', 'Product specs are stored as structured facts.');

$variantId = $repo->saveVariant([
    'product_id' => $productId,
    'title' => '黑色 128GB',
    'sku' => 'TEST-001-BLK-128',
    'stock_quantity' => 2,
    'price_delta_minor' => 100,
    'status' => 'active',
]);
$variantOrder = $repo->createPendingOrder($productId, $variantId, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-variant', hash('sha256', 'variant'));
$variantAfterReserve = $repo->variant($variantId);
$assert((int) ($variantAfterReserve['reserved_quantity'] ?? 0) === 1, 'Variant checkout reserves variant inventory.');
$repo->markOrderPaymentFailed((int) $variantOrder['id'], 'variant provider rejected');
$variantAfterFailure = $repo->variant($variantId);
$assert((int) ($variantAfterFailure['reserved_quantity'] ?? 0) === 0, 'Failed variant payment releases variant inventory.');

$failed = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 2, 'fixture', 'commerce-test-failed', hash('sha256', 'failed'));
$productAfterReserve = $repo->product($productId);
$assert((int) ($productAfterReserve['reserved_quantity'] ?? 0) === 2, 'Checkout reserves product inventory.');
$repo->markOrderPaymentFailed((int) $failed['id'], 'provider rejected');
$productAfterFailure = $repo->product($productId);
$assert((int) ($productAfterFailure['reserved_quantity'] ?? 0) === 0, 'Failed payment releases reserved inventory.');

$paid = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-paid', hash('sha256', 'paid'));
$repo->attachPayment((int) $paid['id'], 123);
$repo->markOrderPaid((int) $paid['id']);
$paidOrder = $repo->order((int) $paid['id']);
$productAfterPaid = $repo->product($productId);

$assert(($paidOrder['status'] ?? '') === 'paid', 'Paid order status is persisted.');
$assert((int) ($productAfterPaid['sold_quantity'] ?? 0) === 1, 'Paid order increments sold quantity.');
$assert((int) ($productAfterPaid['available_quantity'] ?? 0) === 4, 'Paid order reduces available stock.');
$assert(($paidOrder['snapshot']['product']['name'] ?? '') === '测试商品', 'Order keeps product snapshot.');

$synced = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-sync', hash('sha256', 'sync'));
$paymentRepo = new PaymentRepository($pdo);
$paymentId = $paymentRepo->insertPayment([
    'subject_type' => 'commerce_order',
    'subject_id' => 'order:' . (int) $synced['id'],
    'provider_id' => 'fixture',
    'remote_id' => 'remote-sync',
    'reference' => 'reference-sync',
    'status' => 'paid',
    'amount_minor' => (int) $synced['amount_minor'],
    'currency' => (string) $synced['currency'],
    'idempotency_key' => 'payment-sync',
    'request_hash' => hash('sha256', 'payment-sync'),
    'metadata' => [],
]);
$repo->attachPayment((int) $synced['id'], $paymentId);
$syncResult = $repo->markTrustedPaidOrders($paymentRepo);
$syncedOrder = $repo->order((int) $synced['id']);
$assert((int) ($syncResult['marked'] ?? 0) === 1, 'Trusted paid CMS payment can sync a pending commerce order.');
$assert(($syncedOrder['status'] ?? '') === 'paid', 'Synced commerce order is marked paid.');

$repo->saveProduct([
    'id' => $productId,
    'name' => '测试商品改名',
    'sku' => 'TEST-001',
    'status' => 'active',
    'price_minor' => 36100,
    'currency' => 'CNY',
    'stock_quantity' => 5,
]);
$changes = $repo->productChanges($productId);
$fields = array_values(array_map(static fn (array $row): string => (string) $row['field_name'], $changes));
$assert(in_array('name', $fields, true), 'Key product name changes are appended to immutable change history.');
$assert(in_array('price_minor', $fields, true), 'Key product price changes are appended to immutable change history.');

if ($failures > 0) {
    exit(1);
}

echo "Commerce V1 contract OK\n";
