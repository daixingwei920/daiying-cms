<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceContracts.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceRepository.php';

use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Payment\PaymentRepository;
use Daiying\Commerce\CommerceAiModuleInterface;
use Daiying\Commerce\CommerceDistributionInterface;
use Daiying\Commerce\CommerceLogisticsProviderInterface;
use Daiying\Commerce\CommerceProviderIsolation;
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
$assert(in_array('commerce.verify.write', $parsed->capabilities, true), 'Commerce declares a dedicated verification write capability for future permission splits.');
$assert(in_array('commerce.logistics.write', $parsed->capabilities, true), 'Commerce declares a dedicated logistics write capability for future provider integrations.');
$assert(interface_exists(CommerceAiModuleInterface::class), 'Commerce exposes an optional AI module interface without making AI a hard dependency.');
$assert(interface_exists(CommerceDistributionInterface::class), 'Commerce exposes a distribution provider interface for future channels.');
$assert(interface_exists(CommerceLogisticsProviderInterface::class), 'Commerce exposes a logistics provider interface for future carrier plugins.');
$isolated = CommerceProviderIsolation::capture('fixture', 'explode', static function (): void {
    throw new RuntimeException('provider unavailable');
});
$assert(($isolated['ok'] ?? true) === false && ($isolated['provider'] ?? '') === 'fixture', 'Provider failures are captured instead of escaping into the commerce flow.');

$coreMigration = require $root . '/content/plugins/official.commerce/migrations/001_commerce_core.php';
$assert(in_array('table:commerce_orders', $coreMigration['affected_objects'] ?? [], true), 'Migration declares the commerce order table.');
$logisticsMigration = require $root . '/content/plugins/official.commerce/migrations/002_logistics_events.php';
$assert(in_array('table:commerce_logistics_events', $logisticsMigration['affected_objects'] ?? [], true), 'Logistics migration declares the logistics fact table.');
$pricingMigration = require $root . '/content/plugins/official.commerce/migrations/003_price_transparency.php';
$assert(in_array('table:commerce_products', $pricingMigration['affected_objects'] ?? [], true), 'Price transparency migration declares the commerce product table.');
$governanceMigration = require $root . '/content/plugins/official.commerce/migrations/004_source_verification_governance.php';
$assert(in_array('table:commerce_verification_records', $governanceMigration['affected_objects'] ?? [], true), 'Source verification governance migration declares verification records.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
($coreMigration['up'])($pdo);
($logisticsMigration['up'])($pdo);
($pricingMigration['up'])($pdo);
($governanceMigration['up'])($pdo);
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
    'transaction_region' => 'cross_border',
    'shipping_fee_minor' => 1200,
    'tax_fee_minor' => 800,
    'service_fee_minor' => 300,
    'discount_minor' => 100,
    'price_note' => '跨境订单费用以结算页快照为准',
    'source_url' => 'https://example.com/item/1',
    'source_claim_text' => '官方授权渠道采购',
    'brand' => 'Daiying',
    'model' => 'V1',
    'specs' => "颜色: 黑色\n容量: 128GB",
]);
$repo->saveAction(['product_id' => $productId, 'action_type' => 'site_checkout', 'label' => '立即购买']);
$product = $repo->product($productId);

$assert(is_array($product), 'Product can be created.');
$assert((int) ($product['available_quantity'] ?? 0) === 5, 'New product starts with full available stock.');
$assert(($product['specs']['颜色'] ?? '') === '黑色', 'Product specs are stored as structured facts.');
$assert(($product['verification_status'] ?? '') === 'pending', 'Product with a source URL starts as pending verification.');
$assert(($product['transaction_region'] ?? '') === 'cross_border', 'Product keeps a transaction-region fact separate from currency.');
$sourceRecords = $repo->verificationRecords($productId);
$assert(($sourceRecords[0]['record_type'] ?? '') === 'source_declaration', 'Saving a product source creates an append-only source declaration record.');
$assert(($sourceRecords[0]['checked_facts']['source_claim'] ?? '') === '官方授权渠道采购', 'Source declarations keep seller-provided source claims as facts.');

$sellerCannotVerify = false;
try {
    $repo->appendVerificationRecord([
        'product_id' => $productId,
        'status' => 'verified',
        'source_url' => 'https://example.com/item/1',
    ]);
} catch (RuntimeException) {
    $sellerCannotVerify = true;
}
$assert($sellerCannotVerify, 'Seller/manual flows cannot directly mark a source as verified.');

