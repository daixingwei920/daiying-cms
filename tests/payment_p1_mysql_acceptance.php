<?php

declare(strict_types=1);

use Official\Commerce\Infrastructure\ProviderRegistry;

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p1_mysql_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$env = null;
try {
    $env = p1_mysql_root('acceptance');
    $pdo = $env['pdo'];
    p1_install($pdo);
    p1_boot($pdo);
    p1_mysql_check(ProviderRegistry::payment('official.payment-fixture') !== null, 'real MySQL registers Fixture Payment provider');
    $services = p1_services($pdo);
    $order = p1_pending_order($pdo, 'MYSQL-P1');
    $payment = $services['payment']->createProviderPayment((int) $order['order']['id'], 'official.payment-fixture', (int) $order['total'], 'USD', 'success', 'p1-mysql-pay', 9);
    p1_mysql_check((string) $services['orders']->order((int) $order['order']['id'])['status'] === 'paid', 'real MySQL successful fixture payment marks order paid');
    $services['payment']->refundProviderPayment((int) $payment['id'], 500, 'partial mysql', 'p1-mysql-refund-a', 9);
    $services['payment']->refundProviderPayment((int) $payment['id'], (int) $order['total'] - 500, 'full mysql', 'p1-mysql-refund-b', 9);
    p1_mysql_check((string) $services['orders']->payment((int) $payment['id'])['status'] === 'refunded', 'real MySQL fixture refunds reach refunded state');
    $uniqueColumns = [];
    foreach ($pdo->query('SHOW INDEX FROM cms_commerce_payments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string) ($row['Key_name'] ?? '') === 'ux_commerce_payments_provider_remote' && (int) ($row['Non_unique'] ?? 1) === 0) {
            $uniqueColumns[] = (string) ($row['Column_name'] ?? '');
        }
    }
    sort($uniqueColumns);
    p1_mysql_check($uniqueColumns === ['provider_id', 'remote_id'], 'real MySQL has unique provider remote payment index');
    p1_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE event_name = 'commerce.order.paid.v1'")->fetchColumn() === 1, 'real MySQL provider payment writes one paid outbox event');
} catch (Throwable $exception) {
    echo '[FAIL] real MySQL Payment P1 acceptance failed: ' . $exception->getMessage() . PHP_EOL;
    $failures++;
} finally {
    if (is_array($env)) {
        p1_cleanup_env($env);
    }
}

if ($failures > 0) {
    echo '[RESULT] payment_p1_mysql_acceptance failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_p1_mysql_acceptance passed; mysql=passed' . PHP_EOL;
