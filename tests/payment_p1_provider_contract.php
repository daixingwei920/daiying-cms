<?php

declare(strict_types=1);

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p1_contract_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$env = p1_sqlite_root('contract');
$pdo = $env['pdo'];
p1_install($pdo, true, true);
p1_boot($pdo);

$provider = Official\Commerce\Infrastructure\ProviderRegistry::payment('official.payment-fixture');
p1_contract_check($provider !== null, 'Fixture Payment provider registers through Commerce ProviderRegistry');
p1_contract_check($provider?->providerId() === 'official.payment-fixture', 'provider exposes stable providerId');
p1_contract_check($provider?->displayName() === '模拟支付', 'provider exposes Chinese display name for admin UI');
$capabilities = $provider?->capabilities() ?? [];
foreach (['payment.create', 'payment.capture', 'payment.cancel', 'payment.refund', 'payment.status'] as $capability) {
    p1_contract_check(in_array($capability, $capabilities, true), 'provider declares capability ' . $capability);
}

$services = p1_services($pdo);
$order = p1_pending_order($pdo, 'CONTRACT');
$payment = $services['payment']->createProviderPayment((int) $order['order']['id'], 'official.payment-fixture', (int) $order['total'], 'USD', 'success', 'p1-contract-pay', 7);
p1_contract_check((string) $payment['provider_id'] === 'official.payment-fixture' && (string) $payment['status'] === 'paid', 'PaymentService records provider payment via generic contract');
p1_contract_check((string) $services['orders']->order((int) $order['order']['id'])['status'] === 'paid', 'successful provider payment moves order to paid through Commerce state machine');
p1_contract_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.order.paid.v1'")->fetchColumn() === 1, 'provider payment writes order paid outbox event once');

$manifest = json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.payment-fixture/plugin.json'), true);
p1_contract_check(($manifest['plugin_id'] ?? '') === 'official.payment-fixture' && ($manifest['version'] ?? '') === '1.0.0-alpha.2', 'Payment Fixture manifest has expected plugin id and version');
p1_contract_check(($manifest['required_plugins'][0]['plugin_id'] ?? '') === 'official.commerce' && ($manifest['required_plugins'][0]['min_version'] ?? '') === '1.0.0-rc1' && ($manifest['required_plugins'][0]['max_version'] ?? '') === '2.0.0', 'Payment Fixture declares Commerce dependency >=1.0.0-rc1 <2.0.0');

p1_remove($env['root']);
if ($failures > 0) {
    echo '[RESULT] payment_p1_provider_contract failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_p1_provider_contract passed.' . PHP_EOL;