$repo->appendVerificationRecord([
    'product_id' => $productId,
    'status' => 'verified',
    'provider' => 'official.verifier.fixture',
    'source_url' => 'https://example.com/item/1',
    'checked_facts' => "品牌: Daiying\n型号: V1",
    'raw_evidence' => "页面标题: 测试商品\n抓取方式: manual",
]);
$verifiedProduct = $repo->product($productId);
$verifiedRecords = $repo->verificationRecords($productId);
$assert(($verifiedProduct['verification_status'] ?? '') === 'verified', 'Appending a verified record updates the product verification status.');
$assert(($verifiedRecords[0]['record_type'] ?? '') === 'provider_result', 'Trusted provider verification is recorded as a provider result.');
$assert(($verifiedRecords[0]['checked_facts']['品牌'] ?? '') === 'Daiying', 'Verification facts are stored as append-only structured records.');
$requestId = $repo->requestVerificationReview($productId, '来源页面已更新，请重新核验。', 99);
$requestedRecords = $repo->verificationRecords($productId);
$assert($requestId > 0 && ($requestedRecords[0]['record_type'] ?? '') === 'seller_request', 'Seller can request re-verification without editing the result.');
$assert(($repo->product($productId)['verification_status'] ?? '') === 'pending', 'Seller re-verification requests move the product back to pending.');
$repo->appendVerificationRecord([
    'product_id' => $productId,
    'status' => 'verified',
    'provider' => 'official.verifier.fixture',
    'source_url' => 'https://example.com/item/1',
    'checked_facts' => "品牌: Daiying\n型号: V1",
    'raw_evidence' => "页面标题: 测试商品\n抓取方式: fixture",
]);
$repo->saveProduct([
    'id' => $productId,
    'name' => '测试商品',
    'sku' => 'TEST-001',
    'status' => 'active',
    'price_minor' => 36000,
    'currency' => 'CNY',
    'stock_quantity' => 5,
    'transaction_region' => 'cross_border',
    'shipping_fee_minor' => 1200,
    'tax_fee_minor' => 800,
    'service_fee_minor' => 300,
    'discount_minor' => 100,
    'price_note' => '跨境订单费用以结算页快照为准',
    'source_url' => 'https://example.com/item/1',
    'source_claim_text' => '官方授权渠道采购',
    'brand' => 'Daiying Updated',
    'model' => 'V1',
    'specs' => "颜色: 黑色\n容量: 128GB",
]);
$pendingProduct = $repo->product($productId);
$verificationAfterChange = $repo->verificationRecords($productId);
$assert(($pendingProduct['verification_status'] ?? '') === 'pending', 'Changing key product facts invalidates verified source status.');
$assert(($verificationAfterChange[0]['provider'] ?? '') === 'system', 'Verification invalidation is recorded by the system as a separate fact.');
$assert(($verificationAfterChange[0]['record_type'] ?? '') === 'system_invalidation', 'System invalidation is distinguishable from seller requests and provider results.');

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

$cancelled = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-cancelled', hash('sha256', 'cancelled'));
$repo->cancelPendingOrder((int) $cancelled['id'], 'admin cancelled');
$cancelledOrder = $repo->order((int) $cancelled['id']);
$productAfterCancel = $repo->product($productId);
$assert(($cancelledOrder['status'] ?? '') === 'cancelled', 'Pending order can be cancelled by admin.');
$assert((int) ($productAfterCancel['reserved_quantity'] ?? 0) === 0, 'Cancelled pending order releases reserved inventory.');

$paid = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-paid', hash('sha256', 'paid'));
$repo->attachPayment((int) $paid['id'], 123);
$repo->markOrderPaid((int) $paid['id']);
$paidOrder = $repo->order((int) $paid['id']);
$productAfterPaid = $repo->product($productId);

