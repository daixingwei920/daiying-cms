<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('CMS_PAYMENT_TEST_ROOT') ?: dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Payment\PaymentTransaction;

function transaction_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function transaction_database(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $migration = require CMS_ROOT . '/system/migrations/2026_08_20_000001_core_payment_schema.php';
    $migration->up($pdo);
    $pdo->exec('CREATE TABLE cms_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER, action TEXT, context_json TEXT, created_at TEXT)');
    return $pdo;
}

function transaction_payment(PaymentRepository $repository, string $suffix): int
{
    return $repository->insertPayment([
        'subject_type' => 'local_test',
        'subject_id' => 'order:' . $suffix,
        'provider_id' => 'test.sqlite-provider',
        'remote_id' => 'checkout-' . $suffix,
        'reference' => 'checkout-' . $suffix,
        'status' => 'pending',
        'amount_minor' => 195,
        'currency' => 'USD',
        'idempotency_key' => 'checkout-' . $suffix,
        'request_hash' => hash('sha256', $suffix),
    ]);
}

function transaction_webhook(PaymentService $service, string $eventId, array $payload): array
{
    $raw = json_encode(['event_id' => $eventId] + $payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $receipt = $service->recordWebhookReceipt('test.sqlite-provider', $eventId, $raw);
    $payment = $service->applyWebhookPaymentStatus('test.sqlite-provider', (int) $receipt['id'], $raw);
    return ['receipt' => $receipt, 'payment' => $payment];
}

function transaction_authorization(PaymentRepository $repository, int $paymentId, string $suffix): int
{
    $payment = $repository->payment($paymentId);
    return $repository->insertAuthorization([
        'payment_id' => $paymentId,
        'subject_type' => $payment['subject_type'],
        'subject_id' => $payment['subject_id'],
        'token_hash' => hash('sha256', 'local-test-authorization-' . $suffix),
        'status' => 'active',
        'max_uses' => 1,
        'used_count' => 0,
        'expires_at' => gmdate('c', time() + 3600),
    ]);
}

$pdo = transaction_database();
$repository = new PaymentRepository($pdo);
$service = new PaymentService($pdo, $repository);
$paymentId = transaction_payment($repository, 'paid');
$paidPayload = ['provider_payment_id' => 'checkout-paid', 'payment_status' => 'paid'];
$paid = transaction_webhook($service, 'evt_paid', $paidPayload);
transaction_check(($paid['payment']['status'] ?? '') === 'paid', 'paid webhook completes its SQLite transaction without nesting');
transaction_check($repository->webhookReceiptById((int) $paid['receipt']['id'])['status'] === 'processed', 'payment and webhook receipt commit together');
transaction_check($repository->trustedStatus('local_test', 'order:paid', 'USD')['status'] === 'paid', 'trusted payment status reflects the committed payment');
$duplicate = transaction_webhook($service, 'evt_paid', $paidPayload);
transaction_check($duplicate['receipt']['id'] === $paid['receipt']['id'] && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_webhook_receipts')->fetchColumn() === 1, 'duplicate paid event reuses the receipt');

$authorizationId = transaction_authorization($repository, $paymentId, 'paid');
$partial = transaction_webhook($service, 'evt_partial_refund', [
    'event_type' => 'refund.updated',
    'provider_payment_id' => 'checkout-paid',
    'payment_status' => 'partially_refunded',
    'refund' => ['provider_refund_id' => 'refund-partial', 'status' => 'completed', 'amount_minor' => 40],
]);
transaction_check($partial['payment']['status'] === 'partially_refunded', 'partial refund joins the same SQLite transaction');
$full = transaction_webhook($service, 'evt_full_refund', [
    'event_type' => 'refund.updated',
    'provider_payment_id' => 'checkout-paid',
    'payment_status' => 'refunded',
    'refund' => ['provider_refund_id' => 'refund-full', 'status' => 'completed', 'amount_minor' => 155],
]);
transaction_check($full['payment']['status'] === 'refunded', 'full refund commits the payment state and refund together');
$authorization = $pdo->query('SELECT status FROM cms_payment_authorizations WHERE id = ' . $authorizationId)->fetchColumn();
transaction_check($authorization === 'revoked', 'full refund revokes the existing payment authorization');
transaction_webhook($service, 'evt_full_refund', [
    'event_type' => 'refund.updated',
    'provider_payment_id' => 'checkout-paid',
    'payment_status' => 'refunded',
    'refund' => ['provider_refund_id' => 'refund-full', 'status' => 'completed', 'amount_minor' => 155],
]);
transaction_check((int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === 2, 'duplicate refund event does not duplicate refunds');

$failureId = transaction_payment($repository, 'rollback');
$failurePayload = json_encode(['event_id' => 'evt_rollback', 'provider_payment_id' => 'checkout-rollback', 'payment_status' => 'paid'], JSON_UNESCAPED_SLASHES);
$failureReceipt = $service->recordWebhookReceipt('test.sqlite-provider', 'evt_rollback', $failurePayload);
$pdo->exec("CREATE TRIGGER reject_webhook_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.webhook.applied' BEGIN SELECT RAISE(ABORT, 'audit test failure'); END");
try {
    $service->applyWebhookPaymentStatus('test.sqlite-provider', (int) $failureReceipt['id'], $failurePayload);
    throw new RuntimeException('Expected atomic rollback did not occur.');
} catch (PDOException $exception) {
    transaction_check(str_contains($exception->getMessage(), 'audit test failure'), 'injected audit failure reaches the actual rollback path');
}
transaction_check($repository->payment($failureId)['status'] === 'pending' && $repository->webhookReceiptById((int) $failureReceipt['id'])['status'] === 'received', 'audit failure rolls back both payment and receipt state');
transaction_check(!PaymentTransaction::isActive($pdo), 'rolled-back transaction does not leave stale shared state');
$pdo->exec('DROP TRIGGER reject_webhook_audit');
$recovered = $service->applyWebhookPaymentStatus('test.sqlite-provider', (int) $failureReceipt['id'], $failurePayload);
transaction_check($recovered['status'] === 'paid', 'retry after rollback succeeds on the same connection and service');

$callerId = transaction_payment($repository, 'caller');
$pdo->beginTransaction();
transaction_webhook($service, 'evt_caller', ['provider_payment_id' => 'checkout-caller', 'payment_status' => 'paid']);
transaction_check($pdo->inTransaction(), 'caller-owned native transaction is left open');
$pdo->rollBack();
transaction_check($repository->payment($callerId)['status'] === 'pending', 'caller rollback still owns the payment and receipt writes');

$sharedId = transaction_payment($repository, 'shared');
PaymentTransaction::beginImmediate($pdo);
$borrower = new PaymentService($pdo, new PaymentRepository($pdo));
transaction_webhook($borrower, 'evt_shared', ['provider_payment_id' => 'checkout-shared', 'payment_status' => 'paid']);
transaction_check(PaymentTransaction::isActive($pdo), 'another service borrows the existing immediate transaction');
$separate = transaction_database();
transaction_check(!PaymentTransaction::isActive($separate), 'manual transaction state is isolated by PDO connection');
PaymentTransaction::rollback($pdo);
transaction_check($repository->payment($sharedId)['status'] === 'pending', 'borrowed service and repository do not commit the owner transaction');
$standaloneId = transaction_payment($repository, 'standalone');
$repository->updatePaymentStatus($standaloneId, 'paid');
transaction_check($repository->payment($standaloneId)['status'] === 'paid' && !$pdo->inTransaction(), 'standalone repository still commits its native transaction');

foreach (['failed', 'cancelled'] as $status) {
    $terminalId = transaction_payment($repository, $status);
    $terminal = transaction_webhook($service, 'evt_' . $status, ['provider_payment_id' => 'checkout-' . $status, 'payment_status' => $status]);
    transaction_check($terminal['payment']['status'] === $status && $repository->payment($terminalId)['status'] === $status && !PaymentTransaction::isActive($pdo), $status . ' webhook commits its terminal state without granting paid status');
}

PaymentTransaction::beginImmediate($pdo);
$consumedId = transaction_authorization($repository, $standaloneId, 'consume');
$revokedId = transaction_authorization($repository, $standaloneId, 'revoke');
$expiredId = transaction_authorization($repository, $standaloneId, 'expire');
transaction_check($repository->consumeAuthorization($consumedId) && !$repository->consumeAuthorization($consumedId), 'authorization insertion and single-use consumption join the immediate scope');
transaction_check($repository->revokeAuthorization($revokedId), 'authorization revocation joins the immediate scope');
$expireStatement = $pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id');
$expireStatement->execute([':expires_at' => gmdate('c', time() - 3600), ':id' => $expiredId]);
transaction_check($repository->expireExpiredAuthorizations() === 1, 'authorization expiry joins the immediate scope');
PaymentTransaction::rollback($pdo);
transaction_check($repository->authorization($consumedId) === null && $repository->authorization($revokedId) === null && $repository->authorization($expiredId) === null, 'authorization operations remain atomic under the caller rollback');

$lockPath = tempnam(sys_get_temp_dir(), 'daiying-payment-lock-');
$writer = new PDO('sqlite:' . $lockPath);
$contender = new PDO('sqlite:' . $lockPath);
$writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$contender->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$contender->exec('PRAGMA busy_timeout = 0');
try {
    PaymentTransaction::beginImmediate($writer);
    try {
        PaymentTransaction::beginImmediate($contender);
        throw new RuntimeException('Immediate transaction did not reserve the writer lock.');
    } catch (PDOException $exception) {
        transaction_check(str_contains($exception->getMessage(), 'database is locked'), 'BEGIN IMMEDIATE still reserves the SQLite writer lock');
    }
    transaction_check(!PaymentTransaction::isActive($contender), 'failed BEGIN does not leave a tracked transaction');
    PaymentTransaction::rollback($writer);
    PaymentTransaction::beginImmediate($contender);
    PaymentTransaction::commit($contender);
    transaction_check(!PaymentTransaction::isActive($contender), 'writer lock is released and a subsequent transaction commits');
} finally {
    foreach ([$writer, $contender] as $connection) {
        if (PaymentTransaction::isActive($connection)) {
            PaymentTransaction::rollback($connection);
        }
    }
    unset($connection, $writer, $contender);
    unlink($lockPath);
}
echo "Payment SQLite transaction contracts passed on PHP " . PHP_VERSION . ".\n";
