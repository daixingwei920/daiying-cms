<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceRepository.php';

use Cms\Core\Plugin\PluginManifest;
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
