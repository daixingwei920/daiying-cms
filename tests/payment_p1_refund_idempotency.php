<?php

declare(strict_types=1);

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p1_refund_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function p1_refund_throws(callable $callback, string $message): void
{
    try {
        $callback();
        p1_refund_check(false, $message);
    } catch (Throwable) {
        p1_refund_check(true, $message);
    }
}

$env = p1_sqlite_root('refund');
$pdo = $env['pdo'];
p1_install($pdo, true, true);
p1_boot($pdo);
$services = p1_services($pdo);
$order = p1_pending_order($pdo, 'REFUND');
$payment = $services['payment']->createProviderPayment((int) $order['order']['id'], 'official.payment-fixture', (int) $order['total'], 'USD', 'success', 'p1-refund-pay', 9);
$first = $services['payment']->refundProviderPayment((int) $payment['id'], 500, 'partial', 'p1-refund-1', 9);
$again = $services['payment']->refundProviderPayment((int) $payment['id'], 500, 'partial', 'p1-refund-1', 9);
p1_refund_check((int) $first['id'] === (int) $again['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_refunds WHERE idempotency_key = 'p1-refund-1'")->fetchColumn() === 1, 'duplicate fixture refund returns original result without duplicate refund row');
p1_refund_check((string) $services['orders']->order((int) $order['order']['id'])['status'] === 'partially_refunded' && (string) $services['orders']->payment((int) $payment['id'])['status'] === 'partially_refunded', 'partial fixture refund updates payment and order partial refund states');
p1_refund_throws(static fn () => $services['payment']->refundProviderPayment((int) $payment['id'], 600, 'changed', 'p1-refund-1', 9), 'same refund idempotency key with different content is rejected');
$remaining = $services['payment']->refundProviderPayment((int) $payment['id'], (int) $order['total'] - 500, 'remaining', 'p1-refund-2', 9);
p1_refund_check((string) $remaining['status'] === 'completed' && (string) $services['orders']->order((int) $order['order']['id'])['status'] === 'refunded' && (string) $services['orders']->payment((int) $payment['id'])['status'] === 'refunded', 'full fixture refund updates payment and order refunded states');
p1_refund_throws(static fn () => $services['payment']->refundProviderPayment((int) $payment['id'], 1, 'too much', 'p1-refund-too-much', 9), 'refund cannot exceed captured payment amount');
p1_refund_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.refund.completed.v1'")->fetchColumn() === 2, 'duplicate refund does not duplicate refund completed outbox event');

p1_remove($env['root']);
if ($failures > 0) {
    echo '[RESULT] payment_p1_refund_idempotency failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_p1_refund_idempotency passed.' . PHP_EOL;
