<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Config\Settings;
use Cms\Core\Export\CorePaymentLedgerImporter;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\ExportPackageReader;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentEntitlementService;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function core_payment_mysql_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function core_payment_mysql_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        core_payment_mysql_check(true, $message);
        return;
    }

    core_payment_mysql_check(false, $message);
}

/** @return list<string> */
function core_payment_mysql_index_columns(PDO $pdo, string $table, string $index): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.statistics
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index AND NON_UNIQUE = 0
         ORDER BY SEQ_IN_INDEX ASC'
    );
    $stmt->execute([':table' => $table, ':index' => $index]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function core_payment_mysql_has_index(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
    );
    $stmt->execute([':table' => $table, ':index' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

/** @return list<string> */
function core_payment_mysql_any_index_columns(PDO $pdo, string $table, string $index): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.statistics
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index
         ORDER BY SEQ_IN_INDEX ASC'
    );
    $stmt->execute([':table' => $table, ':index' => $index]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function core_payment_mysql_identifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

if (!extension_loaded('pdo_mysql')) {
    echo "[SKIP] pdo_mysql is unavailable.\n";
    exit(0);
}

$database = 'cms_core_payment_' . bin2hex(random_bytes(4));
$restoreDatabase = $database . '_restore';
$rootPdo = null;

try {
    $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $exception) {
    echo '[SKIP] local MySQL/MariaDB is unavailable: ' . $exception->getMessage() . PHP_EOL;
    exit(0);
}

try {
    $rootPdo->exec('CREATE DATABASE ' . core_payment_mysql_identifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $rootPdo->exec('CREATE DATABASE ' . core_payment_mysql_identifier($restoreDatabase) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $database . ';charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $coreMigration = require CMS_ROOT . '/system/migrations/2026_08_12_000001_core_schema.php';
    $paymentMigration = require CMS_ROOT . '/system/migrations/2026_08_20_000001_core_payment_schema.php';
    (new MigrationRunner($pdo, [$coreMigration, $paymentMigration]))->run();

    foreach (['cms_audit_logs'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        core_payment_mysql_check((int) $stmt->fetchColumn() === 1, 'real MySQL/MariaDB creates Core base table ' . $table . ' for payment audit integration');
    }
    foreach (['cms_payments', 'cms_payment_refunds', 'cms_payment_webhook_receipts', 'cms_payment_authorizations', 'cms_payment_authorization_events', 'cms_payment_provider_settings', 'cms_payment_entitlements'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        core_payment_mysql_check((int) $stmt->fetchColumn() === 1, 'real MySQL/MariaDB creates Core payment table ' . $table);
    }

    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payments', 'cms_payments_idempotency_unique') === ['idempotency_key'], 'real MySQL/MariaDB enforces payment idempotency unique index');
    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payments', 'cms_payments_provider_remote_unique') === ['provider_id', 'remote_id'], 'real MySQL/MariaDB enforces provider remote payment unique index');
    core_payment_mysql_check(core_payment_mysql_any_index_columns($pdo, 'cms_payments', 'cms_payments_created_idx') === ['created_at'], 'real MySQL/MariaDB indexes payment reconciliation creation windows');
    core_payment_mysql_check(core_payment_mysql_any_index_columns($pdo, 'cms_payments', 'cms_payments_currency_created_idx') === ['currency', 'created_at'], 'real MySQL/MariaDB indexes currency-scoped payment reconciliation windows');
    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payment_refunds', 'cms_payment_refunds_idempotency_unique') === ['idempotency_key'], 'real MySQL/MariaDB enforces refund idempotency unique index');
    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payment_refunds', 'cms_payment_refunds_provider_remote_unique') === ['provider_id', 'remote_id'], 'real MySQL/MariaDB enforces provider remote refund unique index');
    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payment_webhook_receipts', 'cms_payment_webhook_event_unique') === ['provider_id', 'external_event_id'], 'real MySQL/MariaDB enforces webhook event uniqueness');
    core_payment_mysql_check(core_payment_mysql_has_index($pdo, 'cms_payment_webhook_receipts', 'cms_payment_webhook_receipts_payment_idx'), 'real MySQL/MariaDB indexes payment-bound webhook receipts');
    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payment_authorizations', 'cms_payment_authorizations_token_unique') === ['token_hash'], 'real MySQL/MariaDB enforces authorization token-hash uniqueness');
    core_payment_mysql_check(core_payment_mysql_index_columns($pdo, 'cms_payment_provider_settings', 'cms_payment_provider_settings_provider_unique') === ['provider_id'], 'real MySQL/MariaDB enforces provider settings uniqueness');

    PaymentProviderRegistry::clear();
    PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
    PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
    $providerSettings = new PaymentProviderSettingsRepository($pdo, 'core-payment-mysql-key');
    $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'MySQL Fixture', 'enabled', ['mode' => 'mysql'], ['api_secret' => 'sk_mysql']);
    $providerSettings->save(ManualPaymentProvider::PROVIDER_ID, 'MySQL Manual', 'enabled', ['instructions' => 'Confirm bank transfer in Core admin.'], []);
    $providerSettings->setDefaultProvider(ManualPaymentProvider::PROVIDER_ID);
    $mysqlManualDefaultConfig = json_decode((string) ($providerSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
    $mysqlFixtureDefaultConfig = json_decode((string) ($providerSettings->setting(FixturePaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
    core_payment_mysql_check(
        (new PaymentProviderSelector($pdo, Settings::fromArray(['security' => ['encryption_key' => 'core-payment-mysql-key']])))->defaultProviderId() === ManualPaymentProvider::PROVIDER_ID
        && ($mysqlManualDefaultConfig['default_provider'] ?? false) === true
        && !($mysqlFixtureDefaultConfig['default_provider'] ?? false),
        'real MySQL/MariaDB Core payment Provider selector honors explicit default Provider'
    );
    $providerSettings->save(ManualPaymentProvider::PROVIDER_ID, 'MySQL Manual Disabled', 'disabled', ['instructions' => 'disabled'], []);
    $mysqlManualDisabledConfig = json_decode((string) ($providerSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
    core_payment_mysql_check(
        !($mysqlManualDisabledConfig['default_provider'] ?? false)
        && (new PaymentProviderSelector($pdo, Settings::fromArray(['security' => ['encryption_key' => 'core-payment-mysql-key']])))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID,
        'real MySQL/MariaDB Core payment Provider selector falls back after disabling the default Provider'
    );
    $providerSettings->save(ManualPaymentProvider::PROVIDER_ID, 'MySQL Manual', 'enabled', ['instructions' => 'Confirm bank transfer in Core admin.'], []);
    $repo = new PaymentRepository($pdo);
    $service = new PaymentService($pdo, $repo);

    $payment = $service->createProviderPayment('paid_content', 'mysql-content-1', FixturePaymentProvider::PROVIDER_ID, 1200, 'USD', 'mysql-pay-1');
    $again = $service->createProviderPayment('paid_content', 'mysql-content-1', FixturePaymentProvider::PROVIDER_ID, 1200, 'USD', 'mysql-pay-1');
    core_payment_mysql_check((int) $payment['id'] === (int) $again['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_payments WHERE idempotency_key = 'mysql-pay-1'")->fetchColumn() === 1, 'real MySQL/MariaDB Core payment create is idempotent');
    core_payment_mysql_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.created' AND context_json LIKE '%mysql-pay-1%'")->fetchColumn() === 1, 'real MySQL/MariaDB Core payment create writes one audit event across idempotent retry');
    core_payment_mysql_throws(static fn () => $service->createProviderPayment('paid_content', 'mysql-content-1', FixturePaymentProvider::PROVIDER_ID, 1300, 'USD', 'mysql-pay-1'), 'real MySQL/MariaDB rejects payment idempotency key reuse with changed content');
    $pdo->beginTransaction();
    $lockedPayment = $repo->paymentByIdempotencyForUpdate('mysql-pay-1');
    $pdo->commit();
    core_payment_mysql_check(is_array($lockedPayment) && (int) ($lockedPayment['id'] ?? 0) === (int) $payment['id'], 'real MySQL/MariaDB Core payment idempotency lookup supports row locking for hosted returns');

    $manualPayment = $service->createProviderPayment('paid_content', 'mysql-manual-content', ManualPaymentProvider::PROVIDER_ID, 2100, 'USD', 'mysql-manual-pay');
    $manualPendingStatus = $service->trustedStatus('paid_content', 'mysql-manual-content', 'USD');
    core_payment_mysql_check(
        (string) ($manualPayment['status'] ?? '') === 'pending'
        && str_starts_with((string) ($manualPayment['remote_id'] ?? ''), 'core-manual-pay-')
        && (string) ($manualPendingStatus['status'] ?? '') === 'unpaid',
        'real MySQL/MariaDB Core manual Provider records pending operator-confirmed payments'
    );
    $manualSynced = $service->syncProviderPaymentStatus((int) ($manualPayment['id'] ?? 0), 'paid');
    core_payment_mysql_check((string) ($manualSynced['status'] ?? '') === 'pending', 'real MySQL/MariaDB Core manual Provider status sync cannot promote pending payments without administrator capture');
    $manualCaptured = $service->captureProviderPayment((int) ($manualPayment['id'] ?? 0), 'mysql-manual-capture');
    $manualCapturedStatus = $service->trustedStatus('paid_content', 'mysql-manual-content', 'USD');
    core_payment_mysql_check(
        (string) ($manualCaptured['status'] ?? '') === 'paid'
        && (string) ($manualCapturedStatus['status'] ?? '') === 'paid'
        && (int) ($manualCapturedStatus['net_paid_minor'] ?? 0) === 2100,
        'real MySQL/MariaDB Core manual Provider capture creates trusted paid state'
    );
    $manualRefund = $service->refundProviderPayment((int) ($manualCaptured['id'] ?? 0), 2100, 'mysql manual refund', 'mysql-manual-refund');
    core_payment_mysql_check(
        (string) ($manualRefund['status'] ?? '') === 'completed'
        && str_starts_with((string) ($manualRefund['remote_id'] ?? ''), 'core-manual-refund-')
        && (string) (($repo->payment((int) ($manualCaptured['id'] ?? 0))['status'] ?? '')) === 'refunded',
        'real MySQL/MariaDB Core manual Provider refund is recorded in Core ledger'
    );

    $refund = $service->refundProviderPayment((int) $payment['id'], 200, 'mysql partial', 'mysql-refund-1');
    $repeatRefund = $service->refundProviderPayment((int) $payment['id'], 200, 'mysql partial', 'mysql-refund-1');
    core_payment_mysql_check((int) $refund['id'] === (int) $repeatRefund['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_refunds WHERE idempotency_key = 'mysql-refund-1'")->fetchColumn() === 1, 'real MySQL/MariaDB Core refund create is idempotent');
    core_payment_mysql_throws(static fn () => $service->refundProviderPayment((int) $payment['id'], 300, 'changed', 'mysql-refund-1'), 'real MySQL/MariaDB rejects refund idempotency key reuse with changed content');
    $service->createProviderPayment('paid_content', 'mysql-content-1-cny-shadow', FixturePaymentProvider::PROVIDER_ID, 500, 'CNY', 'mysql-pay-cny-shadow');
    $today = gmdate('Y-m-d');
    $mysqlExportRows = $repo->exportPayments(['q' => 'mysql-content-1', 'currency' => 'usd', 'created_from' => $today]);
    core_payment_mysql_check(
        count($mysqlExportRows) === 1
        && (int) ($mysqlExportRows[0]['amount_minor'] ?? 0) === 1200
        && (int) ($mysqlExportRows[0]['refunded_minor'] ?? 0) === 200
        && (int) ($mysqlExportRows[0]['net_paid_minor'] ?? 0) === 1000,
        'real MySQL/MariaDB Core payment export filters currency and creation window while including refund totals and net paid amount'
    );
    $mysqlSummaryRows = $repo->paymentSummary(['q' => 'mysql-content-1', 'currency' => 'usd', 'created_from' => $today]);
    core_payment_mysql_check(
        count($mysqlSummaryRows) === 1
        && (string) ($mysqlSummaryRows[0]['currency'] ?? '') === 'USD'
        && (int) ($mysqlSummaryRows[0]['payment_count'] ?? 0) === 1
        && (int) ($mysqlSummaryRows[0]['amount_minor'] ?? 0) === 1200
        && (int) ($mysqlSummaryRows[0]['refunded_minor'] ?? 0) === 200
        && (int) ($mysqlSummaryRows[0]['net_paid_minor'] ?? 0) === 1000,
        'real MySQL/MariaDB Core payment summary filters currency and creation window while grouping refund totals and net paid amount by currency'
    );
    core_payment_mysql_check(
        $repo->paymentSummary(['created_to' => '1999-01-01']) === [],
        'real MySQL/MariaDB Core payment summary filters out records before creation window'
    );
    $mixedUsdPayment = $service->createProviderPayment('paid_content', 'mysql-mixed-currency-content', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'mysql-mixed-usd');
    $mixedCnyPayment = $service->createProviderPayment('paid_content', 'mysql-mixed-currency-content', FixturePaymentProvider::PROVIDER_ID, 1000, 'CNY', 'mysql-mixed-cny');
    $service->refundProviderPayment((int) $mixedUsdPayment['id'], 1000, 'mysql full usd refund', 'mysql-mixed-usd-refund');
    $mysqlMixedUsdStatus = $service->trustedStatus('paid_content', 'mysql-mixed-currency-content', 'USD');
    $mysqlMixedCnyStatus = $service->trustedStatus('paid_content', 'mysql-mixed-currency-content', 'CNY');
    core_payment_mysql_check(
        (int) ($mixedCnyPayment['id'] ?? 0) > 0
        && (string) ($mysqlMixedUsdStatus['status'] ?? '') === 'unpaid'
        && (int) ($mysqlMixedUsdStatus['net_paid_minor'] ?? -1) === 0
        && (string) ($mysqlMixedCnyStatus['status'] ?? '') === 'paid'
        && (int) ($mysqlMixedCnyStatus['net_paid_minor'] ?? 0) === 1000,
        'real MySQL/MariaDB Core trusted payment status can be scoped by currency'
    );

    $receipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-1', '{"id":"mysql-event-1"}');
    $repeatReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-1', '{"id":"mysql-event-1"}');
    core_payment_mysql_check((int) $receipt['id'] === (int) $repeatReceipt['id'] && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'mysql-event-1'")->fetchColumn() === 1, 'real MySQL/MariaDB Core webhook receipt is idempotent');
    core_payment_mysql_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-1', '{"id":"changed"}'), 'real MySQL/MariaDB rejects webhook event id reuse with changed payload');
    $webhookPayment = $service->createProviderPayment('paid_content', 'mysql-webhook-content', FixturePaymentProvider::PROVIDER_ID, 1500, 'USD', 'mysql-webhook-pay', 'authorized');
    $webhookPayload = json_encode(['provider_payment_id' => (string) ($webhookPayment['remote_id'] ?? ''), 'status' => 'paid'], JSON_UNESCAPED_SLASHES);
    $webhookReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-apply', (string) $webhookPayload);
    $appliedPayment = $service->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($webhookReceipt['id'] ?? 0), (string) $webhookPayload);
    core_payment_mysql_check(
        is_array($appliedPayment)
        && (string) ($appliedPayment['status'] ?? '') === 'paid'
        && (int) ($repo->webhookReceiptById((int) ($webhookReceipt['id'] ?? 0))['payment_id'] ?? 0) === (int) ($webhookPayment['id'] ?? 0)
        && (string) ($repo->webhookReceiptById((int) ($webhookReceipt['id'] ?? 0))['status'] ?? '') === 'processed',
        'real MySQL/MariaDB Core webhook applies provider-neutral payment status and binds the receipt to its payment'
    );
    $staleWebhookPayload = json_encode(['provider_payment_id' => (string) ($webhookPayment['remote_id'] ?? ''), 'status' => 'failed'], JSON_UNESCAPED_SLASHES);
    $staleWebhookReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-stale-status', (string) $staleWebhookPayload);
    core_payment_mysql_throws(static fn () => $service->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($staleWebhookReceipt['id'] ?? 0), (string) $staleWebhookPayload), 'real MySQL/MariaDB Core webhook rejects stale status downgrades after payment');
    $failedStaleWebhookReceipt = $service->markWebhookReceiptFailed((int) ($staleWebhookReceipt['id'] ?? 0), 'Payment status transition is not allowed.');
    $failedStaleWebhookMeta = json_decode((string) ($failedStaleWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
    core_payment_mysql_check((string) ($repo->payment((int) ($webhookPayment['id'] ?? 0))['status'] ?? '') === 'paid', 'real MySQL/MariaDB Core payment status remains paid after rejected stale webhook');
    core_payment_mysql_check(
        (string) ($failedStaleWebhookReceipt['status'] ?? '') === 'failed'
        && (int) ($failedStaleWebhookReceipt['payment_id'] ?? 0) === (int) ($webhookPayment['id'] ?? 0)
        && (string) ($failedStaleWebhookMeta['failure_error'] ?? '') === 'Payment status transition is not allowed.',
        'real MySQL/MariaDB Core webhook receipt can be marked failed with payment-bound diagnostic metadata'
    );
    $webhookRefundPayment = $service->createProviderPayment('paid_content', 'mysql-webhook-refund-content', FixturePaymentProvider::PROVIDER_ID, 1800, 'USD', 'mysql-webhook-refund-pay');
    $webhookRefundPayload = json_encode([
        'event_type' => 'payment.refund.completed',
        'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
        'refund' => [
            'provider_refund_id' => 'mysql-webhook-refund-1',
            'amount_minor' => 800,
            'status' => 'completed',
        ],
    ], JSON_UNESCAPED_SLASHES);
    $webhookRefundReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-refund', (string) $webhookRefundPayload);
    $appliedRefundPayment = $service->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($webhookRefundReceipt['id'] ?? 0), (string) $webhookRefundPayload);
    $repeatRefundPayment = $service->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($webhookRefundReceipt['id'] ?? 0), (string) $webhookRefundPayload);
    core_payment_mysql_check(
        is_array($appliedRefundPayment)
        && is_array($repeatRefundPayment)
        && (string) ($appliedRefundPayment['status'] ?? '') === 'partially_refunded'
        && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds WHERE payment_id = ' . (int) ($webhookRefundPayment['id'] ?? 0))->fetchColumn() === 1
        && (int) ($repo->webhookReceiptById((int) ($webhookRefundReceipt['id'] ?? 0))['payment_id'] ?? 0) === (int) ($webhookRefundPayment['id'] ?? 0)
        && (string) ($repo->webhookReceiptById((int) ($webhookRefundReceipt['id'] ?? 0))['status'] ?? '') === 'processed',
        'real MySQL/MariaDB Core webhook records provider-neutral refunds idempotently and binds the receipt to its payment'
    );
    $duplicateRefundPayload = json_encode([
        'event_type' => 'payment.refund.completed',
        'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
        'refund' => [
            'provider_refund_id' => 'mysql-webhook-refund-1',
            'amount_minor' => 800,
            'status' => 'completed',
        ],
    ], JSON_UNESCAPED_SLASHES);
    $duplicateRefundReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-refund-duplicate', (string) $duplicateRefundPayload);
    core_payment_mysql_throws(static fn () => $service->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($duplicateRefundReceipt['id'] ?? 0), (string) $duplicateRefundPayload), 'real MySQL/MariaDB Core webhook rejects duplicate provider refund references');
    $webhookRefundAuthorizationId = $repo->insertAuthorization([
        'payment_id' => (int) ($webhookRefundPayment['id'] ?? 0),
        'subject_type' => 'paid_content',
        'subject_id' => 'mysql-webhook-refund-content',
        'token_hash' => hash('sha256', 'mysql-webhook-refund-token-secret'),
        'status' => 'active',
        'max_uses' => 0,
        'used_count' => 0,
        'expires_at' => gmdate('c', time() + 3600),
        'metadata' => ['acceptance' => 'mysql-webhook-full-refund-revoke'],
    ]);
    $webhookRefundEntitlement = (new PaymentEntitlementService($pdo, $repo))->grantFromPayment((int) ($webhookRefundPayment['id'] ?? 0), 'member', 'mysql-webhook-refund-member', $webhookRefundAuthorizationId, '');
    $webhookFullRefundPayload = json_encode([
        'event_type' => 'payment.refund.completed',
        'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
        'refund' => [
            'provider_refund_id' => 'mysql-webhook-refund-2',
            'amount_minor' => 1000,
            'status' => 'completed',
        ],
    ], JSON_UNESCAPED_SLASHES);
    $webhookFullRefundReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'mysql-event-refund-full', (string) $webhookFullRefundPayload);
    $webhookFullRefundPayment = $service->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($webhookFullRefundReceipt['id'] ?? 0), (string) $webhookFullRefundPayload);
    $webhookRevokedAuthorization = $repo->authorization($webhookRefundAuthorizationId);
    $webhookRevokedEntitlement = $repo->entitlement((int) ($webhookRefundEntitlement['id'] ?? 0));
    core_payment_mysql_check(
        is_array($webhookFullRefundPayment)
        && (string) ($webhookFullRefundPayment['status'] ?? '') === 'refunded'
        && is_array($webhookRevokedAuthorization)
        && (string) ($webhookRevokedAuthorization['status'] ?? '') === 'revoked'
        && is_array($webhookRevokedEntitlement)
        && (string) ($webhookRevokedEntitlement['status'] ?? '') === 'revoked'
        && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $webhookRefundAuthorizationId . " AND event_type = 'revoked'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.access.revoked_after_refund' AND context_json LIKE '%webhook_refund%' AND context_json LIKE '%\"payment_id\":" . (int) ($webhookRefundPayment['id'] ?? 0) . "%'")->fetchColumn() === 1,
        'real MySQL/MariaDB Core webhook full refund revokes payment-backed authorization and entitlement state'
    );

    $entitlement = (new PaymentEntitlementService($pdo, $repo))->grantFromPayment((int) $payment['id'], 'member', 'mysql-member', 0, gmdate('c', time() + 3600));
    core_payment_mysql_check((int) ($entitlement['id'] ?? 0) > 0 && (new PaymentEntitlementService($pdo, $repo))->isEntitled('member', 'mysql-member', 'paid_content', 'mysql-content-1'), 'real MySQL/MariaDB Core payment entitlement grants tokenless access');
    $authorizationId = $repo->insertAuthorization([
        'payment_id' => (int) $payment['id'],
        'subject_type' => 'paid_content',
        'subject_id' => 'mysql-content-1',
        'token_hash' => hash('sha256', 'mysql-token-secret'),
        'status' => 'active',
        'max_uses' => 0,
        'used_count' => 0,
        'expires_at' => gmdate('c', time() + 3600),
        'metadata' => ['acceptance' => 'mysql-full-refund-revoke'],
    ]);
    $remainingRefund = $service->refundProviderPayment((int) $payment['id'], 1000, 'mysql remaining full refund', 'mysql-refund-remaining');
    $revokedAuthorization = $repo->authorization($authorizationId);
    $revokedEntitlement = $repo->entitlement((int) ($entitlement['id'] ?? 0));
    core_payment_mysql_check(
        (string) ($remainingRefund['status'] ?? '') === 'completed'
        && (string) (($repo->payment((int) $payment['id'])['status'] ?? '')) === 'refunded'
        && is_array($revokedAuthorization)
        && (string) ($revokedAuthorization['status'] ?? '') === 'revoked'
        && is_array($revokedEntitlement)
        && (string) ($revokedEntitlement['status'] ?? '') === 'revoked'
        && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $authorizationId . " AND event_type = 'revoked'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.access.revoked_after_refund' AND context_json LIKE '%\"payment_id\":" . (int) $payment['id'] . "%'")->fetchColumn() === 1,
        'real MySQL/MariaDB Core full refund revokes payment-backed authorization and entitlement state'
    );
    core_payment_mysql_check(!(new PaymentEntitlementService($pdo, $repo))->isEntitled('member', 'mysql-member', 'paid_content', 'mysql-content-1'), 'real MySQL/MariaDB Core full refund invalidates tokenless entitlement access');

    if (class_exists(ZipArchive::class)) {
        $exportRoot = sys_get_temp_dir() . '/cms-core-payment-mysql-export-' . bin2hex(random_bytes(4));
        mkdir($exportRoot . '/storage/exports', 0775, true);
        $packagePath = (new ExportPackageBuilder($exportRoot, $pdo, '1.2.0-core-payment-mysql'))->build('mysql-payment-ledger-restore');
        $reader = new ExportPackageReader();
        $ledger = $reader->paymentLedger($packagePath);
        $restorePdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $restoreDatabase . ';charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        (new MigrationRunner($restorePdo, [$coreMigration, $paymentMigration]))->run();
        $importer = new CorePaymentLedgerImporter($restorePdo, $reader);
        $importResult = $importer->importPackage($packagePath);
        $reimportResult = $importer->importPackage($packagePath);
        core_payment_mysql_check(
            (int) ($importResult['payments']['imported'] ?? -1) === count($ledger['payments'] ?? [])
            && (int) ($importResult['refunds']['imported'] ?? -1) === count($ledger['refunds'] ?? [])
            && (int) ($importResult['provider_settings']['imported'] ?? -1) === count($ledger['provider_settings'] ?? [])
            && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? [])
            && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() ?: 0) === count($ledger['refunds'] ?? [])
            && (int) ($reimportResult['payments']['skipped'] ?? -1) === count($ledger['payments'] ?? []),
            'real MySQL/MariaDB restores official Core payment ledger idempotently'
        );
        $conflictingLedger = $ledger;
        if (isset($conflictingLedger['payments'][0]) && is_array($conflictingLedger['payments'][0])) {
            $conflictingLedger['payments'][0]['amount_minor'] = (int) ($conflictingLedger['payments'][0]['amount_minor'] ?? 0) + 1;
        }
        core_payment_mysql_throws(
            static fn () => $importer->importLedger($conflictingLedger),
            'real MySQL/MariaDB rejects conflicting duplicate Core payment ledger rows'
        );
        core_payment_mysql_check(
            (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? [])
            && (int) ($restorePdo->query('SELECT amount_minor FROM cms_payments ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0) === (int) ($ledger['payments'][0]['amount_minor'] ?? 0),
            'real MySQL/MariaDB keeps restored payment rows unchanged after ledger conflict'
        );
    }
} catch (Throwable $exception) {
    echo '[FAIL] real MySQL/MariaDB Core payment acceptance failed: ' . $exception->getMessage() . PHP_EOL;
    $failures++;
} finally {
    if ($rootPdo instanceof PDO) {
        $rootPdo->exec('DROP DATABASE IF EXISTS ' . core_payment_mysql_identifier($database));
        $rootPdo->exec('DROP DATABASE IF EXISTS ' . core_payment_mysql_identifier($restoreDatabase));
    }
}

if ($failures > 0) {
    echo '[RESULT] core_payment_mysql_acceptance failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] core_payment_mysql_acceptance passed; mysql=passed' . PHP_EOL;