$assert(($paidOrder['status'] ?? '') === 'paid', 'Paid order status is persisted.');
$assert((int) ($paidOrder['amount_minor'] ?? 0) === 38200, 'Order total includes transparent shipping, tax, service, and discount components.');
$assert((int) ($productAfterPaid['sold_quantity'] ?? 0) === 1, 'Paid order increments sold quantity.');
$assert((int) ($productAfterPaid['available_quantity'] ?? 0) === 4, 'Paid order reduces available stock.');
$assert(($paidOrder['snapshot']['product']['name'] ?? '') === '测试商品', 'Order keeps product snapshot.');
$assert((int) ($paidOrder['snapshot']['pricing']['shipping_fee_minor'] ?? 0) === 1200, 'Order snapshot freezes shipping fee at checkout time.');
$assert(($paidOrder['snapshot']['pricing']['transaction_region'] ?? '') === 'cross_border', 'Order snapshot freezes transaction-region at checkout time.');
$repo->markOrderFulfilled((int) $paid['id']);
$fulfilledOrder = $repo->order((int) $paid['id']);
$assert(($fulfilledOrder['status'] ?? '') === 'fulfilled', 'Paid order can be marked fulfilled.');
$assert(($fulfilledOrder['fulfillment_status'] ?? '') === 'fulfilled', 'Fulfilled order updates fulfillment status.');

$shippingProductId = $repo->saveProduct([
    'name' => '实体商品',
    'sku' => 'SHIP-001',
    'status' => 'active',
    'price_minor' => 9900,
    'currency' => 'CNY',
    'stock_quantity' => 3,
    'requires_shipping' => '1',
]);
$repo->saveAction(['product_id' => $shippingProductId, 'action_type' => 'site_checkout', 'label' => '购买实体商品', 'fulfillment_mode' => 'shipping']);
$shippingOrder = $repo->createPendingOrder($shippingProductId, null, (int) $repo->activeActions($shippingProductId)[0]['id'], 1, 'fixture', 'commerce-test-shipping', hash('sha256', 'shipping'));
$repo->markOrderPaid((int) $shippingOrder['id']);
$repo->appendLogisticsEvent([
    'order_id' => (int) $shippingOrder['id'],
    'status' => 'in_transit',
    'carrier' => 'SF Express',
    'tracking_number' => 'SF123456',
    'provider' => 'manual',
    'raw_status' => 'transit',
    'raw_payload' => "node: Shanghai\nsource: operator",
    'message' => '包裹运输中',
    'occurred_at' => '2026-09-06 12:00:00',
]);
$logisticsEvents = $repo->logisticsEvents((int) $shippingOrder['id']);
$shippingInTransit = $repo->order((int) $shippingOrder['id']);
$assert(($shippingInTransit['fulfillment_status'] ?? '') === 'in_transit', 'Shipping order records the latest logistics status.');
$assert(($logisticsEvents[0]['raw_payload']['node'] ?? '') === 'Shanghai', 'Logistics raw provider facts are stored as structured append-only data.');
$repo->appendLogisticsEvent([
    'order_id' => (int) $shippingOrder['id'],
    'status' => 'delivered',
    'carrier' => 'SF Express',
    'tracking_number' => 'SF123456',
    'message' => '已签收',
    'occurred_at' => '2026-09-07 09:30:00',
]);
$shippingDelivered = $repo->order((int) $shippingOrder['id']);
$assert(($shippingDelivered['status'] ?? '') === 'fulfilled', 'Delivered logistics status fulfills the shipping order.');
$assert(($shippingDelivered['fulfillment_status'] ?? '') === 'delivered', 'Delivered logistics status is kept as an explicit fulfillment fact.');

$blockedNonShipping = false;
try {
    $repo->appendLogisticsEvent(['order_id' => (int) $paid['id'], 'status' => 'picked_up']);
} catch (RuntimeException) {
    $blockedNonShipping = true;
}
$assert($blockedNonShipping, 'Non-shipping orders reject logistics facts.');

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
    'transaction_region' => 'cross_border',
    'shipping_fee_minor' => 1200,
    'tax_fee_minor' => 800,
    'service_fee_minor' => 300,
    'discount_minor' => 100,
    'price_note' => '跨境订单费用以结算页快照为准',
    'source_claim_text' => '官方授权渠道采购',
]);
$changes = $repo->productChanges($productId);
$fields = array_values(array_map(static fn (array $row): string => (string) $row['field_name'], $changes));
$assert(in_array('name', $fields, true), 'Key product name changes are appended to immutable change history.');
$assert(in_array('price_minor', $fields, true), 'Key product price changes are appended to immutable change history.');

if ($failures > 0) {
    exit(1);
}

echo "Commerce V1 contract OK\n";
