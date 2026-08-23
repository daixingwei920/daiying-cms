<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$root = (string) ($argv[1] ?? '');
$action = (string) ($argv[2] ?? '');
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Usage: php payment_provider_browser_helper.php <root> service-enabled|latest-payment|card-state\n");
    exit(1);
}

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);

PaymentProviderRegistry::clear();
PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
PaymentProviderRegistry::register(HostedRedirectPaymentProvider::PROVIDER_ID, new HostedRedirectPaymentProvider());

if ($action === 'service-enabled') {
    $providers = (new PaymentService($pdo, new PaymentRepository($pdo), (string) $settings->get('security.encryption_key', '')))->enabledProviders();
    echo json_encode(['providers' => $providers], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($action === 'latest-payment') {
    $row = $pdo->query('SELECT * FROM cms_payments ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['payment' => is_array($row) ? $row : null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($action === 'card-state') {
    $order = $pdo->query('SELECT * FROM cms_card_orders ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $productId = is_array($order) ? (int) ($order['product_id'] ?? 0) : 0;
    $product = $productId > 0 ? (new CardDeliveryRepository($pdo))->product($productId) : null;
    $deliveryCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_card_deliveries')->fetchColumn();
    $inventoryRows = (int) $pdo->query("SELECT COUNT(*) FROM cms_card_inventory WHERE status = 'available'")->fetchColumn();
    echo json_encode([
        'product' => is_array($product) ? $product : null,
        'order' => is_array($order) ? $order : null,
        'delivery_count' => $deliveryCount,
        'available_inventory' => $inventoryRows,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

fwrite(STDERR, "Unknown action.\n");
exit(1);
