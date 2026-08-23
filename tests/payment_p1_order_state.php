<?php

declare(strict_types=1);

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p1_state_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$env = p1_sqlite_root('state');
$pdo = $env['pdo'];
p1_install($pdo, true, true);
p1_boot($pdo);
$services = p1_services($pdo);

$failedOrder = p1_pending_order($pdo, 'FAIL');
$failed = $services['payment']->createProviderPayment((int) $failedOrder['order']['id'], 'official.payment-fixture', (int) $failedOrder['total'], 'USD', 'failure', 'p1-failed-pay', 5);
p1_state_check((string) $failed['status'] === 'failed', 'failed fixture payment is recorded as failed');
p1_state_check((string) $services['orders']->order((int) $failedOrder['order']['id'])['status'] === 'pending_payment', 'failed payment does not mark order paid');

$cancelOrder = p1_pending_order($pdo, 'CANCEL');
$cancelled = $services['payment']->createProviderPayment((int) $cancelOrder['order']['id'], 'official.payment-fixture', (int) $cancelOrder['total'], 'USD', 'cancel', 'p1-cancel-pay', 5);
p1_state_check((string) $cancelled['status'] === 'cancelled' && (string) $services['orders']->order((int) $cancelOrder['order']['id'])['status'] === 'pending_payment', 'cancelled fixture payment keeps order pending payment');

$paidOrder = p1_pending_order($pdo, 'PAID');
$paid = $services['payment']->createProviderPayment((int) $paidOrder['order']['id'], 'official.payment-fixture', (int) $paidOrder['total'], 'USD', 'success', 'p1-success-pay', 5);
$order = $services['orders']->order((int) $paidOrder['order']['id']);
p1_state_check((string) $paid['status'] === 'paid' && (string) $order['status'] === 'paid' && (string) $order['payment_status'] === 'paid', 'successful fixture payment marks only payment/order payment state as paid');
p1_state_check((string) ($order['fulfillment_status'] ?? 'pending') === 'pending', 'successful payment does not collapse fulfillment status into payment status');
p1_state_check((int) $services['inventory_repo']->itemForVariant((int) $paidOrder['variant_id'])['reserved'] === 0, 'successful payment consumes reserved inventory once');

p1_remove($env['root']);
if ($failures > 0) {
    echo '[RESULT] payment_p1_order_state failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_p1_order_state passed.' . PHP_EOL;
