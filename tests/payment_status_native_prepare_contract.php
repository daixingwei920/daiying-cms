<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('CMS_PAYMENT_TEST_ROOT') ?: dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentRepository;

function status_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$dsn = getenv('CMS_PAYMENT_STATUS_TEST_DSN') ?: 'sqlite::memory:';
if ($dsn !== 'sqlite::memory:' && !preg_match('/^mysql:unix_socket=\/tmp\/[^;]+;dbname=cms_test_[a-z0-9_]+;charset=utf8mb4$/D', $dsn)) {
    throw new RuntimeException('Use an explicitly isolated cms_test_ database over a local Unix socket.');
}
$pdo = new PDO($dsn, $dsn === 'sqlite::memory:' ? null : 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
status_check(!$mysql || !$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'MySQL uses native prepares, never emulation');
$migration = require CMS_ROOT . '/system/migrations/2026_08_20_000001_core_payment_schema.php';
$migration->up($pdo);
$repository = new PaymentRepository($pdo);
$run = bin2hex(random_bytes(8));
$create = static function (string $suffix) use ($repository, $run): int {
    $key = 'native-' . $run . '-' . $suffix;
    return $repository->insertPayment([
        'subject_type' => 'native_test', 'subject_id' => $key,
        'provider_id' => 'test.native', 'remote_id' => 'remote-' . $key,
        'reference' => 'reference-' . $key, 'status' => 'pending',
        'amount_minor' => 1000, 'currency' => 'USD',
        'idempotency_key' => $key, 'request_hash' => hash('sha256', $key),
    ]);
};

$id = $create('capture');
$repository->updatePaymentStatus($id, 'authorized', 'authorized-' . $run, 'auth-ref-' . $run);
$authorized = $repository->payment($id);
status_check($authorized['status'] === 'authorized' && $authorized['authorized_at'] !== null, 'Authorization persists status and timestamp');
status_check($authorized['remote_id'] === 'authorized-' . $run && $authorized['reference'] === 'auth-ref-' . $run, 'Nonempty remote ID and reference update independently');
status_check(!$pdo->inTransaction(), 'Repository commits its own successful transaction');
$repository->updatePaymentStatus($id, 'paid');
$paid = $repository->payment($id);
status_check($paid['status'] === 'paid' && $paid['paid_at'] !== null, 'Capture persists paid status');
status_check($paid['remote_id'] === $authorized['remote_id'] && $paid['reference'] === $authorized['reference'], 'Empty update arguments preserve both stored references');
$repository->updatePaymentStatus($id, 'paid', '', 'new-reference-' . $run);
status_check($repository->payment($id)['remote_id'] === $authorized['remote_id'] && $repository->payment($id)['reference'] === 'new-reference-' . $run, 'Empty remote ID does not prevent reference-only update');
$repository->updatePaymentStatus($id, 'paid', 'new-remote-' . $run);
status_check($repository->payment($id)['remote_id'] === 'new-remote-' . $run && $repository->payment($id)['reference'] === 'new-reference-' . $run, 'Empty reference does not prevent remote-only update');
$repository->updatePaymentStatus($id, 'paid');
status_check($repository->trustedStatus('native_test', $paid['subject_id'], 'USD')['net_paid_minor'] === 1000, 'Repeated paid update does not duplicate trusted payment amount');

$cancelId = $create('cancel');
$repository->updatePaymentStatus($cancelId, 'authorized');
$repository->updatePaymentStatus($cancelId, 'cancelled');
status_check($repository->payment($cancelId)['status'] === 'cancelled' && $repository->payment($cancelId)['cancelled_at'] !== null, 'Cancellation updates status and timestamp');
$refundKey = 'refund-' . $run;
$repository->insertRefund([
    'payment_id' => $id, 'provider_id' => 'test.native', 'remote_id' => $refundKey,
    'status' => 'completed', 'amount_minor' => 1000, 'currency' => 'USD',
    'reason' => 'Isolated native preparation test', 'idempotency_key' => $refundKey,
    'request_hash' => hash('sha256', $refundKey),
]);
$repository->updatePaymentStatus($id, 'refunded');
$repository->updatePaymentStatus($id, 'refunded');
status_check($repository->payment($id)['status'] === 'refunded' && $repository->trustedStatus('native_test', $paid['subject_id'], 'USD')['net_paid_minor'] === 0, 'Refund and duplicate update preserve zero net paid total');
try {
    $repository->updatePaymentStatus($id, 'paid');
    throw new LogicException('Unexpected terminal resurrection');
} catch (PaymentException) {
    status_check($repository->payment($id)['status'] === 'refunded' && !$pdo->inTransaction(), 'Invalid transition cannot revive a refunded payment');
}

$outerId = $create('outer');
$before = $repository->payment($outerId);
$pdo->beginTransaction();
$repository->updatePaymentStatus($outerId, 'authorized', 'outer-' . $run, 'outer-ref-' . $run);
status_check($pdo->inTransaction() && $repository->payment($outerId)['status'] === 'authorized', 'Successful update leaves caller transaction open');
$pdo->rollBack();
status_check($repository->payment($outerId) === $before, 'Caller rollback restores every payment field');

// Inject a database failure at the actual UPDATE, not a mocked repository method.
$trigger = 'native_status_' . $run;
$failReference = 'reject-' . $run;
$pdo->exec($mysql
    ? "CREATE TRIGGER $trigger BEFORE UPDATE ON cms_payments FOR EACH ROW BEGIN IF NEW.reference = '$failReference' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Isolated status write failure'; END IF; END"
    : "CREATE TRIGGER $trigger BEFORE UPDATE ON cms_payments WHEN NEW.reference = '$failReference' BEGIN SELECT RAISE(ABORT, 'Isolated status write failure'); END");
try {
    foreach ([false, true] as $outer) {
        if ($outer) {
            $pdo->beginTransaction();
        }
        try {
            $repository->updatePaymentStatus($outerId, 'authorized', '', $failReference);
            throw new LogicException('Unexpected injected-write success');
        } catch (PDOException) {
            status_check($pdo->inTransaction() === $outer, $outer ? 'SQL failure never rolls back caller transaction' : 'SQL failure rolls back repository-owned transaction');
            status_check($repository->payment($outerId) === $before, 'Failed SQL leaves no changed payment fields');
        } finally {
            if ($outer && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
} finally {
    $pdo->exec('DROP TRIGGER ' . $trigger);
}
echo 'PAYMENT STATUS NATIVE PREPARE CONTRACT PASS ' . PHP_VERSION . ' ' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . PHP_EOL;
