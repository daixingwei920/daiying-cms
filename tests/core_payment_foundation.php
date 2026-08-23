<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Content\BlockRenderer;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Export\CorePaymentLedgerImporter;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\ExportPackageReader;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaController;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentEntitlementService;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Payment\PaymentWebhookController;
use Cms\Core\Payment\PaidContentController;
use Cms\Core\Payment\PaidContentService;
use Cms\Core\Payment\PaidDownloadController;
use Cms\Core\Payment\PaidDownloadService;
use Cms\Core\Security\CsrfToken;

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function core_payment_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function core_payment_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        core_payment_check(true, $message);
        return;
    }

    core_payment_check(false, $message);
}

/** @param array<mixed,mixed> $row */
function core_payment_assoc_row(array $row): bool
{
    foreach (array_keys($row) as $key) {
        if (is_int($key)) {
            return false;
        }
    }

    return true;
}

/** @param array<mixed,mixed> $secrets */
function core_payment_test_secret_ciphertext(string $masterKey, array $secrets): string
{
    $nonce = random_bytes(12);
    $plain = json_encode($secrets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', hash('sha256', $masterKey, true), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($cipher)) {
        throw new RuntimeException('Unable to encrypt test payment provider secrets.');
    }

    return base64_encode($nonce . $tag . $cipher);
}

/** @return array<string,mixed> */
function core_payment_token_payload(string $token): array
{
    $parts = explode('.', $token, 2);
    $json = isset($parts[0]) ? base64_decode(strtr($parts[0], '-_', '+/'), true) : false;
    $payload = is_string($json) ? json_decode($json, true) : null;

    return is_array($payload) ? $payload : [];
}

/** @param array<string,mixed> $payload */
function core_payment_signed_token(array $payload, string $secret): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');

    return $body . '.' . $signature;
}

function core_payment_signed_raw_token(string $json, string $secret): string
{
    $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');

    return $body . '.' . $signature;
}

/** @param list<string> $arguments @return array<string,mixed> */
function core_payment_run_cli(string $root, string $command, array $arguments = []): array
{
    $result = core_payment_run_cli_raw($root, $command, $arguments);
    if ((int) $result['code'] !== 0) {
        throw new RuntimeException('Core payment CLI failed: ' . (string) $result['stderr']);
    }

    $decoded = json_decode((string) $result['stdout'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Core payment CLI returned invalid JSON: ' . (string) $result['stdout']);
    }

    return $decoded;
}

/** @param list<string> $arguments @return array{code:int,stdout:string,stderr:string} */
function core_payment_run_cli_raw(string $root, string $command, array $arguments = []): array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $args = [PHP_BINARY, 'cli.php', $command];
    foreach ($arguments as $argument) {
        $args[] = $argument;
    }
    $env = ['CMS_ROOT_OVERRIDE' => $root];
    foreach ($_SERVER as $key => $value) {
        if (is_scalar($value)) {
            $env[(string) $key] = (string) $value;
        }
    }
    $process = proc_open($args, $descriptor, $pipes, CMS_ROOT, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Core payment CLI.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => (int) $code, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

final class FixedRemotePaymentProvider implements PaymentProviderInterface
{
    public function providerId(): string
    {
        return 'core.fixed-remote';
    }

    public function displayName(): string
    {
        return 'Fixed Remote';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.refund'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'fixed_paid', 'Fixed remote payment paid.', false, '', [
            'status' => 'paid',
            'provider_payment_id' => 'fixed-remote-id',
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'fixed_refunded', 'Fixed refund completed.', false, '', [
            'status' => 'completed',
            'provider_refund_id' => 'fixed-refund-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 8),
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }
}

final class NonCanonicalStatusPaymentProvider implements PaymentProviderInterface
{
    public const PROVIDER_ID = 'core.non-canonical-status';

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function displayName(): string
    {
        return 'Non Canonical Status';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.refund', 'payment.status'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'noncanonical_paid', 'Non-canonical status payment.', false, '', [
            'status' => ' Paid ',
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'noncanonical_refund', 'Non-canonical refund status.', false, '', [
            'status' => ' Completed ',
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(true, 'noncanonical_status', 'Non-canonical status sync.', false, '', [
            'status' => ' Paid ',
        ]);
    }
}

final class SettingsAwarePaymentProvider implements PaymentProviderInterface
{
    public function providerId(): string
    {
        return 'core.settings-aware';
    }

    public function displayName(): string
    {
        return 'Settings Aware';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create'];
    }

    public function createPayment(object $command): PaymentResult
    {
        $public = is_array($command->provider_public_config ?? null) ? $command->provider_public_config : [];
        $secrets = is_array($command->provider_secret_config ?? null) ? $command->provider_secret_config : [];
        if (($public['mode'] ?? '') !== 'sandbox' || ($secrets['api_secret'] ?? '') !== 'sk_core_settings_secret') {
            return new PaymentResult(false, 'settings_missing', 'Core Provider settings were not supplied.');
        }

        $remoteId = 'settings-remote-' . substr(hash('sha256', (string) ($command->idempotency_key ?? 'settings')), 0, 12);

        return new PaymentResult(true, 'settings_paid', 'Settings-backed payment paid.', false, $remoteId, [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
            'observed_mode' => (string) $public['mode'],
            'observed_display_name' => (string) ($command->provider_display_name ?? ''),
            'credential_last4' => substr((string) $secrets['api_secret'], -4),
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }
}

final class CountingPaymentProvider implements PaymentProviderInterface
{
    public static int $createCalls = 0;
    public static int $refundCalls = 0;
    public static int $captureCalls = 0;
    public static int $cancelCalls = 0;

    public function providerId(): string
    {
        return 'core.counting-provider';
    }

    public function displayName(): string
    {
        return 'Counting Provider';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.refund', 'payment.capture', 'payment.cancel'];
    }

    public function createPayment(object $command): PaymentResult
    {
        self::$createCalls++;
        $remoteId = 'counting-payment-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 12);

        return new PaymentResult(true, 'counting_paid', 'Counting payment paid.', false, $remoteId, [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        self::$captureCalls++;

        return new PaymentResult(true, 'counting_captured', 'Counting payment captured.', false, (string) ($command->provider_payment_id ?? ''), [
            'status' => 'paid',
            'provider_payment_id' => (string) ($command->provider_payment_id ?? ''),
        ]);
    }

    public function cancelPayment(object $command): PaymentResult
    {
        self::$cancelCalls++;

        return new PaymentResult(true, 'counting_cancelled', 'Counting payment cancelled.', false, (string) ($command->provider_payment_id ?? ''), [
            'status' => 'cancelled',
            'provider_payment_id' => (string) ($command->provider_payment_id ?? ''),
        ]);
    }

    public function refundPayment(object $command): PaymentResult
    {
        self::$refundCalls++;
        $remoteId = 'counting-refund-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 12);

        return new PaymentResult(true, 'counting_refunded', 'Counting refund completed.', false, $remoteId, [
            'status' => 'completed',
            'provider_refund_id' => $remoteId,
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }
}

final class EmptyRefundReferencePaymentProvider implements PaymentProviderInterface
{
    public function providerId(): string
    {
        return 'core.empty-refund-reference';
    }

    public function displayName(): string
    {
        return 'Empty Refund Reference';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.refund'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'empty_ref_paid', 'Empty refund reference payment paid.', false, '', [
            'status' => 'paid',
            'provider_payment_id' => 'empty-refund-payment-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 8),
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'empty_ref_refunded', 'Empty refund reference completed.', false, '', [
            'status' => 'completed',
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }
}

final class HostedCheckoutPaymentProvider implements PaymentProviderInterface
{
    /** @var list<array<string,mixed>> */
    public static array $commands = [];

    public function providerId(): string
    {
        return 'core.hosted-checkout';
    }

    public function displayName(): string
    {
        return 'Hosted Checkout';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.status'];
    }

    public function createPayment(object $command): PaymentResult
    {
        self::$commands[] = [
            'subject_type' => (string) ($command->subject_type ?? ''),
            'subject_id' => (string) ($command->subject_id ?? ''),
            'metadata' => is_array($command->metadata ?? null) ? $command->metadata : [],
        ];
        $remoteId = 'hosted-payment-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 12);

        return new PaymentResult(true, 'hosted_checkout_pending', 'Hosted checkout session created.', false, $remoteId, [
            'status' => 'pending',
            'provider_payment_id' => $remoteId,
            'checkout_url' => 'https://payments.example.test/checkout/' . rawurlencode($remoteId),
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(true, 'hosted_checkout_status', 'Hosted checkout status returned.', false, '', [
            'status' => 'paid',
            'provider_payment_id' => (string) ($command->provider_payment_id ?? ''),
        ]);
    }
}

final class UnsafeCheckoutPaymentProvider implements PaymentProviderInterface
{
    public static string $checkoutUrl = 'http://payments.example.test/checkout';

    public function providerId(): string
    {
        return 'core.unsafe-checkout';
    }

    public function displayName(): string
    {
        return 'Unsafe Checkout';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'unsafe_checkout_pending', 'Unsafe checkout session created.', false, 'unsafe-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 12), [
            'status' => 'pending',
            'provider_payment_id' => 'unsafe-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 12),
            'checkout_url' => self::$checkoutUrl,
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }
}

final class StatusOnlyPaymentProvider implements PaymentProviderInterface
{
    public static int $statusCalls = 0;

    public function providerId(): string
    {
        return 'core.status-only';
    }

    public function displayName(): string
    {
        return 'Status Only';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.status'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        self::$statusCalls++;

        return new PaymentResult(true, 'status_only', 'Status-only provider returned current status.', false, '', [
            'status' => (string) ($command->current_status ?? 'pending'),
            'provider_payment_id' => (string) ($command->provider_payment_id ?? ''),
        ]);
    }
}

final class InvalidRemoteReferencePaymentProvider implements PaymentProviderInterface
{
    public function providerId(): string
    {
        return 'core.invalid-remote-reference';
    }

    public function displayName(): string
    {
        return 'Invalid Remote Reference';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.refund'];
    }

    public function createPayment(object $command): PaymentResult
    {
        $key = (string) ($command->idempotency_key ?? '');
        if (str_contains($key, 'invalid-create-spaced')) {
            $remoteId = ' invalid-payment ';
        } elseif (str_contains($key, 'invalid-create')) {
            $remoteId = "invalid\npayment";
        } elseif (str_contains($key, 'token-like-create')) {
            $remoteId = 'payment_token%3Draw-provider-payment-reference';
        } else {
            $remoteId = 'invalid-remote-payment-' . substr(hash('sha256', $key), 0, 8);
        }

        return new PaymentResult(true, 'invalid_remote_paid', 'Invalid remote reference payment paid.', false, '', [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        $key = (string) ($command->idempotency_key ?? '');
        return new PaymentResult(true, 'invalid_remote_refund', 'Invalid refund reference completed.', false, '', [
            'status' => 'completed',
            'provider_refund_id' => str_contains($key, 'invalid-refund-spaced')
                ? ' invalid-refund '
                : (str_contains($key, 'token-like-refund')
                    ? 'payment_token%3Draw-provider-refund-reference'
                    : "invalid\nrefund"),
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }
}

final class InvalidResultEnvelopePaymentProvider implements PaymentProviderInterface
{
    public static string $createCode = 'invalid_envelope_paid';

    public static string $createMessage = 'Invalid envelope payment paid.';

    public static string $createRequestId = '';

    public static string $refundCode = 'invalid_envelope_refunded';

    public static string $refundMessage = 'Invalid envelope refund completed.';

    public static string $refundRequestId = '';

    public static string $captureCode = 'invalid_envelope_captured';

    public static string $captureMessage = 'Invalid envelope payment captured.';

    public static string $captureRequestId = '';

    public static string $statusCode = 'invalid_envelope_status';

    public static string $statusMessage = 'Invalid envelope payment status returned.';

    public static string $statusRequestId = '';

    public function providerId(): string
    {
        return 'core.invalid-result-envelope';
    }

    public function displayName(): string
    {
        return 'Invalid Result Envelope';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.capture', 'payment.refund', 'payment.status'];
    }

    public function createPayment(object $command): PaymentResult
    {
        $remoteId = 'invalid-envelope-payment-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 8);

        return new PaymentResult(true, self::$createCode, self::$createMessage, false, self::$createRequestId, [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(true, self::$captureCode, self::$captureMessage, false, self::$captureRequestId, [
            'status' => 'paid',
            'provider_payment_id' => (string) ($command->provider_payment_id ?? ''),
        ]);
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'Unsupported.');
    }

    public function refundPayment(object $command): PaymentResult
    {
        $remoteId = 'invalid-envelope-refund-' . substr(hash('sha256', (string) ($command->idempotency_key ?? '')), 0, 8);

        return new PaymentResult(true, self::$refundCode, self::$refundMessage, false, self::$refundRequestId, [
            'status' => 'completed',
            'provider_refund_id' => $remoteId,
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(true, self::$statusCode, self::$statusMessage, false, self::$statusRequestId, [
            'status' => 'paid',
            'provider_payment_id' => (string) ($command->provider_payment_id ?? ''),
        ]);
    }
}

final class NonScalarResultPaymentProvider implements PaymentProviderInterface
{
    /** @var array<string,mixed> */
    public static array $createData = ['status' => 'paid', 'provider_payment_id' => 'non-scalar-result-created'];

    /** @var array<string,mixed> */
    public static array $captureData = ['status' => 'paid', 'provider_payment_id' => 'non-scalar-result-captured'];

    /** @var array<string,mixed> */
    public static array $cancelData = ['status' => 'cancelled', 'provider_payment_id' => 'non-scalar-result-cancelled'];

    /** @var array<string,mixed> */
    public static array $refundData = ['status' => 'completed', 'provider_refund_id' => 'non-scalar-result-refunded'];

    /** @var array<string,mixed> */
    public static array $statusData = ['status' => 'paid', 'provider_payment_id' => 'non-scalar-result-status'];

    public function providerId(): string
    {
        return 'core.non-scalar-result';
    }

    public function displayName(): string
    {
        return 'Non Scalar Result';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.capture', 'payment.cancel', 'payment.refund', 'payment.status'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'non_scalar_create', 'Non-scalar create result.', false, 'non-scalar-result-create-request', self::$createData);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'non_scalar_capture', 'Non-scalar capture result.', false, 'non-scalar-result-capture-request', self::$captureData);
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'non_scalar_cancel', 'Non-scalar cancel result.', false, 'non-scalar-result-cancel-request', self::$cancelData);
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'non_scalar_refund', 'Non-scalar refund result.', false, 'non-scalar-result-refund-request', self::$refundData);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(true, 'non_scalar_status', 'Non-scalar status result.', false, 'non-scalar-result-status-request', self::$statusData);
    }
}

$pdo = new PDO('sqlite::memory:');
$migrations = [];
foreach (glob(CMS_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

foreach (['cms_payments', 'cms_payment_refunds', 'cms_payment_webhook_receipts', 'cms_payment_authorizations', 'cms_payment_authorization_events', 'cms_payment_provider_settings', 'cms_payment_entitlements'] as $table) {
    $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
    core_payment_check($exists === $table, 'core payment migration creates ' . $table);
}
foreach (['cms_payments_created_idx', 'cms_payments_currency_created_idx'] as $index) {
    $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = " . $pdo->quote($index))->fetchColumn();
    core_payment_check($exists === $index, 'core payment migration indexes reconciliation window ' . $index);
}

PaymentProviderRegistry::clear();
$officialPlugins = new OfficialPluginRegistry(CMS_ROOT);
core_payment_check(
    !$officialPlugins->isTrustedBundled('official.payment-fixture', CMS_ROOT . '/content/plugins/official.payment-fixture'),
    'Core official plugin registry no longer treats the legacy Payment Fixture plugin as bundled payment foundation'
);
core_payment_check(
    $officialPlugins->isReservedOfficialId('official.payment-fixture'),
    'Core still reserves legacy official payment plugin ids without trusting them as bundled payment runtime'
);
$retiredFixtureBuilderCommand = PHP_BINARY . ' ' . escapeshellarg(CMS_ROOT . '/scripts/build_payment_fixture_release.php');
$retiredFixtureBuilderOutput = [];
$retiredFixtureBuilderExit = 0;
exec($retiredFixtureBuilderCommand . ' 2>&1', $retiredFixtureBuilderOutput, $retiredFixtureBuilderExit);
$retiredFixtureBuilderText = implode("\n", $retiredFixtureBuilderOutput);
core_payment_check(
    $retiredFixtureBuilderExit === 2
    && str_contains($retiredFixtureBuilderText, 'Payment is a CMS Core foundation')
    && str_contains($retiredFixtureBuilderText, 'payment.fixture_provider_enabled'),
    'Core retires the legacy Payment Fixture plugin package builder in favor of Core payment fixture configuration'
);
$coreProviderRegistration = new ReflectionMethod(\Cms\Core\Bootstrap\Application::class, 'registerCorePaymentProviders');
$coreProviderRegistration->invoke(null, Settings::fromArray(['payment' => ['fixture_provider_enabled' => false]]));
core_payment_check(
    PaymentProviderRegistry::get(ManualPaymentProvider::PROVIDER_ID) !== null
    && PaymentProviderRegistry::get(HostedRedirectPaymentProvider::PROVIDER_ID) !== null
    && PaymentProviderRegistry::get(FixturePaymentProvider::PROVIDER_ID) === null,
    'Core runtime registers production payment foundations without the fixture Provider by default'
);
PaymentProviderRegistry::clear();
$coreProviderRegistration->invoke(null, Settings::fromArray(['payment' => ['fixture_provider_enabled' => true]]));
core_payment_check(
    PaymentProviderRegistry::get(ManualPaymentProvider::PROVIDER_ID) !== null
    && PaymentProviderRegistry::get(HostedRedirectPaymentProvider::PROVIDER_ID) !== null
    && PaymentProviderRegistry::get(FixturePaymentProvider::PROVIDER_ID) !== null,
    'Core runtime registers fixture payment Provider only when explicitly enabled'
);
PaymentProviderRegistry::clear();
PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
PaymentProviderRegistry::register(HostedRedirectPaymentProvider::PROVIDER_ID, new HostedRedirectPaymentProvider());
PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
PaymentProviderRegistry::register(NonCanonicalStatusPaymentProvider::PROVIDER_ID, new NonCanonicalStatusPaymentProvider());
PaymentProviderRegistry::register('core.status-only', new StatusOnlyPaymentProvider());
core_payment_check(PaymentProviderRegistry::get(FixturePaymentProvider::PROVIDER_ID) !== null, 'Core fixture payment provider registers without plugin runtime');
core_payment_check(PaymentProviderRegistry::get(ManualPaymentProvider::PROVIDER_ID) !== null, 'Core manual payment provider registers as CMS foundation without plugin runtime');
core_payment_check(PaymentProviderRegistry::get(HostedRedirectPaymentProvider::PROVIDER_ID) !== null, 'Core hosted redirect payment provider registers as CMS foundation without plugin runtime');
$registeredProviderIdsBeforeInvalidRegister = PaymentProviderRegistry::ids();
core_payment_throws(
    static fn () => PaymentProviderRegistry::register(' Core.Fixture-Payment ', new FixturePaymentProvider()),
    'Core payment provider registry rejects non-canonical Provider ids before registration'
);
core_payment_check(
    PaymentProviderRegistry::ids() === $registeredProviderIdsBeforeInvalidRegister,
    'Core payment provider registry stays unchanged after non-canonical Provider registration attempts'
);
core_payment_throws(
    static fn () => PaymentProviderRegistry::get(' ' . FixturePaymentProvider::PROVIDER_ID . ' '),
    'Core payment provider registry rejects non-canonical Provider ids before lookup'
);
core_payment_check(
    PaymentProviderRegistry::get('core.missing-provider') === null,
    'Core payment provider registry returns null for canonical unknown Provider ids'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'fixture-non-canonical-scenario',
        'amount_minor' => 1299,
        'currency' => 'USD',
        'idempotency_key' => 'fixture-non-canonical-scenario',
        'scenario' => ' Success ',
    ]),
    'Core fixture payment Provider rejects non-canonical scenarios before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'fixture-non-canonical-amount',
        'amount_minor' => ' 1299 ',
        'currency' => 'USD',
        'idempotency_key' => 'fixture-non-canonical-amount',
        'scenario' => 'success',
    ]),
    'Core fixture payment Provider rejects non-canonical amounts before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'fixture-non-canonical-currency',
        'amount_minor' => 1299,
        'currency' => 'usd',
        'idempotency_key' => 'fixture-non-canonical-currency',
        'scenario' => 'success',
    ]),
    'Core fixture payment Provider rejects non-canonical currencies before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => ['paid_download'],
        'subject_id' => 'fixture-non-string-subject-type',
        'amount_minor' => 1299,
        'currency' => 'USD',
        'idempotency_key' => 'fixture-non-string-subject-type',
        'scenario' => 'success',
    ]),
    'Core fixture payment Provider rejects non-string command subjects before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'fixture-non-string-idempotency',
        'amount_minor' => 1299,
        'currency' => 'USD',
        'idempotency_key' => ['fixture-non-string-idempotency'],
        'scenario' => 'success',
    ]),
    'Core fixture payment Provider rejects non-string idempotency keys before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->capturePayment((object) [
        'provider_payment_id' => ['core-fixture-pay-invalid'],
    ]),
    'Core fixture payment Provider rejects non-string remote references before lifecycle results'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->getPaymentStatus((object) [
        'expected_status' => ['paid'],
        'provider_payment_id' => 'core-fixture-pay-invalid-status',
    ]),
    'Core fixture payment Provider rejects non-string expected statuses before status results'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => "fixture\nunsafe-subject",
        'amount_minor' => 1299,
        'currency' => 'USD',
        'idempotency_key' => 'fixture-unsafe-command-subject',
        'scenario' => 'success',
    ]),
    'Core fixture payment Provider rejects unsafe command subjects before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'fixture-token-like-idempotency',
        'amount_minor' => 1299,
        'currency' => 'USD',
        'idempotency_key' => 'payment_token%3Draw-fixture-idempotency-token',
        'scenario' => 'success',
    ]),
    'Core fixture payment Provider rejects token-like command idempotency keys before creating payment references'
);
core_payment_throws(
    static fn () => (new FixturePaymentProvider())->capturePayment((object) [
        'provider_payment_id' => 'payment_token%3Draw-fixture-remote-token',
    ]),
    'Core fixture payment Provider rejects token-like remote references before lifecycle results'
);

$providerSettings = new PaymentProviderSettingsRepository($pdo, 'core-payment-settings-key');
$savedProvider = $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Fixture Config', 'enabled', ['mode' => 'test', 'publishable_key' => 'pk_test'], ['api_secret' => 'sk_test_123456']);
$providerSettings->save(NonCanonicalStatusPaymentProvider::PROVIDER_ID, 'Non Canonical Status', 'enabled', ['mode' => 'test'], []);
$storedProvider = $providerSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$providerSettingsRows = $providerSettings->all();
core_payment_check(
    is_array($storedProvider)
    && (string) $savedProvider['provider_id'] === FixturePaymentProvider::PROVIDER_ID
    && !array_key_exists(0, $storedProvider)
    && isset($providerSettingsRows[0])
    && is_array($providerSettingsRows[0])
    && !array_key_exists(0, $providerSettingsRows[0])
    && !str_contains((string) $storedProvider['secret_config_ciphertext'], 'sk_test_123456')
    && ($providerSettings->secrets(FixturePaymentProvider::PROVIDER_ID)['api_secret'] ?? '') === 'sk_test_123456'
    && ($providerSettings->maskedSecrets(FixturePaymentProvider::PROVIDER_ID)['api_secret'] ?? '') === '[configured]'
    && !str_contains((string) ($providerSettings->maskedSecrets(FixturePaymentProvider::PROVIDER_ID)['api_secret'] ?? ''), '3456'),
    'Core payment provider settings store encrypted secrets, expose non-revealing masked values and return associative rows only'
);
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Config', 'enabled', ['api_token' => 'leak'], []), 'Core payment provider public config rejects secret-like fields');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Key Config', 'enabled', ['api_key' => 'leak'], []), 'Core payment provider public config rejects key-like public fields');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public Key', 'enabled', ['bad key' => 'value'], []), 'Core payment provider public config rejects unsafe keys');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public Value', 'enabled', ['mode' => "bad\0value"], []), 'Core payment provider public config rejects unsafe string values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public Encoding', 'enabled', ['mode' => "\xC3\x28"], []), 'Core payment provider public config rejects invalid UTF-8 before storing JSON');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public Nested Value', 'enabled', ['mode' => ['nested' => true]], []), 'Core payment provider public config rejects non-scalar values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public Default', 'enabled', ['default_provider' => 'yes'], []), 'Core payment provider public config rejects non-boolean default markers');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public URL', 'enabled', ['callback_url' => 'not a url'], []), 'Core payment provider public config rejects malformed URL values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public URL Type', 'enabled', ['callback_url' => true], []), 'Core payment provider public config rejects non-string URL values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Encoded Public URL', 'enabled', ['callback_url' => 'https://provider.example.test/callback?safe=payment_token%3Draw-public-config-token'], []), 'Core payment provider public config rejects token-like URL query values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Public Token Value', 'enabled', ['mode' => 'Bearer raw-public-config-token'], []), 'Core payment provider public config rejects token-like public values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Encoded Public Token Value', 'enabled', ['mode' => 'payment_token%3Draw-public-config-encoded-token'], []), 'Core payment provider public config rejects URL-encoded token-like public values');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, "Bad\nDisplay", 'enabled', ['mode' => 'test'], []), 'Core payment provider settings reject unsafe display names');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, ' Bad Display ', 'enabled', ['mode' => 'test'], []), 'Core payment provider settings reject non-canonical display names');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'payment_token%3Draw-settings-display-token', 'enabled', ['mode' => 'test'], []), 'Core payment provider settings reject token-like display names');
core_payment_throws(static fn () => (new PaymentProviderSettingsRepository($pdo, ''))->save('core.no-key', 'No Key', 'enabled', [], ['secret' => 'value']), 'Core payment provider settings require encryption key for secrets');
core_payment_throws(static fn () => (new PaymentProviderSettingsRepository($pdo, ' core-payment-settings-key '))->save('core.spaced-key', 'Spaced Key', 'enabled', [], ['secret' => 'value']), 'Core payment provider settings reject non-canonical encryption keys before storing secrets');
core_payment_throws(static fn () => (new PaymentProviderSettingsRepository($pdo, '1'))->save('core.short-key', 'Short Key', 'enabled', [], ['secret' => 'value']), 'Core payment provider settings reject short encryption keys before storing secrets');
core_payment_throws(static fn () => (new PaymentProviderSettingsRepository($pdo, "core-payment\nsettings-key"))->save('core.control-key', 'Control Key', 'enabled', [], ['secret' => 'value']), 'Core payment provider settings reject control-character encryption keys before storing secrets');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Secret Value Type', 'enabled', ['mode' => 'test'], ['api_secret' => true]), 'Core payment provider settings reject non-string secret values before storing secrets');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Secret Key Type', 'enabled', ['mode' => 'test'], [true => 'secret-value']), 'Core payment provider settings reject non-string secret keys before storing secrets');
core_payment_throws(static fn () => $providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Bad Secret Encoding', 'enabled', ['mode' => 'test'], ['api_secret' => "\xC3\x28"]), 'Core payment provider settings reject invalid UTF-8 secrets before encryption');
core_payment_throws(static fn () => (new PaymentProviderSettingsRepository($pdo, ' core-payment-settings-key '))->secrets(FixturePaymentProvider::PROVIDER_ID), 'Core payment provider settings reject non-canonical encryption keys before reading secrets');
core_payment_throws(static fn () => (new PaymentProviderSettingsRepository($pdo, '1'))->secrets(FixturePaymentProvider::PROVIDER_ID), 'Core payment provider settings reject short encryption keys before reading secrets');
$providerSettingsCountBeforeInvalidProviderIds = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn();
core_payment_throws(static fn () => $providerSettings->save(' ' . FixturePaymentProvider::PROVIDER_ID . ' ', 'Bad Provider', 'enabled', ['mode' => 'test'], []), 'Core payment provider settings reject non-canonical Provider ids before saving settings');
core_payment_throws(static fn () => $providerSettings->setting(' ' . FixturePaymentProvider::PROVIDER_ID . ' '), 'Core payment provider settings reject non-canonical Provider ids before reading settings');
core_payment_throws(static fn () => $providerSettings->secrets(strtoupper(FixturePaymentProvider::PROVIDER_ID)), 'Core payment provider settings reject non-canonical Provider ids before reading secrets');
core_payment_throws(static fn () => $providerSettings->setDefaultProvider(' ' . FixturePaymentProvider::PROVIDER_ID . ' '), 'Core payment provider settings reject non-canonical default Provider ids before mutating defaults');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn() === $providerSettingsCountBeforeInvalidProviderIds,
    'Core payment provider settings stay unchanged after non-canonical Provider id attempts'
);

$repo = new PaymentRepository($pdo);
$service = new PaymentService($pdo, $repo);

$paymentCountBeforeNonCanonicalProviderStatus = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
core_payment_throws(
    static fn () => $service->createProviderPayment('paid_download', 'noncanonical-provider-status', NonCanonicalStatusPaymentProvider::PROVIDER_ID, 1200, 'USD', 'noncanonical-provider-status-key'),
    'Core PaymentService rejects non-canonical Provider payment statuses before writing payment rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonCanonicalProviderStatus,
    'Core PaymentService leaves payment ledger unchanged after non-canonical Provider payment status'
);

$payment = $service->createProviderPayment('paid_download', 'asset-42', FixturePaymentProvider::PROVIDER_ID, 1299, 'USD', 'pay-asset-42', 'success', [
    'customer_email' => 'buyer@example.test',
    'safe_note' => 'download checkout',
    'operator_note' => 'visitor returned with payment_token=raw-service-token',
    'encoded_note' => 'visitor returned with payment_token%3Draw-service-encoded-token',
    'receipt_url' => 'https://payments.example.test/receipt?safe=1#payment_token=raw-service-fragment-token',
    'status_url' => 'https://payments.example.test/status?safe=sk_live_service_query_token',
    'encoded_status_url' => 'https://payments.example.test/status/payment_token%3Draw-service-path-token',
    'array_query_url' => 'https://payments.example.test/status?safe[]=raw-service-array-value',
    'api_token' => 'secret-token',
    'api_key' => 'secret-key',
    'bad key' => 'unsafe service metadata key',
    'nested' => ['drop' => true],
]);
core_payment_check((string) $payment['status'] === 'paid' && (int) $payment['amount_minor'] === 1299 && (string) $payment['currency'] === 'USD', 'Core PaymentService records trusted paid payment');

$metadata = json_decode((string) $payment['metadata_json'], true) ?: [];
core_payment_check(($metadata['customer_email'] ?? '') === '[redacted]' && ($metadata['api_token'] ?? '') === '[redacted]' && ($metadata['api_key'] ?? '') === '[redacted]' && ($metadata['operator_note'] ?? '') === '[redacted]' && ($metadata['encoded_note'] ?? '') === '[redacted]' && ($metadata['safe_note'] ?? '') === 'download checkout' && !array_key_exists('bad key', $metadata) && !array_key_exists('nested', $metadata) && !str_contains((string) ($metadata['receipt_url'] ?? ''), 'raw-service-fragment-token') && !str_contains((string) ($metadata['receipt_url'] ?? ''), '#') && str_contains((string) ($metadata['status_url'] ?? ''), 'safe=%5Bredacted%5D') && !str_contains((string) ($metadata['status_url'] ?? ''), 'sk_live_service_query_token') && ($metadata['encoded_status_url'] ?? '') === '[redacted]' && ($metadata['array_query_url'] ?? '') === '[redacted]', 'Core payment metadata redacts sensitive fields, token-like values and unsafe keys at the service boundary');

$repositoryMetadataPaymentId = $repo->insertPayment([
    'subject_type' => 'paid_download',
    'subject_id' => 'repository-metadata-payment',
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'remote_id' => 'repository-metadata-payment-remote',
    'reference' => 'repository-metadata-payment-reference',
    'status' => 'paid',
    'amount_minor' => 500,
    'currency' => 'USD',
    'idempotency_key' => 'repository-metadata-payment-key',
    'request_hash' => hash('sha256', 'repository-metadata-payment'),
    'metadata' => [
        'customer_email' => 'direct@example.test',
        'access_key' => 'direct-access-key',
        'checkout_url' => 'https://payments.example.test/checkout?claim=raw-claim&safe=1#payment_token=fragment-token',
        'status_url' => 'https://payments.example.test/status/sk_live_repository_path_token',
        'encoded_status_url' => 'https://payments.example.test/status/payment_token%3Draw-repository-path-token',
        'array_query_url' => 'https://payments.example.test/status?safe[]=raw-repository-array-value',
        'safe_note' => 'repository metadata payment',
        'operator_note' => 'manual note with Bearer raw-repository-token',
        'encoded_note' => 'manual note with payment_token%3Draw-repository-encoded-token',
        'control_note' => "repository\nmetadata control",
        'encoded_control_note' => 'repository%0Ametadata-control',
        'encoded_spaced_note' => '%20repository-metadata-spaced%20',
        'bad key' => 'unsafe metadata key',
        'nested' => ['drop' => true],
    ],
]);
$repositoryMetadataPayment = $repo->payment($repositoryMetadataPaymentId) ?? [];
$repositoryPaymentMetadata = json_decode((string) ($repositoryMetadataPayment['metadata_json'] ?? '{}'), true) ?: [];
$repositoryMetadataRefundId = $repo->insertRefund([
    'payment_id' => $repositoryMetadataPaymentId,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'remote_id' => 'repository-metadata-refund-remote',
    'status' => 'completed',
    'amount_minor' => 100,
    'currency' => 'USD',
    'reason' => 'repository metadata refund',
    'idempotency_key' => 'repository-metadata-refund-key',
    'request_hash' => hash('sha256', 'repository-metadata-refund'),
    'metadata' => ['api_token' => 'raw-refund-token', 'safe_note' => 'repository metadata refund'],
]);
$repositoryMetadataRefund = $repo->refund($repositoryMetadataRefundId) ?? [];
$repositoryRefundMetadata = json_decode((string) ($repositoryMetadataRefund['metadata_json'] ?? '{}'), true) ?: [];
$repositoryMetadataAuthorizationId = $repo->insertAuthorization([
    'payment_id' => $repositoryMetadataPaymentId,
    'subject_type' => 'paid_download',
    'subject_id' => 'repository-metadata-payment',
    'token_hash' => hash('sha256', 'repository-metadata-authorization-token'),
    'status' => 'active',
    'max_uses' => 1,
    'used_count' => 0,
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => ['payment_token' => 'raw-authorization-token', 'safe_note' => 'repository metadata authorization'],
]);
$repositoryMetadataAuthorization = $repo->authorization($repositoryMetadataAuthorizationId) ?? [];
$repositoryAuthorizationMetadata = json_decode((string) ($repositoryMetadataAuthorization['metadata_json'] ?? '{}'), true) ?: [];
$repositoryMetadataEntitlementId = $repo->insertEntitlement([
    'principal_type' => 'member',
    'principal_id' => 'repository-metadata-member',
    'subject_type' => 'paid_download',
    'subject_id' => 'repository-metadata-payment',
    'source_payment_id' => $repositoryMetadataPaymentId,
    'source_authorization_id' => $repositoryMetadataAuthorizationId,
    'status' => 'active',
    'metadata' => ['private_note' => 'raw-private-note', 'safe_note' => 'repository metadata entitlement'],
]);
$repositoryMetadataEntitlement = $repo->entitlement($repositoryMetadataEntitlementId) ?? [];
$repositoryEntitlementMetadata = json_decode((string) ($repositoryMetadataEntitlement['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    ($repositoryPaymentMetadata['customer_email'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['access_key'] ?? '') === '[redacted]'
    && str_contains((string) ($repositoryPaymentMetadata['checkout_url'] ?? ''), 'claim=%5Bredacted%5D')
    && !str_contains((string) ($repositoryPaymentMetadata['checkout_url'] ?? ''), 'fragment-token')
    && !str_contains((string) ($repositoryPaymentMetadata['checkout_url'] ?? ''), '#')
    && ($repositoryPaymentMetadata['status_url'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['encoded_status_url'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['array_query_url'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['safe_note'] ?? '') === 'repository metadata payment'
    && ($repositoryPaymentMetadata['operator_note'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['encoded_note'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['control_note'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['encoded_control_note'] ?? '') === '[redacted]'
    && ($repositoryPaymentMetadata['encoded_spaced_note'] ?? '') === '[redacted]'
    && !array_key_exists('bad key', $repositoryPaymentMetadata)
    && !array_key_exists('nested', $repositoryPaymentMetadata)
    && ($repositoryRefundMetadata['api_token'] ?? '') === '[redacted]'
    && ($repositoryRefundMetadata['safe_note'] ?? '') === 'repository metadata refund'
    && ($repositoryAuthorizationMetadata['payment_token'] ?? '') === '[redacted]'
    && ($repositoryAuthorizationMetadata['safe_note'] ?? '') === 'repository metadata authorization'
    && ($repositoryEntitlementMetadata['private_note'] ?? '') === '[redacted]'
    && ($repositoryEntitlementMetadata['safe_note'] ?? '') === 'repository metadata entitlement',
    'Core payment repository redacts sensitive metadata before writing ledger rows'
);
$repositoryMetadataReceipt = $repo->recordWebhookReceipt(
    FixturePaymentProvider::PROVIDER_ID,
    'repository-metadata-assoc-receipt',
    hash('sha256', 'repository-metadata-assoc-receipt'),
    'received',
    ['payment_id' => $repositoryMetadataPaymentId, 'safe_note' => 'repository metadata receipt']
);
$repositoryAssocRows = [
    $repo->payment($repositoryMetadataPaymentId) ?? [],
    $repo->paymentByIdempotency('repository-metadata-payment-key') ?? [],
    $repo->paymentByIdempotencyForUpdate('repository-metadata-payment-key') ?? [],
    $repo->paymentByRemote(FixturePaymentProvider::PROVIDER_ID, 'repository-metadata-payment-remote') ?? [],
    $repo->refund($repositoryMetadataRefundId) ?? [],
    $repo->refundByIdempotency('repository-metadata-refund-key') ?? [],
    $repo->refundByRemote(FixturePaymentProvider::PROVIDER_ID, 'repository-metadata-refund-remote') ?? [],
    $repo->authorization($repositoryMetadataAuthorizationId) ?? [],
    $repo->activeAuthorizationForPaymentSubject($repositoryMetadataPaymentId, 'paid_download', 'repository-metadata-payment') ?? [],
    $repo->authorizationsForPayment($repositoryMetadataPaymentId)[0] ?? [],
    $repo->authorizationEventsForPayment($repositoryMetadataPaymentId)[0] ?? [],
    $repo->entitlement($repositoryMetadataEntitlementId) ?? [],
    $repo->activeEntitlements('member', 'repository-metadata-member', 'paid_download', 'repository-metadata-payment')[0] ?? [],
    $repo->entitlementsForPayment($repositoryMetadataPaymentId)[0] ?? [],
    $repo->searchPayments(['q' => 'repository-metadata-payment-reference'])['items'][0] ?? [],
    $repo->exportPayments(['q' => 'repository-metadata-payment-reference'])[0] ?? [],
    $repo->paymentSummary(['q' => 'repository-metadata-payment-reference'])[0] ?? [],
    $repositoryMetadataReceipt,
    $repo->webhookReceiptById((int) ($repositoryMetadataReceipt['id'] ?? 0)) ?? [],
    $repo->webhookReceipts(FixturePaymentProvider::PROVIDER_ID, 10)[0] ?? [],
    $repo->webhookReceiptsForPayment($repositoryMetadataPaymentId, 10)[0] ?? [],
];
core_payment_check(
    array_reduce(
        $repositoryAssocRows,
        static fn (bool $valid, array $row): bool => $valid && $row !== [] && core_payment_assoc_row($row),
        true
    ),
    'Core payment repository returns associative rows only across ledger read paths'
);
core_payment_throws(
    static fn () => $repo->recordWebhookReceipt(
        FixturePaymentProvider::PROVIDER_ID,
        'evt-repository-oversized-payload-trace',
        hash('sha256', 'repository-oversized-payload-trace'),
        'received',
        ['payload_size' => 1048577]
    ),
    'Core payment repository rejects out-of-bounds webhook payload-size metadata before storing receipts'
);
$repositoryReadPaymentCountBeforeInvalidQueries = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$repositoryReadAuthorizationEventCountBeforeInvalidQueries = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn();
core_payment_throws(static fn () => $repo->payment(0), 'Core payment repository rejects invalid payment query ids');
core_payment_throws(static fn () => $repo->paymentByIdempotency(' repository-metadata-payment-key '), 'Core payment repository rejects non-canonical payment idempotency query keys');
core_payment_throws(static fn () => $repo->paymentByIdempotencyForUpdate("repository\nmetadata-payment-key"), 'Core payment repository rejects unsafe payment idempotency query keys');
core_payment_throws(static fn () => $repo->paymentByRemote(' ' . FixturePaymentProvider::PROVIDER_ID . ' ', 'repository-metadata-payment-remote'), 'Core payment repository rejects non-canonical payment remote Provider query ids');
core_payment_throws(static fn () => $repo->paymentByRemote(FixturePaymentProvider::PROVIDER_ID, ' repository-metadata-payment-remote '), 'Core payment repository rejects non-canonical payment remote query references');
core_payment_throws(static fn () => $repo->refund(0), 'Core payment repository rejects invalid refund query ids');
core_payment_throws(static fn () => $repo->refundByIdempotency(' repository-metadata-refund-key '), 'Core payment repository rejects non-canonical refund idempotency query keys');
core_payment_throws(static fn () => $repo->refundByIdempotencyForUpdate(' repository-metadata-refund-key '), 'Core payment repository rejects non-canonical locked refund idempotency query keys');
core_payment_throws(static fn () => $repo->refundByRemote(' ' . FixturePaymentProvider::PROVIDER_ID . ' ', 'repository-metadata-refund-remote'), 'Core payment repository rejects non-canonical refund remote Provider query ids');
core_payment_throws(static fn () => $repo->refundByRemote(FixturePaymentProvider::PROVIDER_ID, "repository\nmetadata-refund-remote"), 'Core payment repository rejects unsafe refund remote query references');
core_payment_throws(static fn () => $repo->authorization(0), 'Core payment repository rejects invalid authorization query ids');
core_payment_throws(static fn () => $repo->authorizationsForPayment(0), 'Core payment repository rejects invalid authorization payment query ids');
core_payment_throws(static fn () => $repo->activeAuthorizationForPaymentSubject($repositoryMetadataPaymentId, 'paid_download', "bad\nsubject"), 'Core payment repository rejects unsafe authorization subject query ids');
core_payment_throws(static fn () => $repo->authorizationEventsForPayment(-1), 'Core payment repository rejects invalid authorization event payment query ids');
core_payment_throws(static fn () => $repo->entitlement(-1), 'Core payment repository rejects invalid entitlement query ids');
core_payment_throws(static fn () => $repo->activeEntitlements('member', "bad\nprincipal", 'paid_download', 'repository-metadata-payment'), 'Core payment repository rejects unsafe entitlement principal query ids');
core_payment_throws(static fn () => $repo->activeEntitlements('member', 'repository-metadata-member', 'Bad Type', 'repository-metadata-payment'), 'Core payment repository rejects unsafe entitlement subject query types');
core_payment_throws(static fn () => $repo->entitlementsForPayment(0), 'Core payment repository rejects invalid entitlement payment query ids');
core_payment_throws(static fn () => $repo->refundsForPayment(0), 'Core payment repository rejects invalid refund payment query ids');
core_payment_throws(static fn () => $repo->webhookReceiptById(0), 'Core payment repository rejects invalid webhook receipt query ids');
core_payment_throws(static fn () => $repo->webhookReceipts('Bad Provider', 10), 'Core payment repository rejects unsafe webhook Provider query ids');
core_payment_throws(static fn () => $repo->webhookReceipts('   ', 10), 'Core payment repository rejects blank non-canonical webhook Provider query ids');
core_payment_throws(static fn () => $repo->webhookReceipts(FixturePaymentProvider::PROVIDER_ID, 0), 'Core payment repository rejects invalid webhook receipt query limits');
core_payment_throws(static fn () => $repo->webhookReceiptsForPayment(0, 10), 'Core payment repository rejects invalid webhook payment query ids');
core_payment_throws(static fn () => $repo->webhookReceiptsForPayment($repositoryMetadataPaymentId, 0), 'Core payment repository rejects invalid webhook payment query limits');
core_payment_throws(static fn () => $repo->trustedStatus('paid_download', "bad\nsubject"), 'Core payment repository rejects unsafe trusted-status subject query ids');
core_payment_throws(static fn () => $repo->trustedStatus('paid_download', 'repository-metadata-payment', 'USDT'), 'Core payment repository rejects invalid trusted-status currencies');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $repositoryReadPaymentCountBeforeInvalidQueries
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn() === $repositoryReadAuthorizationEventCountBeforeInvalidQueries,
    'Core payment repository read input validation leaves ledger rows unchanged'
);
core_payment_throws(static fn () => $repo->updatePaymentStatus($repositoryMetadataPaymentId, 'settled'), 'Core payment repository rejects invalid status updates before writing payment rows');
core_payment_throws(static fn () => $repo->updatePaymentStatus($repositoryMetadataPaymentId, ' Paid '), 'Core payment repository rejects non-canonical status updates before writing payment rows');
core_payment_throws(static fn () => $repo->updatePaymentStatus($repositoryMetadataPaymentId, 'pending'), 'Core payment repository rejects paid-to-pending status regressions before writing payment rows');
core_payment_throws(static fn () => $repo->updatePaymentStatus($repositoryMetadataPaymentId, 'failed'), 'Core payment repository rejects paid-to-failed status regressions before writing payment rows');
core_payment_check(
    (string) (($repo->payment($repositoryMetadataPaymentId) ?? [])['status'] ?? '') === 'paid',
    'Core payment repository leaves payment rows unchanged after invalid status updates'
);
$repositoryInvalidStatusReceipt = $repo->recordWebhookReceipt(
    FixturePaymentProvider::PROVIDER_ID,
    'repository-invalid-status-receipt',
    hash('sha256', 'repository-invalid-status-receipt'),
    'received',
    []
);
$repositoryMismatchedWebhookPaymentId = $repo->insertPayment([
    'subject_type' => 'paid_download',
    'subject_id' => 'repository-mismatched-webhook-payment',
    'provider_id' => 'core.repository-other-provider',
    'remote_id' => 'repository-mismatched-webhook-payment-remote',
    'reference' => 'repository-mismatched-webhook-payment-reference',
    'status' => 'paid',
    'amount_minor' => 100,
    'currency' => 'USD',
    'idempotency_key' => 'repository-mismatched-webhook-payment-key',
    'request_hash' => hash('sha256', 'repository-mismatched-webhook-payment'),
]);
core_payment_throws(static fn () => $repo->updateWebhookReceiptStatus((int) ($repositoryInvalidStatusReceipt['id'] ?? 0), 'done'), 'Core payment repository rejects invalid webhook receipt status updates before writing rows');
core_payment_throws(static fn () => $repo->updateWebhookReceiptStatus((int) ($repositoryInvalidStatusReceipt['id'] ?? 0), ' Processed '), 'Core payment repository rejects non-canonical webhook receipt status updates before writing rows');
core_payment_throws(static fn () => $repo->updateWebhookReceiptStatus((int) ($repositoryInvalidStatusReceipt['id'] ?? 0), 'failed'), 'Core payment repository rejects direct failed webhook receipt status updates without diagnostic metadata');
core_payment_throws(static fn () => $repo->attachWebhookReceiptPayment((int) ($repositoryInvalidStatusReceipt['id'] ?? 0), -5), 'Core payment repository rejects invalid webhook payment bindings before writing rows');
core_payment_throws(
    static fn () => $repo->recordWebhookReceipt(
        FixturePaymentProvider::PROVIDER_ID,
        'repository-mismatched-payment-receipt',
        hash('sha256', 'repository-mismatched-payment-receipt'),
        'received',
        ['payment_id' => $repositoryMismatchedWebhookPaymentId]
    ),
    'Core payment repository rejects webhook receipt creation when Provider does not match bound payment before writing rows'
);
core_payment_throws(
    static fn () => $repo->attachWebhookReceiptPayment((int) ($repositoryInvalidStatusReceipt['id'] ?? 0), $repositoryMismatchedWebhookPaymentId),
    'Core payment repository rejects webhook payment bindings when Provider does not match before writing rows'
);
core_payment_check(
    (string) (($repo->webhookReceiptById((int) ($repositoryInvalidStatusReceipt['id'] ?? 0)) ?? [])['status'] ?? '') === 'received'
    && (($repo->webhookReceiptById((int) ($repositoryInvalidStatusReceipt['id'] ?? 0)) ?? [])['payment_id'] ?? null) === null,
    'Core payment repository leaves webhook receipt rows unchanged after invalid status or binding updates'
);
$repositoryMalformedMetadataReceipt = $repo->recordWebhookReceipt(
    FixturePaymentProvider::PROVIDER_ID,
    'repository-malformed-metadata-receipt',
    hash('sha256', 'repository-malformed-metadata-receipt'),
    'received',
    ['payload_size' => 12]
);
$pdo->prepare('UPDATE cms_payment_webhook_receipts SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => (int) ($repositoryMalformedMetadataReceipt['id'] ?? 0),
    ':metadata' => '{"payload_size":',
]);
core_payment_throws(
    static fn () => $repo->updateWebhookReceiptStatus((int) ($repositoryMalformedMetadataReceipt['id'] ?? 0), 'processed'),
    'Core payment repository rejects webhook status updates when restored receipt metadata JSON is malformed'
);
core_payment_throws(
    static fn () => $repo->markWebhookReceiptFailed((int) ($repositoryMalformedMetadataReceipt['id'] ?? 0), ['failure_error' => 'Payment webhook application failed.', 'failed_at' => gmdate('c')]),
    'Core payment repository rejects webhook failed writes when restored receipt metadata JSON is malformed'
);
$repositoryMalformedMetadataAfter = $repo->webhookReceiptById((int) ($repositoryMalformedMetadataReceipt['id'] ?? 0));
core_payment_check(
    is_array($repositoryMalformedMetadataAfter)
    && (string) ($repositoryMalformedMetadataAfter['status'] ?? '') === 'received'
    && (string) ($repositoryMalformedMetadataAfter['metadata_json'] ?? '') === '{"payload_size":',
    'Core payment repository leaves malformed webhook receipt metadata unchanged after rejected status writes'
);
$repositoryIgnoredReceipt = $repo->recordWebhookReceipt(
    FixturePaymentProvider::PROVIDER_ID,
    'repository-ignored-terminal-receipt',
    hash('sha256', 'repository-ignored-terminal-receipt'),
    'received',
    []
);
$repo->updateWebhookReceiptStatus((int) ($repositoryIgnoredReceipt['id'] ?? 0), 'ignored');
$repositoryIgnoredFailed = $repo->markWebhookReceiptFailed((int) ($repositoryIgnoredReceipt['id'] ?? 0), ['failure_error' => 'ignored should stay terminal']);
$repositoryIgnoredAfterFailed = $repo->webhookReceiptById((int) ($repositoryIgnoredReceipt['id'] ?? 0));
core_payment_check(
    $repositoryIgnoredFailed === false
    && is_array($repositoryIgnoredAfterFailed)
    && (string) ($repositoryIgnoredAfterFailed['status'] ?? '') === 'ignored'
    && !str_contains((string) ($repositoryIgnoredAfterFailed['metadata_json'] ?? ''), 'ignored should stay terminal'),
    'Core payment repository refuses to overwrite ignored webhook receipts with failed state'
);
$repositoryUnsafeFailureReceipt = $repo->recordWebhookReceipt(
    FixturePaymentProvider::PROVIDER_ID,
    'repository-unsafe-failure-diagnostic',
    hash('sha256', 'repository-unsafe-failure-diagnostic'),
    'received',
    []
);
$repo->markWebhookReceiptFailed((int) ($repositoryUnsafeFailureReceipt['id'] ?? 0), [
    'failure_error' => '{"raw":"provider payload"}',
    'failed_at' => 'yesterday',
    'receipt_url' => 'https://provider.example.test/receipt?api_key=raw-key&safe=1',
]);
$repositoryUnsafeFailureAfter = $repo->webhookReceiptById((int) ($repositoryUnsafeFailureReceipt['id'] ?? 0));
$repositoryUnsafeFailureMeta = json_decode((string) ($repositoryUnsafeFailureAfter['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    is_array($repositoryUnsafeFailureAfter)
    && (string) ($repositoryUnsafeFailureAfter['status'] ?? '') === 'failed'
    && !array_key_exists('failure_error', $repositoryUnsafeFailureMeta)
    && !array_key_exists('failed_at', $repositoryUnsafeFailureMeta)
    && (string) ($repositoryUnsafeFailureMeta['receipt_url'] ?? '') === 'https://provider.example.test/receipt?api_key=%5Bredacted%5D&safe=1',
    'Core payment repository rejects non-canonical webhook failure diagnostics while preserving redacted safe metadata'
);

$paymentCountBeforeInvalidInsert = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$validPaymentInsert = [
    'subject_type' => 'paid_download',
    'subject_id' => 'asset-insert-validation',
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'remote_id' => 'insert-validation-payment',
    'reference' => 'insert-validation-request',
    'status' => 'paid',
    'amount_minor' => 999,
    'currency' => 'USD',
    'idempotency_key' => 'insert-validation-payment-key',
    'request_hash' => hash('sha256', 'insert-validation-payment-request'),
    'metadata' => ['source' => 'payment-insert-validation'],
];
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['amount_minor' => 0])), 'Core payment ledger rejects non-positive amounts before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['amount_minor' => ' 999 '])), 'Core payment ledger rejects non-canonical payment amounts before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['amount_minor' => '0999'])), 'Core payment ledger rejects leading-zero payment amounts before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['amount_minor' => '123456789012345678901234567890'])), 'Core payment ledger rejects oversized numeric-string payment amounts before integer conversion');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['status' => 'settled'])), 'Core payment ledger rejects unknown statuses before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['status' => 'partially_refunded'])), 'Core payment ledger rejects partially refunded payment creation before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['status' => 'refunded'])), 'Core payment ledger rejects refunded payment creation before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['status' => ' Paid '])), 'Core payment ledger rejects non-canonical statuses before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['currency' => 'USDT'])), 'Core payment ledger rejects invalid currencies before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['currency' => 'usd'])), 'Core payment ledger rejects non-canonical currencies before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['subject_type' => ' Paid_Download '])), 'Core payment ledger rejects non-canonical subject types before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['subject_type' => ['paid_download']])), 'Core payment ledger rejects non-string subject types before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['subject_id' => ' asset-insert-validation '])), 'Core payment ledger rejects non-canonical subject ids before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['subject_id' => ['asset-insert-validation']])), 'Core payment ledger rejects non-string subject ids before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['subject_id' => 'payment_token%3Draw-payment-subject-token'])), 'Core payment ledger rejects URL-encoded token-like subject ids before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['provider_id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' '])), 'Core payment ledger rejects non-canonical Provider ids before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['provider_id' => [FixturePaymentProvider::PROVIDER_ID]])), 'Core payment ledger rejects non-string Provider ids before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['remote_id' => "remote\nbad"])), 'Core payment ledger rejects remote references with control characters before writing rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['remote_id' => ' remote-bad '])), 'Core payment ledger rejects non-canonical remote references before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['remote_id' => ['remote-bad']])), 'Core payment ledger rejects non-string remote references before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['request_hash' => 'not-a-sha256-hash'])), 'Core payment ledger rejects malformed request hashes before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['request_hash' => strtoupper(hash('sha256', 'insert-validation-payment-request'))])), 'Core payment ledger rejects non-canonical request hashes before writing payment rows');
core_payment_throws(static fn () => $repo->insertPayment(array_replace($validPaymentInsert, ['request_hash' => ['not-a-sha256-hash']])), 'Core payment ledger rejects non-string request hashes before writing payment rows');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeInvalidInsert,
    'Core payment ledger stays unchanged after invalid payment insert attempts'
);

$refundCountBeforeInvalidInsert = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
$validRefundInsert = [
    'payment_id' => (int) ($payment['id'] ?? 0),
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'remote_id' => 'insert-validation-refund',
    'status' => 'completed',
    'amount_minor' => 100,
    'currency' => 'USD',
    'reason' => 'operator refund',
    'idempotency_key' => 'insert-validation-refund-key',
    'request_hash' => hash('sha256', 'insert-validation-refund-request'),
    'metadata' => ['source' => 'refund-insert-validation'],
];
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['payment_id' => 0])), 'Core payment refund ledger rejects invalid payment ids before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['amount_minor' => -1])), 'Core payment refund ledger rejects non-positive amounts before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['amount_minor' => ' 100 '])), 'Core payment refund ledger rejects non-canonical amounts before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['amount_minor' => '0100'])), 'Core payment refund ledger rejects leading-zero refund amounts before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['provider_id' => 'core.other-provider'])), 'Core payment refund ledger rejects Provider mismatches before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['currency' => 'EUR'])), 'Core payment refund ledger rejects currency mismatches before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['amount_minor' => 1000000])), 'Core payment refund ledger rejects completed refunds above source payment amount before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['status' => 'refunded'])), 'Core payment refund ledger rejects unknown statuses before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['status' => ' Completed '])), 'Core payment refund ledger rejects non-canonical statuses before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['status' => ['completed']])), 'Core payment refund ledger rejects non-string statuses before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['provider_id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' '])), 'Core payment refund ledger rejects non-canonical Provider ids before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['provider_id' => [FixturePaymentProvider::PROVIDER_ID]])), 'Core payment refund ledger rejects non-string Provider ids before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['remote_id' => "refund\nbad"])), 'Core payment refund ledger rejects remote references with control characters before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['remote_id' => ' refund-bad '])), 'Core payment refund ledger rejects non-canonical remote references before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['remote_id' => ['refund-bad']])), 'Core payment refund ledger rejects non-string remote references before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['request_hash' => 'not-a-sha256-hash'])), 'Core payment refund ledger rejects malformed request hashes before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['request_hash' => ' ' . hash('sha256', 'insert-validation-refund-request') . ' '])), 'Core payment refund ledger rejects non-canonical request hashes before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['request_hash' => ['not-a-sha256-hash']])), 'Core payment refund ledger rejects non-string request hashes before writing refund rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['reason' => "operator\0refund"])), 'Core payment refund ledger rejects reasons with control characters before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['reason' => ' operator refund '])), 'Core payment refund ledger rejects non-canonical reasons before writing rows');
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, ['reason' => ['operator refund']])), 'Core payment refund ledger rejects non-string reasons before writing rows');
$corruptSourceAmountRefundPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'remote_id' => 'insert-validation-corrupt-source-amount-payment',
    'reference' => 'insert-validation-corrupt-source-amount-request',
    'idempotency_key' => 'insert-validation-corrupt-source-amount-payment-key',
    'request_hash' => hash('sha256', 'insert-validation-corrupt-source-amount-payment'),
]));
$pdo->prepare('UPDATE cms_payments SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '999abc',
    ':id' => $corruptSourceAmountRefundPaymentId,
]);
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, [
    'payment_id' => $corruptSourceAmountRefundPaymentId,
    'remote_id' => 'insert-validation-corrupt-source-amount-refund',
    'idempotency_key' => 'insert-validation-corrupt-source-amount-refund-key',
    'request_hash' => hash('sha256', 'insert-validation-corrupt-source-amount-refund'),
])), 'Core payment refund ledger rejects corrupted source payment amounts before writing refund rows');
$unrefundableRefundSourcePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'remote_id' => 'insert-validation-unrefundable-payment',
    'reference' => 'insert-validation-unrefundable-request',
    'status' => 'pending',
    'idempotency_key' => 'insert-validation-unrefundable-payment-key',
]));
core_payment_throws(static fn () => $repo->insertRefund(array_replace($validRefundInsert, [
    'payment_id' => $unrefundableRefundSourcePaymentId,
    'remote_id' => 'insert-validation-unrefundable-refund',
    'idempotency_key' => 'insert-validation-unrefundable-refund-key',
])), 'Core payment refund ledger rejects unrefundable source payments before writing rows');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $refundCountBeforeInvalidInsert,
    'Core payment refund ledger stays unchanged after invalid refund insert attempts'
);

$authorizationCountBeforeInvalidInsert = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$authorizationEventCountBeforeInvalidInsert = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn();
$validAuthorizationInsert = [
    'payment_id' => (int) ($payment['id'] ?? 0),
    'subject_type' => 'paid_download',
    'subject_id' => 'asset-42',
    'token_hash' => hash('sha256', 'invalid-insert-baseline'),
    'status' => 'active',
    'max_uses' => 1,
    'used_count' => 0,
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => ['source' => 'authorization-insert-validation'],
];
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['subject_type' => ' Paid_Download '])), 'Core payment authorization ledger rejects non-canonical subject types before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['subject_type' => ['paid_download']])), 'Core payment authorization ledger rejects non-string subject types before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['subject_id' => ' asset-42 '])), 'Core payment authorization ledger rejects non-canonical subject ids before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['subject_id' => ['asset-42']])), 'Core payment authorization ledger rejects non-string subject ids before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['subject_id' => 'payment_token%3Draw-authorization-subject-token'])), 'Core payment authorization ledger rejects URL-encoded token-like subject ids before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['used_count' => -1])), 'Core payment authorization ledger rejects negative used counts before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['max_uses' => '01'])), 'Core payment authorization ledger rejects leading-zero max uses before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['used_count' => '01'])), 'Core payment authorization ledger rejects leading-zero used counts before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['max_uses' => '123456789012345678901234567890'])), 'Core payment authorization ledger rejects oversized numeric-string max uses before integer conversion');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['used_count' => '123456789012345678901234567890'])), 'Core payment authorization ledger rejects oversized numeric-string used counts before integer conversion');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['used_count' => 1, 'last_used_at' => gmdate('c')])), 'Core payment authorization ledger rejects pre-used authorization creation before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['used_count' => 2])), 'Core payment authorization ledger rejects used counts above max uses before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['last_used_at' => gmdate('c')])), 'Core payment authorization ledger rejects last_used_at on new authorizations before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['revoked_at' => gmdate('c')])), 'Core payment authorization ledger rejects revoked_at on active authorization creation before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['status' => 'revoked', 'revoked_at' => gmdate('c')])), 'Core payment authorization ledger rejects revoked authorization creation before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['status' => 'expired'])), 'Core payment authorization ledger rejects expired authorization creation before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['status' => ' Active '])), 'Core payment authorization ledger rejects non-canonical statuses before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['status' => ['active']])), 'Core payment authorization ledger rejects non-string statuses before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['expires_at' => gmdate('c', time() - 60)])), 'Core payment authorization ledger rejects expired authorization creation before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['expires_at' => ' ' . gmdate('c', time() + 3600) . ' '])), 'Core payment authorization ledger rejects non-canonical expiries before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['expires_at' => '+1 day'])), 'Core payment authorization ledger rejects natural-language expiries before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['expires_at' => ['+1 day']])), 'Core payment authorization ledger rejects non-string expiries before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['expires_at' => '2099-02-31T00:00:00+00:00'])), 'Core payment authorization ledger rejects impossible calendar expiries before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['token_hash' => "bad\nhash"])), 'Core payment authorization ledger rejects token hashes with control characters before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['token_hash' => 'not-a-sha256-hash'])), 'Core payment authorization ledger rejects malformed token hashes before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['token_hash' => strtoupper(hash('sha256', 'invalid-insert-baseline'))])), 'Core payment authorization ledger rejects non-canonical token hashes before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, ['token_hash' => ['not-a-sha256-hash']])), 'Core payment authorization ledger rejects non-string token hashes before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $unrefundableRefundSourcePaymentId,
    'subject_id' => 'asset-insert-validation',
    'token_hash' => hash('sha256', 'authorization-pending-source-payment'),
])), 'Core payment authorization ledger rejects untrusted source payments before writing rows');
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'subject_id' => 'mismatched-authorization-subject',
    'token_hash' => hash('sha256', 'authorization-mismatched-source-subject'),
])), 'Core payment authorization ledger rejects subjects that do not match source payments before writing rows');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $authorizationCountBeforeInvalidInsert
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn() === $authorizationEventCountBeforeInvalidInsert,
    'Core payment authorization ledger stays unchanged after invalid authorization insert attempts'
);

$authorizationCountBeforeEventFailure = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$authorizationEventCountBeforeEventFailure = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn();
$pdo->exec(
    "CREATE TEMP TRIGGER core_payment_fail_authorization_created
     BEFORE INSERT ON cms_payment_authorization_events
     WHEN NEW.event_type = 'created'
     BEGIN
       SELECT RAISE(ABORT, 'authorization event failure');
     END"
);
core_payment_throws(static fn () => $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'authorization-created-event-failure'),
    'metadata' => ['source' => 'authorization-created-event-failure'],
])), 'Core payment authorization insert rolls back authorization when creation event persistence fails');
$pdo->exec('DROP TRIGGER core_payment_fail_authorization_created');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $authorizationCountBeforeEventFailure
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn() === $authorizationEventCountBeforeEventFailure,
    'Core payment authorization insert leaves no orphaned authorization after creation event failure'
);

$expiredDirectConsumeAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'expired-direct-consume-authorization'),
    'metadata' => ['source' => 'expired-direct-consume'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $expiredDirectConsumeAuthorizationId,
    ':expires_at' => gmdate('c', time() - 60),
]);
$expiredDirectConsumeBeforeEvents = (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $expiredDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn();
$expiredDirectConsumeResult = $repo->consumeAuthorization($expiredDirectConsumeAuthorizationId);
$expiredDirectConsumeAuthorization = $repo->authorization($expiredDirectConsumeAuthorizationId);
core_payment_check(
    $expiredDirectConsumeResult === false
    && is_array($expiredDirectConsumeAuthorization)
    && (int) ($expiredDirectConsumeAuthorization['used_count'] ?? -1) === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $expiredDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn() === $expiredDirectConsumeBeforeEvents,
    'Core payment authorization ledger refuses to consume expired active rows at repository boundary'
);
$corruptCounterDirectConsumePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'corrupt-counter-direct-consume-subject',
    'remote_id' => 'corrupt-counter-direct-consume-payment-remote',
    'reference' => 'corrupt-counter-direct-consume-payment-reference',
    'idempotency_key' => 'corrupt-counter-direct-consume-payment-key',
    'request_hash' => hash('sha256', 'corrupt-counter-direct-consume-payment'),
]));
$corruptCounterDirectConsumeAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $corruptCounterDirectConsumePaymentId,
    'subject_id' => 'corrupt-counter-direct-consume-subject',
    'token_hash' => hash('sha256', 'corrupt-counter-direct-consume-authorization'),
    'metadata' => ['source' => 'corrupt-counter-direct-consume'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET used_count = :used_count WHERE id = :id')->execute([
    ':id' => $corruptCounterDirectConsumeAuthorizationId,
    ':used_count' => -1,
]);
$corruptCounterDirectConsumeBeforeEvents = (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptCounterDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn();
$corruptCounterDirectConsumeResult = $repo->consumeAuthorization($corruptCounterDirectConsumeAuthorizationId);
$corruptCounterDirectConsumeAuthorization = $repo->authorization($corruptCounterDirectConsumeAuthorizationId);
core_payment_check(
    $corruptCounterDirectConsumeResult === false
    && is_array($corruptCounterDirectConsumeAuthorization)
    && (int) ($corruptCounterDirectConsumeAuthorization['used_count'] ?? 0) === -1
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptCounterDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn() === $corruptCounterDirectConsumeBeforeEvents,
    'Core payment authorization ledger refuses to consume corrupted usage counters at repository boundary'
);
$nonCanonicalCounterDirectConsumeAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $corruptCounterDirectConsumePaymentId,
    'subject_id' => 'corrupt-counter-direct-consume-subject',
    'token_hash' => hash('sha256', 'noncanonical-counter-direct-consume-authorization'),
    'metadata' => ['source' => 'noncanonical-counter-direct-consume'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => $nonCanonicalCounterDirectConsumeAuthorizationId,
    ':max_uses' => '1abc',
    ':used_count' => '0abc',
]);
$nonCanonicalCounterDirectConsumeBeforeEvents = (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $nonCanonicalCounterDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn();
$nonCanonicalCounterDirectConsumeResult = $repo->consumeAuthorization($nonCanonicalCounterDirectConsumeAuthorizationId);
$nonCanonicalCounterDirectConsumeAuthorization = $repo->authorization($nonCanonicalCounterDirectConsumeAuthorizationId);
core_payment_check(
    $nonCanonicalCounterDirectConsumeResult === false
    && is_array($nonCanonicalCounterDirectConsumeAuthorization)
    && (string) ($nonCanonicalCounterDirectConsumeAuthorization['max_uses'] ?? '') === '1abc'
    && (string) ($nonCanonicalCounterDirectConsumeAuthorization['used_count'] ?? '') === '0abc'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $nonCanonicalCounterDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn() === $nonCanonicalCounterDirectConsumeBeforeEvents,
    'Core payment authorization ledger refuses to consume non-canonical string usage counters before database comparison'
);
$corruptPaymentDirectConsumeAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'corrupt-payment-direct-consume-authorization'),
    'metadata' => ['source' => 'corrupt-payment-direct-consume'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => $corruptPaymentDirectConsumeAuthorizationId,
    ':payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
]);
$corruptPaymentDirectConsumeBeforeEvents = (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptPaymentDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn();
$corruptPaymentDirectConsumeResult = $repo->consumeAuthorization($corruptPaymentDirectConsumeAuthorizationId);
$corruptPaymentDirectConsumeAuthorization = $repo->authorization($corruptPaymentDirectConsumeAuthorizationId);
core_payment_check(
    $corruptPaymentDirectConsumeResult === false
    && is_array($corruptPaymentDirectConsumeAuthorization)
    && (int) ($corruptPaymentDirectConsumeAuthorization['used_count'] ?? -1) === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptPaymentDirectConsumeAuthorizationId . " AND event_type = 'consumed'")->fetchColumn() === $corruptPaymentDirectConsumeBeforeEvents,
    'Core payment authorization ledger refuses to consume corrupted payment ids at repository boundary'
);
$corruptPaymentDirectRevokeAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'corrupt-payment-direct-revoke-authorization'),
    'metadata' => ['source' => 'corrupt-payment-direct-revoke'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => $corruptPaymentDirectRevokeAuthorizationId,
    ':payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
]);
$corruptPaymentDirectRevokeBeforeEvents = (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptPaymentDirectRevokeAuthorizationId . " AND event_type = 'revoked'")->fetchColumn();
$corruptPaymentDirectRevokeResult = $repo->revokeAuthorization($corruptPaymentDirectRevokeAuthorizationId);
$corruptPaymentDirectRevokeAuthorization = $repo->authorization($corruptPaymentDirectRevokeAuthorizationId);
core_payment_check(
    $corruptPaymentDirectRevokeResult === false
    && is_array($corruptPaymentDirectRevokeAuthorization)
    && (string) ($corruptPaymentDirectRevokeAuthorization['status'] ?? '') === 'active'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptPaymentDirectRevokeAuthorizationId . " AND event_type = 'revoked'")->fetchColumn() === $corruptPaymentDirectRevokeBeforeEvents,
    'Core payment authorization ledger refuses to revoke corrupted payment ids at repository boundary'
);
$consumeEventFailurePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'consume-event-failure-subject',
    'remote_id' => 'consume-event-failure-payment',
    'reference' => 'consume-event-failure-reference',
    'idempotency_key' => 'consume-event-failure-payment-key',
    'request_hash' => hash('sha256', 'consume-event-failure-payment'),
]));
$consumeEventFailureAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $consumeEventFailurePaymentId,
    'subject_id' => 'consume-event-failure-subject',
    'token_hash' => hash('sha256', 'consume-event-failure-authorization'),
    'metadata' => ['source' => 'consume-event-failure'],
]));
$pdo->exec(
    "CREATE TEMP TRIGGER core_payment_fail_authorization_consumed
     BEFORE INSERT ON cms_payment_authorization_events
     WHEN NEW.event_type = 'consumed'
     BEGIN
       SELECT RAISE(ABORT, 'authorization consumed event failure');
     END"
);
core_payment_throws(static fn () => $repo->consumeAuthorization($consumeEventFailureAuthorizationId), 'Core payment authorization consume rolls back usage when consumption event persistence fails');
$pdo->exec('DROP TRIGGER core_payment_fail_authorization_consumed');
$consumeEventFailureAuthorization = $repo->authorization($consumeEventFailureAuthorizationId);
core_payment_check(
    is_array($consumeEventFailureAuthorization)
    && (int) ($consumeEventFailureAuthorization['used_count'] ?? -1) === 0
    && ($consumeEventFailureAuthorization['last_used_at'] ?? null) === null
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $consumeEventFailureAuthorizationId . " AND event_type = 'consumed'")->fetchColumn() === 0,
    'Core payment authorization consume leaves no usage mutation after consumption event failure'
);
$revokeEventFailurePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'revoke-event-failure-subject',
    'remote_id' => 'revoke-event-failure-payment',
    'reference' => 'revoke-event-failure-reference',
    'idempotency_key' => 'revoke-event-failure-payment-key',
    'request_hash' => hash('sha256', 'revoke-event-failure-payment'),
]));
$revokeEventFailureAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $revokeEventFailurePaymentId,
    'subject_id' => 'revoke-event-failure-subject',
    'token_hash' => hash('sha256', 'revoke-event-failure-authorization'),
    'metadata' => ['source' => 'revoke-event-failure'],
]));
$pdo->exec(
    "CREATE TEMP TRIGGER core_payment_fail_authorization_revoked
     BEFORE INSERT ON cms_payment_authorization_events
     WHEN NEW.event_type = 'revoked'
     BEGIN
       SELECT RAISE(ABORT, 'authorization revoked event failure');
     END"
);
core_payment_throws(static fn () => $repo->revokeAuthorization($revokeEventFailureAuthorizationId), 'Core payment authorization revoke rolls back state when revoke event persistence fails');
$pdo->exec('DROP TRIGGER core_payment_fail_authorization_revoked');
$revokeEventFailureAuthorization = $repo->authorization($revokeEventFailureAuthorizationId);
core_payment_check(
    is_array($revokeEventFailureAuthorization)
    && (string) ($revokeEventFailureAuthorization['status'] ?? '') === 'active'
    && ($revokeEventFailureAuthorization['revoked_at'] ?? null) === null
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $revokeEventFailureAuthorizationId . " AND event_type = 'revoked'")->fetchColumn() === 0,
    'Core payment authorization revoke leaves no state mutation after revoke event failure'
);
$bulkRevokeEventFailurePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'bulk-revoke-event-failure-subject',
    'remote_id' => 'bulk-revoke-event-failure-payment',
    'reference' => 'bulk-revoke-event-failure-reference',
    'idempotency_key' => 'bulk-revoke-event-failure-payment-key',
    'request_hash' => hash('sha256', 'bulk-revoke-event-failure-payment'),
]));
$bulkRevokeEventFailureAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $bulkRevokeEventFailurePaymentId,
    'subject_id' => 'bulk-revoke-event-failure-subject',
    'token_hash' => hash('sha256', 'bulk-revoke-event-failure-authorization'),
    'metadata' => ['source' => 'bulk-revoke-event-failure'],
]));
$pdo->exec(
    "CREATE TEMP TRIGGER core_payment_fail_authorization_bulk_revoked
     BEFORE INSERT ON cms_payment_authorization_events
     WHEN NEW.event_type = 'revoked'
     BEGIN
       SELECT RAISE(ABORT, 'authorization bulk revoked event failure');
     END"
);
core_payment_throws(static fn () => $repo->revokeActiveAuthorizationsForPayment($bulkRevokeEventFailurePaymentId), 'Core payment authorization payment-wide revoke rolls back state when revoke event persistence fails');
$pdo->exec('DROP TRIGGER core_payment_fail_authorization_bulk_revoked');
$bulkRevokeEventFailureAuthorization = $repo->authorization($bulkRevokeEventFailureAuthorizationId);
core_payment_check(
    is_array($bulkRevokeEventFailureAuthorization)
    && (string) ($bulkRevokeEventFailureAuthorization['status'] ?? '') === 'active'
    && ($bulkRevokeEventFailureAuthorization['revoked_at'] ?? null) === null
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $bulkRevokeEventFailureAuthorizationId . " AND event_type = 'revoked'")->fetchColumn() === 0,
    'Core payment authorization payment-wide revoke leaves no state mutation after revoke event failure'
);
core_payment_check(
    $repo->activeAuthorizationForPaymentSubject((int) ($payment['id'] ?? 0), 'paid_download', 'asset-42') === null,
    'Core payment authorization ledger excludes expired active rows from active authorization lookup'
);
$nonCanonicalCounterLookupPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'noncanonical-counter-active-lookup-subject',
    'remote_id' => 'noncanonical-counter-active-lookup-payment',
    'reference' => 'noncanonical-counter-active-lookup-reference',
    'idempotency_key' => 'noncanonical-counter-active-lookup-payment-key',
    'request_hash' => hash('sha256', 'noncanonical-counter-active-lookup-payment'),
]));
$nonCanonicalCounterLookupAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $nonCanonicalCounterLookupPaymentId,
    'subject_id' => 'noncanonical-counter-active-lookup-subject',
    'token_hash' => hash('sha256', 'noncanonical-counter-active-lookup-authorization'),
    'metadata' => ['source' => 'noncanonical-counter-active-lookup'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => $nonCanonicalCounterLookupAuthorizationId,
    ':max_uses' => '1abc',
    ':used_count' => '0abc',
]);
core_payment_check(
    $repo->activeAuthorizationForPaymentSubject($nonCanonicalCounterLookupPaymentId, 'paid_download', 'noncanonical-counter-active-lookup-subject') === null,
    'Core payment authorization ledger excludes non-canonical restored use counters from active authorization lookup'
);
$exhaustedCounterLookupPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'exhausted-counter-active-lookup-subject',
    'remote_id' => 'exhausted-counter-active-lookup-payment',
    'reference' => 'exhausted-counter-active-lookup-reference',
    'idempotency_key' => 'exhausted-counter-active-lookup-payment-key',
    'request_hash' => hash('sha256', 'exhausted-counter-active-lookup-payment'),
]));
$exhaustedCounterLookupAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $exhaustedCounterLookupPaymentId,
    'subject_id' => 'exhausted-counter-active-lookup-subject',
    'max_uses' => 1,
    'token_hash' => hash('sha256', 'exhausted-counter-active-lookup-authorization'),
    'metadata' => ['source' => 'exhausted-counter-active-lookup'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET used_count = :used_count WHERE id = :id')->execute([
    ':id' => $exhaustedCounterLookupAuthorizationId,
    ':used_count' => 1,
]);
core_payment_check(
    $repo->activeAuthorizationForPaymentSubject($exhaustedCounterLookupPaymentId, 'paid_download', 'exhausted-counter-active-lookup-subject') === null,
    'Core payment authorization ledger excludes exhausted finite-use grants from active authorization lookup'
);
$nonCanonicalExpiryMaintenancePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'noncanonical-expiry-maintenance-subject',
    'remote_id' => 'noncanonical-expiry-maintenance-payment',
    'reference' => 'noncanonical-expiry-maintenance-reference',
    'idempotency_key' => 'noncanonical-expiry-maintenance-payment-key',
    'request_hash' => hash('sha256', 'noncanonical-expiry-maintenance-payment'),
]));
$nonCanonicalExpiryMaintenanceAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $nonCanonicalExpiryMaintenancePaymentId,
    'subject_id' => 'noncanonical-expiry-maintenance-subject',
    'token_hash' => hash('sha256', 'noncanonical-expiry-maintenance-authorization'),
    'metadata' => ['source' => 'noncanonical-expiry-maintenance'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $nonCanonicalExpiryMaintenanceAuthorizationId,
    ':expires_at' => ' 1999-01-01T00:00:00+00:00',
]);
$repo->expireExpiredAuthorizations(10);
$nonCanonicalExpiryMaintenanceAuthorization = $repo->authorization($nonCanonicalExpiryMaintenanceAuthorizationId);
core_payment_check(
    is_array($nonCanonicalExpiryMaintenanceAuthorization)
    && (string) ($nonCanonicalExpiryMaintenanceAuthorization['status'] ?? '') === 'active'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $nonCanonicalExpiryMaintenanceAuthorizationId . " AND event_type = 'expired'")->fetchColumn() === 0,
    'Core payment authorization expiry maintenance skips non-canonical restored expiry rows without writing lifecycle events'
);
$corruptPaymentExpiryMaintenanceAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'corrupt-payment-expiry-maintenance-authorization'),
    'metadata' => ['source' => 'corrupt-payment-expiry-maintenance'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id, expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $corruptPaymentExpiryMaintenanceAuthorizationId,
    ':payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
    ':expires_at' => gmdate('c', time() - 60),
]);
$repo->expireExpiredAuthorizations(10);
$corruptPaymentExpiryMaintenanceAuthorization = $repo->authorization($corruptPaymentExpiryMaintenanceAuthorizationId);
core_payment_check(
    is_array($corruptPaymentExpiryMaintenanceAuthorization)
    && (string) ($corruptPaymentExpiryMaintenanceAuthorization['status'] ?? '') === 'active'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $corruptPaymentExpiryMaintenanceAuthorizationId . " AND event_type = 'expired'")->fetchColumn() === 0,
    'Core payment authorization expiry maintenance skips corrupted payment ids without writing lifecycle events'
);
$expireEventFailurePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'expire-event-failure-subject',
    'remote_id' => 'expire-event-failure-payment',
    'reference' => 'expire-event-failure-reference',
    'idempotency_key' => 'expire-event-failure-payment-key',
    'request_hash' => hash('sha256', 'expire-event-failure-payment'),
]));
$expireEventFailureAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $expireEventFailurePaymentId,
    'subject_id' => 'expire-event-failure-subject',
    'token_hash' => hash('sha256', 'expire-event-failure-authorization'),
    'metadata' => ['source' => 'expire-event-failure'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $expireEventFailureAuthorizationId,
    ':expires_at' => gmdate('c', time() - 60),
]);
$pdo->exec(
    "CREATE TEMP TRIGGER core_payment_fail_authorization_expired
     BEFORE INSERT ON cms_payment_authorization_events
     WHEN NEW.event_type = 'expired'
     BEGIN
       SELECT RAISE(ABORT, 'authorization expired event failure');
     END"
);
core_payment_throws(static fn () => $repo->expireExpiredAuthorizations(10), 'Core payment authorization expiry rolls back state when expired event persistence fails');
$pdo->exec('DROP TRIGGER core_payment_fail_authorization_expired');
$expireEventFailureAuthorization = $repo->authorization($expireEventFailureAuthorizationId);
core_payment_check(
    is_array($expireEventFailureAuthorization)
    && (string) ($expireEventFailureAuthorization['status'] ?? '') === 'active'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $expireEventFailureAuthorizationId . " AND event_type = 'expired'")->fetchColumn() === 0,
    'Core payment authorization expiry leaves no state mutation after expired event failure'
);

$entitlementCountBeforeInvalidInsert = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn();
$validEntitlementInsert = [
    'principal_type' => 'member',
    'principal_id' => 'entitled-member',
    'subject_type' => 'paid_download',
    'subject_id' => 'asset-42',
    'source_payment_id' => (int) ($payment['id'] ?? 0),
    'source_authorization_id' => null,
    'status' => 'active',
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => ['source' => 'entitlement-insert-validation'],
];
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['principal_type' => ' Member '])), 'Core payment entitlement ledger rejects non-canonical principal types before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['principal_type' => ['member']])), 'Core payment entitlement ledger rejects non-string principal types before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['subject_type' => ' Paid_Download '])), 'Core payment entitlement ledger rejects non-canonical subject types before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['subject_type' => ['paid_download']])), 'Core payment entitlement ledger rejects non-string subject types before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['principal_id' => ' entitled-member '])), 'Core payment entitlement ledger rejects non-canonical principal ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['principal_id' => ['entitled-member']])), 'Core payment entitlement ledger rejects non-string principal ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['subject_id' => ' asset-42 '])), 'Core payment entitlement ledger rejects non-canonical subject ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['subject_id' => ['asset-42']])), 'Core payment entitlement ledger rejects non-string subject ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['principal_id' => "member\nbad"])), 'Core payment entitlement ledger rejects principal ids with control characters before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['principal_id' => 'payment_token%3Draw-entitlement-principal-token'])), 'Core payment entitlement ledger rejects URL-encoded token-like principal ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['subject_id' => 'payment_token%3Draw-entitlement-subject-token'])), 'Core payment entitlement ledger rejects URL-encoded token-like subject ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['status' => 'pending'])), 'Core payment entitlement ledger rejects unknown statuses before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['status' => ' Active '])), 'Core payment entitlement ledger rejects non-canonical statuses before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['status' => ['active']])), 'Core payment entitlement ledger rejects non-string statuses before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['status' => 'revoked', 'revoked_at' => gmdate('c')])), 'Core payment entitlement ledger rejects revoked entitlement creation before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['status' => 'expired'])), 'Core payment entitlement ledger rejects expired entitlement creation before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['revoked_at' => gmdate('c')])), 'Core payment entitlement ledger rejects revoked_at on active entitlement creation before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['source_payment_id' => 0])), 'Core payment entitlement ledger rejects invalid source payment ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['source_authorization_id' => -1])), 'Core payment entitlement ledger rejects invalid source authorization ids before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['expires_at' => gmdate('c', time() - 60)])), 'Core payment entitlement ledger rejects expired entitlement creation before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['expires_at' => ' ' . gmdate('c', time() + 3600) . ' '])), 'Core payment entitlement ledger rejects non-canonical expiries before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['expires_at' => '+1 day'])), 'Core payment entitlement ledger rejects natural-language expiries before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['expires_at' => ['+1 day']])), 'Core payment entitlement ledger rejects non-string expiries before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, ['expires_at' => '2099-02-31T00:00:00+00:00'])), 'Core payment entitlement ledger rejects impossible calendar expiries before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'source_payment_id' => $unrefundableRefundSourcePaymentId,
    'subject_id' => 'asset-insert-validation',
])), 'Core payment entitlement ledger rejects untrusted source payments before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'subject_id' => 'mismatched-entitlement-subject',
])), 'Core payment entitlement ledger rejects subjects that do not match source payments before writing rows');
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'source_authorization_id' => $repositoryMetadataAuthorizationId,
])), 'Core payment entitlement ledger rejects source authorizations that do not match source payments before writing rows');
$inactiveSourceAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'inactive-entitlement-source-authorization'),
    'expires_at' => gmdate('c', time() + 3600),
]));
$repo->revokeAuthorization($inactiveSourceAuthorizationId);
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'source_authorization_id' => $inactiveSourceAuthorizationId,
])), 'Core payment entitlement ledger rejects inactive source authorizations before writing rows');
$expiredSourceAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'expired-entitlement-source-authorization'),
    'expires_at' => gmdate('c', time() + 3600),
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $expiredSourceAuthorizationId,
    ':expires_at' => gmdate('c', time() - 60),
]);
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'source_authorization_id' => $expiredSourceAuthorizationId,
])), 'Core payment entitlement ledger rejects expired source authorizations before writing rows');
$naturalLanguageSourceAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'natural-language-entitlement-source-authorization'),
    'expires_at' => gmdate('c', time() + 3600),
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $naturalLanguageSourceAuthorizationId,
    ':expires_at' => '+1 day',
]);
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'source_authorization_id' => $naturalLanguageSourceAuthorizationId,
])), 'Core payment entitlement ledger rejects natural-language source authorization expiries before writing rows');
$corruptInsertSourceAuthorizationPaymentId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'corrupt-insert-source-authorization-payment-id'),
    'expires_at' => gmdate('c', time() + 3600),
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => $corruptInsertSourceAuthorizationPaymentId,
    ':payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
]);
core_payment_throws(static fn () => $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'source_authorization_id' => $corruptInsertSourceAuthorizationPaymentId,
])), 'Core payment entitlement ledger rejects corrupted source authorization payment ids before writing rows');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === $entitlementCountBeforeInvalidInsert,
    'Core payment entitlement ledger stays unchanged after invalid entitlement insert attempts'
);

$expiredLookupEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'expired-active-lookup-member',
    'metadata' => ['source' => 'expired-active-lookup'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $expiredLookupEntitlementId,
    ':expires_at' => gmdate('c', time() - 60),
]);
core_payment_check(
    $repo->activeEntitlements('member', 'expired-active-lookup-member', 'paid_download', 'asset-42') === [],
    'Core payment entitlement ledger excludes expired active rows from active entitlement lookup'
);
$runtimeEntitlements = new PaymentEntitlementService($pdo, $repo);
$corruptSourcePaymentEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'corrupt-source-payment-member',
    'metadata' => ['source' => 'corrupt-source-payment-entitlement'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET source_payment_id = :source_payment_id WHERE id = :id')->execute([
    ':source_payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
    ':id' => $corruptSourcePaymentEntitlementId,
]);
core_payment_check(
    $repo->activeEntitlements('member', 'corrupt-source-payment-member', 'paid_download', 'asset-42') === []
    && !$runtimeEntitlements->isEntitled('member', 'corrupt-source-payment-member', 'paid_download', 'asset-42'),
    'Core payment entitlement checks reject corrupted source payment ids instead of casting restored rows'
);
$corruptSourceAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'corrupt-source-authorization-entitlement'),
    'expires_at' => gmdate('c', time() + 3600),
]));
$corruptSourceAuthorizationEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'corrupt-source-authorization-member',
    'source_authorization_id' => $corruptSourceAuthorizationId,
    'metadata' => ['source' => 'corrupt-source-authorization-entitlement'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET source_authorization_id = :source_authorization_id WHERE id = :id')->execute([
    ':source_authorization_id' => (string) $corruptSourceAuthorizationId . 'abc',
    ':id' => $corruptSourceAuthorizationEntitlementId,
]);
core_payment_check(
    $repo->activeEntitlements('member', 'corrupt-source-authorization-member', 'paid_download', 'asset-42') === []
    && !$runtimeEntitlements->isEntitled('member', 'corrupt-source-authorization-member', 'paid_download', 'asset-42'),
    'Core payment entitlement checks reject corrupted source authorization ids instead of casting restored rows'
);
$corruptAuthorizationPaymentId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'token_hash' => hash('sha256', 'corrupt-authorization-payment-id-entitlement'),
    'expires_at' => gmdate('c', time() + 3600),
]));
$repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'corrupt-authorization-payment-member',
    'source_authorization_id' => $corruptAuthorizationPaymentId,
    'metadata' => ['source' => 'corrupt-authorization-payment-entitlement'],
]));
$pdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
    ':id' => $corruptAuthorizationPaymentId,
]);
core_payment_check(
    $repo->activeEntitlements('member', 'corrupt-authorization-payment-member', 'paid_download', 'asset-42') === []
    && !$runtimeEntitlements->isEntitled('member', 'corrupt-authorization-payment-member', 'paid_download', 'asset-42'),
    'Core payment entitlement checks reject corrupted source authorization payment ids instead of casting restored rows'
);
$revokedAtActiveLookupEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'revoked-at-active-lookup-member',
    'source_authorization_id' => null,
    'metadata' => ['source' => 'revoked-at-active-lookup'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET revoked_at = :revoked_at WHERE id = :id')->execute([
    ':id' => $revokedAtActiveLookupEntitlementId,
    ':revoked_at' => gmdate('c'),
]);
core_payment_check(
    $repo->activeEntitlements('member', 'revoked-at-active-lookup-member', 'paid_download', 'asset-42') === [],
    'Core payment entitlement ledger excludes active rows with restored revoked_at from active entitlement lookup'
);
$nonCanonicalExpiryMaintenanceEntitlementPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'noncanonical-entitlement-maintenance-subject',
    'remote_id' => 'noncanonical-entitlement-maintenance-payment',
    'reference' => 'noncanonical-entitlement-maintenance-reference',
    'idempotency_key' => 'noncanonical-entitlement-maintenance-payment-key',
    'request_hash' => hash('sha256', 'noncanonical-entitlement-maintenance-payment'),
]));
$nonCanonicalExpiryMaintenanceEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'noncanonical-entitlement-maintenance-member',
    'subject_id' => 'noncanonical-entitlement-maintenance-subject',
    'source_payment_id' => $nonCanonicalExpiryMaintenanceEntitlementPaymentId,
    'source_authorization_id' => null,
    'metadata' => ['source' => 'noncanonical-entitlement-maintenance'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $nonCanonicalExpiryMaintenanceEntitlementId,
    ':expires_at' => ' 1999-01-01T00:00:00+00:00',
]);
$repo->expireExpiredEntitlements(10);
$nonCanonicalExpiryMaintenanceEntitlement = $repo->entitlement($nonCanonicalExpiryMaintenanceEntitlementId);
core_payment_check(
    is_array($nonCanonicalExpiryMaintenanceEntitlement)
    && (string) ($nonCanonicalExpiryMaintenanceEntitlement['status'] ?? '') === 'active',
    'Core payment entitlement expiry maintenance skips non-canonical restored expiry rows'
);
$corruptSourcePaymentRevokeEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'corrupt-source-payment-revoke-member',
    'metadata' => ['source' => 'corrupt-source-payment-revoke'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET source_payment_id = :source_payment_id WHERE id = :id')->execute([
    ':id' => $corruptSourcePaymentRevokeEntitlementId,
    ':source_payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
]);
$corruptSourcePaymentRevokeResult = $repo->revokeEntitlement($corruptSourcePaymentRevokeEntitlementId);
$corruptSourcePaymentRevokeEntitlement = $repo->entitlement($corruptSourcePaymentRevokeEntitlementId);
core_payment_check(
    $corruptSourcePaymentRevokeResult === false
    && is_array($corruptSourcePaymentRevokeEntitlement)
    && (string) ($corruptSourcePaymentRevokeEntitlement['status'] ?? '') === 'active',
    'Core payment entitlement ledger refuses to revoke corrupted source payment ids at repository boundary'
);
$corruptSourcePaymentExpiryEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'corrupt-source-payment-expiry-member',
    'metadata' => ['source' => 'corrupt-source-payment-expiry'],
]));
$pdo->prepare('UPDATE cms_payment_entitlements SET source_payment_id = :source_payment_id, expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $corruptSourcePaymentExpiryEntitlementId,
    ':source_payment_id' => (string) ((int) ($payment['id'] ?? 0)) . 'abc',
    ':expires_at' => gmdate('c', time() - 60),
]);
$repo->expireExpiredEntitlements(10);
$corruptSourcePaymentExpiryEntitlement = $repo->entitlement($corruptSourcePaymentExpiryEntitlementId);
core_payment_check(
    is_array($corruptSourcePaymentExpiryEntitlement)
    && (string) ($corruptSourcePaymentExpiryEntitlement['status'] ?? '') === 'active',
    'Core payment entitlement expiry maintenance skips corrupted source payment ids without changing lifecycle state'
);

$repositoryRefundStatusPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'repository-refund-status',
    'remote_id' => 'repository-refund-status-payment',
    'reference' => 'repository-refund-status-reference',
    'idempotency_key' => 'repository-refund-status-payment-key',
    'request_hash' => hash('sha256', 'repository-refund-status-payment'),
]));
core_payment_throws(
    static fn () => $repo->updatePaymentStatus($repositoryRefundStatusPaymentId, 'partially_refunded'),
    'Core payment repository rejects partially refunded status without matching completed refund totals'
);
core_payment_throws(
    static fn () => $repo->updatePaymentStatus($repositoryRefundStatusPaymentId, 'refunded'),
    'Core payment repository rejects refunded status without matching completed refund totals'
);
$corruptRefundStatusAmountPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'repository-corrupt-refund-status-amount',
    'remote_id' => 'repository-corrupt-refund-status-amount-payment',
    'reference' => 'repository-corrupt-refund-status-amount-reference',
    'idempotency_key' => 'repository-corrupt-refund-status-amount-payment-key',
    'request_hash' => hash('sha256', 'repository-corrupt-refund-status-amount-payment'),
]));
$repo->insertRefund(array_replace($validRefundInsert, [
    'payment_id' => $corruptRefundStatusAmountPaymentId,
    'remote_id' => 'repository-corrupt-refund-status-amount-refund',
    'amount_minor' => 100,
    'idempotency_key' => 'repository-corrupt-refund-status-amount-refund-key',
    'request_hash' => hash('sha256', 'repository-corrupt-refund-status-amount-refund'),
]));
$pdo->prepare('UPDATE cms_payments SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '999abc',
    ':id' => $corruptRefundStatusAmountPaymentId,
]);
core_payment_throws(
    static fn () => $repo->updatePaymentStatus($corruptRefundStatusAmountPaymentId, 'partially_refunded'),
    'Core payment repository rejects refund status updates for corrupted source payment amounts'
);
$refundStatusEventFailurePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'refund-status-event-failure-subject',
    'remote_id' => 'refund-status-event-failure-payment',
    'reference' => 'refund-status-event-failure-reference',
    'amount_minor' => 500,
    'idempotency_key' => 'refund-status-event-failure-payment-key',
    'request_hash' => hash('sha256', 'refund-status-event-failure-payment'),
]));
$repo->insertRefund(array_replace($validRefundInsert, [
    'payment_id' => $refundStatusEventFailurePaymentId,
    'remote_id' => 'refund-status-event-failure-refund',
    'amount_minor' => 500,
    'idempotency_key' => 'refund-status-event-failure-refund-key',
    'request_hash' => hash('sha256', 'refund-status-event-failure-refund'),
]));
$refundStatusEventFailureAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $refundStatusEventFailurePaymentId,
    'subject_id' => 'refund-status-event-failure-subject',
    'token_hash' => hash('sha256', 'refund-status-event-failure-authorization'),
    'metadata' => ['source' => 'refund-status-event-failure'],
]));
$refundStatusEventFailureEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'refund-status-event-failure-member',
    'subject_id' => 'refund-status-event-failure-subject',
    'source_payment_id' => $refundStatusEventFailurePaymentId,
    'source_authorization_id' => null,
    'metadata' => ['source' => 'refund-status-event-failure'],
]));
$pdo->exec(
    "CREATE TEMP TRIGGER core_payment_fail_refund_status_authorization_revoked
     BEFORE INSERT ON cms_payment_authorization_events
     WHEN NEW.event_type = 'revoked'
     BEGIN
       SELECT RAISE(ABORT, 'refund status authorization revoke event failure');
     END"
);
core_payment_throws(static fn () => $repo->updatePaymentStatus($refundStatusEventFailurePaymentId, 'refunded'), 'Core payment status update rolls back full-refund access revocation when authorization event persistence fails');
$pdo->exec('DROP TRIGGER core_payment_fail_refund_status_authorization_revoked');
$refundStatusEventFailurePayment = $repo->payment($refundStatusEventFailurePaymentId);
$refundStatusEventFailureAuthorization = $repo->authorization($refundStatusEventFailureAuthorizationId);
$refundStatusEventFailureEntitlement = $repo->entitlement($refundStatusEventFailureEntitlementId);
core_payment_check(
    is_array($refundStatusEventFailurePayment)
    && (string) ($refundStatusEventFailurePayment['status'] ?? '') === 'paid'
    && is_array($refundStatusEventFailureAuthorization)
    && (string) ($refundStatusEventFailureAuthorization['status'] ?? '') === 'active'
    && is_array($refundStatusEventFailureEntitlement)
    && (string) ($refundStatusEventFailureEntitlement['status'] ?? '') === 'active',
    'Core payment status update leaves payment and access state unchanged after full-refund revocation failure'
);
$repositoryRefundStatusAuthorizationId = $repo->insertAuthorization(array_replace($validAuthorizationInsert, [
    'payment_id' => $repositoryRefundStatusPaymentId,
    'subject_id' => 'repository-refund-status',
    'token_hash' => hash('sha256', 'repository-refund-status-authorization'),
]));
$repositoryRefundStatusEntitlementId = $repo->insertEntitlement(array_replace($validEntitlementInsert, [
    'principal_id' => 'repository-refund-status-member',
    'subject_id' => 'repository-refund-status',
    'source_payment_id' => $repositoryRefundStatusPaymentId,
    'source_authorization_id' => $repositoryRefundStatusAuthorizationId,
]));
$repo->insertRefund(array_replace($validRefundInsert, [
    'payment_id' => $repositoryRefundStatusPaymentId,
    'remote_id' => 'repository-refund-status-refund',
    'amount_minor' => 999,
    'idempotency_key' => 'repository-refund-status-refund-key',
    'request_hash' => hash('sha256', 'repository-refund-status-refund'),
]));
$repo->updatePaymentStatus($repositoryRefundStatusPaymentId, 'refunded');
$repositoryRefundStatusAuthorization = $repo->authorization($repositoryRefundStatusAuthorizationId);
$repositoryRefundStatusEntitlement = $repo->entitlement($repositoryRefundStatusEntitlementId);
core_payment_check(
    (string) (($repo->payment($repositoryRefundStatusPaymentId) ?? [])['status'] ?? '') === 'refunded'
    && is_array($repositoryRefundStatusAuthorization)
    && (string) ($repositoryRefundStatusAuthorization['status'] ?? '') === 'revoked'
    && is_array($repositoryRefundStatusEntitlement)
    && (string) ($repositoryRefundStatusEntitlement['status'] ?? '') === 'revoked',
    'Core payment repository revokes active authorization and entitlement state when a payment becomes fully refunded'
);

$authorizationCountBeforeInvalidMutations = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$authorizationEventCountBeforeInvalidMutations = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn();
$entitlementCountBeforeInvalidMutations = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn();
core_payment_throws(static fn () => $repo->consumeAuthorization(0), 'Core payment authorization ledger rejects invalid consumption ids before writing rows');
core_payment_throws(static fn () => $repo->revokeAuthorization(-1), 'Core payment authorization ledger rejects invalid revoke ids before writing rows');
core_payment_throws(static fn () => $repo->revokeActiveAuthorizationsForPayment(0), 'Core payment authorization ledger rejects invalid payment revoke ids before writing rows');
core_payment_throws(static fn () => $repo->expireExpiredAuthorizations(0), 'Core payment authorization ledger rejects invalid expiry limits before writing rows');
core_payment_throws(static fn () => $repo->expireExpiredAuthorizations(1001), 'Core payment authorization ledger rejects out-of-bounds expiry limits before writing rows');
core_payment_throws(static fn () => $repo->revokeEntitlement(0), 'Core payment entitlement ledger rejects invalid revoke ids before writing rows');
core_payment_throws(static fn () => $repo->revokeActiveEntitlementsForPayment(-1), 'Core payment entitlement ledger rejects invalid payment revoke ids before writing rows');
core_payment_throws(static fn () => $repo->expireExpiredEntitlements(0), 'Core payment entitlement ledger rejects invalid expiry limits before writing rows');
core_payment_throws(static fn () => $repo->expireExpiredEntitlements(1001), 'Core payment entitlement ledger rejects out-of-bounds expiry limits before writing rows');
core_payment_throws(static fn () => $service->expirePaymentAuthorizations(0), 'Core payment service rejects invalid authorization expiry limits before repository writes');
core_payment_throws(static fn () => $service->expirePaymentAuthorizations(1001), 'Core payment service rejects out-of-bounds authorization expiry limits before repository writes');
core_payment_throws(static fn () => $service->expirePaymentEntitlements(0), 'Core payment service rejects invalid entitlement expiry limits before repository writes');
core_payment_throws(static fn () => $service->expirePaymentEntitlements(1001), 'Core payment service rejects out-of-bounds entitlement expiry limits before repository writes');
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $authorizationCountBeforeInvalidMutations
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_authorization_events')->fetchColumn() === $authorizationEventCountBeforeInvalidMutations
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === $entitlementCountBeforeInvalidMutations,
    'Core payment authorization and entitlement ledgers stay unchanged after invalid mutation attempts'
);

$providerSettings->save(ManualPaymentProvider::PROVIDER_ID, 'Manual Bank Transfer', 'enabled', ['instructions' => 'Bank transfer reference is shown after checkout.'], []);
$manualPayment = $service->createProviderPayment('paid_download', 'manual-asset', ManualPaymentProvider::PROVIDER_ID, 1999, 'USD', 'manual-payment-key');
$manualStatus = $service->trustedStatus('paid_download', 'manual-asset', 'USD');
core_payment_check(
    (string) ($manualPayment['status'] ?? '') === 'pending'
    && str_starts_with((string) ($manualPayment['remote_id'] ?? ''), 'core-manual-pay-')
    && (string) ($manualStatus['status'] ?? '') === 'unpaid',
    'Core manual payment provider records pending operator-confirmed payments without plugin runtime'
);
$manualSynced = $service->syncProviderPaymentStatus((int) ($manualPayment['id'] ?? 0), 'paid');
core_payment_check((string) ($manualSynced['status'] ?? '') === 'pending', 'Core manual payment provider status sync cannot promote pending payments without administrator capture');
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->getPaymentStatus((object) [
        'current_status' => ' Paid ',
        'provider_payment_id' => 'core-manual-pay-non-canonical-status',
    ]),
    'Core manual payment Provider rejects non-canonical current status values during status sync'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'manual-non-canonical-amount',
        'amount_minor' => ' 1999 ',
        'currency' => 'USD',
        'idempotency_key' => 'manual-non-canonical-amount',
        'provider_public_config' => ['instructions' => 'Bank transfer reference is shown after checkout.'],
    ]),
    'Core manual payment Provider rejects non-canonical amounts before creating payment references'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'manual-non-canonical-currency',
        'amount_minor' => 1999,
        'currency' => 'usd',
        'idempotency_key' => 'manual-non-canonical-currency',
        'provider_public_config' => ['instructions' => 'Bank transfer reference is shown after checkout.'],
    ]),
    'Core manual payment Provider rejects non-canonical currencies before creating payment references'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->createPayment((object) [
        'subject_type' => ['paid_download'],
        'subject_id' => 'manual-non-string-subject-type',
        'amount_minor' => 1999,
        'currency' => 'USD',
        'idempotency_key' => 'manual-non-string-subject-type',
        'provider_public_config' => ['instructions' => 'Bank transfer reference is shown after checkout.'],
    ]),
    'Core manual payment Provider rejects non-string command subjects before creating payment references'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'manual-non-string-idempotency',
        'amount_minor' => 1999,
        'currency' => 'USD',
        'idempotency_key' => ['manual-non-string-idempotency'],
        'provider_public_config' => ['instructions' => 'Bank transfer reference is shown after checkout.'],
    ]),
    'Core manual payment Provider rejects non-string idempotency keys before creating payment references'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => "manual\nunsafe-subject",
        'amount_minor' => 1999,
        'currency' => 'USD',
        'idempotency_key' => 'manual-unsafe-command-subject',
        'provider_public_config' => ['instructions' => 'Bank transfer reference is shown after checkout.'],
    ]),
    'Core manual payment Provider rejects unsafe command subjects before creating payment references'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_download',
        'subject_id' => 'manual-token-like-idempotency',
        'amount_minor' => 1999,
        'currency' => 'USD',
        'idempotency_key' => 'payment_token%3Draw-manual-idempotency-token',
        'provider_public_config' => ['instructions' => 'Bank transfer reference is shown after checkout.'],
    ]),
    'Core manual payment Provider rejects token-like command idempotency keys before creating payment references'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->capturePayment((object) [
        'provider_payment_id' => ' core-manual-pay-non-canonical-reference ',
    ]),
    'Core manual payment Provider rejects non-canonical remote references during capture'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->capturePayment((object) [
        'provider_payment_id' => ['core-manual-pay-non-string-reference'],
    ]),
    'Core manual payment Provider rejects non-string remote references during capture'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->capturePayment((object) [
        'provider_payment_id' => 'payment_token%3Draw-manual-remote-token',
    ]),
    'Core manual payment Provider rejects token-like remote references during capture'
);
core_payment_throws(
    static fn () => (new ManualPaymentProvider())->getPaymentStatus((object) [
        'current_status' => ['paid'],
        'provider_payment_id' => 'core-manual-pay-non-string-status',
    ]),
    'Core manual payment Provider rejects non-string current statuses during status sync'
);
$manualCaptured = $service->captureProviderPayment((int) ($manualPayment['id'] ?? 0), 'manual-capture-key');
$manualCapturedStatus = $service->trustedStatus('paid_download', 'manual-asset', 'USD');
core_payment_check(
    (string) ($manualCaptured['status'] ?? '') === 'paid'
    && (string) ($manualCapturedStatus['status'] ?? '') === 'paid'
    && (int) ($manualCapturedStatus['net_paid_minor'] ?? 0) === 1999,
    'Core manual payment provider lets administrators confirm trusted paid state'
);
$manualRefund = $service->refundProviderPayment((int) ($manualCaptured['id'] ?? 0), 1999, 'manual refund', 'manual-refund-key');
core_payment_check(
    (string) ($manualRefund['status'] ?? '') === 'completed'
    && str_starts_with((string) ($manualRefund['remote_id'] ?? ''), 'core-manual-refund-')
    && (string) (($repo->payment((int) ($manualCaptured['id'] ?? 0))['status'] ?? '')) === 'refunded',
    'Core manual payment provider records administrator-confirmed refunds in Core ledger'
);
$manualCancelPayment = $service->createProviderPayment('paid_download', 'manual-cancel-asset', ManualPaymentProvider::PROVIDER_ID, 599, 'USD', 'manual-cancel-payment-key');
$manualCancelled = $service->cancelProviderPayment((int) ($manualCancelPayment['id'] ?? 0), 'manual-cancel-key');
core_payment_check((string) ($manualCancelled['status'] ?? '') === 'cancelled', 'Core manual payment provider lets administrators cancel pending payments');

PaymentProviderRegistry::register('core.settings-aware', new SettingsAwarePaymentProvider());
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$settingsAwarePayment = (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-asset', 'core.settings-aware', 777, 'USD', 'settings-aware-payment');
$settingsAwareMetadata = json_decode((string) ($settingsAwarePayment['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($settingsAwarePayment['status'] ?? '') === 'paid'
    && ($settingsAwareMetadata['observed_mode'] ?? '') === 'sandbox'
    && ($settingsAwareMetadata['observed_display_name'] ?? '') === 'Settings Aware'
    && ($settingsAwareMetadata['credential_last4'] ?? '') === 'cret'
    && !str_contains((string) ($settingsAwarePayment['metadata_json'] ?? ''), 'sk_core_settings_secret'),
    'Core PaymentService supplies Core-managed Provider settings to Providers without storing raw secrets'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$pdo->prepare('UPDATE cms_payment_provider_settings SET display_name = :display_name WHERE provider_id = :provider_id')->execute([
    ':display_name' => " Settings\nAware ",
    ':provider_id' => 'core.settings-aware',
]);
$legacyDisplayNamePayment = (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-legacy-display-name', 'core.settings-aware', 777, 'USD', 'settings-aware-legacy-display-name');
$legacyDisplayNameMetadata = json_decode((string) ($legacyDisplayNamePayment['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($legacyDisplayNamePayment['status'] ?? '') === 'paid'
    && ($legacyDisplayNameMetadata['observed_display_name'] ?? '') === 'Settings Aware'
    && !str_contains((string) ($legacyDisplayNamePayment['metadata_json'] ?? ''), "Settings\nAware"),
    'Core PaymentService falls back from corrupted Provider display names before Provider commands'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$pdo->prepare('UPDATE cms_payment_provider_settings SET display_name = :display_name WHERE provider_id = :provider_id')->execute([
    ':display_name' => 'payment_token%3Draw-provider-command-label-token',
    ':provider_id' => 'core.settings-aware',
]);
$legacyTokenDisplayNamePayment = (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-legacy-token-display-name', 'core.settings-aware', 777, 'USD', 'settings-aware-legacy-token-display-name');
$legacyTokenDisplayNameMetadata = json_decode((string) ($legacyTokenDisplayNamePayment['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($legacyTokenDisplayNamePayment['status'] ?? '') === 'paid'
    && ($legacyTokenDisplayNameMetadata['observed_display_name'] ?? '') === 'Settings Aware'
    && !str_contains((string) ($legacyTokenDisplayNamePayment['metadata_json'] ?? ''), 'payment_token%3Draw-provider-command-label-token')
    && !str_contains((string) ($legacyTokenDisplayNamePayment['metadata_json'] ?? ''), 'payment_token=raw-provider-command-label-token'),
    'Core PaymentService falls back from token-like legacy Provider display names before Provider commands'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeCorruptPublicConfig = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => '{"mode":',
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-corrupt-public', 'core.settings-aware', 777, 'USD', 'settings-aware-corrupt-public'),
    'Core PaymentService fails closed when Provider public config JSON is invalid'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeCorruptPublicConfig,
    'Core PaymentService does not create payment records when Provider public config is invalid'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeNonCanonicalPublicJson = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => ' {"mode":"sandbox"} ',
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-non-canonical-public-json', 'core.settings-aware', 777, 'USD', 'settings-aware-non-canonical-public-json'),
    'Core PaymentService fails closed when Provider public config JSON is non-canonical'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonCanonicalPublicJson,
    'Core PaymentService does not create payment records when Provider public config JSON is non-canonical'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeSecretLikePublicField = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'api_key' => 'raw-public-config-key'], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-secret-like-public-field', 'core.settings-aware', 777, 'USD', 'settings-aware-secret-like-public-field'),
    'Core PaymentService fails closed when Provider public config contains key-like secret fields'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeSecretLikePublicField,
    'Core PaymentService does not create payment records when Provider public config contains key-like secret fields'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeTokenLikePublicValue = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'operator_note' => 'Bearer raw-public-config-token'], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-token-like-public-value', 'core.settings-aware', 777, 'USD', 'settings-aware-token-like-public-value'),
    'Core PaymentService fails closed when Provider public config contains token-like values'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeTokenLikePublicValue,
    'Core PaymentService does not create payment records when Provider public config contains token-like values'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeNonCanonicalPublicUrl = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'callback_url' => ' https://provider.example.test/callback '], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-non-canonical-public-url', 'core.settings-aware', 777, 'USD', 'settings-aware-non-canonical-public-url'),
    'Core PaymentService fails closed when Provider public config URL values are non-canonical'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonCanonicalPublicUrl,
    'Core PaymentService does not create payment records when Provider public config URL values are non-canonical'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeMalformedPublicUrl = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'callback_url' => 'not a url'], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-malformed-public-url', 'core.settings-aware', 777, 'USD', 'settings-aware-malformed-public-url'),
    'Core PaymentService fails closed when Provider public config URL values are malformed'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeMalformedPublicUrl,
    'Core PaymentService does not create payment records when Provider public config URL values are malformed'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforePublicUrlUserinfo = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'callback_url' => 'https://operator:secret@provider.example.test/callback'], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-public-url-userinfo', 'core.settings-aware', 777, 'USD', 'settings-aware-public-url-userinfo'),
    'Core PaymentService fails closed when Provider public config URL values contain userinfo'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforePublicUrlUserinfo,
    'Core PaymentService does not create payment records when Provider public config URL values contain userinfo'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforePublicUrlFragment = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'callback_url' => 'https://provider.example.test/callback#return'], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-public-url-fragment', 'core.settings-aware', 777, 'USD', 'settings-aware-public-url-fragment'),
    'Core PaymentService fails closed when Provider public config URL values contain fragments'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforePublicUrlFragment,
    'Core PaymentService does not create payment records when Provider public config URL values contain fragments'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeNonCanonicalPublicValue = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => ' sandbox '], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-non-canonical-public-value', 'core.settings-aware', 777, 'USD', 'settings-aware-non-canonical-public-value'),
    'Core PaymentService fails closed when Provider public config string values are non-canonical'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonCanonicalPublicValue,
    'Core PaymentService does not create payment records when Provider public config string values are non-canonical'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeNonStringPublicUrl = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => json_encode(['mode' => 'sandbox', 'callback_url' => true], JSON_UNESCAPED_SLASHES),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-non-string-public-url', 'core.settings-aware', 777, 'USD', 'settings-aware-non-string-public-url'),
    'Core PaymentService fails closed when Provider public config URL values are non-string'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonStringPublicUrl,
    'Core PaymentService does not create payment records when Provider public config URL values are non-string'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeCorruptSecretConfig = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET secret_config_ciphertext = :secret_config_ciphertext WHERE provider_id = :provider_id')->execute([
    ':secret_config_ciphertext' => 'not-a-valid-runtime-secret-ciphertext',
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-corrupt-secret', 'core.settings-aware', 777, 'USD', 'settings-aware-corrupt-secret'),
    'Core PaymentService fails closed when Provider secret config cannot be decrypted'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeCorruptSecretConfig,
    'Core PaymentService does not create payment records when Provider secret config is unreadable'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeUnsafeSecretPayload = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET secret_config_ciphertext = :secret_config_ciphertext WHERE provider_id = :provider_id')->execute([
    ':secret_config_ciphertext' => core_payment_test_secret_ciphertext('core-payment-settings-key', ['api_secret' => "sk\nunsafe"]),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-unsafe-secret-payload', 'core.settings-aware', 777, 'USD', 'settings-aware-unsafe-secret-payload'),
    'Core PaymentService fails closed when decrypted Provider secret payload contains unsafe values'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeUnsafeSecretPayload,
    'Core PaymentService does not create payment records when decrypted Provider secret payload is unsafe'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$paymentCountBeforeNonCanonicalSecretPayload = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET secret_config_ciphertext = :secret_config_ciphertext WHERE provider_id = :provider_id')->execute([
    ':secret_config_ciphertext' => core_payment_test_secret_ciphertext('core-payment-settings-key', ['api_secret' => ' sk_core_settings_secret ']),
    ':provider_id' => 'core.settings-aware',
]);
core_payment_throws(
    static fn () => (new PaymentService($pdo, $repo, 'core-payment-settings-key'))->createProviderPayment('paid_download', 'settings-aware-noncanonical-secret-payload', 'core.settings-aware', 777, 'USD', 'settings-aware-noncanonical-secret-payload'),
    'Core PaymentService fails closed when decrypted Provider secret payload contains non-canonical values'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonCanonicalSecretPayload,
    'Core PaymentService does not create payment records when decrypted Provider secret payload is non-canonical'
);
$providerSettings->save('core.settings-aware', 'Settings Aware', 'enabled', ['mode' => 'sandbox'], ['api_secret' => 'sk_core_settings_secret']);
$keyedPaymentService = new PaymentService($pdo, $repo, 'core-payment-settings-key');
$publicLifecyclePayment = $keyedPaymentService->createProviderPayment('paid_download', 'fixture-public-lifecycle', FixturePaymentProvider::PROVIDER_ID, 1500, 'USD', 'fixture-public-lifecycle-payment');
$refundCountBeforeCorruptPublicConfig = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => '{"mode":',
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
]);
core_payment_throws(
    static fn () => $keyedPaymentService->refundProviderPayment((int) ($publicLifecyclePayment['id'] ?? 0), 100, 'corrupt public lifecycle refund', 'fixture-public-lifecycle-refund'),
    'Core PaymentService fails closed for Provider lifecycle actions when public config JSON is invalid'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $refundCountBeforeCorruptPublicConfig
    && (string) ($repo->payment((int) ($publicLifecyclePayment['id'] ?? 0))['status'] ?? '') === 'paid',
    'Core PaymentService does not create refund records when Provider public config is invalid'
);
$providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Fixture Config', 'enabled', ['mode' => 'test', 'publishable_key' => 'pk_test'], ['api_secret' => 'sk_test_123456']);
$secretLifecyclePayment = $keyedPaymentService->createProviderPayment('paid_download', 'fixture-secret-lifecycle', FixturePaymentProvider::PROVIDER_ID, 1500, 'USD', 'fixture-secret-lifecycle-payment');
$refundCountBeforeCorruptSecretConfig = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
$pdo->prepare('UPDATE cms_payment_provider_settings SET secret_config_ciphertext = :secret_config_ciphertext WHERE provider_id = :provider_id')->execute([
    ':secret_config_ciphertext' => 'not-a-valid-lifecycle-secret-ciphertext',
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
]);
core_payment_throws(
    static fn () => $keyedPaymentService->refundProviderPayment((int) ($secretLifecyclePayment['id'] ?? 0), 100, 'corrupt secret lifecycle refund', 'fixture-secret-lifecycle-refund'),
    'Core PaymentService fails closed for Provider lifecycle actions when secret config cannot be decrypted'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $refundCountBeforeCorruptSecretConfig
    && (string) ($repo->payment((int) ($secretLifecyclePayment['id'] ?? 0))['status'] ?? '') === 'paid',
    'Core PaymentService does not create refund records when Provider secret config is unreadable'
);
$providerSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Fixture Config', 'enabled', ['mode' => 'test', 'publishable_key' => 'pk_test'], ['api_secret' => 'sk_test_123456']);

$repeat = $service->createProviderPayment('paid_download', 'asset-42', FixturePaymentProvider::PROVIDER_ID, 1299, 'USD', 'pay-asset-42', 'success');
core_payment_check((int) $repeat['id'] === (int) $payment['id'], 'Core payment create is idempotent for identical request');
core_payment_check(
    (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.created' AND context_json LIKE '%pay-asset-42%'")->fetchColumn() === 1,
    'Core payment provider create writes one CMS audit event for idempotent payment creation'
);
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'asset-42', FixturePaymentProvider::PROVIDER_ID, 1300, 'USD', 'pay-asset-42', 'success'), 'Core payment rejects idempotency key reuse with changed content');

PaymentProviderRegistry::register('core.counting-provider', new CountingPaymentProvider());
$providerSettings->save('core.counting-provider', 'Counting Provider', 'enabled', ['mode' => 'test'], []);
$countingPaymentCountBeforeInvalidServiceInput = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
CountingPaymentProvider::$createCalls = 0;
CountingPaymentProvider::$refundCalls = 0;
core_payment_throws(static fn () => $service->createProviderPayment(' Paid_Download ', 'counting-invalid-subject-type', 'core.counting-provider', 100, 'USD', 'counting-invalid-subject-type'), 'Core payment service rejects non-canonical subject types before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', ' counting-invalid-subject ', 'core.counting-provider', 100, 'USD', 'counting-non-canonical-subject'), 'Core payment service rejects non-canonical subject ids before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', "bad\nsubject", 'core.counting-provider', 100, 'USD', 'counting-invalid-subject'), 'Core payment service rejects control-character subjects before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'payment_token%3Draw-service-subject-token', 'core.counting-provider', 100, 'USD', 'counting-token-like-subject'), 'Core payment service rejects URL-encoded token-like subject ids before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'counting-invalid-key', 'core.counting-provider', 100, 'USD', "counting\ninvalid-key"), 'Core payment service rejects control-character idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'counting-non-canonical-key', 'core.counting-provider', 100, 'USD', ' counting-non-canonical-key '), 'Core payment service rejects non-canonical idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'counting-token-like-key', 'core.counting-provider', 100, 'USD', 'counting-payment_token%3Draw-create-key'), 'Core payment service rejects URL-encoded token-like idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'counting-non-canonical-scenario', 'core.counting-provider', 100, 'USD', 'counting-non-canonical-scenario', ' success '), 'Core payment service rejects non-canonical Provider scenarios before Provider calls');
core_payment_check(
    CountingPaymentProvider::$createCalls === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $countingPaymentCountBeforeInvalidServiceInput,
    'Core payment service keeps Provider and payment ledger untouched after invalid create inputs'
);
$countingPayment = $service->createProviderPayment('paid_download', 'counting-valid', 'core.counting-provider', 500, 'USD', 'counting-valid-payment');
CountingPaymentProvider::$createCalls = 0;
$countingPaymentAgain = $service->createProviderPayment('paid_download', 'counting-valid', 'core.counting-provider', 500, 'USD', 'counting-valid-payment');
core_payment_check(
    (int) ($countingPaymentAgain['id'] ?? 0) === (int) ($countingPayment['id'] ?? 0)
    && CountingPaymentProvider::$createCalls === 0,
    'Core payment create resolves idempotent retries inside the transaction before calling Providers again'
);
$countingRefundCountBeforeInvalidServiceInput = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
CountingPaymentProvider::$refundCalls = 0;
core_payment_throws(static fn () => $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, 'bad' . chr(0) . 'reason', 'counting-invalid-refund-reason'), 'Core payment service rejects unsafe refund reasons before Provider calls');
core_payment_throws(static fn () => $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, ' valid reason ', 'counting-non-canonical-refund-reason'), 'Core payment service rejects non-canonical refund reasons before Provider calls');
core_payment_throws(static fn () => $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, 'valid reason', "counting\ninvalid-refund-key"), 'Core payment service rejects unsafe refund idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, 'valid reason', ' counting-non-canonical-refund-key '), 'Core payment service rejects non-canonical refund idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, 'valid reason', 'counting-payment_token%3Draw-refund-key'), 'Core payment service rejects URL-encoded token-like refund idempotency keys before Provider calls');
core_payment_check(
    CountingPaymentProvider::$refundCalls === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $countingRefundCountBeforeInvalidServiceInput
    && (string) (($repo->payment((int) ($countingPayment['id'] ?? 0))['status'] ?? '')) === 'paid',
    'Core payment service keeps Provider, refund ledger and parent payment untouched after invalid refund inputs'
);
$corruptCaptureLedgerPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'counting-corrupt-capture-amount',
    'provider_id' => 'core.counting-provider',
    'remote_id' => 'counting-corrupt-capture-amount-remote',
    'reference' => 'counting-corrupt-capture-amount-reference',
    'status' => 'authorized',
    'amount_minor' => 1200,
    'currency' => 'USD',
    'idempotency_key' => 'counting-corrupt-capture-amount-key',
    'request_hash' => hash('sha256', 'counting-corrupt-capture-amount'),
]));
$pdo->prepare('UPDATE cms_payments SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '1200abc',
    ':id' => $corruptCaptureLedgerPaymentId,
]);
CountingPaymentProvider::$captureCalls = 0;
core_payment_throws(
    static fn () => $service->captureProviderPayment($corruptCaptureLedgerPaymentId, 'counting-corrupt-capture-amount-action'),
    'Core PaymentService rejects corrupted ledger amounts before Provider capture'
);
core_payment_check(
    CountingPaymentProvider::$captureCalls === 0
    && (string) (($repo->payment($corruptCaptureLedgerPaymentId)['status'] ?? '')) === 'authorized',
    'Core PaymentService does not call Providers after corrupted capture ledger amounts'
);
$corruptCancelLedgerPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'counting-corrupt-cancel-currency',
    'provider_id' => 'core.counting-provider',
    'remote_id' => 'counting-corrupt-cancel-currency-remote',
    'reference' => 'counting-corrupt-cancel-currency-reference',
    'status' => 'authorized',
    'amount_minor' => 1300,
    'currency' => 'USD',
    'idempotency_key' => 'counting-corrupt-cancel-currency-key',
    'request_hash' => hash('sha256', 'counting-corrupt-cancel-currency'),
]));
$pdo->prepare('UPDATE cms_payments SET currency = :currency WHERE id = :id')->execute([
    ':currency' => 'usd',
    ':id' => $corruptCancelLedgerPaymentId,
]);
CountingPaymentProvider::$cancelCalls = 0;
core_payment_throws(
    static fn () => $service->cancelProviderPayment($corruptCancelLedgerPaymentId, 'counting-corrupt-cancel-currency-action'),
    'Core PaymentService rejects corrupted ledger currencies before Provider cancel'
);
core_payment_check(
    CountingPaymentProvider::$cancelCalls === 0
    && (string) (($repo->payment($corruptCancelLedgerPaymentId)['status'] ?? '')) === 'authorized',
    'Core PaymentService does not call Providers after corrupted cancel ledger currencies'
);
$corruptCaptureRemotePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'counting-corrupt-capture-remote',
    'provider_id' => 'core.counting-provider',
    'remote_id' => 'counting-corrupt-capture-remote',
    'reference' => 'counting-corrupt-capture-remote-reference',
    'status' => 'authorized',
    'amount_minor' => 1350,
    'currency' => 'USD',
    'idempotency_key' => 'counting-corrupt-capture-remote-key',
    'request_hash' => hash('sha256', 'counting-corrupt-capture-remote'),
]));
$pdo->prepare('UPDATE cms_payments SET remote_id = :remote_id WHERE id = :id')->execute([
    ':remote_id' => ' counting-corrupt-capture-remote ',
    ':id' => $corruptCaptureRemotePaymentId,
]);
CountingPaymentProvider::$captureCalls = 0;
core_payment_throws(
    static fn () => $service->captureProviderPayment($corruptCaptureRemotePaymentId, 'counting-corrupt-capture-remote-action'),
    'Core PaymentService rejects corrupted ledger remote references before Provider capture'
);
core_payment_check(
    CountingPaymentProvider::$captureCalls === 0
    && (string) (($repo->payment($corruptCaptureRemotePaymentId)['status'] ?? '')) === 'authorized',
    'Core PaymentService does not call Providers after corrupted capture ledger remote references'
);
$corruptRefundLedgerPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'counting-corrupt-refund-amount',
    'provider_id' => 'core.counting-provider',
    'remote_id' => 'counting-corrupt-refund-amount-remote',
    'reference' => 'counting-corrupt-refund-amount-reference',
    'status' => 'paid',
    'amount_minor' => 1400,
    'currency' => 'USD',
    'idempotency_key' => 'counting-corrupt-refund-amount-key',
    'request_hash' => hash('sha256', 'counting-corrupt-refund-amount'),
]));
$pdo->prepare('UPDATE cms_payments SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '1400abc',
    ':id' => $corruptRefundLedgerPaymentId,
]);
$corruptRefundCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
CountingPaymentProvider::$refundCalls = 0;
core_payment_throws(
    static fn () => $service->refundProviderPayment($corruptRefundLedgerPaymentId, 100, 'corrupt refund amount', 'counting-corrupt-refund-amount-action'),
    'Core PaymentService rejects corrupted ledger amounts before Provider refunds'
);
core_payment_check(
    CountingPaymentProvider::$refundCalls === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $corruptRefundCountBefore
    && (string) (($repo->payment($corruptRefundLedgerPaymentId)['status'] ?? '')) === 'paid',
    'Core PaymentService does not call Providers or write refunds after corrupted refund ledger amounts'
);
$corruptRefundSubjectPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'counting-corrupt-refund-subject',
    'provider_id' => 'core.counting-provider',
    'remote_id' => 'counting-corrupt-refund-subject-remote',
    'reference' => 'counting-corrupt-refund-subject-reference',
    'status' => 'paid',
    'amount_minor' => 1450,
    'currency' => 'USD',
    'idempotency_key' => 'counting-corrupt-refund-subject-key',
    'request_hash' => hash('sha256', 'counting-corrupt-refund-subject'),
]));
$pdo->prepare('UPDATE cms_payments SET subject_id = :subject_id WHERE id = :id')->execute([
    ':subject_id' => ' counting-corrupt-refund-subject ',
    ':id' => $corruptRefundSubjectPaymentId,
]);
$corruptRefundSubjectCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
CountingPaymentProvider::$refundCalls = 0;
core_payment_throws(
    static fn () => $service->refundProviderPayment($corruptRefundSubjectPaymentId, 100, 'corrupt refund subject', 'counting-corrupt-refund-subject-action'),
    'Core PaymentService rejects corrupted ledger subjects before Provider refunds'
);
core_payment_check(
    CountingPaymentProvider::$refundCalls === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $corruptRefundSubjectCountBefore
    && (string) (($repo->payment($corruptRefundSubjectPaymentId)['status'] ?? '')) === 'paid',
    'Core PaymentService does not call Providers or write refunds after corrupted refund ledger subjects'
);

$status = $service->trustedStatus('paid_download', 'asset-42');
core_payment_check((int) $status['paid_minor'] === 1299 && (int) $status['net_paid_minor'] === 1299 && (string) $status['status'] === 'paid', 'Core exposes trusted payment status by subject');

$refund = $service->refundProviderPayment((int) $payment['id'], 299, 'partial refund', 'refund-asset-42');
core_payment_check((string) $refund['status'] === 'completed' && (int) $refund['amount_minor'] === 299, 'Core PaymentService records provider refund');
core_payment_check(
    (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.refunded' AND context_json LIKE '%refund-asset-42%'")->fetchColumn() === 1,
    'Core payment provider refund writes a CMS audit event'
);
$status = $service->trustedStatus('paid_download', 'asset-42');
core_payment_check((int) $status['refunded_minor'] === 299 && (int) $status['net_paid_minor'] === 1000, 'Core trusted payment status subtracts completed refunds');
core_payment_check((string) ($repo->payment((int) $payment['id'])['status'] ?? '') === 'partially_refunded', 'Core refund updates payment status to partially refunded');
CountingPaymentProvider::$refundCalls = 0;
$countingIdempotentRefund = $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, 'idempotent refund', 'counting-idempotent-refund');
$countingIdempotentRefundAgain = $service->refundProviderPayment((int) ($countingPayment['id'] ?? 0), 100, 'idempotent refund', 'counting-idempotent-refund');
core_payment_check(
    (int) ($countingIdempotentRefundAgain['id'] ?? 0) === (int) ($countingIdempotentRefund['id'] ?? 0)
    && CountingPaymentProvider::$refundCalls === 1,
    'Core payment refunds resolve idempotent retries inside the transaction before calling Providers again'
);
$usdMixedPayment = $service->createProviderPayment('paid_download', 'mixed-currency-asset', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'mixed-currency-usd');
$cnyMixedPayment = $service->createProviderPayment('paid_download', 'mixed-currency-asset', FixturePaymentProvider::PROVIDER_ID, 1000, 'CNY', 'mixed-currency-cny');
$service->refundProviderPayment((int) $usdMixedPayment['id'], 1000, 'full usd refund', 'mixed-currency-usd-refund');
$mixedAllStatus = $service->trustedStatus('paid_download', 'mixed-currency-asset');
$mixedUsdStatus = $service->trustedStatus('paid_download', 'mixed-currency-asset', 'USD');
$mixedCnyStatus = $service->trustedStatus('paid_download', 'mixed-currency-asset', 'CNY');
core_payment_check(
    (int) ($cnyMixedPayment['id'] ?? 0) > 0
    && (string) ($mixedAllStatus['status'] ?? '') === 'paid'
    && (string) ($mixedUsdStatus['currency'] ?? '') === 'USD'
    && (string) ($mixedUsdStatus['status'] ?? '') === 'unpaid'
    && (int) ($mixedUsdStatus['net_paid_minor'] ?? -1) === 0
    && (string) ($mixedCnyStatus['currency'] ?? '') === 'CNY'
    && (string) ($mixedCnyStatus['status'] ?? '') === 'paid'
    && (int) ($mixedCnyStatus['net_paid_minor'] ?? 0) === 1000,
    'Core trusted payment status can be scoped by currency for mixed-currency subjects'
);
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'non-canonical-currency-service', FixturePaymentProvider::PROVIDER_ID, 1000, 'usd', 'non-canonical-currency-service'), 'Core payment service rejects non-canonical currencies before Provider calls');
core_payment_throws(static fn () => $service->trustedStatus('paid_download', 'mixed-currency-asset', ' usd '), 'Core trusted payment status rejects non-canonical currency filters before querying');
$corruptTrustedAmountPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'corrupt-trusted-status-amount',
    'remote_id' => 'corrupt-trusted-status-amount-payment',
    'reference' => 'corrupt-trusted-status-amount-reference',
    'idempotency_key' => 'corrupt-trusted-status-amount-payment-key',
    'request_hash' => hash('sha256', 'corrupt-trusted-status-amount-payment'),
]));
$pdo->prepare('UPDATE cms_payments SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '999abc',
    ':id' => $corruptTrustedAmountPaymentId,
]);
core_payment_throws(
    static fn () => $service->trustedStatus('paid_download', 'corrupt-trusted-status-amount'),
    'Core trusted payment status rejects corrupted payment amounts instead of casting them into paid state'
);
$corruptTrustedAmountSummary = $repo->paymentSummary(['q' => 'corrupt-trusted-status-amount-reference']);
core_payment_check(
    ($corruptTrustedAmountSummary[0]['amount_minor'] ?? null) === 'invalid'
    && ($corruptTrustedAmountSummary[0]['refunded_minor'] ?? null) === 'invalid'
    && ($corruptTrustedAmountSummary[0]['net_paid_minor'] ?? null) === 'invalid',
    'Core payment summary marks corrupted payment amounts invalid instead of casting them into reconciliation totals'
);
$corruptTrustedRefundPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_id' => 'corrupt-trusted-status-refund',
    'remote_id' => 'corrupt-trusted-status-refund-payment',
    'reference' => 'corrupt-trusted-status-refund-reference',
    'idempotency_key' => 'corrupt-trusted-status-refund-payment-key',
    'request_hash' => hash('sha256', 'corrupt-trusted-status-refund-payment'),
]));
$corruptTrustedRefundId = $repo->insertRefund(array_replace($validRefundInsert, [
    'payment_id' => $corruptTrustedRefundPaymentId,
    'remote_id' => 'corrupt-trusted-status-refund-row',
    'amount_minor' => 100,
    'idempotency_key' => 'corrupt-trusted-status-refund-key',
    'request_hash' => hash('sha256', 'corrupt-trusted-status-refund'),
]));
$pdo->prepare('UPDATE cms_payment_refunds SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '100abc',
    ':id' => $corruptTrustedRefundId,
]);
core_payment_throws(
    static fn () => $repo->refundedMinorForPayment($corruptTrustedRefundPaymentId),
    'Core refund totals reject corrupted refund amounts instead of casting them into refund evidence'
);
core_payment_throws(
    static fn () => $service->trustedStatus('paid_download', 'corrupt-trusted-status-refund'),
    'Core trusted payment status rejects corrupted refund amounts instead of casting them into net paid state'
);
core_payment_throws(static fn () => $service->refundProviderPayment((int) $payment['id'], 2000, 'too much', 'refund-too-much'), 'Core refund rejects amount above captured payment');

PaymentProviderRegistry::register('core.empty-refund-reference', new EmptyRefundReferencePaymentProvider());
$providerSettings->save('core.empty-refund-reference', 'Empty Refund Reference', 'enabled', ['mode' => 'test'], []);
$emptyReferencePayment = $service->createProviderPayment('paid_download', 'empty-refund-reference', 'core.empty-refund-reference', 1200, 'USD', 'empty-refund-reference-payment');
$emptyReferenceRefund = $service->refundProviderPayment((int) ($emptyReferencePayment['id'] ?? 0), 200, 'empty reference refund', 'empty-refund-reference-refund');
$emptyReferenceRepeat = $service->refundProviderPayment((int) ($emptyReferencePayment['id'] ?? 0), 200, 'empty reference refund', 'empty-refund-reference-refund');
core_payment_check(
    (int) ($emptyReferenceRefund['id'] ?? 0) === (int) ($emptyReferenceRepeat['id'] ?? 0)
    && str_starts_with((string) ($emptyReferenceRefund['remote_id'] ?? ''), 'core-refund-'),
    'Core refund synthesizes a stable remote reference when Provider omits one'
);

$authorizedPayment = $service->createProviderPayment('paid_download', 'asset-capture', FixturePaymentProvider::PROVIDER_ID, 2500, 'USD', 'pay-capture', 'authorized');
core_payment_check((string) $authorizedPayment['status'] === 'authorized', 'Core PaymentService records authorized provider payments');
core_payment_throws(static fn () => $service->captureProviderPayment((int) $authorizedPayment['id'], "capture\nbad"), 'Core PaymentService rejects unsafe capture idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->captureProviderPayment((int) $authorizedPayment['id'], ' capture-non-canonical '), 'Core PaymentService rejects non-canonical capture idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->captureProviderPayment((int) $authorizedPayment['id'], 'capture-payment_token%3Draw-capture-key'), 'Core PaymentService rejects URL-encoded token-like capture idempotency keys before Provider calls');
core_payment_check((string) ($repo->payment((int) $authorizedPayment['id'])['status'] ?? '') === 'authorized', 'Core PaymentService keeps payment authorized after invalid capture idempotency input');
$capturedPayment = $service->captureProviderPayment((int) $authorizedPayment['id'], 'capture-asset-capture');
core_payment_check((string) $capturedPayment['status'] === 'paid' && (string) $capturedPayment['paid_at'] !== '', 'Core PaymentService captures authorized provider payments');
core_payment_check(
    (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.captured' AND context_json LIKE '%capture-asset-capture%'")->fetchColumn() === 1,
    'Core payment provider capture writes a CMS audit event'
);
core_payment_throws(static fn () => $service->cancelProviderPayment((int) $capturedPayment['id'], 'cancel-captured'), 'Core PaymentService rejects cancelling captured payments');

$cancelPayment = $service->createProviderPayment('paid_download', 'asset-cancel', FixturePaymentProvider::PROVIDER_ID, 900, 'USD', 'pay-cancel', 'authorized');
core_payment_throws(static fn () => $service->cancelProviderPayment((int) $cancelPayment['id'], "cancel\nbad"), 'Core PaymentService rejects unsafe cancel idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->cancelProviderPayment((int) $cancelPayment['id'], ' cancel-non-canonical '), 'Core PaymentService rejects non-canonical cancel idempotency keys before Provider calls');
core_payment_throws(static fn () => $service->cancelProviderPayment((int) $cancelPayment['id'], 'cancel-payment_token%3Draw-cancel-key'), 'Core PaymentService rejects URL-encoded token-like cancel idempotency keys before Provider calls');
core_payment_check((string) ($repo->payment((int) $cancelPayment['id'])['status'] ?? '') === 'authorized', 'Core PaymentService keeps payment authorized after invalid cancel idempotency input');
$cancelledPayment = $service->cancelProviderPayment((int) $cancelPayment['id'], 'cancel-asset-cancel');
core_payment_check((string) $cancelledPayment['status'] === 'cancelled' && (string) $cancelledPayment['cancelled_at'] !== '', 'Core PaymentService cancels authorized provider payments');
core_payment_check(
    (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.cancelled' AND context_json LIKE '%cancel-asset-cancel%'")->fetchColumn() === 1,
    'Core payment provider cancel writes a CMS audit event'
);
core_payment_throws(static fn () => $service->captureProviderPayment((int) $cancelledPayment['id'], 'capture-cancelled'), 'Core PaymentService rejects capturing cancelled payments');

$syncPayment = $service->createProviderPayment('paid_download', 'asset-sync', FixturePaymentProvider::PROVIDER_ID, 1100, 'USD', 'pay-sync', 'authorized');
$syncAuditCountBeforeInvalidExpectedStatus = (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.status_synced'")->fetchColumn();
core_payment_throws(static fn () => $service->syncProviderPaymentStatus((int) $syncPayment['id'], ' paid '), 'Core PaymentService rejects non-canonical expected statuses before Provider status sync');
core_payment_check(
    (string) ($repo->payment((int) $syncPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.status_synced'")->fetchColumn() === $syncAuditCountBeforeInvalidExpectedStatus,
    'Core PaymentService keeps payment unchanged after invalid expected status sync input'
);
$corruptCurrentStatusPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'corrupt-current-status-sync',
    'provider_id' => 'core.status-only',
    'remote_id' => 'corrupt-current-status-sync-remote',
    'reference' => 'corrupt-current-status-sync-reference',
    'status' => 'pending',
    'amount_minor' => 1100,
    'currency' => 'USD',
    'idempotency_key' => 'corrupt-current-status-sync-key',
    'request_hash' => hash('sha256', 'corrupt-current-status-sync'),
]));
$pdo->prepare('UPDATE cms_payments SET status = :status WHERE id = :id')->execute([
    ':status' => ' Pending ',
    ':id' => $corruptCurrentStatusPaymentId,
]);
StatusOnlyPaymentProvider::$statusCalls = 0;
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus($corruptCurrentStatusPaymentId),
    'Core PaymentService rejects corrupted current statuses before Provider status sync'
);
core_payment_check(
    StatusOnlyPaymentProvider::$statusCalls === 0,
    'Core PaymentService does not call Providers after corrupted current status sync input'
);
$corruptProviderStatusPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'paid_download',
    'subject_id' => 'corrupt-provider-status-sync',
    'provider_id' => 'core.status-only',
    'remote_id' => 'corrupt-provider-status-sync-remote',
    'reference' => 'corrupt-provider-status-sync-reference',
    'status' => 'pending',
    'amount_minor' => 1100,
    'currency' => 'USD',
    'idempotency_key' => 'corrupt-provider-status-sync-key',
    'request_hash' => hash('sha256', 'corrupt-provider-status-sync'),
]));
$pdo->prepare('UPDATE cms_payments SET provider_id = :provider_id WHERE id = :id')->execute([
    ':provider_id' => ' core.status-only ',
    ':id' => $corruptProviderStatusPaymentId,
]);
StatusOnlyPaymentProvider::$statusCalls = 0;
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus($corruptProviderStatusPaymentId),
    'Core PaymentService rejects corrupted ledger Provider ids before Provider status sync'
);
core_payment_check(
    StatusOnlyPaymentProvider::$statusCalls === 0
    && (string) (($repo->payment($corruptProviderStatusPaymentId)['status'] ?? '')) === 'pending',
    'Core PaymentService does not call Providers after corrupted ledger Provider ids'
);
$syncedPayment = $service->syncProviderPaymentStatus((int) $syncPayment['id'], 'paid');
core_payment_check((string) $syncedPayment['status'] === 'paid' && (string) $syncedPayment['paid_at'] !== '', 'Core PaymentService syncs trusted provider payment status');
core_payment_check(
    (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.status_synced' AND context_json LIKE '%asset-sync%'")->fetchColumn() === 1,
    'Core payment provider status sync writes a CMS audit event'
);
core_payment_throws(static fn () => $service->syncProviderPaymentStatus((int) $syncedPayment['id'], 'failed'), 'Core PaymentService rejects stale Provider status sync that would downgrade a paid payment');
core_payment_check((string) ($repo->payment((int) $syncedPayment['id'])['status'] ?? '') === 'paid', 'Core PaymentService preserves paid status after rejected stale Provider sync');

$atomicCapturePayment = $service->createProviderPayment('paid_download', 'atomic-capture', FixturePaymentProvider::PROVIDER_ID, 1200, 'USD', 'atomic-capture-create', 'authorized');
$atomicCaptureAuditCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.captured' AND context_json LIKE '%atomic-capture-key%'")->fetchColumn();
$pdo->exec("CREATE TEMP TRIGGER core_payment_fail_capture_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.provider.captured' BEGIN SELECT RAISE(ABORT, 'capture audit failure'); END");
core_payment_throws(
    static fn () => $service->captureProviderPayment((int) ($atomicCapturePayment['id'] ?? 0), 'atomic-capture-key'),
    'Core PaymentService rolls back capture state when Core audit persistence fails'
);
$pdo->exec('DROP TRIGGER core_payment_fail_capture_audit');
core_payment_check(
    (string) ($repo->payment((int) ($atomicCapturePayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.captured' AND context_json LIKE '%atomic-capture-key%'")->fetchColumn() === $atomicCaptureAuditCountBefore,
    'Core PaymentService keeps capture state and audit atomic'
);

$atomicCancelPayment = $service->createProviderPayment('paid_download', 'atomic-cancel', FixturePaymentProvider::PROVIDER_ID, 1200, 'USD', 'atomic-cancel-create', 'authorized');
$atomicCancelAuditCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.cancelled' AND context_json LIKE '%atomic-cancel-key%'")->fetchColumn();
$pdo->exec("CREATE TEMP TRIGGER core_payment_fail_cancel_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.provider.cancelled' BEGIN SELECT RAISE(ABORT, 'cancel audit failure'); END");
core_payment_throws(
    static fn () => $service->cancelProviderPayment((int) ($atomicCancelPayment['id'] ?? 0), 'atomic-cancel-key'),
    'Core PaymentService rolls back cancel state when Core audit persistence fails'
);
$pdo->exec('DROP TRIGGER core_payment_fail_cancel_audit');
core_payment_check(
    (string) ($repo->payment((int) ($atomicCancelPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.cancelled' AND context_json LIKE '%atomic-cancel-key%'")->fetchColumn() === $atomicCancelAuditCountBefore,
    'Core PaymentService keeps cancel state and audit atomic'
);

$atomicSyncPayment = $service->createProviderPayment('paid_download', 'atomic-status-sync', FixturePaymentProvider::PROVIDER_ID, 1200, 'USD', 'atomic-status-sync-create', 'authorized');
$atomicSyncAuditCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.status_synced' AND context_json LIKE '%atomic-status-sync%'")->fetchColumn();
$pdo->exec("CREATE TEMP TRIGGER core_payment_fail_status_sync_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.provider.status_synced' BEGIN SELECT RAISE(ABORT, 'status sync audit failure'); END");
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus((int) ($atomicSyncPayment['id'] ?? 0), 'paid'),
    'Core PaymentService rolls back status sync state when Core audit persistence fails'
);
$pdo->exec('DROP TRIGGER core_payment_fail_status_sync_audit');
core_payment_check(
    (string) ($repo->payment((int) ($atomicSyncPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.status_synced' AND context_json LIKE '%atomic-status-sync%'")->fetchColumn() === $atomicSyncAuditCountBefore,
    'Core PaymentService keeps status sync state and audit atomic'
);

PaymentProviderRegistry::register('core.fixed-remote', new FixedRemotePaymentProvider());
$providerSettings->save('core.fixed-remote', 'Fixed Remote', 'enabled', ['mode' => 'test'], []);
$service->createProviderPayment('invoice', 'fixed-1', 'core.fixed-remote', 500, 'USD', 'fixed-1', 'success');
core_payment_throws(static fn () => $service->createProviderPayment('invoice', 'fixed-2', 'core.fixed-remote', 500, 'USD', 'fixed-2', 'success'), 'Core payment rejects duplicate provider remote reference');

PaymentProviderRegistry::register('core.invalid-remote-reference', new InvalidRemoteReferencePaymentProvider());
$providerSettings->save('core.invalid-remote-reference', 'Invalid Remote Reference', 'enabled', ['mode' => 'test'], []);
$paymentCountBeforeInvalidRemote = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'invalid-remote-create', 'core.invalid-remote-reference', 500, 'USD', 'invalid-create-remote-reference', 'success'),
    'Core payment rejects Provider payment references with control characters before writing payment rows'
);
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'invalid-remote-create-spaced', 'core.invalid-remote-reference', 500, 'USD', 'invalid-create-spaced-remote-reference', 'success'),
    'Core payment rejects non-canonical Provider payment references before writing payment rows'
);
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'token-like-remote-create', 'core.invalid-remote-reference', 500, 'USD', 'token-like-create-remote-reference', 'success'),
    'Core payment rejects URL-encoded token-like Provider payment references before writing payment rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeInvalidRemote,
    'Core payment keeps ledger unchanged after invalid Provider payment reference'
);
$invalidRefundReferencePayment = $service->createProviderPayment('invoice', 'invalid-remote-refund', 'core.invalid-remote-reference', 500, 'USD', 'valid-create-invalid-refund', 'success');
$refundCountBeforeInvalidRemote = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($invalidRefundReferencePayment['id'] ?? 0), 100, 'invalid remote refund reference', 'invalid-refund-remote-reference'),
    'Core payment rejects Provider refund references with control characters before writing refund rows'
);
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($invalidRefundReferencePayment['id'] ?? 0), 100, 'invalid spaced remote refund reference', 'invalid-refund-spaced-remote-reference'),
    'Core payment rejects non-canonical Provider refund references before writing refund rows'
);
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($invalidRefundReferencePayment['id'] ?? 0), 100, 'token-like remote refund reference', 'token-like-refund-remote-reference'),
    'Core payment rejects URL-encoded token-like Provider refund references before writing refund rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $refundCountBeforeInvalidRemote
    && (string) ($repo->payment((int) ($invalidRefundReferencePayment['id'] ?? 0))['status'] ?? '') === 'paid',
    'Core payment keeps refund ledger and parent payment unchanged after invalid Provider refund reference'
);

PaymentProviderRegistry::register('core.invalid-result-envelope', new InvalidResultEnvelopePaymentProvider());
$providerSettings->save('core.invalid-result-envelope', 'Invalid Result Envelope', 'enabled', ['mode' => 'test'], []);
$paymentCountBeforeInvalidEnvelope = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
InvalidResultEnvelopePaymentProvider::$createCode = 'Invalid Envelope Paid';
InvalidResultEnvelopePaymentProvider::$createMessage = 'Invalid envelope payment paid.';
InvalidResultEnvelopePaymentProvider::$createRequestId = '';
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'invalid-envelope-create-code', 'core.invalid-result-envelope', 500, 'USD', 'invalid-envelope-create-code', 'success'),
    'Core payment rejects non-canonical Provider result codes before writing payment rows'
);
InvalidResultEnvelopePaymentProvider::$createCode = 'payment_token%3Draw-provider-code';
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'invalid-envelope-token-code', 'core.invalid-result-envelope', 500, 'USD', 'invalid-envelope-token-code', 'success'),
    'Core payment rejects token-like Provider result codes before writing payment rows'
);
InvalidResultEnvelopePaymentProvider::$createCode = 'invalid_envelope_paid';
InvalidResultEnvelopePaymentProvider::$createMessage = "Invalid envelope\npayment paid.";
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'invalid-envelope-message', 'core.invalid-result-envelope', 500, 'USD', 'invalid-envelope-message', 'success'),
    'Core payment rejects unsafe Provider result messages before writing payment rows'
);
InvalidResultEnvelopePaymentProvider::$createMessage = 'Invalid envelope payment paid.';
InvalidResultEnvelopePaymentProvider::$createRequestId = ' payment-request ';
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'invalid-envelope-request', 'core.invalid-result-envelope', 500, 'USD', 'invalid-envelope-request', 'success'),
    'Core payment rejects non-canonical Provider request references before writing payment rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeInvalidEnvelope,
    'Core payment keeps ledger unchanged after invalid Provider result envelopes'
);
InvalidResultEnvelopePaymentProvider::$createRequestId = '';
$invalidEnvelopePayment = $service->createProviderPayment('invoice', 'invalid-envelope-valid-payment', 'core.invalid-result-envelope', 600, 'USD', 'invalid-envelope-valid-payment', 'success');
$refundCountBeforeInvalidEnvelope = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
InvalidResultEnvelopePaymentProvider::$refundCode = 'Invalid Envelope Refund';
InvalidResultEnvelopePaymentProvider::$refundMessage = 'Invalid envelope refund completed.';
InvalidResultEnvelopePaymentProvider::$refundRequestId = '';
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($invalidEnvelopePayment['id'] ?? 0), 100, 'invalid refund envelope', 'invalid-envelope-refund-code'),
    'Core payment rejects non-canonical Provider refund result codes before writing refund rows'
);
InvalidResultEnvelopePaymentProvider::$refundCode = 'invalid_envelope_refunded';
InvalidResultEnvelopePaymentProvider::$refundMessage = 'secret=raw-provider-message';
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($invalidEnvelopePayment['id'] ?? 0), 100, 'invalid refund message', 'invalid-envelope-refund-message'),
    'Core payment rejects secret-like Provider refund result messages before writing refund rows'
);
InvalidResultEnvelopePaymentProvider::$refundMessage = 'Invalid envelope refund completed.';
InvalidResultEnvelopePaymentProvider::$refundRequestId = 'payment_token%3Draw-provider-request';
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($invalidEnvelopePayment['id'] ?? 0), 100, 'invalid refund request', 'invalid-envelope-refund-request'),
    'Core payment rejects token-like Provider refund request references before writing refund rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $refundCountBeforeInvalidEnvelope
    && (string) ($repo->payment((int) ($invalidEnvelopePayment['id'] ?? 0))['status'] ?? '') === 'paid',
    'Core payment keeps refund ledger and parent payment unchanged after invalid Provider refund envelopes'
);
$invalidEnvelopeCapturePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'invoice',
    'subject_id' => 'invalid-envelope-capture',
    'provider_id' => 'core.invalid-result-envelope',
    'remote_id' => 'invalid-envelope-capture-remote',
    'reference' => 'invalid-envelope-capture-reference',
    'status' => 'authorized',
    'amount_minor' => 700,
    'currency' => 'USD',
    'idempotency_key' => 'invalid-envelope-capture-key',
    'request_hash' => hash('sha256', 'invalid-envelope-capture'),
]));
InvalidResultEnvelopePaymentProvider::$captureCode = 'Invalid Envelope Capture';
InvalidResultEnvelopePaymentProvider::$captureMessage = 'Invalid envelope payment captured.';
InvalidResultEnvelopePaymentProvider::$captureRequestId = '';
core_payment_throws(
    static fn () => $service->captureProviderPayment($invalidEnvelopeCapturePaymentId, 'invalid-envelope-capture-code'),
    'Core payment rejects non-canonical Provider capture result codes before mutating payment rows'
);
InvalidResultEnvelopePaymentProvider::$captureCode = 'invalid_envelope_captured';
InvalidResultEnvelopePaymentProvider::$statusCode = 'invalid_envelope_status';
InvalidResultEnvelopePaymentProvider::$statusMessage = 'secret=raw-status-message';
InvalidResultEnvelopePaymentProvider::$statusRequestId = '';
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus($invalidEnvelopeCapturePaymentId, 'paid'),
    'Core payment rejects secret-like Provider status result messages before mutating payment rows'
);
core_payment_check(
    (string) ($repo->payment($invalidEnvelopeCapturePaymentId)['status'] ?? '') === 'authorized',
    'Core payment keeps lifecycle rows unchanged after invalid Provider result envelopes'
);

PaymentProviderRegistry::register('core.non-scalar-result', new NonScalarResultPaymentProvider());
$providerSettings->save('core.non-scalar-result', 'Non Scalar Result', 'enabled', ['mode' => 'test'], []);
$paymentCountBeforeNonScalarProviderResults = (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
NonScalarResultPaymentProvider::$createData = ['status' => ['paid'], 'provider_payment_id' => 'non-scalar-create-status'];
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'non-scalar-create-status', 'core.non-scalar-result', 500, 'USD', 'non-scalar-create-status', 'success'),
    'Core payment rejects non-scalar Provider create statuses before writing payment rows'
);
NonScalarResultPaymentProvider::$createData = ['status' => true, 'provider_payment_id' => 'non-string-create-status'];
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'non-string-create-status', 'core.non-scalar-result', 500, 'USD', 'non-string-create-status', 'success'),
    'Core payment rejects boolean Provider create statuses before writing payment rows'
);
NonScalarResultPaymentProvider::$createData = ['status' => 'paid', 'provider_payment_id' => ['non-scalar-create-remote']];
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'non-scalar-create-remote', 'core.non-scalar-result', 500, 'USD', 'non-scalar-create-remote', 'success'),
    'Core payment rejects non-scalar Provider payment references before writing payment rows'
);
NonScalarResultPaymentProvider::$createData = ['status' => 'paid', 'provider_payment_id' => 12345];
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'non-string-create-remote', 'core.non-scalar-result', 500, 'USD', 'non-string-create-remote', 'success'),
    'Core payment rejects integer Provider payment references before writing payment rows'
);
NonScalarResultPaymentProvider::$createData = ['status' => 'pending', 'provider_payment_id' => 'non-scalar-create-checkout-url', 'checkout_url' => ['https://payments.example.test/checkout']];
core_payment_throws(
    static fn () => $service->createProviderPayment('invoice', 'non-scalar-create-checkout-url', 'core.non-scalar-result', 500, 'USD', 'non-scalar-create-checkout-url', 'pending'),
    'Core payment rejects non-string Provider checkout URLs before writing payment rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNonScalarProviderResults,
    'Core payment keeps ledger unchanged after non-string Provider create result data'
);
NonScalarResultPaymentProvider::$createData = ['status' => 'paid', 'provider_payment_id' => 'non-scalar-result-valid-payment'];
$nonScalarResultPayment = $service->createProviderPayment('invoice', 'non-scalar-result-valid', 'core.non-scalar-result', 600, 'USD', 'non-scalar-result-valid', 'success');
$refundCountBeforeNonScalarProviderResults = (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn();
NonScalarResultPaymentProvider::$refundData = ['status' => ['completed'], 'provider_refund_id' => 'non-scalar-refund-status'];
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($nonScalarResultPayment['id'] ?? 0), 100, 'non scalar refund status', 'non-scalar-refund-status'),
    'Core payment rejects non-scalar Provider refund statuses before writing refund rows'
);
NonScalarResultPaymentProvider::$refundData = ['status' => false, 'provider_refund_id' => 'non-string-refund-status'];
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($nonScalarResultPayment['id'] ?? 0), 100, 'non string refund status', 'non-string-refund-status'),
    'Core payment rejects boolean Provider refund statuses before writing refund rows'
);
NonScalarResultPaymentProvider::$refundData = ['status' => 'completed', 'provider_refund_id' => ['non-scalar-refund-remote']];
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($nonScalarResultPayment['id'] ?? 0), 100, 'non scalar refund remote', 'non-scalar-refund-remote'),
    'Core payment rejects non-scalar Provider refund references before writing refund rows'
);
NonScalarResultPaymentProvider::$refundData = ['status' => 'completed', 'provider_refund_id' => 67890];
core_payment_throws(
    static fn () => $service->refundProviderPayment((int) ($nonScalarResultPayment['id'] ?? 0), 100, 'non string refund remote', 'non-string-refund-remote'),
    'Core payment rejects integer Provider refund references before writing refund rows'
);
core_payment_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() === $refundCountBeforeNonScalarProviderResults
    && (string) ($repo->payment((int) ($nonScalarResultPayment['id'] ?? 0))['status'] ?? '') === 'paid',
    'Core payment keeps refund ledger and parent payment unchanged after non-string Provider refund result data'
);
$nonScalarCapturePaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'invoice',
    'subject_id' => 'non-scalar-capture-result',
    'provider_id' => 'core.non-scalar-result',
    'remote_id' => 'non-scalar-capture-result-remote',
    'reference' => 'non-scalar-capture-result-reference',
    'status' => 'authorized',
    'amount_minor' => 700,
    'currency' => 'USD',
    'idempotency_key' => 'non-scalar-capture-result-key',
    'request_hash' => hash('sha256', 'non-scalar-capture-result'),
]));
NonScalarResultPaymentProvider::$captureData = ['status' => ['paid'], 'provider_payment_id' => 'non-scalar-capture-result-remote'];
core_payment_throws(
    static fn () => $service->captureProviderPayment($nonScalarCapturePaymentId, 'non-scalar-capture-result-action'),
    'Core payment rejects non-scalar Provider capture statuses before mutating payment rows'
);
NonScalarResultPaymentProvider::$captureData = ['status' => 'paid', 'provider_payment_id' => 13579];
core_payment_throws(
    static fn () => $service->captureProviderPayment($nonScalarCapturePaymentId, 'non-string-capture-result-action'),
    'Core payment rejects integer Provider capture references before mutating payment rows'
);
$nonScalarCancelPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'invoice',
    'subject_id' => 'non-scalar-cancel-result',
    'provider_id' => 'core.non-scalar-result',
    'remote_id' => 'non-scalar-cancel-result-remote',
    'reference' => 'non-scalar-cancel-result-reference',
    'status' => 'authorized',
    'amount_minor' => 800,
    'currency' => 'USD',
    'idempotency_key' => 'non-scalar-cancel-result-key',
    'request_hash' => hash('sha256', 'non-scalar-cancel-result'),
]));
NonScalarResultPaymentProvider::$cancelData = ['status' => 'cancelled', 'provider_payment_id' => ['non-scalar-cancel-result-remote']];
core_payment_throws(
    static fn () => $service->cancelProviderPayment($nonScalarCancelPaymentId, 'non-scalar-cancel-result-action'),
    'Core payment rejects non-scalar Provider cancel references before mutating payment rows'
);
NonScalarResultPaymentProvider::$cancelData = ['status' => 'cancelled', 'provider_payment_id' => 97531];
core_payment_throws(
    static fn () => $service->cancelProviderPayment($nonScalarCancelPaymentId, 'non-string-cancel-result-action'),
    'Core payment rejects integer Provider cancel references before mutating payment rows'
);
$nonScalarStatusPaymentId = $repo->insertPayment(array_replace($validPaymentInsert, [
    'subject_type' => 'invoice',
    'subject_id' => 'non-scalar-status-result',
    'provider_id' => 'core.non-scalar-result',
    'remote_id' => 'non-scalar-status-result-remote',
    'reference' => 'non-scalar-status-result-reference',
    'status' => 'authorized',
    'amount_minor' => 900,
    'currency' => 'USD',
    'idempotency_key' => 'non-scalar-status-result-key',
    'request_hash' => hash('sha256', 'non-scalar-status-result'),
]));
NonScalarResultPaymentProvider::$statusData = ['status' => ['paid'], 'provider_payment_id' => 'non-scalar-status-result-remote'];
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus($nonScalarStatusPaymentId, 'paid'),
    'Core payment rejects non-scalar Provider sync statuses before mutating payment rows'
);
NonScalarResultPaymentProvider::$statusData = ['status' => true, 'provider_payment_id' => 'non-string-status-result-remote'];
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus($nonScalarStatusPaymentId, 'paid'),
    'Core payment rejects boolean Provider sync statuses before mutating payment rows'
);
NonScalarResultPaymentProvider::$statusData = ['status' => 'paid', 'provider_payment_id' => 24680];
core_payment_throws(
    static fn () => $service->syncProviderPaymentStatus($nonScalarStatusPaymentId, 'paid'),
    'Core payment rejects integer Provider sync references before mutating payment rows'
);
core_payment_check(
    (string) ($repo->payment($nonScalarCapturePaymentId)['status'] ?? '') === 'authorized'
    && (string) ($repo->payment($nonScalarCancelPaymentId)['status'] ?? '') === 'authorized'
    && (string) ($repo->payment($nonScalarStatusPaymentId)['status'] ?? '') === 'authorized',
    'Core payment keeps lifecycle rows unchanged after non-string Provider lifecycle result data'
);

$receipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-1', '{"id":"evt-1"}');
$repeatReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-1', '{"id":"evt-1"}');
core_payment_check((int) $receipt['id'] === (int) $repeatReceipt['id'], 'Core payment webhook receipt is idempotent for duplicate event payload');
$overriddenPayloadSizeReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-overridden-payload-size', '{"id":"evt-service-overridden-payload-size"}', 'received', [
    'payload_size' => '999999',
    'content_type' => 'application/json',
    'webhook_timestamp' => (string) time(),
    'source_ip_hash' => str_repeat('a', 64),
]);
$overriddenPayloadSizeMeta = json_decode((string) ($overriddenPayloadSizeReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (int) ($overriddenPayloadSizeMeta['payload_size'] ?? 0) === strlen('{"id":"evt-service-overridden-payload-size"}')
    && (string) ($overriddenPayloadSizeMeta['content_type'] ?? '') === 'application/json'
    && (string) ($overriddenPayloadSizeMeta['source_ip_hash'] ?? '') === str_repeat('a', 64),
    'Core payment service computes webhook payload-size trace metadata instead of trusting caller-supplied values'
);
$processedReceipt = $service->updateWebhookReceiptStatus((int) $receipt['id'], 'processed');
core_payment_check((string) ($processedReceipt['status'] ?? '') === 'processed' && (string) ($processedReceipt['processed_at'] ?? '') !== '', 'Core payment webhook receipt can be marked processed');
$serviceNonCanonicalStatusReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-spaced-status', '{"id":"evt-service-spaced-status"}');
core_payment_throws(
    static fn () => $service->updateWebhookReceiptStatus((int) ($serviceNonCanonicalStatusReceipt['id'] ?? 0), ' Ignored '),
    'Core payment service rejects non-canonical webhook receipt status updates before writing rows'
);
core_payment_check(
    (string) ($repo->webhookReceiptById((int) ($serviceNonCanonicalStatusReceipt['id'] ?? 0))['status'] ?? '') === 'received',
    'Core payment service leaves webhook receipts unchanged after non-canonical status updates'
);
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-1', '{"id":"evt-1","changed":true}'), 'Core payment webhook receipt rejects event id reuse with changed payload');
core_payment_throws(static fn () => $repo->recordWebhookReceipt('Bad Provider', 'evt-invalid-provider', hash('sha256', 'invalid-provider'), 'received', []), 'Core payment webhook receipt rejects malformed Provider ids before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(' ' . FixturePaymentProvider::PROVIDER_ID . ' ', 'evt-spaced-provider', hash('sha256', 'spaced-provider'), 'received', []), 'Core payment webhook receipt rejects non-canonical Provider ids before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, ' evt-spaced-repository ', hash('sha256', 'spaced-event'), 'received', []), 'Core payment webhook receipt rejects non-canonical event ids before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-bad-hash', 'not-a-sha256-hash', 'received', []), 'Core payment webhook receipt rejects malformed payload hashes before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-uppercase-hash', strtoupper(hash('sha256', 'uppercase-payload-hash')), 'received', []), 'Core payment webhook receipt rejects non-canonical payload hashes before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-bad-status', hash('sha256', 'bad-status'), 'done', []), 'Core payment webhook receipt rejects unknown receipt statuses before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-direct-processed-status', hash('sha256', 'direct-processed-status'), 'processed', []), 'Core payment webhook receipt rejects processed receipt creation before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-direct-ignored-status', hash('sha256', 'direct-ignored-status'), 'ignored', []), 'Core payment webhook receipt rejects ignored receipt creation before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-direct-failed-status', hash('sha256', 'direct-failed-status'), 'failed', []), 'Core payment webhook receipt rejects failed receipt creation before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-spaced-status', hash('sha256', 'spaced-status'), ' Received ', []), 'Core payment webhook receipt rejects non-canonical receipt statuses before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-bad-payment-id', hash('sha256', 'bad-payment-id'), 'received', ['payment_id' => -1]), 'Core payment webhook receipt rejects invalid payment ids before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-token-content-type', hash('sha256', 'token-content-type'), 'received', ['content_type' => 'application/json; note=payment_token%3Draw-trace-token']), 'Core payment webhook receipt rejects token-like content-type trace metadata before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-spaced-trace-timestamp', hash('sha256', 'spaced-trace-timestamp'), 'received', ['webhook_timestamp' => ' 1234567890 ']), 'Core payment webhook receipt rejects non-canonical timestamp trace metadata before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-leading-zero-trace-timestamp', hash('sha256', 'leading-zero-trace-timestamp'), 'received', ['webhook_timestamp' => '000123456789']), 'Core payment webhook receipt rejects leading-zero timestamp trace metadata before writing rows');
core_payment_throws(static fn () => $repo->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-uppercase-source-hash', hash('sha256', 'uppercase-source-hash'), 'received', ['source_ip_hash' => strtoupper(str_repeat('a', 64))]), 'Core payment webhook receipt rejects non-canonical source trace metadata before writing rows');
$serviceWebhookReceiptCountBeforeNonCanonical = count($repo->webhookReceipts(FixturePaymentProvider::PROVIDER_ID, 20));
core_payment_throws(static fn () => $service->recordWebhookReceipt(' ' . FixturePaymentProvider::PROVIDER_ID . ' ', 'evt-service-spaced-provider', '{"id":"evt-service-spaced-provider"}'), 'Core payment service rejects non-canonical webhook Provider ids before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, ' evt-service-spaced ', '{"id":"evt-service-spaced"}'), 'Core payment service rejects non-canonical webhook event ids before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-direct-terminal', '{"id":"evt-service-direct-terminal"}', 'processed'), 'Core payment service rejects terminal webhook receipt creation before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-spaced-trace-content-type', '{"id":"evt-service-spaced-trace-content-type"}', 'received', ['content_type' => ' application/json ']), 'Core payment service rejects non-canonical webhook content-type trace metadata before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-token-trace-content-type', '{"id":"evt-service-token-trace-content-type"}', 'received', ['content_type' => 'application/json; note=payment_token%3Draw-service-trace-token']), 'Core payment service rejects token-like webhook content-type trace metadata before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-spaced-trace-timestamp', '{"id":"evt-service-spaced-trace-timestamp"}', 'received', ['webhook_timestamp' => ' 1234567890 ']), 'Core payment service rejects non-canonical webhook timestamp trace metadata before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-leading-zero-trace-timestamp', '{"id":"evt-service-leading-zero-trace-timestamp"}', 'received', ['webhook_timestamp' => '000123456789']), 'Core payment service rejects leading-zero webhook timestamp trace metadata before storing receipts');
core_payment_throws(static fn () => $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-uppercase-trace-source', '{"id":"evt-service-uppercase-trace-source"}', 'received', ['source_ip_hash' => strtoupper(str_repeat('a', 64))]), 'Core payment service rejects non-canonical webhook source trace metadata before storing receipts');
core_payment_check(
    count($repo->webhookReceipts(FixturePaymentProvider::PROVIDER_ID, 20)) === $serviceWebhookReceiptCountBeforeNonCanonical,
    'Core payment service leaves webhook receipts unchanged after invalid webhook receipt creation attempts'
);
$ignoredAuditFailurePdo = new PDO('sqlite::memory:');
(new MigrationRunner($ignoredAuditFailurePdo, $migrations))->run();
$ignoredAuditFailureSettings = new PaymentProviderSettingsRepository($ignoredAuditFailurePdo, 'core-payment-settings-key');
$ignoredAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Fixture Config', 'enabled', ['mode' => 'test'], ['api_secret' => 'sk_test_123456']);
$ignoredAuditFailureRepo = new PaymentRepository($ignoredAuditFailurePdo);
$ignoredAuditFailureService = new PaymentService($ignoredAuditFailurePdo, $ignoredAuditFailureRepo, 'core-payment-settings-key');
$ignoredAuditFailureReceipt = $ignoredAuditFailureService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-ignored-audit-failure', '{"id":"evt-ignored-audit-failure"}');
$ignoredAuditFailurePdo->exec('DROP TABLE cms_audit_logs');
core_payment_throws(
    static fn () => $ignoredAuditFailureService->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($ignoredAuditFailureReceipt['id'] ?? 0), '{"id":"evt-ignored-audit-failure"}'),
    'Core payment ignored webhook application fails when audit persistence is unavailable'
);
core_payment_check(
    (string) ($ignoredAuditFailureRepo->webhookReceiptById((int) ($ignoredAuditFailureReceipt['id'] ?? 0))['status'] ?? '') === 'received',
    'Core payment ignored webhook application rolls back receipt status when audit persistence fails'
);
$failedReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-failed-receipt', '{"id":"evt-failed-receipt"}');
$markedFailedReceipt = $service->markWebhookReceiptFailed((int) ($failedReceipt['id'] ?? 0), 'Provider payment target was not found.');
$markedFailedReceiptMeta = json_decode((string) ($markedFailedReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($markedFailedReceipt['status'] ?? '') === 'failed'
    && (string) ($markedFailedReceiptMeta['failure_error'] ?? '') === 'Provider payment target was not found.'
    && (string) ($markedFailedReceiptMeta['failed_at'] ?? '') !== '',
    'Core payment webhook receipt can be marked failed with safe diagnostic metadata'
);
core_payment_throws(static fn () => $service->createProviderPayment('paid_download', 'missing-provider', 'missing.provider', 100, 'USD', 'missing-provider-key'), 'Core payment fails closed when provider is unavailable');

$search = $repo->searchPayments(['subject_type' => 'paid_download', 'q' => 'asset-42']);
core_payment_check((int) $search['total'] === 1 && (string) $search['items'][0]['subject_id'] === 'asset-42', 'Core payment repository searches payments for admin UI');
core_payment_throws(static fn () => $repo->searchPayments(['page' => 0]), 'Core payment repository rejects invalid payment search pages');
core_payment_throws(static fn () => $repo->searchPayments(['page' => ' 1 ']), 'Core payment repository rejects non-canonical payment search pages');
core_payment_throws(static fn () => $repo->searchPayments(['per_page' => 0]), 'Core payment repository rejects invalid payment search page sizes');
core_payment_throws(static fn () => $repo->searchPayments(['per_page' => '25.5']), 'Core payment repository rejects non-integer payment search page sizes');
core_payment_throws(static fn () => $repo->searchPayments(['status' => 'settled']), 'Core payment repository rejects invalid payment search statuses');
core_payment_throws(static fn () => $repo->searchPayments(['provider_id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' ']), 'Core payment repository rejects non-canonical payment search Provider ids');
core_payment_throws(static fn () => $repo->searchPayments(['subject_type' => ' Paid_Download ']), 'Core payment repository rejects non-canonical payment search subject types');
core_payment_throws(static fn () => $repo->searchPayments(['q' => "asset\n42"]), 'Core payment repository rejects unsafe payment search queries');
core_payment_throws(static fn () => $repo->searchPayments(['q' => ' asset-42 ']), 'Core payment repository rejects non-canonical payment search queries');
core_payment_throws(static fn () => $repo->searchPayments(['q' => 'payment_token%3Draw-search-token']), 'Core payment repository rejects URL-encoded token-like payment search queries');
core_payment_throws(static fn () => $repo->searchPayments(['q' => ['asset-42']]), 'Core payment repository rejects non-string payment search filters');
core_payment_throws(static fn () => $repo->searchPayments(['status' => true]), 'Core payment repository rejects boolean payment search filters');
core_payment_throws(static fn () => $repo->searchPayments(['provider_id' => 123]), 'Core payment repository rejects integer payment search filters');
core_payment_throws(static fn () => $repo->exportPayments([], 0), 'Core payment repository rejects invalid payment export limits');
core_payment_throws(static fn () => $repo->exportPayments(['currency' => 'USDT']), 'Core payment repository rejects invalid payment export currencies');
core_payment_throws(static fn () => $repo->exportPayments(['currency' => ['USD']]), 'Core payment repository rejects non-string payment export filters');
core_payment_throws(static fn () => $repo->exportPayments(['currency' => false]), 'Core payment repository rejects boolean payment export filters');
core_payment_throws(static fn () => $repo->paymentSummary(['created_from' => 'not-a-date']), 'Core payment repository rejects invalid payment summary date filters');
core_payment_throws(static fn () => $repo->paymentSummary(['created_from' => ' ' . gmdate('Y-m-d') . ' ']), 'Core payment repository rejects non-canonical payment summary date filters');
core_payment_throws(static fn () => $repo->paymentSummary(['created_from' => 'yesterday']), 'Core payment repository rejects ambiguous natural-language payment summary date filters');
core_payment_throws(static fn () => $repo->paymentSummary(['created_from' => ['yesterday']]), 'Core payment repository rejects non-string payment summary filters');
core_payment_throws(static fn () => $repo->paymentSummary(['created_to' => 20990101]), 'Core payment repository rejects integer payment summary filters');
core_payment_throws(static fn () => $repo->searchPayments(['created_from' => '2099-02-31']), 'Core payment repository rejects impossible calendar payment search date filters');
core_payment_throws(static fn () => $repo->exportPayments(['created_to' => '2099-02-31T00:00:00+00:00']), 'Core payment repository rejects impossible calendar payment export timestamp filters');
core_payment_throws(static fn () => $repo->paymentSummary(['created_from' => '2099-02-31']), 'Core payment repository rejects impossible calendar payment summary date filters');
core_payment_check(count($repo->refundsForPayment((int) $payment['id'])) === 1, 'Core payment repository lists refunds for admin detail UI');
core_payment_check(count($repo->webhookReceipts(FixturePaymentProvider::PROVIDER_ID, 10)) >= 8, 'Core payment repository lists webhook receipts for admin detail UI');
$unsafeFailedReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-unsafe-failed-receipt', '{"id":"evt-unsafe-failed-receipt"}');
$unsafeMarkedFailedReceipt = $service->markWebhookReceiptFailed((int) ($unsafeFailedReceipt['id'] ?? 0), 'Provider failed with payment_token=raw-leak');
$unsafeMarkedFailedReceiptMeta = json_decode((string) ($unsafeMarkedFailedReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($unsafeMarkedFailedReceipt['status'] ?? '') === 'failed'
    && (string) ($unsafeMarkedFailedReceiptMeta['failure_error'] ?? '') === 'Payment webhook application failed.'
    && !str_contains((string) ($unsafeMarkedFailedReceipt['metadata_json'] ?? ''), 'payment_token=raw-leak'),
    'Core payment webhook failure diagnostics sanitize token-like errors before storing receipt metadata'
);
$secretLikeFailedReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-secret-like-failed-receipt', '{"id":"evt-secret-like-failed-receipt"}');
$secretLikeMarkedFailedReceipt = $service->markWebhookReceiptFailed((int) ($secretLikeFailedReceipt['id'] ?? 0), 'Provider failed with secret=raw-webhook-secret');
$secretLikeMarkedFailedReceiptMeta = json_decode((string) ($secretLikeMarkedFailedReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($secretLikeMarkedFailedReceipt['status'] ?? '') === 'failed'
    && (string) ($secretLikeMarkedFailedReceiptMeta['failure_error'] ?? '') === 'Payment webhook application failed.'
    && !str_contains((string) ($secretLikeMarkedFailedReceipt['metadata_json'] ?? ''), 'raw-webhook-secret'),
    'Core payment webhook failure diagnostics sanitize secret-like errors before storing receipt metadata'
);
$encodedTokenLikeFailedReceipt = $service->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-encoded-token-like-failed-receipt', '{"id":"evt-encoded-token-like-failed-receipt"}');
$encodedTokenLikeMarkedFailedReceipt = $service->markWebhookReceiptFailed((int) ($encodedTokenLikeFailedReceipt['id'] ?? 0), 'Provider failed with payment_token%3Draw-webhook-encoded-token');
$encodedTokenLikeMarkedFailedReceiptMeta = json_decode((string) ($encodedTokenLikeMarkedFailedReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($encodedTokenLikeMarkedFailedReceipt['status'] ?? '') === 'failed'
    && (string) ($encodedTokenLikeMarkedFailedReceiptMeta['failure_error'] ?? '') === 'Payment webhook application failed.'
    && !str_contains((string) ($encodedTokenLikeMarkedFailedReceipt['metadata_json'] ?? ''), 'payment_token%3Draw-webhook-encoded-token')
    && !str_contains((string) ($encodedTokenLikeMarkedFailedReceipt['metadata_json'] ?? ''), 'payment_token=raw-webhook-encoded-token'),
    'Core payment webhook failure diagnostics sanitize URL-encoded token-like errors before storing receipt metadata'
);
$serviceRedactedUrlReceipt = $service->recordWebhookReceipt(
    FixturePaymentProvider::PROVIDER_ID,
    'evt-service-redacted-url',
    '{"id":"evt-service-redacted-url"}',
    'received',
    ['receipt_url' => 'https://provider.example.test/receipt?api_key=raw-secret&safe=1']
);
$serviceRedactedUrlMetadata = json_decode((string) ($serviceRedactedUrlReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($serviceRedactedUrlMetadata['receipt_url'] ?? '') === 'https://provider.example.test/receipt?api_key=%5Bredacted%5D&safe=1',
    'Core payment service redacts sensitive webhook receipt URL query metadata before storage'
);

$tmpRoot = sys_get_temp_dir() . '/cms-core-payment-admin-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/storage/logs', 0775, true);
$dbFile = $tmpRoot . '/payment-admin.sqlite';
$adminPdo = new PDO('sqlite:' . $dbFile);
(new MigrationRunner($adminPdo, $migrations))->run();
$adminRepo = new PaymentRepository($adminPdo);
$adminProviderSettings = new PaymentProviderSettingsRepository($adminPdo, 'core-payment-admin-settings-key');
$adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Admin Fixture', 'enabled', ['mode' => 'admin-test'], ['api_secret' => 'sk_admin_bootstrap', 'webhook_secret' => 'whsec_admin_secret']);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect', 'enabled', [], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminProviderSettings->save(ManualPaymentProvider::PROVIDER_ID, 'Manual Bank Transfer', 'enabled', ['instructions' => 'Bank transfer reference is shown after checkout.'], []);
$adminProviderSettings->save('core.status-only', 'Status Only', 'enabled', ['default_provider' => true], ['webhook_secret' => 'whsec_status_only']);
$adminService = new PaymentService($adminPdo, $adminRepo);
$entitlements = new PaymentEntitlementService($adminPdo, $adminRepo);
$adminPayment = $adminService->createProviderPayment('paid_download', 'admin-asset', FixturePaymentProvider::PROVIDER_ID, 1200, 'USD', 'admin-payment-key');
$adminAuthorizedPayment = $adminService->createProviderPayment('paid_download', 'admin-authorized', FixturePaymentProvider::PROVIDER_ID, 1300, 'USD', 'admin-authorized-key', 'authorized');
$adminExportPayment = $adminService->createProviderPayment('paid_download', '=cmd|export', FixturePaymentProvider::PROVIDER_ID, 1400, 'USD', '+admin-export-key');
$adminService->refundProviderPayment((int) ($adminExportPayment['id'] ?? 0), 400, 'admin export reconciliation refund', 'admin-export-refund-key');
$adminCnyPayment = $adminService->createProviderPayment('paid_download', 'admin-cny-asset', FixturePaymentProvider::PROVIDER_ID, 500, 'CNY', 'admin-cny-payment-key');
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$csrf = CsrfToken::get();
$settings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]);
mkdir($tmpRoot . '/config', 0775, true);
file_put_contents($tmpRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
], true) . ";\n");
$admin = new AdminController($settings, new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$_SERVER['REQUEST_URI'] = '/admin/payments';
$indexResponse = $admin->paymentsIndex(new Request('GET', '/admin/payments', [], [], []));
core_payment_check(
    $indexResponse->status() === 200
    && str_contains($indexResponse->body(), '支付管理')
    && str_contains($indexResponse->body(), 'admin-asset')
    && str_contains($indexResponse->body(), 'Provider 设置')
    && str_contains($indexResponse->body(), '已完成退款')
    && str_contains($indexResponse->body(), 'USD 4.00')
    && str_contains($indexResponse->body(), 'USD 35.00'),
    'Core admin payment index renders CMS-owned payments with filtered reconciliation summary'
);
$currencyFilteredIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['currency' => 'USD'], [], []));
core_payment_check(
    $currencyFilteredIndex->status() === 200
    && str_contains($currencyFilteredIndex->body(), 'USD 35.00')
    && !str_contains($currencyFilteredIndex->body(), 'admin-cny-asset')
    && !str_contains($currencyFilteredIndex->body(), 'CNY 5.00'),
    'Core admin payment index filters reconciliation summary by currency'
);
$today = gmdate('Y-m-d');
$dateFilteredIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['currency' => 'USD', 'created_from' => $today], [], []));
core_payment_check(
    $dateFilteredIndex->status() === 200
    && str_contains($dateFilteredIndex->body(), 'USD 35.00')
    && !str_contains($dateFilteredIndex->body(), 'admin-cny-asset'),
    'Core admin payment index filters reconciliation summary by creation window'
);
$pastDateFilteredIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['created_to' => '1999-01-01'], [], []));
core_payment_check(
    $pastDateFilteredIndex->status() === 200
    && str_contains($pastDateFilteredIndex->body(), '当前筛选暂无汇总')
    && !str_contains($pastDateFilteredIndex->body(), 'admin-asset'),
    'Core admin payment index returns empty reconciliation summary outside creation window'
);
$invalidCurrencyIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['currency' => 'USDT'], [], []));
$nonCanonicalCurrencyIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['currency' => 'usd'], [], []));
$invalidPageIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['page' => ' 1 '], [], []));
$nonCanonicalQueryIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['q' => ' admin-asset '], [], []));
$tokenLikeQueryIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['q' => 'payment_token%3Draw-admin-search-token'], [], []));
$nonCanonicalDateIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['created_from' => ' ' . $today . ' '], [], []));
$ambiguousDateIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['created_from' => 'yesterday'], [], []));
$impossibleDateIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['created_from' => '2099-02-31'], [], []));
$impossibleTimestampIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['created_to' => '2099-02-31T00:00:00+00:00'], [], []));
$arrayQueryIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['q' => ['admin-asset']], [], []));
$arrayCurrencyIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['currency' => ['USD']], [], []));
$booleanCurrencyIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['currency' => true], [], []));
core_payment_check(
    $invalidCurrencyIndex->status() === 400
    && $nonCanonicalCurrencyIndex->status() === 400
    && $invalidPageIndex->status() === 400
    && $nonCanonicalQueryIndex->status() === 400
    && $tokenLikeQueryIndex->status() === 400
    && $nonCanonicalDateIndex->status() === 400
    && $ambiguousDateIndex->status() === 400
    && $impossibleDateIndex->status() === 400
    && $impossibleTimestampIndex->status() === 400
    && $arrayQueryIndex->status() === 400
    && $arrayCurrencyIndex->status() === 400
    && $booleanCurrencyIndex->status() === 400
    && str_contains($invalidCurrencyIndex->body(), '支付筛选条件无效')
    && str_contains($nonCanonicalCurrencyIndex->body(), '支付筛选条件无效')
    && str_contains($invalidPageIndex->body(), '支付筛选条件无效')
    && str_contains($nonCanonicalQueryIndex->body(), '支付筛选条件无效')
    && str_contains($tokenLikeQueryIndex->body(), '支付筛选条件无效')
    && str_contains($nonCanonicalDateIndex->body(), '支付筛选条件无效')
    && str_contains($ambiguousDateIndex->body(), '支付筛选条件无效')
    && str_contains($arrayQueryIndex->body(), '支付筛选条件无效')
    && str_contains($arrayCurrencyIndex->body(), '支付筛选条件无效')
    && str_contains($booleanCurrencyIndex->body(), '支付筛选条件无效'),
    'Core admin payment index rejects invalid and non-string filters without treating them as service failures'
);
$exportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => 'cmd|export', 'currency' => 'USD', 'created_from' => $today], [], []));
$exportHeaders = $exportResponse->headers();
$exportLines = preg_split('/\r\n|\n|\r/', trim($exportResponse->body())) ?: [];
$exportHeader = str_getcsv(ltrim((string) ($exportLines[0] ?? ''), "\xEF\xBB\xBF"), ',', '"', '\\');
$exportData = str_getcsv((string) ($exportLines[1] ?? ''), ',', '"', '\\');
core_payment_check(
    $exportResponse->status() === 200
    && str_contains((string) ($exportHeaders['Content-Type'] ?? ''), 'text/csv')
    && str_contains((string) ($exportHeaders['Content-Disposition'] ?? ''), 'cms-payments-')
    && in_array('Refunded Minor', $exportHeader, true)
    && in_array('Net Paid Minor', $exportHeader, true)
    && str_contains($exportResponse->body(), "'=cmd|export")
    && str_contains($exportResponse->body(), "'+admin-export-key")
    && (int) ($exportData[7] ?? 0) === 1400
    && (int) ($exportData[8] ?? 0) === 400
    && (int) ($exportData[9] ?? 0) === 1000
    && !str_contains($exportResponse->body(), 'metadata_json')
    && !str_contains($exportResponse->body(), 'api_secret'),
    'Core admin payment export emits filtered safe CSV without sensitive metadata'
);
$exportAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.export.csv' ORDER BY id DESC LIMIT 1")->fetchColumn();
$exportAuditContext = json_decode((string) $exportAudit, true) ?: [];
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.export.csv'")->fetchColumn() === 1
    && (int) ($exportAuditContext['row_count'] ?? 0) === 1
    && (string) ($exportAuditContext['format'] ?? '') === 'csv'
    && (string) ($exportAuditContext['filters']['q'] ?? '') === 'cmd|export'
    && (string) ($exportAuditContext['filters']['currency'] ?? '') === 'USD'
    && (string) ($exportAuditContext['filters']['created_from'] ?? '') === $today
    && !str_contains((string) $exportAudit, 'api_secret')
    && !str_contains((string) $exportAudit, 'metadata_json'),
    'Core admin payment export writes minimized audit context'
);
$adminPdo->prepare('UPDATE cms_payments SET currency = :currency WHERE id = :id')->execute([
    ':currency' => 'usd',
    ':id' => (int) $adminExportPayment['id'],
]);
$corruptCurrencyIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['q' => 'cmd|export'], [], []));
core_payment_check(
    $corruptCurrencyIndex->status() === 200
    && str_contains($corruptCurrencyIndex->body(), '支付金额无效')
    && str_contains($corruptCurrencyIndex->body(), 'usd')
    && !str_contains($corruptCurrencyIndex->body(), 'USD 14.00')
    && !str_contains($corruptCurrencyIndex->body(), 'USD 10.00'),
    'Core admin payment index does not normalize corrupted ledger currency into display amounts'
);
$corruptCurrencyExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => 'cmd|export'], [], []));
core_payment_check(
    $corruptCurrencyExportResponse->status() === 400
    && str_contains($corruptCurrencyExportResponse->body(), '支付导出数据无效')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.export.csv'")->fetchColumn() === 1,
    'Core admin payment export rejects corrupted non-canonical ledger rows without writing export audit records'
);
$adminPdo->prepare('UPDATE cms_payments SET currency = :currency WHERE id = :id')->execute([
    ':currency' => 'USD',
    ':id' => (int) $adminExportPayment['id'],
]);
$adminExportPaymentCreatedAt = (string) ($adminExportPayment['created_at'] ?? '');
$adminPdo->prepare('UPDATE cms_payments SET created_at = :created_at WHERE id = :id')->execute([
    ':created_at' => 'yesterday',
    ':id' => (int) $adminExportPayment['id'],
]);
$corruptCreatedAtIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['q' => 'cmd|export'], [], []));
core_payment_check(
    $corruptCreatedAtIndex->status() === 200
    && str_contains($corruptCreatedAtIndex->body(), '支付时间无效')
    && !str_contains($corruptCreatedAtIndex->body(), '<td>yesterday</td>'),
    'Core admin payment index does not render corrupted ledger timestamps as reconciliation times'
);
$adminPdo->prepare('UPDATE cms_payments SET created_at = :created_at WHERE id = :id')->execute([
    ':created_at' => $adminExportPaymentCreatedAt,
    ':id' => (int) $adminExportPayment['id'],
]);
$adminExportRefundId = (int) $adminPdo->query('SELECT id FROM cms_payment_refunds WHERE payment_id = ' . (int) $adminExportPayment['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
$adminExportPaymentStatusBefore = (string) $adminPdo->query('SELECT status FROM cms_payments WHERE id = ' . (int) $adminExportPayment['id'])->fetchColumn();
$adminExportRefundStatusBefore = (string) $adminPdo->query('SELECT status FROM cms_payment_refunds WHERE id = ' . $adminExportRefundId)->fetchColumn();
$adminPdo->prepare('UPDATE cms_payments SET status = :status WHERE id = :id')->execute([
    ':status' => 'settled',
    ':id' => (int) $adminExportPayment['id'],
]);
$adminPdo->prepare('UPDATE cms_payment_refunds SET status = :status WHERE id = :id')->execute([
    ':status' => 'chargebacked',
    ':id' => $adminExportRefundId,
]);
$corruptStatusIndex = $admin->paymentsIndex(new Request('GET', '/admin/payments', ['q' => 'cmd|export'], [], []));
$corruptStatusDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) $adminExportPayment['id'], [], [], []));
core_payment_check(
    $corruptStatusIndex->status() === 200
    && $corruptStatusDetail->status() === 200
    && str_contains($corruptStatusIndex->body(), '支付状态无效')
    && str_contains($corruptStatusDetail->body(), '支付状态无效')
    && str_contains($corruptStatusDetail->body(), '退款状态无效')
    && !str_contains($corruptStatusIndex->body(), '>settled<')
    && !str_contains($corruptStatusDetail->body(), '>settled<')
    && !str_contains($corruptStatusDetail->body(), '>chargebacked<'),
    'Core admin payment list and detail mark corrupted payment/refund statuses invalid instead of rendering them as payment evidence'
);
$adminPdo->prepare('UPDATE cms_payments SET status = :status WHERE id = :id')->execute([
    ':status' => $adminExportPaymentStatusBefore,
    ':id' => (int) $adminExportPayment['id'],
]);
$adminPdo->prepare('UPDATE cms_payment_refunds SET status = :status WHERE id = :id')->execute([
    ':status' => $adminExportRefundStatusBefore,
    ':id' => $adminExportRefundId,
]);
$adminPdo->prepare('UPDATE cms_payment_refunds SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '400abc',
    ':id' => $adminExportRefundId,
]);
$corruptRefundExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => 'cmd|export'], [], []));
core_payment_check(
    $corruptRefundExportResponse->status() === 400
    && str_contains($corruptRefundExportResponse->body(), '支付导出数据无效')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.export.csv'")->fetchColumn() === 1,
    'Core admin payment export rejects corrupted refund totals before CSV aggregation or export audit records'
);
$adminPdo->prepare('UPDATE cms_payment_refunds SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => 400,
    ':id' => $adminExportRefundId,
]);
$adminPdo->prepare('UPDATE cms_payments SET remote_id = :remote_id WHERE id = :id')->execute([
    ':remote_id' => 'payment_token%3Draw-export-row-remote-token',
    ':id' => (int) $adminExportPayment['id'],
]);
$tokenLikeRemoteExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => 'cmd|export'], [], []));
core_payment_check(
    $tokenLikeRemoteExportResponse->status() === 400
    && str_contains($tokenLikeRemoteExportResponse->body(), '支付导出数据无效')
    && !str_contains($tokenLikeRemoteExportResponse->body(), 'payment_token%3Draw-export-row-remote-token')
    && !str_contains($tokenLikeRemoteExportResponse->body(), 'payment_token=raw-export-row-remote-token')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.export.csv'")->fetchColumn() === 1,
    'Core admin payment export rejects token-like legacy row references before CSV generation or export audit records'
);
$adminPdo->prepare('UPDATE cms_payments SET remote_id = :remote_id WHERE id = :id')->execute([
    ':remote_id' => (string) ($adminExportPayment['remote_id'] ?? ''),
    ':id' => (int) $adminExportPayment['id'],
]);
$invalidExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['currency' => 'USDT'], [], []));
$nonCanonicalQueryExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => ' cmd|export '], [], []));
$tokenLikeQueryExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => 'payment_token%3Draw-export-search-token'], [], []));
$arrayQueryExportResponse = $admin->paymentsExport(new Request('GET', '/admin/payments/export.csv', ['q' => ['cmd|export']], [], []));
core_payment_check(
    $invalidExportResponse->status() === 400
    && $nonCanonicalQueryExportResponse->status() === 400
    && $tokenLikeQueryExportResponse->status() === 400
    && $arrayQueryExportResponse->status() === 400
    && str_contains($invalidExportResponse->body(), '支付导出筛选条件无效')
    && str_contains($nonCanonicalQueryExportResponse->body(), '支付导出筛选条件无效')
    && str_contains($tokenLikeQueryExportResponse->body(), '支付导出筛选条件无效')
    && str_contains($arrayQueryExportResponse->body(), '支付导出筛选条件无效')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.export.csv'")->fetchColumn() === 1,
    'Core admin payment export rejects invalid and non-scalar filters without writing export audit records'
);
if (class_exists(ZipArchive::class)) {
    $missingPaymentSchemaPdo = new PDO('sqlite::memory:');
    $missingPaymentSchemaPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $missingPaymentSchemaPdo, '1.2.0-core-payment-test'))->build('missing-payment-schema'),
        'Core official export package fails closed when Core payment ledger tables are unavailable'
    );
    $adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataPaymentId,
        ':metadata' => json_encode([
            'customer_email' => 'export-legacy@example.test',
            'checkout_url' => 'https://payments.example.test/checkout?claim=raw-export-claim&safe=1#payment_token=raw-export-fragment-token',
            'status_url' => 'https://payments.example.test/status/sk_live_export_path_token',
            'encoded_status_url' => 'https://payments.example.test/status/payment_token%3Draw-export-path-token',
            'receipt_url' => 'https://payments.example.test/receipt?safe=sk_live_export_query_token',
            'array_query_url' => 'https://payments.example.test/status?safe[]=raw-export-array-value',
            'api_key' => 'raw-export-payment-api-key',
            'access_key' => 'raw-export-payment-access-key',
            'auth_context' => 'raw-export-payment-auth-context',
            'operator_note' => 'legacy note with Bearer raw-export-note-token',
            'encoded_note' => 'legacy note with payment_token%3Draw-export-encoded-note-token',
            'safe_note' => 'export legacy payment metadata',
            'bad key' => 'unsafe export metadata key',
            'unsafe_control' => "bad\0export metadata value",
            'malformed_url' => ' not a url ',
            'nested' => ['drop' => true],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $adminPdo->prepare('UPDATE cms_payment_entitlements SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataEntitlementId,
        ':metadata' => json_encode([
            'payment_token' => 'raw-export-entitlement-token',
            'access_key' => 'raw-export-entitlement-access-key',
            'auth_context' => 'raw-export-entitlement-auth-context',
            'encoded_note' => 'payment_token%3Draw-export-entitlement-encoded-token',
            'safe_note' => 'export legacy entitlement metadata',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
        ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
        ':public_config_json' => json_encode([
            'mode' => 'export-safe',
            'default_provider' => false,
            'publishable_key' => 'pk_export_safe',
            'api_key' => 'raw-export-provider-api-key',
            'callback_url' => 'https://payments.example.test/callback?auth=raw-export-provider-auth',
            'status_url' => 'https://payments.example.test/status/payment_token%3Draw-export-provider-path-token',
            'fragment_url' => 'https://payments.example.test/return#payment_token=raw-export-provider-fragment-token',
            'return_url_base' => true,
            'nested' => ['drop' => true],
            'encoded_note' => 'payment_token%3Draw-export-provider-encoded-token',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $packagePath = (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('core-payment-foundation');
    $reader = new ExportPackageReader();
    $manifest = $reader->manifest($packagePath);
    $ledgerSummary = $reader->paymentLedgerSummary($packagePath);
    $ledger = $reader->paymentLedger($packagePath);
    $ledgerJson = json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $exportedRepositoryPayment = null;
    foreach ($ledger['payments'] ?? [] as $paymentRow) {
        if (is_array($paymentRow) && (int) ($paymentRow['id'] ?? 0) === $repositoryMetadataPaymentId) {
            $exportedRepositoryPayment = $paymentRow;
            break;
        }
    }
    $exportedRepositoryPaymentMetadata = is_array($exportedRepositoryPayment)
        ? (json_decode((string) ($exportedRepositoryPayment['metadata_json'] ?? '{}'), true) ?: [])
        : [];
    core_payment_check(
        isset($manifest['checksums']['payments/payment-ledger.json'])
        && (string) ($ledgerSummary['type'] ?? '') === 'core_payment_ledger'
        && (string) ($ledgerSummary['schema_version'] ?? '') === '1.0.0'
        && (string) ($ledger['schema_version'] ?? '') === '1.0.0'
        && trim((string) ($ledger['exported_at'] ?? '')) !== ''
        && (string) ($ledgerSummary['exported_at'] ?? '') === (string) ($ledger['exported_at'] ?? '')
        && count($ledger['payments'] ?? []) >= 4
        && (int) ($ledger['counts']['payments'] ?? -1) === count($ledger['payments'] ?? [])
        && (int) ($ledgerSummary['counts']['payments'] ?? -1) === count($ledger['payments'] ?? [])
        && (int) ($ledger['counts']['refunds'] ?? -1) === count($ledger['refunds'] ?? [])
        && count($ledger['refunds'] ?? []) >= 1
        && count($ledger['provider_settings'] ?? []) >= 1
        && is_string($ledgerJson),
        'Core official export package includes Core payment ledger structure'
    );
    $metadataBeforeInvalidExport = (string) $adminPdo->query('SELECT metadata_json FROM cms_payments WHERE id = ' . (int) $repositoryMetadataPaymentId)->fetchColumn();
    $adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataPaymentId,
        ':metadata' => '123',
    ]);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('invalid-payment-metadata-json'),
        'Core official export package fails closed for non-object payment metadata JSON before writing recovery packages'
    );
    $adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataPaymentId,
        ':metadata' => '{"safe_note":',
    ]);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('malformed-payment-metadata-json'),
        'Core official export package fails closed for malformed payment metadata JSON before writing recovery packages'
    );
    $adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataPaymentId,
        ':metadata' => '["bad-list"]',
    ]);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('list-payment-metadata-json'),
        'Core official export package fails closed for list-shaped payment metadata JSON before writing recovery packages'
    );
    $adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataPaymentId,
        ':metadata' => $metadataBeforeInvalidExport,
    ]);
    $publicConfigBeforeInvalidExportStmt = $adminPdo->prepare('SELECT public_config_json FROM cms_payment_provider_settings WHERE provider_id = :provider_id');
    $publicConfigBeforeInvalidExportStmt->execute([':provider_id' => FixturePaymentProvider::PROVIDER_ID]);
    $publicConfigBeforeInvalidExport = (string) $publicConfigBeforeInvalidExportStmt->fetchColumn();
    $publicConfigBeforeInvalidExportStmt = null;
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config WHERE provider_id = :provider_id')->execute([
        ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
        ':public_config' => '123',
    ]);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('invalid-provider-public-config-json'),
        'Core official export package fails closed for non-object Provider public config JSON before writing recovery packages'
    );
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config WHERE provider_id = :provider_id')->execute([
        ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
        ':public_config' => '{"mode":',
    ]);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('malformed-provider-public-config-json'),
        'Core official export package fails closed for malformed Provider public config JSON before writing recovery packages'
    );
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config WHERE provider_id = :provider_id')->execute([
        ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
        ':public_config' => '["bad-list"]',
    ]);
    core_payment_throws(
        static fn () => (new ExportPackageBuilder($tmpRoot, $adminPdo, '1.2.0-core-payment-test'))->build('list-provider-public-config-json'),
        'Core official export package fails closed for list-shaped Provider public config JSON before writing recovery packages'
    );
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config WHERE provider_id = :provider_id')->execute([
        ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
        ':public_config' => $publicConfigBeforeInvalidExport,
    ]);
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'sk_admin_bootstrap')
        && !str_contains($ledgerJson, 'whsec_admin_secret')
        && !str_contains($ledgerJson, 'payment_token='),
        'Core official export package includes Core payment ledger without raw payment secrets or bearer tokens'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'export-legacy@example.test'),
        'Core official export package removes unsafe legacy payment email metadata from ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'raw-export-claim'),
        'Core official export package removes unsafe legacy payment URL claim metadata from ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'raw-export-fragment-token')
        && !str_contains($ledgerJson, '#payment_token='),
        'Core official export package removes unsafe legacy payment URL fragment metadata from ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'sk_live_export_path_token')
        && !str_contains($ledgerJson, 'sk_live_export_query_token')
        && !str_contains($ledgerJson, 'raw-export-note-token')
        && !str_contains($ledgerJson, 'payment_token%3Draw-export-path-token')
        && !str_contains($ledgerJson, 'payment_token=raw-export-path-token')
        && !str_contains($ledgerJson, 'payment_token%3Draw-export-encoded-note-token')
        && !str_contains($ledgerJson, 'payment_token=raw-export-encoded-note-token')
        && !str_contains($ledgerJson, 'raw-export-array-value'),
        'Core official export package removes token-like legacy payment URL and note metadata from ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'raw-export-entitlement-token')
        && !str_contains($ledgerJson, 'raw-export-entitlement-access-key')
        && !str_contains($ledgerJson, 'raw-export-entitlement-auth-context')
        && !str_contains($ledgerJson, 'payment_token%3Draw-export-entitlement-encoded-token')
        && !str_contains($ledgerJson, 'payment_token=raw-export-entitlement-encoded-token'),
        'Core official export package removes unsafe legacy entitlement token metadata from ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !str_contains($ledgerJson, 'raw-export-payment-api-key')
        && !str_contains($ledgerJson, 'raw-export-payment-access-key')
        && !str_contains($ledgerJson, 'raw-export-payment-auth-context'),
        'Core official export package removes unsafe legacy payment key-like metadata from ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && !array_key_exists('bad key', $exportedRepositoryPaymentMetadata)
        && !array_key_exists('unsafe_control', $exportedRepositoryPaymentMetadata)
        && ($exportedRepositoryPaymentMetadata['malformed_url'] ?? '') === '[redacted]'
        && !str_contains($ledgerJson, 'unsafe export metadata key')
        && !str_contains($ledgerJson, 'bad\\u0000export metadata value')
        && !str_contains($ledgerJson, ' not a url '),
        'Core official export package canonicalizes unsafe legacy payment metadata before restore'
    );
    $exportedFixtureProvider = null;
    foreach ($ledger['provider_settings'] ?? [] as $providerSettingRow) {
        if (is_array($providerSettingRow) && (string) ($providerSettingRow['provider_id'] ?? '') === FixturePaymentProvider::PROVIDER_ID) {
            $exportedFixtureProvider = $providerSettingRow;
            break;
        }
    }
    $exportedFixturePublic = is_array($exportedFixtureProvider)
        ? (json_decode((string) ($exportedFixtureProvider['public_config_json'] ?? '{}'), true) ?: [])
        : [];
    core_payment_check(
        is_string($ledgerJson)
        && ($exportedFixturePublic['mode'] ?? '') === 'export-safe'
        && ($exportedFixturePublic['default_provider'] ?? null) === false
        && ($exportedFixturePublic['publishable_key'] ?? '') === 'pk_export_safe'
        && !array_key_exists('api_key', $exportedFixturePublic)
        && !array_key_exists('callback_url', $exportedFixturePublic)
        && !array_key_exists('status_url', $exportedFixturePublic)
        && !array_key_exists('fragment_url', $exportedFixturePublic)
        && !array_key_exists('return_url_base', $exportedFixturePublic)
        && !array_key_exists('nested', $exportedFixturePublic)
        && !array_key_exists('encoded_note', $exportedFixturePublic)
        && !str_contains($ledgerJson, 'raw-export-provider-api-key')
        && !str_contains($ledgerJson, 'raw-export-provider-auth')
        && !str_contains($ledgerJson, 'raw-export-provider-path-token')
        && !str_contains($ledgerJson, 'raw-export-provider-fragment-token')
        && !str_contains($ledgerJson, 'raw-export-provider-encoded-token'),
        'Core official export package strips unsafe legacy Provider public config before writing payment ledger payload'
    );
    core_payment_check(
        is_string($ledgerJson)
        && str_contains($ledgerJson, 'claim=%5Bredacted%5D')
        && str_contains($ledgerJson, '[redacted]'),
        'Core official export package writes redacted placeholders for unsafe legacy payment metadata'
    );
    $adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataPaymentId,
        ':metadata' => json_encode([
            'customer_email' => '[redacted]',
            'checkout_url' => 'https://payments.example.test/checkout?claim=%5Bredacted%5D&safe=1',
            'status_url' => '[redacted]',
            'encoded_status_url' => '[redacted]',
            'receipt_url' => 'https://payments.example.test/receipt?safe=%5Bredacted%5D',
            'array_query_url' => '[redacted]',
            'api_key' => '[redacted]',
            'access_key' => '[redacted]',
            'auth_context' => '[redacted]',
            'operator_note' => '[redacted]',
            'encoded_note' => '[redacted]',
            'safe_note' => 'export legacy payment metadata',
            'malformed_url' => '[redacted]',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $adminPdo->prepare('UPDATE cms_payment_entitlements SET metadata_json = :metadata WHERE id = :id')->execute([
        ':id' => $repositoryMetadataEntitlementId,
        ':metadata' => json_encode([
            'payment_token' => '[redacted]',
            'access_key' => '[redacted]',
            'auth_context' => '[redacted]',
            'encoded_note' => '[redacted]',
            'safe_note' => 'export legacy entitlement metadata',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
        ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
        ':public_config_json' => json_encode([
            'mode' => 'export-safe',
            'default_provider' => false,
            'publishable_key' => 'pk_export_safe',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $restorePdo = new PDO('sqlite::memory:');
    (new MigrationRunner($restorePdo, $migrations))->run();
    $importer = new CorePaymentLedgerImporter($restorePdo, $reader);
    $importResult = $importer->importPackage($packagePath);
    $reimportResult = $importer->importPackage($packagePath);
    core_payment_check(
        (int) ($importResult['payments']['imported'] ?? -1) === count($ledger['payments'] ?? [])
        && (int) ($importResult['refunds']['imported'] ?? -1) === count($ledger['refunds'] ?? [])
        && (int) ($importResult['provider_settings']['imported'] ?? -1) === count($ledger['provider_settings'] ?? [])
        && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? [])
        && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payment_refunds')->fetchColumn() ?: 0) === count($ledger['refunds'] ?? [])
        && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn() ?: 0) === count($ledger['provider_settings'] ?? [])
        && (int) ($reimportResult['payments']['skipped'] ?? -1) === count($ledger['payments'] ?? [])
        && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? []),
        'Core official export payment ledger restores idempotently into Core payment tables'
    );
    $conflictingLedger = $ledger;
    if (isset($conflictingLedger['payments'][0]) && is_array($conflictingLedger['payments'][0])) {
        $conflictingLedger['payments'][0]['amount_minor'] = (int) ($conflictingLedger['payments'][0]['amount_minor'] ?? 0) + 1;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($conflictingLedger),
        'Core payment ledger importer rejects conflicting duplicate restore rows'
    );
    core_payment_check(
        (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? []),
        'Core payment ledger importer keeps restored rows unchanged after duplicate conflict'
    );
    $duplicateProviderSettingsLedger = $ledger;
    if (isset($duplicateProviderSettingsLedger['provider_settings'][0]) && is_array($duplicateProviderSettingsLedger['provider_settings'][0])) {
        $duplicateProviderSettingsLedger['provider_settings'][] = $duplicateProviderSettingsLedger['provider_settings'][0];
        $duplicateProviderSettingsLedger['counts']['provider_settings'] = count($duplicateProviderSettingsLedger['provider_settings']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($duplicateProviderSettingsLedger),
        'Core payment ledger importer rejects duplicate Provider settings before restore commit'
    );
    core_payment_check(
        (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn() ?: 0) === count($ledger['provider_settings'] ?? []),
        'Core payment ledger importer rolls back duplicate Provider settings'
    );
    $paymentForDuplicateAccessLedger = null;
    foreach (is_array($ledger['payments'] ?? null) ? $ledger['payments'] : [] as $candidatePayment) {
        if (is_array($candidatePayment) && in_array((string) ($candidatePayment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            $paymentForDuplicateAccessLedger = $candidatePayment;
            break;
        }
    }
    core_payment_check(is_array($paymentForDuplicateAccessLedger), 'Core payment ledger importer has a trusted payment for duplicate access validation coverage');
    $duplicateAuthorizationLedger = $ledger;
    if (is_array($paymentForDuplicateAccessLedger)) {
        $duplicateAuthorizationCreatedAt = gmdate('c');
        foreach ([991070, 991071] as $offset => $duplicateAuthorizationId) {
            $duplicateAuthorizationLedger['authorizations'][] = [
                'id' => $duplicateAuthorizationId,
                'payment_id' => (int) ($paymentForDuplicateAccessLedger['id'] ?? 0),
                'subject_type' => (string) ($paymentForDuplicateAccessLedger['subject_type'] ?? 'paid_content'),
                'subject_id' => (string) ($paymentForDuplicateAccessLedger['subject_id'] ?? 'content:991070'),
                'token_hash' => hash('sha256', 'duplicate-authorization-ledger-token-' . $offset),
                'status' => 'active',
                'max_uses' => 0,
                'used_count' => 0,
                'expires_at' => gmdate('c', time() + 3600),
                'revoked_at' => null,
                'last_used_at' => null,
                'metadata_json' => '{}',
                'created_at' => $duplicateAuthorizationCreatedAt,
                'updated_at' => $duplicateAuthorizationCreatedAt,
            ];
            $duplicateAuthorizationLedger['authorization_events'][] = [
                'id' => 991072 + $offset,
                'authorization_id' => $duplicateAuthorizationId,
                'payment_id' => (int) ($paymentForDuplicateAccessLedger['id'] ?? 0),
                'event_type' => 'created',
                'metadata_json' => '{}',
                'created_at' => $duplicateAuthorizationCreatedAt,
            ];
        }
        $duplicateAuthorizationLedger['counts']['authorizations'] = count($duplicateAuthorizationLedger['authorizations']);
        $duplicateAuthorizationLedger['counts']['authorization_events'] = count($duplicateAuthorizationLedger['authorization_events']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($duplicateAuthorizationLedger),
        'Core payment ledger importer rejects duplicate authorizations for one payment subject before restore commit'
    );
    core_payment_check(
        (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() ?: 0) === count($ledger['authorizations'] ?? []),
        'Core payment ledger importer rolls back duplicate payment authorizations'
    );
    $duplicateEntitlementLedger = $ledger;
    if (is_array($paymentForDuplicateAccessLedger)) {
        $duplicateEntitlementCreatedAt = gmdate('c');
        foreach ([991080, 991081] as $duplicateEntitlementId) {
            $duplicateEntitlementLedger['entitlements'][] = [
                'id' => $duplicateEntitlementId,
                'principal_type' => 'member',
                'principal_id' => 'duplicate-entitlement-member',
                'subject_type' => (string) ($paymentForDuplicateAccessLedger['subject_type'] ?? 'paid_content'),
                'subject_id' => (string) ($paymentForDuplicateAccessLedger['subject_id'] ?? 'content:991080'),
                'source_payment_id' => (int) ($paymentForDuplicateAccessLedger['id'] ?? 0),
                'source_authorization_id' => null,
                'status' => 'active',
                'expires_at' => null,
                'revoked_at' => null,
                'metadata_json' => '{}',
                'created_at' => $duplicateEntitlementCreatedAt,
                'updated_at' => $duplicateEntitlementCreatedAt,
            ];
        }
        $duplicateEntitlementLedger['counts']['entitlements'] = count($duplicateEntitlementLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($duplicateEntitlementLedger),
        'Core payment ledger importer rejects duplicate entitlements for one payment principal and subject before restore commit'
    );
    core_payment_check(
        (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() ?: 0) === count($ledger['entitlements'] ?? []),
        'Core payment ledger importer rolls back duplicate payment entitlements'
    );
    $ambiguousDefaultProviderLedger = $ledger;
    if (isset($ambiguousDefaultProviderLedger['provider_settings'][0]) && is_array($ambiguousDefaultProviderLedger['provider_settings'][0])) {
        $firstDefaultPublicConfig = json_decode((string) ($ambiguousDefaultProviderLedger['provider_settings'][0]['public_config_json'] ?? '{}'), true);
        $firstDefaultPublicConfig = is_array($firstDefaultPublicConfig) ? $firstDefaultPublicConfig : [];
        $firstDefaultPublicConfig['default_provider'] = true;
        $ambiguousDefaultProviderLedger['provider_settings'][0]['status'] = 'enabled';
        $ambiguousDefaultProviderLedger['provider_settings'][0]['public_config_json'] = json_encode($firstDefaultPublicConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secondDefaultProvider = $ambiguousDefaultProviderLedger['provider_settings'][0];
        $secondDefaultProvider['provider_id'] = 'core.ambiguous-default';
        $secondDefaultProvider['display_name'] = 'Ambiguous Default';
        $ambiguousDefaultProviderLedger['provider_settings'][] = $secondDefaultProvider;
        $ambiguousDefaultProviderLedger['counts']['provider_settings'] = count($ambiguousDefaultProviderLedger['provider_settings']);
    }
    $ambiguousDefaultProviderPdo = new PDO('sqlite::memory:');
    (new MigrationRunner($ambiguousDefaultProviderPdo, $migrations))->run();
    core_payment_throws(
        static fn () => (new CorePaymentLedgerImporter($ambiguousDefaultProviderPdo, $reader))->importLedger($ambiguousDefaultProviderLedger),
        'Core payment ledger importer rejects ambiguous restored default Provider markers before restore commit'
    );
    core_payment_check(
        (int) ($ambiguousDefaultProviderPdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn() ?: 0) === 0,
        'Core payment ledger importer rolls back ambiguous default Provider settings'
    );
    $defaultMarkerValidator = new ReflectionMethod(CorePaymentLedgerImporter::class, 'validateProviderDefaultMarkers');
    core_payment_throws(
        static fn () => $defaultMarkerValidator->invoke(new CorePaymentLedgerImporter($restorePdo, $reader), [
            'provider_settings' => [[
                'provider_id' => 'core.malformed-default-marker-json',
                'display_name' => 'Malformed Default Marker JSON',
                'status' => 'enabled',
                'public_config_json' => '{"default_provider":',
                'secret_config_ciphertext' => null,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ]],
        ]),
        'Core payment ledger default Provider marker validation rejects malformed public config JSON'
    );
    $unsafeDiagnosticLedger = $ledger;
    if (isset($unsafeDiagnosticLedger['payments'][0]) && is_array($unsafeDiagnosticLedger['payments'][0])) {
        $unsafeDiagnosticLedger['payments'][0]['id'] = 990500;
        $unsafeDiagnosticLedger['payments'][0]['status'] = "paid\npayment_token=raw-leak";
    }
    $unsafeDiagnosticMessage = '';
    try {
        $importer->importLedger($unsafeDiagnosticLedger);
    } catch (Throwable $exception) {
        $unsafeDiagnosticMessage = $exception->getMessage();
    }
    core_payment_check(
        $unsafeDiagnosticMessage !== ''
        && !str_contains($unsafeDiagnosticMessage, "\n")
        && !str_contains($unsafeDiagnosticMessage, 'payment_token=raw-leak')
        && str_contains($unsafeDiagnosticMessage, '[invalid]')
        && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? []),
        'Core payment ledger importer sanitizes unsafe restore failure diagnostics'
    );
    $tokenLikeDiagnosticLedger = $ledger;
    if (isset($tokenLikeDiagnosticLedger['payments'][0]) && is_array($tokenLikeDiagnosticLedger['payments'][0])) {
        $tokenLikeDiagnosticLedger['payments'][0]['id'] = 990501;
        $tokenLikeDiagnosticLedger['payments'][0]['status'] = 'paid payment_token=raw-leak';
    }
    $tokenLikeDiagnosticMessage = '';
    try {
        $importer->importLedger($tokenLikeDiagnosticLedger);
    } catch (Throwable $exception) {
        $tokenLikeDiagnosticMessage = $exception->getMessage();
    }
    core_payment_check(
        $tokenLikeDiagnosticMessage !== ''
        && !str_contains($tokenLikeDiagnosticMessage, 'payment_token=raw-leak')
        && str_contains($tokenLikeDiagnosticMessage, '[invalid]')
        && (int) ($restorePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? []),
        'Core payment ledger importer sanitizes token-like restore failure diagnostics'
    );
    $corruptRestoredAuthorizationLedger = $ledger;
    $paymentForCorruptRestoredAuthorization = null;
    foreach (is_array($corruptRestoredAuthorizationLedger['payments'] ?? null) ? $corruptRestoredAuthorizationLedger['payments'] : [] as $candidatePayment) {
        if (is_array($candidatePayment) && in_array((string) ($candidatePayment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            $paymentForCorruptRestoredAuthorization = $candidatePayment;
            break;
        }
    }
    core_payment_check(is_array($paymentForCorruptRestoredAuthorization), 'Core payment ledger importer has a trusted payment for restored authorization relation validation coverage');
    if (is_array($paymentForCorruptRestoredAuthorization)) {
        $corruptRestoredAuthorizationCreatedAt = gmdate('c');
        $corruptRestoredAuthorizationLedger['authorizations'][] = [
            'id' => 991088,
            'payment_id' => (int) ($paymentForCorruptRestoredAuthorization['id'] ?? 0),
            'subject_type' => (string) ($paymentForCorruptRestoredAuthorization['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($paymentForCorruptRestoredAuthorization['subject_id'] ?? 'content:991088'),
            'token_hash' => hash('sha256', 'corrupt-restored-authorization-relation-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => $corruptRestoredAuthorizationCreatedAt,
            'updated_at' => $corruptRestoredAuthorizationCreatedAt,
        ];
        $corruptRestoredAuthorizationLedger['authorization_events'][] = [
            'id' => 991088,
            'authorization_id' => 991088,
            'payment_id' => (int) ($paymentForCorruptRestoredAuthorization['id'] ?? 0),
            'event_type' => 'created',
            'metadata_json' => '{}',
            'created_at' => $corruptRestoredAuthorizationCreatedAt,
        ];
        $corruptRestoredAuthorizationLedger['counts']['authorizations'] = count($corruptRestoredAuthorizationLedger['authorizations']);
        $corruptRestoredAuthorizationLedger['counts']['authorization_events'] = count($corruptRestoredAuthorizationLedger['authorization_events']);
    }
    $corruptRestoredAuthorizationPdo = new PDO('sqlite::memory:');
    (new MigrationRunner($corruptRestoredAuthorizationPdo, $migrations))->run();
    $corruptRestoredAuthorizationImporter = new CorePaymentLedgerImporter($corruptRestoredAuthorizationPdo, $reader);
    $corruptRestoredAuthorizationImporter->importLedger($corruptRestoredAuthorizationLedger);
    $restoredAuthorizationForCorruptRelation = $corruptRestoredAuthorizationPdo->query('SELECT * FROM cms_payment_authorizations WHERE id = 991088 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    core_payment_check(is_array($restoredAuthorizationForCorruptRelation), 'Core payment ledger importer restored authorization rows for relation validation coverage');
    if (is_array($restoredAuthorizationForCorruptRelation)) {
        $corruptRestoredAuthorizationPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
            ':id' => (int) ($restoredAuthorizationForCorruptRelation['id'] ?? 0),
            ':payment_id' => (string) ((int) ($restoredAuthorizationForCorruptRelation['payment_id'] ?? 0)) . 'abc',
        ]);
        core_payment_throws(
            static fn () => $corruptRestoredAuthorizationImporter->importLedger([
                'authorization_events' => [[
                    'id' => 991089,
                    'authorization_id' => (int) ($restoredAuthorizationForCorruptRelation['id'] ?? 0),
                    'payment_id' => (int) ($restoredAuthorizationForCorruptRelation['payment_id'] ?? 0),
                    'event_type' => 'consumed',
                    'metadata_json' => '{}',
                    'created_at' => (string) ($restoredAuthorizationForCorruptRelation['created_at'] ?? gmdate('c')),
                ]],
            ]),
            'Core payment ledger importer rejects corrupted restored authorization payment ids before accepting authorization events'
        );
    }
    $paidPaymentMissingTimestampLedger = $ledger;
    if (isset($paidPaymentMissingTimestampLedger['payments'][0]) && is_array($paidPaymentMissingTimestampLedger['payments'][0])) {
        $paidPaymentMissingTimestampLedger['payments'][0]['id'] = 990040;
        $paidPaymentMissingTimestampLedger['payments'][0]['remote_id'] = 'paid-payment-missing-timestamp-ledger-remote';
        $paidPaymentMissingTimestampLedger['payments'][0]['idempotency_key'] = 'paid-payment-missing-timestamp-ledger-key';
        $paidPaymentMissingTimestampLedger['payments'][0]['status'] = 'paid';
        $paidPaymentMissingTimestampLedger['payments'][0]['paid_at'] = null;
        $paidPaymentMissingTimestampLedger['payments'][0]['failed_at'] = null;
        $paidPaymentMissingTimestampLedger['payments'][0]['cancelled_at'] = null;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($paidPaymentMissingTimestampLedger),
        'Core payment ledger importer rejects paid payments without paid_at before restore commit'
    );
    $pendingPaymentWithLifecycleTimestampLedger = $ledger;
    if (isset($pendingPaymentWithLifecycleTimestampLedger['payments'][0]) && is_array($pendingPaymentWithLifecycleTimestampLedger['payments'][0])) {
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['id'] = 990041;
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['remote_id'] = 'pending-payment-lifecycle-timestamp-ledger-remote';
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['idempotency_key'] = 'pending-payment-lifecycle-timestamp-ledger-key';
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['status'] = 'pending';
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['authorized_at'] = null;
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['paid_at'] = gmdate('c');
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['failed_at'] = null;
        $pendingPaymentWithLifecycleTimestampLedger['payments'][0]['cancelled_at'] = null;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($pendingPaymentWithLifecycleTimestampLedger),
        'Core payment ledger importer rejects pending payments with lifecycle timestamps before restore commit'
    );
    $authorizedPaymentMissingTimestampLedger = $ledger;
    if (isset($authorizedPaymentMissingTimestampLedger['payments'][0]) && is_array($authorizedPaymentMissingTimestampLedger['payments'][0])) {
        $authorizedPaymentMissingTimestampLedger['payments'][0]['id'] = 990042;
        $authorizedPaymentMissingTimestampLedger['payments'][0]['remote_id'] = 'authorized-payment-missing-timestamp-ledger-remote';
        $authorizedPaymentMissingTimestampLedger['payments'][0]['idempotency_key'] = 'authorized-payment-missing-timestamp-ledger-key';
        $authorizedPaymentMissingTimestampLedger['payments'][0]['status'] = 'authorized';
        $authorizedPaymentMissingTimestampLedger['payments'][0]['authorized_at'] = null;
        $authorizedPaymentMissingTimestampLedger['payments'][0]['paid_at'] = null;
        $authorizedPaymentMissingTimestampLedger['payments'][0]['failed_at'] = null;
        $authorizedPaymentMissingTimestampLedger['payments'][0]['cancelled_at'] = null;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($authorizedPaymentMissingTimestampLedger),
        'Core payment ledger importer rejects authorized payments without authorized_at before restore commit'
    );
    $failedPaymentWithPaidTimestampLedger = $ledger;
    if (isset($failedPaymentWithPaidTimestampLedger['payments'][0]) && is_array($failedPaymentWithPaidTimestampLedger['payments'][0])) {
        $failedPaymentWithPaidTimestampLedger['payments'][0]['id'] = 990043;
        $failedPaymentWithPaidTimestampLedger['payments'][0]['remote_id'] = 'failed-payment-paid-timestamp-ledger-remote';
        $failedPaymentWithPaidTimestampLedger['payments'][0]['idempotency_key'] = 'failed-payment-paid-timestamp-ledger-key';
        $failedPaymentWithPaidTimestampLedger['payments'][0]['status'] = 'failed';
        $failedPaymentWithPaidTimestampLedger['payments'][0]['paid_at'] = gmdate('c');
        $failedPaymentWithPaidTimestampLedger['payments'][0]['failed_at'] = gmdate('c');
        $failedPaymentWithPaidTimestampLedger['payments'][0]['cancelled_at'] = null;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($failedPaymentWithPaidTimestampLedger),
        'Core payment ledger importer rejects failed payments with paid_at before restore commit'
    );
    $paidPaymentBeforeCreatedLedger = $ledger;
    if (isset($paidPaymentBeforeCreatedLedger['payments'][0]) && is_array($paidPaymentBeforeCreatedLedger['payments'][0])) {
        $paidPaymentBeforeCreatedLedger['payments'][0]['id'] = 990050;
        $paidPaymentBeforeCreatedLedger['payments'][0]['remote_id'] = 'paid-payment-before-created-ledger-remote';
        $paidPaymentBeforeCreatedLedger['payments'][0]['idempotency_key'] = 'paid-payment-before-created-ledger-key';
        $paidPaymentBeforeCreatedLedger['payments'][0]['status'] = 'paid';
        $paidPaymentBeforeCreatedLedger['payments'][0]['authorized_at'] = null;
        $paidPaymentBeforeCreatedLedger['payments'][0]['paid_at'] = gmdate('c', time() - 3600);
        $paidPaymentBeforeCreatedLedger['payments'][0]['failed_at'] = null;
        $paidPaymentBeforeCreatedLedger['payments'][0]['cancelled_at'] = null;
        $paidPaymentBeforeCreatedLedger['payments'][0]['created_at'] = gmdate('c');
        $paidPaymentBeforeCreatedLedger['payments'][0]['updated_at'] = gmdate('c', time() + 60);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($paidPaymentBeforeCreatedLedger),
        'Core payment ledger importer rejects paid payments whose paid_at predates created_at before restore commit'
    );
    $orphanRefundLedger = $ledger;
    $orphanRefundLedger['refunds'][] = [
        'id' => 990025,
        'payment_id' => 990025,
        'provider_id' => FixturePaymentProvider::PROVIDER_ID,
        'remote_id' => 'orphan-refund-ledger-remote',
        'status' => 'completed',
        'amount_minor' => 1,
        'currency' => 'USD',
        'reason' => 'orphan refund restore',
        'idempotency_key' => 'orphan-refund-ledger-key',
        'request_hash' => hash('sha256', 'orphan-refund-ledger'),
        'metadata_json' => '{}',
        'completed_at' => gmdate('c'),
        'failed_at' => null,
        'cancelled_at' => null,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $orphanRefundLedger['counts']['refunds'] = count($orphanRefundLedger['refunds']);
    core_payment_throws(
        static fn () => $importer->importLedger($orphanRefundLedger),
        'Core payment ledger importer rejects refunds that reference missing source payments before restore commit'
    );
    $mismatchedRefundProviderLedger = $ledger;
    if (isset($mismatchedRefundProviderLedger['payments'][0]) && is_array($mismatchedRefundProviderLedger['payments'][0])) {
        $mismatchedRefundProviderLedger['refunds'][] = [
            'id' => 990027,
            'payment_id' => (int) ($mismatchedRefundProviderLedger['payments'][0]['id'] ?? 1),
            'provider_id' => 'core.mismatched-refund-provider',
            'remote_id' => 'mismatched-refund-provider-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 1,
            'currency' => (string) ($mismatchedRefundProviderLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'mismatched refund provider restore',
            'idempotency_key' => 'mismatched-refund-provider-ledger-key',
            'request_hash' => hash('sha256', 'mismatched-refund-provider-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $mismatchedRefundProviderLedger['counts']['refunds'] = count($mismatchedRefundProviderLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($mismatchedRefundProviderLedger),
        'Core payment ledger importer rejects refunds whose Provider does not match the source payment before restore commit'
    );
    $completedRefundMissingTimestampLedger = $ledger;
    if (isset($completedRefundMissingTimestampLedger['payments'][0]) && is_array($completedRefundMissingTimestampLedger['payments'][0])) {
        $completedRefundMissingTimestampLedger['refunds'][] = [
            'id' => 990036,
            'payment_id' => (int) ($completedRefundMissingTimestampLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($completedRefundMissingTimestampLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'completed-refund-missing-timestamp-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 1,
            'currency' => (string) ($completedRefundMissingTimestampLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'completed refund missing timestamp restore',
            'idempotency_key' => 'completed-refund-missing-timestamp-ledger-key',
            'request_hash' => hash('sha256', 'completed-refund-missing-timestamp-ledger'),
            'metadata_json' => '{}',
            'completed_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $completedRefundMissingTimestampLedger['counts']['refunds'] = count($completedRefundMissingTimestampLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($completedRefundMissingTimestampLedger),
        'Core payment ledger importer rejects completed refunds without completed_at before restore commit'
    );
    $pendingRefundWithTerminalTimestampLedger = $ledger;
    if (isset($pendingRefundWithTerminalTimestampLedger['payments'][0]) && is_array($pendingRefundWithTerminalTimestampLedger['payments'][0])) {
        $pendingRefundWithTerminalTimestampLedger['refunds'][] = [
            'id' => 990037,
            'payment_id' => (int) ($pendingRefundWithTerminalTimestampLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($pendingRefundWithTerminalTimestampLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'pending-refund-terminal-timestamp-ledger-remote',
            'status' => 'pending',
            'amount_minor' => 1,
            'currency' => (string) ($pendingRefundWithTerminalTimestampLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'pending refund terminal timestamp restore',
            'idempotency_key' => 'pending-refund-terminal-timestamp-ledger-key',
            'request_hash' => hash('sha256', 'pending-refund-terminal-timestamp-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $pendingRefundWithTerminalTimestampLedger['counts']['refunds'] = count($pendingRefundWithTerminalTimestampLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($pendingRefundWithTerminalTimestampLedger),
        'Core payment ledger importer rejects pending refunds with terminal timestamps before restore commit'
    );
    $completedRefundBeforeCreatedLedger = $ledger;
    if (isset($completedRefundBeforeCreatedLedger['payments'][0]) && is_array($completedRefundBeforeCreatedLedger['payments'][0])) {
        $completedRefundBeforeCreatedLedger['refunds'][] = [
            'id' => 990051,
            'payment_id' => (int) ($completedRefundBeforeCreatedLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($completedRefundBeforeCreatedLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'completed-refund-before-created-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 1,
            'currency' => (string) ($completedRefundBeforeCreatedLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'completed refund before created restore',
            'idempotency_key' => 'completed-refund-before-created-ledger-key',
            'request_hash' => hash('sha256', 'completed-refund-before-created-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c', time() - 3600),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c', time() + 60),
        ];
        $completedRefundBeforeCreatedLedger['counts']['refunds'] = count($completedRefundBeforeCreatedLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($completedRefundBeforeCreatedLedger),
        'Core payment ledger importer rejects completed refunds whose completed_at predates created_at before restore commit'
    );
    $overRefundedPaymentLedger = $ledger;
    if (isset($overRefundedPaymentLedger['payments'][0]) && is_array($overRefundedPaymentLedger['payments'][0])) {
        $overRefundedPaymentLedger['payments'][0]['id'] = 990044;
        $overRefundedPaymentLedger['payments'][0]['remote_id'] = 'over-refunded-payment-ledger-remote';
        $overRefundedPaymentLedger['payments'][0]['idempotency_key'] = 'over-refunded-payment-ledger-key';
        $overRefundedPaymentLedger['payments'][0]['status'] = 'partially_refunded';
        $overRefundedPaymentLedger['payments'][0]['amount_minor'] = 100;
        $overRefundedPaymentLedger['payments'][0]['paid_at'] = gmdate('c');
        $overRefundedPaymentLedger['refunds'][] = [
            'id' => 990044,
            'payment_id' => 990044,
            'provider_id' => (string) ($overRefundedPaymentLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'over-refunded-payment-refund-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 101,
            'currency' => (string) ($overRefundedPaymentLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'over refunded restore',
            'idempotency_key' => 'over-refunded-payment-refund-ledger-key',
            'request_hash' => hash('sha256', 'over-refunded-payment-refund-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $overRefundedPaymentLedger['counts']['refunds'] = count($overRefundedPaymentLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($overRefundedPaymentLedger),
        'Core payment ledger importer rejects completed refunds that exceed source payment amount before restore commit'
    );
    $paidPaymentWithCompletedRefundLedger = $ledger;
    if (isset($paidPaymentWithCompletedRefundLedger['payments'][0]) && is_array($paidPaymentWithCompletedRefundLedger['payments'][0])) {
        $paidPaymentWithCompletedRefundLedger['payments'][0]['id'] = 990045;
        $paidPaymentWithCompletedRefundLedger['payments'][0]['remote_id'] = 'paid-payment-completed-refund-ledger-remote';
        $paidPaymentWithCompletedRefundLedger['payments'][0]['idempotency_key'] = 'paid-payment-completed-refund-ledger-key';
        $paidPaymentWithCompletedRefundLedger['payments'][0]['status'] = 'paid';
        $paidPaymentWithCompletedRefundLedger['payments'][0]['amount_minor'] = 100;
        $paidPaymentWithCompletedRefundLedger['payments'][0]['paid_at'] = gmdate('c');
        $paidPaymentWithCompletedRefundLedger['refunds'][] = [
            'id' => 990045,
            'payment_id' => 990045,
            'provider_id' => (string) ($paidPaymentWithCompletedRefundLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'paid-payment-completed-refund-refund-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 1,
            'currency' => (string) ($paidPaymentWithCompletedRefundLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'paid payment completed refund restore',
            'idempotency_key' => 'paid-payment-completed-refund-refund-ledger-key',
            'request_hash' => hash('sha256', 'paid-payment-completed-refund-refund-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $paidPaymentWithCompletedRefundLedger['counts']['refunds'] = count($paidPaymentWithCompletedRefundLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($paidPaymentWithCompletedRefundLedger),
        'Core payment ledger importer rejects paid payments with completed refunds before restore commit'
    );
    $partialPaymentFullyRefundedLedger = $ledger;
    if (isset($partialPaymentFullyRefundedLedger['payments'][0]) && is_array($partialPaymentFullyRefundedLedger['payments'][0])) {
        $partialPaymentFullyRefundedLedger['payments'][0]['id'] = 990046;
        $partialPaymentFullyRefundedLedger['payments'][0]['remote_id'] = 'partial-payment-fully-refunded-ledger-remote';
        $partialPaymentFullyRefundedLedger['payments'][0]['idempotency_key'] = 'partial-payment-fully-refunded-ledger-key';
        $partialPaymentFullyRefundedLedger['payments'][0]['status'] = 'partially_refunded';
        $partialPaymentFullyRefundedLedger['payments'][0]['amount_minor'] = 100;
        $partialPaymentFullyRefundedLedger['payments'][0]['paid_at'] = gmdate('c');
        $partialPaymentFullyRefundedLedger['refunds'][] = [
            'id' => 990046,
            'payment_id' => 990046,
            'provider_id' => (string) ($partialPaymentFullyRefundedLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'partial-payment-fully-refunded-refund-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 100,
            'currency' => (string) ($partialPaymentFullyRefundedLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'partial payment fully refunded restore',
            'idempotency_key' => 'partial-payment-fully-refunded-refund-ledger-key',
            'request_hash' => hash('sha256', 'partial-payment-fully-refunded-refund-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $partialPaymentFullyRefundedLedger['counts']['refunds'] = count($partialPaymentFullyRefundedLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($partialPaymentFullyRefundedLedger),
        'Core payment ledger importer rejects partially refunded payments whose completed refunds equal the payment amount before restore commit'
    );
    $refundedPaymentPartialTotalLedger = $ledger;
    if (isset($refundedPaymentPartialTotalLedger['payments'][0]) && is_array($refundedPaymentPartialTotalLedger['payments'][0])) {
        $refundedPaymentPartialTotalLedger['payments'][0]['id'] = 990047;
        $refundedPaymentPartialTotalLedger['payments'][0]['remote_id'] = 'refunded-payment-partial-total-ledger-remote';
        $refundedPaymentPartialTotalLedger['payments'][0]['idempotency_key'] = 'refunded-payment-partial-total-ledger-key';
        $refundedPaymentPartialTotalLedger['payments'][0]['status'] = 'refunded';
        $refundedPaymentPartialTotalLedger['payments'][0]['amount_minor'] = 100;
        $refundedPaymentPartialTotalLedger['payments'][0]['paid_at'] = gmdate('c');
        $refundedPaymentPartialTotalLedger['refunds'][] = [
            'id' => 990047,
            'payment_id' => 990047,
            'provider_id' => (string) ($refundedPaymentPartialTotalLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'refunded-payment-partial-total-refund-ledger-remote',
            'status' => 'completed',
            'amount_minor' => 99,
            'currency' => (string) ($refundedPaymentPartialTotalLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'refunded payment partial total restore',
            'idempotency_key' => 'refunded-payment-partial-total-refund-ledger-key',
            'request_hash' => hash('sha256', 'refunded-payment-partial-total-refund-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $refundedPaymentPartialTotalLedger['counts']['refunds'] = count($refundedPaymentPartialTotalLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($refundedPaymentPartialTotalLedger),
        'Core payment ledger importer rejects refunded payments whose completed refunds do not equal the payment amount before restore commit'
    );
    $mismatchedWebhookProviderLedger = $ledger;
    if (isset($mismatchedWebhookProviderLedger['payments'][0]) && is_array($mismatchedWebhookProviderLedger['payments'][0])) {
        $mismatchedWebhookProviderLedger['webhook_receipts'][] = [
            'id' => 990028,
            'payment_id' => (int) ($mismatchedWebhookProviderLedger['payments'][0]['id'] ?? 1),
            'provider_id' => 'core.mismatched-webhook-provider',
            'external_event_id' => 'evt-mismatched-webhook-provider-restore',
            'payload_hash' => hash('sha256', 'mismatched-webhook-provider-ledger'),
            'status' => 'received',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $mismatchedWebhookProviderLedger['counts']['webhook_receipts'] = count($mismatchedWebhookProviderLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($mismatchedWebhookProviderLedger),
        'Core payment ledger importer rejects payment-bound webhooks whose Provider does not match the bound payment before restore commit'
    );
    $processedWebhookMissingTimestampLedger = $ledger;
    if (isset($processedWebhookMissingTimestampLedger['payments'][0]) && is_array($processedWebhookMissingTimestampLedger['payments'][0])) {
        $processedWebhookMissingTimestampLedger['webhook_receipts'][] = [
            'id' => 990038,
            'payment_id' => (int) ($processedWebhookMissingTimestampLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($processedWebhookMissingTimestampLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-processed-webhook-missing-timestamp-restore',
            'payload_hash' => hash('sha256', 'processed-webhook-missing-timestamp-ledger'),
            'status' => 'processed',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $processedWebhookMissingTimestampLedger['counts']['webhook_receipts'] = count($processedWebhookMissingTimestampLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($processedWebhookMissingTimestampLedger),
        'Core payment ledger importer rejects terminal webhook receipts without processed_at before restore commit'
    );
    $receivedWebhookWithProcessedAtLedger = $ledger;
    if (isset($receivedWebhookWithProcessedAtLedger['payments'][0]) && is_array($receivedWebhookWithProcessedAtLedger['payments'][0])) {
        $receivedWebhookWithProcessedAtLedger['webhook_receipts'][] = [
            'id' => 990039,
            'payment_id' => (int) ($receivedWebhookWithProcessedAtLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($receivedWebhookWithProcessedAtLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-received-webhook-with-processed-at-restore',
            'payload_hash' => hash('sha256', 'received-webhook-with-processed-at-ledger'),
            'status' => 'received',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => gmdate('c'),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $receivedWebhookWithProcessedAtLedger['counts']['webhook_receipts'] = count($receivedWebhookWithProcessedAtLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($receivedWebhookWithProcessedAtLedger),
        'Core payment ledger importer rejects non-terminal webhook receipts with processed_at before restore commit'
    );
    $longFailureWebhookMetadataLedger = $ledger;
    if (isset($longFailureWebhookMetadataLedger['payments'][0]) && is_array($longFailureWebhookMetadataLedger['payments'][0])) {
        $longFailureWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990053,
            'payment_id' => (int) ($longFailureWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($longFailureWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-long-failure-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'long-failure-webhook-metadata-ledger'),
            'status' => 'failed',
            'metadata_json' => json_encode(['failure_error' => str_repeat('x', 241), 'failed_at' => gmdate('c')], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $longFailureWebhookMetadataLedger['counts']['webhook_receipts'] = count($longFailureWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($longFailureWebhookMetadataLedger),
        'Core payment ledger importer rejects oversized webhook failure diagnostics before restore commit'
    );
    $secretLikeFailureWebhookMetadataLedger = $ledger;
    if (isset($secretLikeFailureWebhookMetadataLedger['payments'][0]) && is_array($secretLikeFailureWebhookMetadataLedger['payments'][0])) {
        $secretLikeFailureWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990055,
            'payment_id' => (int) ($secretLikeFailureWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($secretLikeFailureWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-secret-like-failure-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'secret-like-failure-webhook-metadata-ledger'),
            'status' => 'failed',
            'metadata_json' => json_encode(['failure_error' => 'Provider failed with signature=raw-signature', 'failed_at' => gmdate('c')], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $secretLikeFailureWebhookMetadataLedger['counts']['webhook_receipts'] = count($secretLikeFailureWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($secretLikeFailureWebhookMetadataLedger),
        'Core payment ledger importer rejects secret-like webhook failure diagnostics before restore commit'
    );
    $nonCanonicalPayloadSizeWebhookMetadataLedger = $ledger;
    if (isset($nonCanonicalPayloadSizeWebhookMetadataLedger['payments'][0]) && is_array($nonCanonicalPayloadSizeWebhookMetadataLedger['payments'][0])) {
        $nonCanonicalPayloadSizeWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990058,
            'payment_id' => (int) ($nonCanonicalPayloadSizeWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($nonCanonicalPayloadSizeWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-non-canonical-payload-size-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'non-canonical-payload-size-webhook-metadata-ledger'),
            'status' => 'failed',
            'metadata_json' => json_encode(['payload_size' => '123', 'failure_error' => 'Payment webhook application failed.', 'failed_at' => gmdate('c')], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalPayloadSizeWebhookMetadataLedger['counts']['webhook_receipts'] = count($nonCanonicalPayloadSizeWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalPayloadSizeWebhookMetadataLedger),
        'Core payment ledger importer rejects non-canonical webhook payload-size metadata before restore commit'
    );
    $oversizedPayloadSizeWebhookMetadataLedger = $ledger;
    if (isset($oversizedPayloadSizeWebhookMetadataLedger['payments'][0]) && is_array($oversizedPayloadSizeWebhookMetadataLedger['payments'][0])) {
        $oversizedPayloadSizeWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990059,
            'payment_id' => (int) ($oversizedPayloadSizeWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($oversizedPayloadSizeWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-oversized-payload-size-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'oversized-payload-size-webhook-metadata-ledger'),
            'status' => 'received',
            'metadata_json' => json_encode(['payload_size' => 1048577], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $oversizedPayloadSizeWebhookMetadataLedger['counts']['webhook_receipts'] = count($oversizedPayloadSizeWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($oversizedPayloadSizeWebhookMetadataLedger),
        'Core payment ledger importer rejects out-of-bounds webhook payload-size metadata before restore commit'
    );
    $nonCanonicalContentTypeWebhookMetadataLedger = $ledger;
    if (isset($nonCanonicalContentTypeWebhookMetadataLedger['payments'][0]) && is_array($nonCanonicalContentTypeWebhookMetadataLedger['payments'][0])) {
        $nonCanonicalContentTypeWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990060,
            'payment_id' => (int) ($nonCanonicalContentTypeWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($nonCanonicalContentTypeWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-non-canonical-content-type-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'non-canonical-content-type-webhook-metadata-ledger'),
            'status' => 'received',
            'metadata_json' => json_encode(['payload_size' => 123, 'content_type' => ' application/json '], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalContentTypeWebhookMetadataLedger['counts']['webhook_receipts'] = count($nonCanonicalContentTypeWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalContentTypeWebhookMetadataLedger),
        'Core payment ledger importer rejects non-canonical webhook content-type metadata before restore commit'
    );
    $tokenLikeContentTypeWebhookMetadataLedger = $ledger;
    if (isset($tokenLikeContentTypeWebhookMetadataLedger['payments'][0]) && is_array($tokenLikeContentTypeWebhookMetadataLedger['payments'][0])) {
        $tokenLikeContentTypeWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990062,
            'payment_id' => (int) ($tokenLikeContentTypeWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($tokenLikeContentTypeWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-token-like-content-type-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'token-like-content-type-webhook-metadata-ledger'),
            'status' => 'received',
            'metadata_json' => json_encode(['payload_size' => 123, 'content_type' => 'application/json; note=payment_token%3Draw-restore-trace-token'], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $tokenLikeContentTypeWebhookMetadataLedger['counts']['webhook_receipts'] = count($tokenLikeContentTypeWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenLikeContentTypeWebhookMetadataLedger),
        'Core payment ledger importer rejects token-like webhook content-type metadata before restore commit'
    );
    $nonCanonicalWebhookTimestampMetadataLedger = $ledger;
    if (isset($nonCanonicalWebhookTimestampMetadataLedger['payments'][0]) && is_array($nonCanonicalWebhookTimestampMetadataLedger['payments'][0])) {
        $nonCanonicalWebhookTimestampMetadataLedger['webhook_receipts'][] = [
            'id' => 990060,
            'payment_id' => (int) ($nonCanonicalWebhookTimestampMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($nonCanonicalWebhookTimestampMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-non-canonical-webhook-timestamp-metadata-restore',
            'payload_hash' => hash('sha256', 'non-canonical-webhook-timestamp-metadata-ledger'),
            'status' => 'received',
            'metadata_json' => json_encode(['payload_size' => 123, 'webhook_timestamp' => ' 1234567890 '], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalWebhookTimestampMetadataLedger['counts']['webhook_receipts'] = count($nonCanonicalWebhookTimestampMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalWebhookTimestampMetadataLedger),
        'Core payment ledger importer rejects non-canonical webhook timestamp metadata before restore commit'
    );
    $leadingZeroWebhookTimestampMetadataLedger = $ledger;
    if (isset($leadingZeroWebhookTimestampMetadataLedger['payments'][0]) && is_array($leadingZeroWebhookTimestampMetadataLedger['payments'][0])) {
        $leadingZeroWebhookTimestampMetadataLedger['webhook_receipts'][] = [
            'id' => 990063,
            'payment_id' => (int) ($leadingZeroWebhookTimestampMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($leadingZeroWebhookTimestampMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-leading-zero-webhook-timestamp-metadata-restore',
            'payload_hash' => hash('sha256', 'leading-zero-webhook-timestamp-metadata-ledger'),
            'status' => 'received',
            'metadata_json' => json_encode(['payload_size' => 123, 'webhook_timestamp' => '000123456789'], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $leadingZeroWebhookTimestampMetadataLedger['counts']['webhook_receipts'] = count($leadingZeroWebhookTimestampMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($leadingZeroWebhookTimestampMetadataLedger),
        'Core payment ledger importer rejects leading-zero webhook timestamp metadata before restore commit'
    );
    $nonCanonicalSourceHashWebhookMetadataLedger = $ledger;
    if (isset($nonCanonicalSourceHashWebhookMetadataLedger['payments'][0]) && is_array($nonCanonicalSourceHashWebhookMetadataLedger['payments'][0])) {
        $nonCanonicalSourceHashWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990061,
            'payment_id' => (int) ($nonCanonicalSourceHashWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($nonCanonicalSourceHashWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-non-canonical-source-hash-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'non-canonical-source-hash-webhook-metadata-ledger'),
            'status' => 'received',
            'metadata_json' => json_encode(['payload_size' => 123, 'source_ip_hash' => strtoupper(str_repeat('a', 64))], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalSourceHashWebhookMetadataLedger['counts']['webhook_receipts'] = count($nonCanonicalSourceHashWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalSourceHashWebhookMetadataLedger),
        'Core payment ledger importer rejects non-canonical webhook source hash metadata before restore commit'
    );
    $nonCanonicalFailureWebhookMetadataLedger = $ledger;
    if (isset($nonCanonicalFailureWebhookMetadataLedger['payments'][0]) && is_array($nonCanonicalFailureWebhookMetadataLedger['payments'][0])) {
        $nonCanonicalFailureWebhookMetadataLedger['webhook_receipts'][] = [
            'id' => 990054,
            'payment_id' => (int) ($nonCanonicalFailureWebhookMetadataLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($nonCanonicalFailureWebhookMetadataLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-non-canonical-failure-webhook-metadata-restore',
            'payload_hash' => hash('sha256', 'non-canonical-failure-webhook-metadata-ledger'),
            'status' => 'failed',
            'metadata_json' => json_encode(['failure_error' => 'Payment webhook application failed.', 'failed_at' => 'yesterday'], JSON_UNESCAPED_SLASHES),
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalFailureWebhookMetadataLedger['counts']['webhook_receipts'] = count($nonCanonicalFailureWebhookMetadataLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalFailureWebhookMetadataLedger),
        'Core payment ledger importer rejects non-canonical webhook failure timestamps before restore commit'
    );
    $processedWebhookBeforeReceivedLedger = $ledger;
    if (isset($processedWebhookBeforeReceivedLedger['payments'][0]) && is_array($processedWebhookBeforeReceivedLedger['payments'][0])) {
        $processedWebhookBeforeReceivedLedger['webhook_receipts'][] = [
            'id' => 990052,
            'payment_id' => (int) ($processedWebhookBeforeReceivedLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($processedWebhookBeforeReceivedLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-processed-webhook-before-received-restore',
            'payload_hash' => hash('sha256', 'processed-webhook-before-received-ledger'),
            'status' => 'processed',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => gmdate('c', time() - 3600),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c', time() + 60),
        ];
        $processedWebhookBeforeReceivedLedger['counts']['webhook_receipts'] = count($processedWebhookBeforeReceivedLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($processedWebhookBeforeReceivedLedger),
        'Core payment ledger importer rejects processed webhook receipts whose processed_at predates received_at before restore commit'
    );
    $mismatchedAuthorizationLedger = $ledger;
    if (isset($mismatchedAuthorizationLedger['payments'][0]) && is_array($mismatchedAuthorizationLedger['payments'][0])) {
        $mismatchedAuthorizationLedger['authorizations'][] = [
            'id' => 990026,
            'payment_id' => (int) ($mismatchedAuthorizationLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($mismatchedAuthorizationLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => 'mismatched-authorization-ledger-subject',
            'token_hash' => hash('sha256', 'mismatched-authorization-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $mismatchedAuthorizationLedger['counts']['authorizations'] = count($mismatchedAuthorizationLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($mismatchedAuthorizationLedger),
        'Core payment ledger importer rejects authorizations whose subject does not match the source payment before restore commit'
    );
    $activeAuthorizationRefundedPaymentLedger = $ledger;
    if (isset($activeAuthorizationRefundedPaymentLedger['payments'][0]) && is_array($activeAuthorizationRefundedPaymentLedger['payments'][0])) {
        $activeAuthorizationRefundedPaymentLedger['payments'][0]['id'] = 990029;
        $activeAuthorizationRefundedPaymentLedger['payments'][0]['remote_id'] = 'active-auth-refunded-payment-ledger-remote';
        $activeAuthorizationRefundedPaymentLedger['payments'][0]['idempotency_key'] = 'active-auth-refunded-payment-ledger-key';
        $activeAuthorizationRefundedPaymentLedger['payments'][0]['status'] = 'refunded';
        $activeAuthorizationRefundedPaymentLedger['authorizations'][] = [
            'id' => 990029,
            'payment_id' => 990029,
            'subject_type' => (string) ($activeAuthorizationRefundedPaymentLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeAuthorizationRefundedPaymentLedger['payments'][0]['subject_id'] ?? 'content:990029'),
            'token_hash' => hash('sha256', 'active-auth-refunded-payment-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeAuthorizationRefundedPaymentLedger['counts']['authorizations'] = count($activeAuthorizationRefundedPaymentLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeAuthorizationRefundedPaymentLedger),
        'Core payment ledger importer rejects active authorizations that reference fully refunded payments before restore commit'
    );
    $activeAuthorizationPendingPaymentLedger = $ledger;
    if (isset($activeAuthorizationPendingPaymentLedger['payments'][0]) && is_array($activeAuthorizationPendingPaymentLedger['payments'][0])) {
        $activeAuthorizationPendingPaymentLedger['payments'][0]['id'] = 990048;
        $activeAuthorizationPendingPaymentLedger['payments'][0]['remote_id'] = 'active-auth-pending-payment-ledger-remote';
        $activeAuthorizationPendingPaymentLedger['payments'][0]['idempotency_key'] = 'active-auth-pending-payment-ledger-key';
        $activeAuthorizationPendingPaymentLedger['payments'][0]['status'] = 'pending';
        $activeAuthorizationPendingPaymentLedger['payments'][0]['authorized_at'] = null;
        $activeAuthorizationPendingPaymentLedger['payments'][0]['paid_at'] = null;
        $activeAuthorizationPendingPaymentLedger['payments'][0]['failed_at'] = null;
        $activeAuthorizationPendingPaymentLedger['payments'][0]['cancelled_at'] = null;
        $activeAuthorizationPendingPaymentLedger['authorizations'][] = [
            'id' => 990048,
            'payment_id' => 990048,
            'subject_type' => (string) ($activeAuthorizationPendingPaymentLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeAuthorizationPendingPaymentLedger['payments'][0]['subject_id'] ?? 'content:990048'),
            'token_hash' => hash('sha256', 'active-auth-pending-payment-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeAuthorizationPendingPaymentLedger['counts']['authorizations'] = count($activeAuthorizationPendingPaymentLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeAuthorizationPendingPaymentLedger),
        'Core payment ledger importer rejects active authorizations that reference untrusted source payments before restore commit'
    );
    $activeExpiredAuthorizationLedger = $ledger;
    if (isset($activeExpiredAuthorizationLedger['payments'][0]) && is_array($activeExpiredAuthorizationLedger['payments'][0])) {
        $activeExpiredAuthorizationLedger['authorizations'][] = [
            'id' => 990057,
            'payment_id' => (int) ($activeExpiredAuthorizationLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($activeExpiredAuthorizationLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeExpiredAuthorizationLedger['payments'][0]['subject_id'] ?? 'content:990057'),
            'token_hash' => hash('sha256', 'active-expired-authorization-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() - 60),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c', time() - 3600),
            'updated_at' => gmdate('c'),
        ];
        $activeExpiredAuthorizationLedger['counts']['authorizations'] = count($activeExpiredAuthorizationLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeExpiredAuthorizationLedger),
        'Core payment ledger importer rejects active authorizations whose expiry is no longer trusted before restore commit'
    );
    $activeAuthorizationWithRevokedAtLedger = $ledger;
    if (isset($activeAuthorizationWithRevokedAtLedger['payments'][0]) && is_array($activeAuthorizationWithRevokedAtLedger['payments'][0])) {
        $activeAuthorizationWithRevokedAtLedger['authorizations'][] = [
            'id' => 990032,
            'payment_id' => (int) ($activeAuthorizationWithRevokedAtLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($activeAuthorizationWithRevokedAtLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeAuthorizationWithRevokedAtLedger['payments'][0]['subject_id'] ?? 'content:990032'),
            'token_hash' => hash('sha256', 'active-authorization-with-revoked-at-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => gmdate('c'),
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeAuthorizationWithRevokedAtLedger['counts']['authorizations'] = count($activeAuthorizationWithRevokedAtLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeAuthorizationWithRevokedAtLedger),
        'Core payment ledger importer rejects active authorizations with revoked_at before restore commit'
    );
    $expiredAuthorizationWithRevokedAtLedger = $ledger;
    if (isset($expiredAuthorizationWithRevokedAtLedger['payments'][0]) && is_array($expiredAuthorizationWithRevokedAtLedger['payments'][0])) {
        $expiredAuthorizationCreatedAt = gmdate('c', time() - 7200);
        $expiredAuthorizationExpiresAt = gmdate('c', time() - 3600);
        $expiredAuthorizationWithRevokedAtLedger['authorizations'][] = [
            'id' => 990059,
            'payment_id' => (int) ($expiredAuthorizationWithRevokedAtLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($expiredAuthorizationWithRevokedAtLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($expiredAuthorizationWithRevokedAtLedger['payments'][0]['subject_id'] ?? 'content:990059'),
            'token_hash' => hash('sha256', 'expired-authorization-with-revoked-at-ledger-token'),
            'status' => 'expired',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => $expiredAuthorizationExpiresAt,
            'revoked_at' => gmdate('c'),
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => $expiredAuthorizationCreatedAt,
            'updated_at' => gmdate('c'),
        ];
        foreach (['created', 'expired'] as $offset => $eventType) {
            $expiredAuthorizationWithRevokedAtLedger['authorization_events'][] = [
                'id' => 990059 + $offset,
                'authorization_id' => 990059,
                'payment_id' => (int) ($expiredAuthorizationWithRevokedAtLedger['payments'][0]['id'] ?? 1),
                'event_type' => $eventType,
                'metadata_json' => '{}',
                'created_at' => $eventType === 'created' ? $expiredAuthorizationCreatedAt : $expiredAuthorizationExpiresAt,
            ];
        }
        $expiredAuthorizationWithRevokedAtLedger['counts']['authorizations'] = count($expiredAuthorizationWithRevokedAtLedger['authorizations']);
        $expiredAuthorizationWithRevokedAtLedger['counts']['authorization_events'] = count($expiredAuthorizationWithRevokedAtLedger['authorization_events']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($expiredAuthorizationWithRevokedAtLedger),
        'Core payment ledger importer rejects expired authorizations with revoked_at before restore commit'
    );
    $revokedAuthorizationMissingRevokedAtLedger = $ledger;
    if (isset($revokedAuthorizationMissingRevokedAtLedger['payments'][0]) && is_array($revokedAuthorizationMissingRevokedAtLedger['payments'][0])) {
        $revokedAuthorizationMissingRevokedAtLedger['authorizations'][] = [
            'id' => 990033,
            'payment_id' => (int) ($revokedAuthorizationMissingRevokedAtLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($revokedAuthorizationMissingRevokedAtLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($revokedAuthorizationMissingRevokedAtLedger['payments'][0]['subject_id'] ?? 'content:990033'),
            'token_hash' => hash('sha256', 'revoked-authorization-missing-revoked-at-ledger-token'),
            'status' => 'revoked',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $revokedAuthorizationMissingRevokedAtLedger['counts']['authorizations'] = count($revokedAuthorizationMissingRevokedAtLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($revokedAuthorizationMissingRevokedAtLedger),
        'Core payment ledger importer rejects revoked authorizations without revoked_at before restore commit'
    );
    $activeEntitlementInactiveAuthorizationLedger = $ledger;
    if (isset($activeEntitlementInactiveAuthorizationLedger['payments'][0]) && is_array($activeEntitlementInactiveAuthorizationLedger['payments'][0])) {
        $activeEntitlementInactiveAuthorizationLedger['authorizations'][] = [
            'id' => 990030,
            'payment_id' => (int) ($activeEntitlementInactiveAuthorizationLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($activeEntitlementInactiveAuthorizationLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeEntitlementInactiveAuthorizationLedger['payments'][0]['subject_id'] ?? 'content:990030'),
            'token_hash' => hash('sha256', 'active-entitlement-inactive-auth-ledger-token'),
            'status' => 'revoked',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => gmdate('c'),
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeEntitlementInactiveAuthorizationLedger['entitlements'][] = [
            'id' => 990030,
            'principal_type' => 'member',
            'principal_id' => 'active-entitlement-inactive-auth-member',
            'subject_type' => (string) ($activeEntitlementInactiveAuthorizationLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeEntitlementInactiveAuthorizationLedger['payments'][0]['subject_id'] ?? 'content:990030'),
            'source_payment_id' => (int) ($activeEntitlementInactiveAuthorizationLedger['payments'][0]['id'] ?? 1),
            'source_authorization_id' => 990030,
            'status' => 'active',
            'expires_at' => null,
            'revoked_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeEntitlementInactiveAuthorizationLedger['counts']['authorizations'] = count($activeEntitlementInactiveAuthorizationLedger['authorizations']);
        $activeEntitlementInactiveAuthorizationLedger['counts']['entitlements'] = count($activeEntitlementInactiveAuthorizationLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeEntitlementInactiveAuthorizationLedger),
        'Core payment ledger importer rejects active entitlements that reference inactive source authorizations before restore commit'
    );
    $activeEntitlementFailedPaymentLedger = $ledger;
    if (isset($activeEntitlementFailedPaymentLedger['payments'][0]) && is_array($activeEntitlementFailedPaymentLedger['payments'][0])) {
        $activeEntitlementFailedPaymentLedger['payments'][0]['id'] = 990049;
        $activeEntitlementFailedPaymentLedger['payments'][0]['remote_id'] = 'active-entitlement-failed-payment-ledger-remote';
        $activeEntitlementFailedPaymentLedger['payments'][0]['idempotency_key'] = 'active-entitlement-failed-payment-ledger-key';
        $activeEntitlementFailedPaymentLedger['payments'][0]['status'] = 'failed';
        $activeEntitlementFailedPaymentLedger['payments'][0]['authorized_at'] = null;
        $activeEntitlementFailedPaymentLedger['payments'][0]['paid_at'] = null;
        $activeEntitlementFailedPaymentLedger['payments'][0]['failed_at'] = gmdate('c');
        $activeEntitlementFailedPaymentLedger['payments'][0]['cancelled_at'] = null;
        $activeEntitlementFailedPaymentLedger['entitlements'][] = [
            'id' => 990049,
            'principal_type' => 'member',
            'principal_id' => 'active-entitlement-failed-payment-member',
            'subject_type' => (string) ($activeEntitlementFailedPaymentLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeEntitlementFailedPaymentLedger['payments'][0]['subject_id'] ?? 'content:990049'),
            'source_payment_id' => 990049,
            'source_authorization_id' => null,
            'status' => 'active',
            'expires_at' => null,
            'revoked_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeEntitlementFailedPaymentLedger['counts']['entitlements'] = count($activeEntitlementFailedPaymentLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeEntitlementFailedPaymentLedger),
        'Core payment ledger importer rejects active entitlements that reference untrusted source payments before restore commit'
    );
    $activeExpiredEntitlementLedger = $ledger;
    if (isset($activeExpiredEntitlementLedger['payments'][0]) && is_array($activeExpiredEntitlementLedger['payments'][0])) {
        $activeExpiredEntitlementLedger['entitlements'][] = [
            'id' => 990058,
            'principal_type' => 'member',
            'principal_id' => 'active-expired-entitlement-member',
            'subject_type' => (string) ($activeExpiredEntitlementLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeExpiredEntitlementLedger['payments'][0]['subject_id'] ?? 'content:990058'),
            'source_payment_id' => (int) ($activeExpiredEntitlementLedger['payments'][0]['id'] ?? 1),
            'source_authorization_id' => null,
            'status' => 'active',
            'expires_at' => gmdate('c', time() - 60),
            'revoked_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c', time() - 3600),
            'updated_at' => gmdate('c'),
        ];
        $activeExpiredEntitlementLedger['counts']['entitlements'] = count($activeExpiredEntitlementLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeExpiredEntitlementLedger),
        'Core payment ledger importer rejects active entitlements whose expiry is no longer trusted before restore commit'
    );
    $activeEntitlementWithRevokedAtLedger = $ledger;
    if (isset($activeEntitlementWithRevokedAtLedger['payments'][0]) && is_array($activeEntitlementWithRevokedAtLedger['payments'][0])) {
        $activeEntitlementWithRevokedAtLedger['entitlements'][] = [
            'id' => 990034,
            'principal_type' => 'member',
            'principal_id' => 'active-entitlement-with-revoked-at-member',
            'subject_type' => (string) ($activeEntitlementWithRevokedAtLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeEntitlementWithRevokedAtLedger['payments'][0]['subject_id'] ?? 'content:990034'),
            'source_payment_id' => (int) ($activeEntitlementWithRevokedAtLedger['payments'][0]['id'] ?? 1),
            'source_authorization_id' => null,
            'status' => 'active',
            'expires_at' => null,
            'revoked_at' => gmdate('c'),
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $activeEntitlementWithRevokedAtLedger['counts']['entitlements'] = count($activeEntitlementWithRevokedAtLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeEntitlementWithRevokedAtLedger),
        'Core payment ledger importer rejects active entitlements with revoked_at before restore commit'
    );
    $expiredEntitlementWithRevokedAtLedger = $ledger;
    if (isset($expiredEntitlementWithRevokedAtLedger['payments'][0]) && is_array($expiredEntitlementWithRevokedAtLedger['payments'][0])) {
        $expiredEntitlementWithRevokedAtLedger['entitlements'][] = [
            'id' => 990060,
            'principal_type' => 'member',
            'principal_id' => 'expired-entitlement-with-revoked-at-member',
            'subject_type' => (string) ($expiredEntitlementWithRevokedAtLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($expiredEntitlementWithRevokedAtLedger['payments'][0]['subject_id'] ?? 'content:990060'),
            'source_payment_id' => (int) ($expiredEntitlementWithRevokedAtLedger['payments'][0]['id'] ?? 1),
            'source_authorization_id' => null,
            'status' => 'expired',
            'expires_at' => gmdate('c', time() - 3600),
            'revoked_at' => gmdate('c'),
            'metadata_json' => '{}',
            'created_at' => gmdate('c', time() - 7200),
            'updated_at' => gmdate('c'),
        ];
        $expiredEntitlementWithRevokedAtLedger['counts']['entitlements'] = count($expiredEntitlementWithRevokedAtLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($expiredEntitlementWithRevokedAtLedger),
        'Core payment ledger importer rejects expired entitlements with revoked_at before restore commit'
    );
    $revokedEntitlementMissingRevokedAtLedger = $ledger;
    if (isset($revokedEntitlementMissingRevokedAtLedger['payments'][0]) && is_array($revokedEntitlementMissingRevokedAtLedger['payments'][0])) {
        $revokedEntitlementMissingRevokedAtLedger['entitlements'][] = [
            'id' => 990035,
            'principal_type' => 'member',
            'principal_id' => 'revoked-entitlement-missing-revoked-at-member',
            'subject_type' => (string) ($revokedEntitlementMissingRevokedAtLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($revokedEntitlementMissingRevokedAtLedger['payments'][0]['subject_id'] ?? 'content:990035'),
            'source_payment_id' => (int) ($revokedEntitlementMissingRevokedAtLedger['payments'][0]['id'] ?? 1),
            'source_authorization_id' => null,
            'status' => 'revoked',
            'expires_at' => null,
            'revoked_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $revokedEntitlementMissingRevokedAtLedger['counts']['entitlements'] = count($revokedEntitlementMissingRevokedAtLedger['entitlements']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($revokedEntitlementMissingRevokedAtLedger),
        'Core payment ledger importer rejects revoked entitlements without revoked_at before restore commit'
    );
    $invalidAuthorizationEventTypeLedger = $ledger;
    if (isset($invalidAuthorizationEventTypeLedger['payments'][0]) && is_array($invalidAuthorizationEventTypeLedger['payments'][0])) {
        $invalidAuthorizationEventTypeLedger['authorizations'][] = [
            'id' => 990031,
            'payment_id' => (int) ($invalidAuthorizationEventTypeLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($invalidAuthorizationEventTypeLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($invalidAuthorizationEventTypeLedger['payments'][0]['subject_id'] ?? 'content:990031'),
            'token_hash' => hash('sha256', 'invalid-authorization-event-type-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $invalidAuthorizationEventTypeLedger['authorization_events'][] = [
            'id' => 990031,
            'authorization_id' => 990031,
            'payment_id' => (int) ($invalidAuthorizationEventTypeLedger['payments'][0]['id'] ?? 1),
            'event_type' => 'reopened',
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
        ];
        $invalidAuthorizationEventTypeLedger['counts']['authorizations'] = count($invalidAuthorizationEventTypeLedger['authorizations']);
        $invalidAuthorizationEventTypeLedger['counts']['authorization_events'] = count($invalidAuthorizationEventTypeLedger['authorization_events']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidAuthorizationEventTypeLedger),
        'Core payment ledger importer rejects unknown authorization event types before restore commit'
    );
    $mixedCaseStatusLedger = $ledger;
    if (isset($mixedCaseStatusLedger['payments'][0]) && is_array($mixedCaseStatusLedger['payments'][0])) {
        $mixedCaseStatusLedger['payments'][0]['id'] = 990000;
        $mixedCaseStatusLedger['payments'][0]['idempotency_key'] = 'mixed-case-status-ledger-key';
        $mixedCaseStatusLedger['payments'][0]['remote_id'] = 'mixed-case-status-ledger-remote';
        $mixedCaseStatusLedger['payments'][0]['status'] = 'PAID';
    }
    $mixedCaseStatusPdo = new PDO('sqlite::memory:');
    (new MigrationRunner($mixedCaseStatusPdo, $migrations))->run();
    core_payment_throws(
        static fn () => (new CorePaymentLedgerImporter($mixedCaseStatusPdo, $reader))->importLedger($mixedCaseStatusLedger),
        'Core payment ledger importer rejects non-canonical status case before restore'
    );
    $spacedStatusLedger = $ledger;
    if (isset($spacedStatusLedger['payments'][0]) && is_array($spacedStatusLedger['payments'][0])) {
        $spacedStatusLedger['payments'][0]['id'] = 990002;
        $spacedStatusLedger['payments'][0]['idempotency_key'] = 'spaced-status-ledger-key';
        $spacedStatusLedger['payments'][0]['remote_id'] = 'spaced-status-ledger-remote';
        $spacedStatusLedger['payments'][0]['status'] = ' paid ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($spacedStatusLedger),
        'Core payment ledger importer rejects whitespace-padded statuses before restore'
    );
    $invalidStatusLedger = $ledger;
    if (isset($invalidStatusLedger['payments'][0]) && is_array($invalidStatusLedger['payments'][0])) {
        $invalidStatusLedger['payments'][0]['id'] = 990001;
        $invalidStatusLedger['payments'][0]['idempotency_key'] = 'invalid-status-ledger-key';
        $invalidStatusLedger['payments'][0]['status'] = 'chargeback_pending';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidStatusLedger),
        'Core payment ledger importer rejects unknown payment statuses before restore'
    );
    $invalidAmountLedger = $ledger;
    if (isset($invalidAmountLedger['payments'][0]) && is_array($invalidAmountLedger['payments'][0])) {
        $invalidAmountLedger['payments'][0]['id'] = 990003;
        $invalidAmountLedger['payments'][0]['idempotency_key'] = 'invalid-amount-ledger-key';
        $invalidAmountLedger['payments'][0]['amount_minor'] = -1;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidAmountLedger),
        'Core payment ledger importer rejects non-positive payment amounts before restore'
    );
    $nonCanonicalAmountLedger = $ledger;
    if (isset($nonCanonicalAmountLedger['payments'][0]) && is_array($nonCanonicalAmountLedger['payments'][0])) {
        $nonCanonicalAmountLedger['payments'][0]['id'] = 990023;
        $nonCanonicalAmountLedger['payments'][0]['remote_id'] = 'non-canonical-amount-ledger-remote';
        $nonCanonicalAmountLedger['payments'][0]['idempotency_key'] = 'non-canonical-amount-ledger-key';
        $nonCanonicalAmountLedger['payments'][0]['amount_minor'] = ' 100 ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalAmountLedger),
        'Core payment ledger importer rejects non-canonical integer amounts before restore'
    );
    $oversizedAmountLedger = $ledger;
    if (isset($oversizedAmountLedger['payments'][0]) && is_array($oversizedAmountLedger['payments'][0])) {
        $oversizedAmountLedger['payments'][0]['id'] = 990024;
        $oversizedAmountLedger['payments'][0]['remote_id'] = 'oversized-amount-ledger-remote';
        $oversizedAmountLedger['payments'][0]['idempotency_key'] = 'oversized-amount-ledger-key';
        $oversizedAmountLedger['payments'][0]['amount_minor'] = '123456789012345678901234567890';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($oversizedAmountLedger),
        'Core payment ledger importer rejects oversized integer amount strings before restore'
    );
    $nonCanonicalRefundAmountLedger = $ledger;
    if (isset($nonCanonicalRefundAmountLedger['payments'][0]) && is_array($nonCanonicalRefundAmountLedger['payments'][0])) {
        $nonCanonicalRefundAmountLedger['payments'][0]['id'] = 990058;
        $nonCanonicalRefundAmountLedger['payments'][0]['remote_id'] = 'non-canonical-refund-amount-ledger-remote';
        $nonCanonicalRefundAmountLedger['payments'][0]['idempotency_key'] = 'non-canonical-refund-amount-ledger-key';
        $nonCanonicalRefundAmountLedger['payments'][0]['status'] = 'partially_refunded';
        $nonCanonicalRefundAmountLedger['payments'][0]['amount_minor'] = 100;
        $nonCanonicalRefundAmountLedger['payments'][0]['paid_at'] = gmdate('c');
        $nonCanonicalRefundAmountLedger['refunds'][] = [
            'id' => 990058,
            'payment_id' => 990058,
            'provider_id' => (string) ($nonCanonicalRefundAmountLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'remote_id' => 'non-canonical-refund-amount-refund-ledger-remote',
            'status' => 'completed',
            'amount_minor' => '50abc',
            'currency' => (string) ($nonCanonicalRefundAmountLedger['payments'][0]['currency'] ?? 'USD'),
            'reason' => 'non canonical refund amount restore',
            'idempotency_key' => 'non-canonical-refund-amount-refund-ledger-key',
            'request_hash' => hash('sha256', 'non-canonical-refund-amount-refund-ledger'),
            'metadata_json' => '{}',
            'completed_at' => gmdate('c'),
            'failed_at' => null,
            'cancelled_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalRefundAmountLedger['counts']['refunds'] = count($nonCanonicalRefundAmountLedger['refunds']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalRefundAmountLedger),
        'Core payment ledger importer rejects non-canonical refund amounts before restore'
    );
    $nonCanonicalCurrencyLedger = $ledger;
    if (isset($nonCanonicalCurrencyLedger['payments'][0]) && is_array($nonCanonicalCurrencyLedger['payments'][0])) {
        $nonCanonicalCurrencyLedger['payments'][0]['id'] = 990019;
        $nonCanonicalCurrencyLedger['payments'][0]['remote_id'] = 'non-canonical-currency-ledger-remote';
        $nonCanonicalCurrencyLedger['payments'][0]['idempotency_key'] = 'non-canonical-currency-ledger-key';
        $nonCanonicalCurrencyLedger['payments'][0]['currency'] = ' usd ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalCurrencyLedger),
        'Core payment ledger importer rejects non-canonical currencies before restore'
    );
    $invalidUseCountLedger = $ledger;
    if (isset($invalidUseCountLedger['payments'][0]) && is_array($invalidUseCountLedger['payments'][0])) {
        $invalidUseCountLedger['authorizations'][] = [
            'id' => 990004,
            'payment_id' => (int) ($invalidUseCountLedger['payments'][0]['id'] ?? 1),
            'subject_type' => 'paid_download',
            'subject_id' => 'invalid-use-count-ledger',
            'token_hash' => hash('sha256', 'invalid-use-count-ledger-token'),
            'status' => 'active',
            'max_uses' => 1,
            'used_count' => -1,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $invalidUseCountLedger['counts']['authorizations'] = count($invalidUseCountLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidUseCountLedger),
        'Core payment ledger importer rejects negative authorization use counts before restore'
    );
    $nonCanonicalUseCountLedger = $ledger;
    if (isset($nonCanonicalUseCountLedger['payments'][0]) && is_array($nonCanonicalUseCountLedger['payments'][0])) {
        $nonCanonicalUseCountLedger['authorizations'][] = [
            'id' => 990024,
            'payment_id' => (int) ($nonCanonicalUseCountLedger['payments'][0]['id'] ?? 1),
            'subject_type' => 'paid_download',
            'subject_id' => 'non-canonical-use-count-ledger',
            'token_hash' => hash('sha256', 'non-canonical-use-count-ledger-token'),
            'status' => 'active',
            'max_uses' => '01',
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalUseCountLedger['counts']['authorizations'] = count($nonCanonicalUseCountLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalUseCountLedger),
        'Core payment ledger importer rejects non-canonical authorization counters before restore'
    );
    $overUsedAuthorizationLedger = $ledger;
    if (isset($overUsedAuthorizationLedger['payments'][0]) && is_array($overUsedAuthorizationLedger['payments'][0])) {
        $overUsedAuthorizationLedger['authorizations'][] = [
            'id' => 990050,
            'payment_id' => (int) ($overUsedAuthorizationLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($overUsedAuthorizationLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($overUsedAuthorizationLedger['payments'][0]['subject_id'] ?? 'content:990050'),
            'token_hash' => hash('sha256', 'over-used-authorization-ledger-token'),
            'status' => 'active',
            'max_uses' => 1,
            'used_count' => 2,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => gmdate('c'),
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $overUsedAuthorizationLedger['counts']['authorizations'] = count($overUsedAuthorizationLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($overUsedAuthorizationLedger),
        'Core payment ledger importer rejects authorization counters that exceed max uses before restore'
    );
    $usedAuthorizationMissingLastUsedLedger = $ledger;
    if (isset($usedAuthorizationMissingLastUsedLedger['payments'][0]) && is_array($usedAuthorizationMissingLastUsedLedger['payments'][0])) {
        $usedAuthorizationMissingLastUsedLedger['authorizations'][] = [
            'id' => 990051,
            'payment_id' => (int) ($usedAuthorizationMissingLastUsedLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($usedAuthorizationMissingLastUsedLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($usedAuthorizationMissingLastUsedLedger['payments'][0]['subject_id'] ?? 'content:990051'),
            'token_hash' => hash('sha256', 'used-authorization-missing-last-used-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 1,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $usedAuthorizationMissingLastUsedLedger['counts']['authorizations'] = count($usedAuthorizationMissingLastUsedLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($usedAuthorizationMissingLastUsedLedger),
        'Core payment ledger importer rejects used authorizations without last_used_at before restore'
    );
    $unusedAuthorizationWithLastUsedLedger = $ledger;
    if (isset($unusedAuthorizationWithLastUsedLedger['payments'][0]) && is_array($unusedAuthorizationWithLastUsedLedger['payments'][0])) {
        $unusedAuthorizationWithLastUsedLedger['authorizations'][] = [
            'id' => 990052,
            'payment_id' => (int) ($unusedAuthorizationWithLastUsedLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($unusedAuthorizationWithLastUsedLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($unusedAuthorizationWithLastUsedLedger['payments'][0]['subject_id'] ?? 'content:990052'),
            'token_hash' => hash('sha256', 'unused-authorization-with-last-used-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => gmdate('c'),
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $unusedAuthorizationWithLastUsedLedger['counts']['authorizations'] = count($unusedAuthorizationWithLastUsedLedger['authorizations']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($unusedAuthorizationWithLastUsedLedger),
        'Core payment ledger importer rejects unused authorizations with last_used_at before restore'
    );
    $usedAuthorizationMissingConsumedEventLedger = $ledger;
    if (isset($usedAuthorizationMissingConsumedEventLedger['payments'][0]) && is_array($usedAuthorizationMissingConsumedEventLedger['payments'][0])) {
        $usedAuthorizationMissingConsumedEventLedger['authorizations'][] = [
            'id' => 990053,
            'payment_id' => (int) ($usedAuthorizationMissingConsumedEventLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($usedAuthorizationMissingConsumedEventLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($usedAuthorizationMissingConsumedEventLedger['payments'][0]['subject_id'] ?? 'content:990053'),
            'token_hash' => hash('sha256', 'used-authorization-missing-consumed-event-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 1,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => gmdate('c'),
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $usedAuthorizationMissingConsumedEventLedger['authorization_events'][] = [
            'id' => 990053,
            'authorization_id' => 990053,
            'payment_id' => (int) ($usedAuthorizationMissingConsumedEventLedger['payments'][0]['id'] ?? 1),
            'event_type' => 'created',
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
        ];
        $usedAuthorizationMissingConsumedEventLedger['counts']['authorizations'] = count($usedAuthorizationMissingConsumedEventLedger['authorizations']);
        $usedAuthorizationMissingConsumedEventLedger['counts']['authorization_events'] = count($usedAuthorizationMissingConsumedEventLedger['authorization_events']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($usedAuthorizationMissingConsumedEventLedger),
        'Core payment ledger importer rejects used authorizations whose consumed events do not match used_count before restore'
    );
    $activeAuthorizationWithRevokedEventLedger = $ledger;
    if (isset($activeAuthorizationWithRevokedEventLedger['payments'][0]) && is_array($activeAuthorizationWithRevokedEventLedger['payments'][0])) {
        $activeAuthorizationWithRevokedEventLedger['authorizations'][] = [
            'id' => 990054,
            'payment_id' => (int) ($activeAuthorizationWithRevokedEventLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($activeAuthorizationWithRevokedEventLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($activeAuthorizationWithRevokedEventLedger['payments'][0]['subject_id'] ?? 'content:990054'),
            'token_hash' => hash('sha256', 'active-authorization-with-revoked-event-ledger-token'),
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => null,
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        foreach (['created', 'revoked'] as $offset => $eventType) {
            $activeAuthorizationWithRevokedEventLedger['authorization_events'][] = [
                'id' => 990054 + $offset,
                'authorization_id' => 990054,
                'payment_id' => (int) ($activeAuthorizationWithRevokedEventLedger['payments'][0]['id'] ?? 1),
                'event_type' => $eventType,
                'metadata_json' => '{}',
                'created_at' => gmdate('c'),
            ];
        }
        $activeAuthorizationWithRevokedEventLedger['counts']['authorizations'] = count($activeAuthorizationWithRevokedEventLedger['authorizations']);
        $activeAuthorizationWithRevokedEventLedger['counts']['authorization_events'] = count($activeAuthorizationWithRevokedEventLedger['authorization_events']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($activeAuthorizationWithRevokedEventLedger),
        'Core payment ledger importer rejects active authorizations with terminal events before restore'
    );
    $revokedAuthorizationMissingEventLedger = $ledger;
    if (isset($revokedAuthorizationMissingEventLedger['payments'][0]) && is_array($revokedAuthorizationMissingEventLedger['payments'][0])) {
        $revokedAuthorizationMissingEventLedger['authorizations'][] = [
            'id' => 990056,
            'payment_id' => (int) ($revokedAuthorizationMissingEventLedger['payments'][0]['id'] ?? 1),
            'subject_type' => (string) ($revokedAuthorizationMissingEventLedger['payments'][0]['subject_type'] ?? 'paid_content'),
            'subject_id' => (string) ($revokedAuthorizationMissingEventLedger['payments'][0]['subject_id'] ?? 'content:990056'),
            'token_hash' => hash('sha256', 'revoked-authorization-missing-event-ledger-token'),
            'status' => 'revoked',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => gmdate('c', time() + 3600),
            'revoked_at' => gmdate('c'),
            'last_used_at' => null,
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $revokedAuthorizationMissingEventLedger['authorization_events'][] = [
            'id' => 990056,
            'authorization_id' => 990056,
            'payment_id' => (int) ($revokedAuthorizationMissingEventLedger['payments'][0]['id'] ?? 1),
            'event_type' => 'created',
            'metadata_json' => '{}',
            'created_at' => gmdate('c'),
        ];
        $revokedAuthorizationMissingEventLedger['counts']['authorizations'] = count($revokedAuthorizationMissingEventLedger['authorizations']);
        $revokedAuthorizationMissingEventLedger['counts']['authorization_events'] = count($revokedAuthorizationMissingEventLedger['authorization_events']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($revokedAuthorizationMissingEventLedger),
        'Core payment ledger importer rejects revoked authorizations without revoke events before restore'
    );
    $invalidJsonLedger = $ledger;
    if (isset($invalidJsonLedger['payments'][0]) && is_array($invalidJsonLedger['payments'][0])) {
        $invalidJsonLedger['payments'][0]['id'] = 990002;
        $invalidJsonLedger['payments'][0]['idempotency_key'] = 'invalid-json-ledger-key';
        $invalidJsonLedger['payments'][0]['metadata_json'] = '{"broken":';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidJsonLedger),
        'Core payment ledger importer rejects invalid JSON metadata before restore'
    );
    $nonCanonicalJsonLedger = $ledger;
    if (isset($nonCanonicalJsonLedger['payments'][0]) && is_array($nonCanonicalJsonLedger['payments'][0])) {
        $nonCanonicalJsonLedger['payments'][0]['id'] = 990021;
        $nonCanonicalJsonLedger['payments'][0]['remote_id'] = 'non-canonical-json-ledger-remote';
        $nonCanonicalJsonLedger['payments'][0]['idempotency_key'] = 'non-canonical-json-ledger-key';
        $nonCanonicalJsonLedger['payments'][0]['metadata_json'] = ' ' . (string) ($nonCanonicalJsonLedger['payments'][0]['metadata_json'] ?? '{}') . ' ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalJsonLedger),
        'Core payment ledger importer rejects whitespace-padded JSON columns before restore'
    );
    $formattedJsonLedger = $ledger;
    if (isset($formattedJsonLedger['payments'][0]) && is_array($formattedJsonLedger['payments'][0])) {
        $formattedJsonLedger['payments'][0]['id'] = 990023;
        $formattedJsonLedger['payments'][0]['remote_id'] = 'formatted-json-ledger-remote';
        $formattedJsonLedger['payments'][0]['idempotency_key'] = 'formatted-json-ledger-key';
        $formattedJsonLedger['payments'][0]['metadata_json'] = '{"safe_note": "formatted restore metadata"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($formattedJsonLedger),
        'Core payment ledger importer rejects formatted non-canonical JSON metadata before restore'
    );
    $unsafeMetadataLedger = $ledger;
    if (isset($unsafeMetadataLedger['payments'][0]) && is_array($unsafeMetadataLedger['payments'][0])) {
        $unsafeMetadataLedger['payments'][0]['id'] = 990011;
        $unsafeMetadataLedger['payments'][0]['remote_id'] = 'unsafe-metadata-ledger-remote';
        $unsafeMetadataLedger['payments'][0]['idempotency_key'] = 'unsafe-metadata-ledger-key';
        $unsafeMetadataLedger['payments'][0]['metadata_json'] = json_encode(['customer_email' => 'restore@example.test'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($unsafeMetadataLedger),
        'Core payment ledger importer rejects unredacted sensitive metadata before restore'
    );
    $unsafeKeyMetadataLedger = $ledger;
    if (isset($unsafeKeyMetadataLedger['payments'][0]) && is_array($unsafeKeyMetadataLedger['payments'][0])) {
        $unsafeKeyMetadataLedger['payments'][0]['id'] = 990016;
        $unsafeKeyMetadataLedger['payments'][0]['remote_id'] = 'unsafe-key-metadata-ledger-remote';
        $unsafeKeyMetadataLedger['payments'][0]['idempotency_key'] = 'unsafe-key-metadata-ledger-key';
        $unsafeKeyMetadataLedger['payments'][0]['metadata_json'] = json_encode(['api_key' => 'restore-api-key'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($unsafeKeyMetadataLedger),
        'Core payment ledger importer rejects unredacted key-like metadata before restore'
    );
    $unsafeValueMetadataLedger = $ledger;
    if (isset($unsafeValueMetadataLedger['payments'][0]) && is_array($unsafeValueMetadataLedger['payments'][0])) {
        $unsafeValueMetadataLedger['payments'][0]['id'] = 990017;
        $unsafeValueMetadataLedger['payments'][0]['remote_id'] = 'unsafe-value-metadata-ledger-remote';
        $unsafeValueMetadataLedger['payments'][0]['idempotency_key'] = 'unsafe-value-metadata-ledger-key';
        $unsafeValueMetadataLedger['payments'][0]['metadata_json'] = json_encode(['operator_note' => 'Bearer raw-restore-token'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($unsafeValueMetadataLedger),
        'Core payment ledger importer rejects token-like metadata values before restore'
    );
    $encodedUnsafeValueMetadataLedger = $ledger;
    if (isset($encodedUnsafeValueMetadataLedger['payments'][0]) && is_array($encodedUnsafeValueMetadataLedger['payments'][0])) {
        $encodedUnsafeValueMetadataLedger['payments'][0]['id'] = 990067;
        $encodedUnsafeValueMetadataLedger['payments'][0]['remote_id'] = 'encoded-unsafe-value-metadata-ledger-remote';
        $encodedUnsafeValueMetadataLedger['payments'][0]['idempotency_key'] = 'encoded-unsafe-value-metadata-ledger-key';
        $encodedUnsafeValueMetadataLedger['payments'][0]['metadata_json'] = json_encode(['operator_note' => 'payment_token%3Draw-restore-encoded-token'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($encodedUnsafeValueMetadataLedger),
        'Core payment ledger importer rejects URL-encoded token-like metadata values before restore'
    );
    $unsafeUrlMetadataLedger = $ledger;
    if (isset($unsafeUrlMetadataLedger['payments'][0]) && is_array($unsafeUrlMetadataLedger['payments'][0])) {
        $unsafeUrlMetadataLedger['payments'][0]['id'] = 990012;
        $unsafeUrlMetadataLedger['payments'][0]['remote_id'] = 'unsafe-url-metadata-ledger-remote';
        $unsafeUrlMetadataLedger['payments'][0]['idempotency_key'] = 'unsafe-url-metadata-ledger-key';
        $unsafeUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://payments.example.test/checkout?claim=raw-claim'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($unsafeUrlMetadataLedger),
        'Core payment ledger importer rejects unredacted metadata URLs before restore'
    );
    $nonCanonicalUrlMetadataLedger = $ledger;
    if (isset($nonCanonicalUrlMetadataLedger['payments'][0]) && is_array($nonCanonicalUrlMetadataLedger['payments'][0])) {
        $nonCanonicalUrlMetadataLedger['payments'][0]['id'] = 990022;
        $nonCanonicalUrlMetadataLedger['payments'][0]['remote_id'] = 'non-canonical-url-metadata-ledger-remote';
        $nonCanonicalUrlMetadataLedger['payments'][0]['idempotency_key'] = 'non-canonical-url-metadata-ledger-key';
        $nonCanonicalUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => ' https://payments.example.test/checkout '], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalUrlMetadataLedger),
        'Core payment ledger importer rejects non-canonical metadata URLs before restore'
    );
    $fragmentUrlMetadataLedger = $ledger;
    if (isset($fragmentUrlMetadataLedger['payments'][0]) && is_array($fragmentUrlMetadataLedger['payments'][0])) {
        $fragmentUrlMetadataLedger['payments'][0]['id'] = 990062;
        $fragmentUrlMetadataLedger['payments'][0]['remote_id'] = 'fragment-url-metadata-ledger-remote';
        $fragmentUrlMetadataLedger['payments'][0]['idempotency_key'] = 'fragment-url-metadata-ledger-key';
        $fragmentUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://payments.example.test/checkout#claim'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($fragmentUrlMetadataLedger),
        'Core payment ledger importer rejects metadata URLs with fragments before restore'
    );
    $userinfoUrlMetadataLedger = $ledger;
    if (isset($userinfoUrlMetadataLedger['payments'][0]) && is_array($userinfoUrlMetadataLedger['payments'][0])) {
        $userinfoUrlMetadataLedger['payments'][0]['id'] = 990063;
        $userinfoUrlMetadataLedger['payments'][0]['remote_id'] = 'userinfo-url-metadata-ledger-remote';
        $userinfoUrlMetadataLedger['payments'][0]['idempotency_key'] = 'userinfo-url-metadata-ledger-key';
        $userinfoUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://operator:secret@payments.example.test/checkout'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($userinfoUrlMetadataLedger),
        'Core payment ledger importer rejects metadata URLs with userinfo before restore'
    );
    $tokenPathUrlMetadataLedger = $ledger;
    if (isset($tokenPathUrlMetadataLedger['payments'][0]) && is_array($tokenPathUrlMetadataLedger['payments'][0])) {
        $tokenPathUrlMetadataLedger['payments'][0]['id'] = 990064;
        $tokenPathUrlMetadataLedger['payments'][0]['remote_id'] = 'token-path-url-metadata-ledger-remote';
        $tokenPathUrlMetadataLedger['payments'][0]['idempotency_key'] = 'token-path-url-metadata-ledger-key';
        $tokenPathUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://payments.example.test/checkout/sk_live_restore_path_token'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenPathUrlMetadataLedger),
        'Core payment ledger importer rejects token-like metadata URL paths before restore'
    );
    $encodedTokenPathUrlMetadataLedger = $ledger;
    if (isset($encodedTokenPathUrlMetadataLedger['payments'][0]) && is_array($encodedTokenPathUrlMetadataLedger['payments'][0])) {
        $encodedTokenPathUrlMetadataLedger['payments'][0]['id'] = 990068;
        $encodedTokenPathUrlMetadataLedger['payments'][0]['remote_id'] = 'encoded-token-path-url-metadata-ledger-remote';
        $encodedTokenPathUrlMetadataLedger['payments'][0]['idempotency_key'] = 'encoded-token-path-url-metadata-ledger-key';
        $encodedTokenPathUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://payments.example.test/checkout/payment_token%3Draw-restore-path-token'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($encodedTokenPathUrlMetadataLedger),
        'Core payment ledger importer rejects URL-encoded token-like metadata URL paths before restore'
    );
    $tokenQueryUrlMetadataLedger = $ledger;
    if (isset($tokenQueryUrlMetadataLedger['payments'][0]) && is_array($tokenQueryUrlMetadataLedger['payments'][0])) {
        $tokenQueryUrlMetadataLedger['payments'][0]['id'] = 990065;
        $tokenQueryUrlMetadataLedger['payments'][0]['remote_id'] = 'token-query-url-metadata-ledger-remote';
        $tokenQueryUrlMetadataLedger['payments'][0]['idempotency_key'] = 'token-query-url-metadata-ledger-key';
        $tokenQueryUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://payments.example.test/checkout?safe=sk_live_restore_query_token'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenQueryUrlMetadataLedger),
        'Core payment ledger importer rejects token-like metadata URL query values before restore'
    );
    $arrayQueryUrlMetadataLedger = $ledger;
    if (isset($arrayQueryUrlMetadataLedger['payments'][0]) && is_array($arrayQueryUrlMetadataLedger['payments'][0])) {
        $arrayQueryUrlMetadataLedger['payments'][0]['id'] = 990073;
        $arrayQueryUrlMetadataLedger['payments'][0]['remote_id'] = 'array-query-url-metadata-ledger-remote';
        $arrayQueryUrlMetadataLedger['payments'][0]['idempotency_key'] = 'array-query-url-metadata-ledger-key';
        $arrayQueryUrlMetadataLedger['payments'][0]['metadata_json'] = json_encode(['checkout_url' => 'https://payments.example.test/checkout?safe[]=restore-array-value'], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($arrayQueryUrlMetadataLedger),
        'Core payment ledger importer rejects array-shaped metadata URL query values before restore'
    );
    $nestedMetadataLedger = $ledger;
    if (isset($nestedMetadataLedger['payments'][0]) && is_array($nestedMetadataLedger['payments'][0])) {
        $nestedMetadataLedger['payments'][0]['id'] = 990013;
        $nestedMetadataLedger['payments'][0]['remote_id'] = 'nested-metadata-ledger-remote';
        $nestedMetadataLedger['payments'][0]['idempotency_key'] = 'nested-metadata-ledger-key';
        $nestedMetadataLedger['payments'][0]['metadata_json'] = json_encode(['safe_note' => ['nested' => true]], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nestedMetadataLedger),
        'Core payment ledger importer rejects nested metadata before restore'
    );
    $nonCanonicalManualInstructionsLedger = $ledger;
    if (isset($nonCanonicalManualInstructionsLedger['payments'][0]) && is_array($nonCanonicalManualInstructionsLedger['payments'][0])) {
        $nonCanonicalManualInstructionsLedger['payments'][0]['id'] = 990014;
        $nonCanonicalManualInstructionsLedger['payments'][0]['remote_id'] = 'non-canonical-manual-instructions-ledger-remote';
        $nonCanonicalManualInstructionsLedger['payments'][0]['idempotency_key'] = 'non-canonical-manual-instructions-ledger-key';
        $nonCanonicalManualInstructionsLedger['payments'][0]['metadata_json'] = json_encode(['manual_instructions' => ' Manual restore instructions '], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalManualInstructionsLedger),
        'Core payment ledger importer rejects non-canonical manual instructions metadata before restore'
    );
    $nonCanonicalManualReferenceLedger = $ledger;
    if (isset($nonCanonicalManualReferenceLedger['payments'][0]) && is_array($nonCanonicalManualReferenceLedger['payments'][0])) {
        $nonCanonicalManualReferenceLedger['payments'][0]['id'] = 990015;
        $nonCanonicalManualReferenceLedger['payments'][0]['remote_id'] = 'non-canonical-manual-reference-ledger-remote';
        $nonCanonicalManualReferenceLedger['payments'][0]['idempotency_key'] = 'non-canonical-manual-reference-ledger-key';
        $nonCanonicalManualReferenceLedger['payments'][0]['metadata_json'] = json_encode(['manual_reference' => ' manual-ref '], JSON_UNESCAPED_SLASHES);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalManualReferenceLedger),
        'Core payment ledger importer rejects non-canonical manual reference metadata before restore'
    );
    $invalidHashLedger = $ledger;
    if (isset($invalidHashLedger['payments'][0]) && is_array($invalidHashLedger['payments'][0])) {
        $invalidHashLedger['payments'][0]['id'] = 990008;
        $invalidHashLedger['payments'][0]['remote_id'] = 'invalid-hash-ledger-remote';
        $invalidHashLedger['payments'][0]['idempotency_key'] = 'invalid-hash-ledger-key';
        $invalidHashLedger['payments'][0]['request_hash'] = 'not-a-sha256-hash';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidHashLedger),
        'Core payment ledger importer rejects malformed hash columns before restore'
    );
    $nonCanonicalHashLedger = $ledger;
    if (isset($nonCanonicalHashLedger['payments'][0]) && is_array($nonCanonicalHashLedger['payments'][0])) {
        $nonCanonicalHashLedger['payments'][0]['id'] = 990018;
        $nonCanonicalHashLedger['payments'][0]['remote_id'] = 'non-canonical-hash-ledger-remote';
        $nonCanonicalHashLedger['payments'][0]['idempotency_key'] = 'non-canonical-hash-ledger-key';
        $nonCanonicalHashLedger['payments'][0]['request_hash'] = strtoupper((string) ($nonCanonicalHashLedger['payments'][0]['request_hash'] ?? hash('sha256', 'non-canonical-hash-ledger')));
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalHashLedger),
        'Core payment ledger importer rejects non-canonical hash columns before restore'
    );
    $invalidProviderIdLedger = $ledger;
    if (isset($invalidProviderIdLedger['provider_settings'][0]) && is_array($invalidProviderIdLedger['provider_settings'][0])) {
        $invalidProviderIdLedger['provider_settings'][0]['provider_id'] = 'bad provider';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderIdLedger),
        'Core payment ledger importer rejects unsafe Provider ids before restore'
    );
    $nonCanonicalProviderIdLedger = $ledger;
    if (isset($nonCanonicalProviderIdLedger['provider_settings'][0]) && is_array($nonCanonicalProviderIdLedger['provider_settings'][0])) {
        $nonCanonicalProviderIdLedger['provider_settings'][0]['provider_id'] = ' ' . FixturePaymentProvider::PROVIDER_ID . ' ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalProviderIdLedger),
        'Core payment ledger importer rejects non-canonical Provider ids before restore'
    );
    $nonCanonicalTypeLedger = $ledger;
    if (isset($nonCanonicalTypeLedger['payments'][0]) && is_array($nonCanonicalTypeLedger['payments'][0])) {
        $nonCanonicalTypeLedger['payments'][0]['id'] = 990020;
        $nonCanonicalTypeLedger['payments'][0]['remote_id'] = 'non-canonical-type-ledger-remote';
        $nonCanonicalTypeLedger['payments'][0]['idempotency_key'] = 'non-canonical-type-ledger-key';
        $nonCanonicalTypeLedger['payments'][0]['subject_type'] = ' Paid_Download ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalTypeLedger),
        'Core payment ledger importer rejects non-canonical type columns before restore'
    );
    $nonStringSubjectIdLedger = $ledger;
    if (isset($nonStringSubjectIdLedger['payments'][0]) && is_array($nonStringSubjectIdLedger['payments'][0])) {
        $nonStringSubjectIdLedger['payments'][0]['id'] = 990025;
        $nonStringSubjectIdLedger['payments'][0]['remote_id'] = 'non-string-subject-id-ledger-remote';
        $nonStringSubjectIdLedger['payments'][0]['idempotency_key'] = 'non-string-subject-id-ledger-key';
        $nonStringSubjectIdLedger['payments'][0]['subject_id'] = 12345;
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonStringSubjectIdLedger),
        'Core payment ledger importer rejects non-string payment subject ids before restore'
    );
    $invalidProviderDisplayNameLedger = $ledger;
    if (isset($invalidProviderDisplayNameLedger['provider_settings'][0]) && is_array($invalidProviderDisplayNameLedger['provider_settings'][0])) {
        $invalidProviderDisplayNameLedger['provider_settings'][0]['provider_id'] = 'core.invalid-display-name-restore';
        $invalidProviderDisplayNameLedger['provider_settings'][0]['display_name'] = "Bad\nDisplay";
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderDisplayNameLedger),
        'Core payment ledger importer rejects unsafe Provider display names before restore'
    );
    $nonCanonicalProviderDisplayNameLedger = $ledger;
    if (isset($nonCanonicalProviderDisplayNameLedger['provider_settings'][0]) && is_array($nonCanonicalProviderDisplayNameLedger['provider_settings'][0])) {
        $nonCanonicalProviderDisplayNameLedger['provider_settings'][0]['provider_id'] = 'core.noncanonical-display-name-restore';
        $nonCanonicalProviderDisplayNameLedger['provider_settings'][0]['display_name'] = ' Restored Provider ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalProviderDisplayNameLedger),
        'Core payment ledger importer rejects non-canonical Provider display names before restore'
    );
    $tokenLikeProviderDisplayNameLedger = $ledger;
    if (isset($tokenLikeProviderDisplayNameLedger['provider_settings'][0]) && is_array($tokenLikeProviderDisplayNameLedger['provider_settings'][0])) {
        $tokenLikeProviderDisplayNameLedger['provider_settings'][0]['provider_id'] = 'core.token-display-name-restore';
        $tokenLikeProviderDisplayNameLedger['provider_settings'][0]['display_name'] = 'payment_token%3Draw-restore-display-token';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenLikeProviderDisplayNameLedger),
        'Core payment ledger importer rejects token-like Provider display names before restore'
    );
    $invalidProviderPublicSecretLedger = $ledger;
    if (isset($invalidProviderPublicSecretLedger['provider_settings'][0]) && is_array($invalidProviderPublicSecretLedger['provider_settings'][0])) {
        $invalidProviderPublicSecretLedger['provider_settings'][0]['provider_id'] = 'core.invalid-public-secret-restore';
        $invalidProviderPublicSecretLedger['provider_settings'][0]['public_config_json'] = '{"api_token":"leak"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderPublicSecretLedger),
        'Core payment ledger importer rejects secret-like Provider public config before restore'
    );
    $tokenLikeProviderPublicValueLedger = $ledger;
    if (isset($tokenLikeProviderPublicValueLedger['provider_settings'][0]) && is_array($tokenLikeProviderPublicValueLedger['provider_settings'][0])) {
        $tokenLikeProviderPublicValueLedger['provider_settings'][0]['provider_id'] = 'core.token-like-public-value-restore';
        $tokenLikeProviderPublicValueLedger['provider_settings'][0]['public_config_json'] = '{"mode":"Bearer raw-public-config-token"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenLikeProviderPublicValueLedger),
        'Core payment ledger importer rejects token-like Provider public config values before restore'
    );
    $encodedTokenLikeProviderPublicValueLedger = $ledger;
    if (isset($encodedTokenLikeProviderPublicValueLedger['provider_settings'][0]) && is_array($encodedTokenLikeProviderPublicValueLedger['provider_settings'][0])) {
        $encodedTokenLikeProviderPublicValueLedger['provider_settings'][0]['provider_id'] = 'core.encoded-token-like-public-value-restore';
        $encodedTokenLikeProviderPublicValueLedger['provider_settings'][0]['public_config_json'] = '{"mode":"payment_token%3Draw-public-config-encoded-token"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($encodedTokenLikeProviderPublicValueLedger),
        'Core payment ledger importer rejects URL-encoded token-like Provider public config values before restore'
    );
    $invalidProviderPublicAuthKeyLedger = $ledger;
    if (isset($invalidProviderPublicAuthKeyLedger['provider_settings'][0]) && is_array($invalidProviderPublicAuthKeyLedger['provider_settings'][0])) {
        $invalidProviderPublicAuthKeyLedger['provider_settings'][0]['provider_id'] = 'core.invalid-public-auth-key-restore';
        $invalidProviderPublicAuthKeyLedger['provider_settings'][0]['public_config_json'] = '{"auth_url":"https://provider.example.test/oauth"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderPublicAuthKeyLedger),
        'Core payment ledger importer rejects auth-like Provider public config keys before restore'
    );
    $invalidProviderPublicKeyLedger = $ledger;
    if (isset($invalidProviderPublicKeyLedger['provider_settings'][0]) && is_array($invalidProviderPublicKeyLedger['provider_settings'][0])) {
        $invalidProviderPublicKeyLedger['provider_settings'][0]['provider_id'] = 'core.invalid-public-key-restore';
        $invalidProviderPublicKeyLedger['provider_settings'][0]['public_config_json'] = '{"bad key":"value"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderPublicKeyLedger),
        'Core payment ledger importer rejects unsafe Provider public config keys before restore'
    );
    $invalidProviderPublicValueLedger = $ledger;
    if (isset($invalidProviderPublicValueLedger['provider_settings'][0]) && is_array($invalidProviderPublicValueLedger['provider_settings'][0])) {
        $invalidProviderPublicValueLedger['provider_settings'][0]['provider_id'] = 'core.invalid-public-value-restore';
        $invalidProviderPublicValueLedger['provider_settings'][0]['public_config_json'] = '{"mode":"bad\u0000value"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderPublicValueLedger),
        'Core payment ledger importer rejects unsafe Provider public config values before restore'
    );
    $malformedProviderPublicConfigLedger = $ledger;
    if (isset($malformedProviderPublicConfigLedger['provider_settings'][0]) && is_array($malformedProviderPublicConfigLedger['provider_settings'][0])) {
        $malformedProviderPublicConfigLedger['provider_settings'][0]['provider_id'] = 'core.malformed-public-config-restore';
        $malformedProviderPublicConfigLedger['provider_settings'][0]['public_config_json'] = '{"mode":';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($malformedProviderPublicConfigLedger),
        'Core payment ledger importer rejects malformed Provider public config JSON before restore'
    );
    $formattedProviderPublicConfigLedger = $ledger;
    if (isset($formattedProviderPublicConfigLedger['provider_settings'][0]) && is_array($formattedProviderPublicConfigLedger['provider_settings'][0])) {
        $formattedProviderPublicConfigLedger['provider_settings'][0]['provider_id'] = 'core.formatted-public-config-restore';
        $formattedProviderPublicConfigLedger['provider_settings'][0]['public_config_json'] = '{"mode": "sandbox"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($formattedProviderPublicConfigLedger),
        'Core payment ledger importer rejects formatted non-canonical Provider public config JSON before restore'
    );
    $nonCanonicalProviderPublicValueLedger = $ledger;
    if (isset($nonCanonicalProviderPublicValueLedger['provider_settings'][0]) && is_array($nonCanonicalProviderPublicValueLedger['provider_settings'][0])) {
        $nonCanonicalProviderPublicValueLedger['provider_settings'][0]['provider_id'] = 'core.non-canonical-public-value-restore';
        $nonCanonicalProviderPublicValueLedger['provider_settings'][0]['public_config_json'] = '{"mode":" sandbox "}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalProviderPublicValueLedger),
        'Core payment ledger importer rejects non-canonical Provider public config values before restore'
    );
    $nonStringProviderPublicUrlLedger = $ledger;
    if (isset($nonStringProviderPublicUrlLedger['provider_settings'][0]) && is_array($nonStringProviderPublicUrlLedger['provider_settings'][0])) {
        $nonStringProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.non-string-public-url-restore';
        $nonStringProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":true}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonStringProviderPublicUrlLedger),
        'Core payment ledger importer rejects non-string Provider public config URLs before restore'
    );
    $sensitiveProviderPublicUrlLedger = $ledger;
    if (isset($sensitiveProviderPublicUrlLedger['provider_settings'][0]) && is_array($sensitiveProviderPublicUrlLedger['provider_settings'][0])) {
        $sensitiveProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.sensitive-public-url-restore';
        $sensitiveProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":"https://provider.example.test/callback?api_key=leak"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($sensitiveProviderPublicUrlLedger),
        'Core payment ledger importer rejects Provider public config URLs with sensitive query parameters before restore'
    );
    $tokenValueProviderPublicUrlLedger = $ledger;
    if (isset($tokenValueProviderPublicUrlLedger['provider_settings'][0]) && is_array($tokenValueProviderPublicUrlLedger['provider_settings'][0])) {
        $tokenValueProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.token-value-public-url-restore';
        $tokenValueProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":"https://provider.example.test/callback?safe=payment_token%3Draw-restore-public-url-token"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenValueProviderPublicUrlLedger),
        'Core payment ledger importer rejects Provider public config URLs with token-like query values before restore'
    );
    $tokenPathProviderPublicUrlLedger = $ledger;
    if (isset($tokenPathProviderPublicUrlLedger['provider_settings'][0]) && is_array($tokenPathProviderPublicUrlLedger['provider_settings'][0])) {
        $tokenPathProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.token-path-public-url-restore';
        $tokenPathProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":"https://provider.example.test/callback/sk%5Flive_restore_public_path_token"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenPathProviderPublicUrlLedger),
        'Core payment ledger importer rejects Provider public config URLs with token-like paths before restore'
    );
    $malformedProviderPublicUrlLedger = $ledger;
    if (isset($malformedProviderPublicUrlLedger['provider_settings'][0]) && is_array($malformedProviderPublicUrlLedger['provider_settings'][0])) {
        $malformedProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.malformed-public-url-restore';
        $malformedProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":"not a url"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($malformedProviderPublicUrlLedger),
        'Core payment ledger importer rejects malformed Provider public config URLs before restore'
    );
    $fragmentProviderPublicUrlLedger = $ledger;
    if (isset($fragmentProviderPublicUrlLedger['provider_settings'][0]) && is_array($fragmentProviderPublicUrlLedger['provider_settings'][0])) {
        $fragmentProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.fragment-public-url-restore';
        $fragmentProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":"https://provider.example.test/callback#return"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($fragmentProviderPublicUrlLedger),
        'Core payment ledger importer rejects Provider public config URLs with fragments before restore'
    );
    $userinfoProviderPublicUrlLedger = $ledger;
    if (isset($userinfoProviderPublicUrlLedger['provider_settings'][0]) && is_array($userinfoProviderPublicUrlLedger['provider_settings'][0])) {
        $userinfoProviderPublicUrlLedger['provider_settings'][0]['provider_id'] = 'core.userinfo-public-url-restore';
        $userinfoProviderPublicUrlLedger['provider_settings'][0]['public_config_json'] = '{"callback_url":"https://operator:secret@provider.example.test/callback"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($userinfoProviderPublicUrlLedger),
        'Core payment ledger importer rejects Provider public config URLs with userinfo before restore'
    );
    $invalidProviderDefaultMarkerLedger = $ledger;
    if (isset($invalidProviderDefaultMarkerLedger['provider_settings'][0]) && is_array($invalidProviderDefaultMarkerLedger['provider_settings'][0])) {
        $invalidProviderDefaultMarkerLedger['provider_settings'][0]['provider_id'] = 'core.invalid-default-marker-restore';
        $invalidProviderDefaultMarkerLedger['provider_settings'][0]['public_config_json'] = '{"default_provider":"yes"}';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderDefaultMarkerLedger),
        'Core payment ledger importer rejects non-boolean Provider default markers before restore'
    );
    $invalidProviderSecretCiphertextLedger = $ledger;
    if (isset($invalidProviderSecretCiphertextLedger['provider_settings'][0]) && is_array($invalidProviderSecretCiphertextLedger['provider_settings'][0])) {
        $invalidProviderSecretCiphertextLedger['provider_settings'][0]['provider_id'] = 'core.invalid-secret-ciphertext-restore';
        $invalidProviderSecretCiphertextLedger['provider_settings'][0]['secret_config_ciphertext'] = 'not-a-valid-secret-ciphertext';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidProviderSecretCiphertextLedger),
        'Core payment ledger importer rejects invalid Provider secret ciphertext before restore'
    );
    $shortProviderSecretCiphertextLedger = $ledger;
    if (isset($shortProviderSecretCiphertextLedger['provider_settings'][0]) && is_array($shortProviderSecretCiphertextLedger['provider_settings'][0])) {
        $shortProviderSecretCiphertextLedger['provider_settings'][0]['provider_id'] = 'core.short-secret-ciphertext-restore';
        $shortProviderSecretCiphertextLedger['provider_settings'][0]['secret_config_ciphertext'] = base64_encode('too-short');
    }
    core_payment_throws(
        static fn () => $importer->importLedger($shortProviderSecretCiphertextLedger),
        'Core payment ledger importer rejects short Provider secret ciphertext before restore'
    );
    $nonCanonicalProviderSecretCiphertextLedger = $ledger;
    if (isset($nonCanonicalProviderSecretCiphertextLedger['provider_settings'][0]) && is_array($nonCanonicalProviderSecretCiphertextLedger['provider_settings'][0])) {
        $nonCanonicalProviderSecretCiphertextLedger['provider_settings'][0]['provider_id'] = 'core.non-canonical-secret-ciphertext-restore';
        $nonCanonicalProviderSecretCiphertextLedger['provider_settings'][0]['secret_config_ciphertext'] = ' ' . (string) ($nonCanonicalProviderSecretCiphertextLedger['provider_settings'][0]['secret_config_ciphertext'] ?? '') . ' ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalProviderSecretCiphertextLedger),
        'Core payment ledger importer rejects non-canonical Provider secret ciphertext before restore'
    );
    $paymentCountBeforeMalformedImport = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
    $providerCountBeforeMalformedImport = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn();
    $missingPaymentSectionLedger = $ledger;
    unset($missingPaymentSectionLedger['payments']);
    core_payment_throws(
        static fn () => $importer->importLedger($missingPaymentSectionLedger),
        'Core payment ledger importer rejects missing required sections before restore'
    );
    $nonArrayRefundSectionLedger = $ledger;
    $nonArrayRefundSectionLedger['refunds'] = 'not-ledger-rows';
    core_payment_throws(
        static fn () => $importer->importLedger($nonArrayRefundSectionLedger),
        'Core payment ledger importer rejects non-array required sections before restore'
    );
    $missingPaymentColumnLedger = $ledger;
    if (isset($missingPaymentColumnLedger['payments'][0]) && is_array($missingPaymentColumnLedger['payments'][0])) {
        unset($missingPaymentColumnLedger['payments'][0]['metadata_json']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($missingPaymentColumnLedger),
        'Core payment ledger importer rejects rows missing required columns before restore'
    );
    $unknownPaymentColumnLedger = $ledger;
    if (isset($unknownPaymentColumnLedger['payments'][0]) && is_array($unknownPaymentColumnLedger['payments'][0])) {
        $unknownPaymentColumnLedger['payments'][0]['raw_webhook_payload'] = 'must-not-be-imported';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($unknownPaymentColumnLedger),
        'Core payment ledger importer rejects rows with unknown columns before restore'
    );
    core_payment_check(
        (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeMalformedImport
        && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn() === $providerCountBeforeMalformedImport,
        'Core payment ledger importer rolls back malformed section and row-shape imports'
    );
    $invalidReferenceLedger = $ledger;
    if (isset($invalidReferenceLedger['payments'][0]) && is_array($invalidReferenceLedger['payments'][0])) {
        $invalidReferenceLedger['payments'][0]['id'] = 990005;
        $invalidReferenceLedger['payments'][0]['remote_id'] = 'invalid-reference-ledger-remote';
        $invalidReferenceLedger['payments'][0]['idempotency_key'] = 'invalid-reference-ledger-key';
        $invalidReferenceLedger['payments'][0]['subject_id'] = "bad\nsubject";
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidReferenceLedger),
        'Core payment ledger importer rejects unsafe payment references before restore'
    );
    $nonCanonicalPaymentReferenceLedger = $ledger;
    if (isset($nonCanonicalPaymentReferenceLedger['payments'][0]) && is_array($nonCanonicalPaymentReferenceLedger['payments'][0])) {
        $nonCanonicalPaymentReferenceLedger['payments'][0]['id'] = 990006;
        $nonCanonicalPaymentReferenceLedger['payments'][0]['remote_id'] = ' non-canonical-payment-restore ';
        $nonCanonicalPaymentReferenceLedger['payments'][0]['idempotency_key'] = 'non-canonical-payment-restore-key';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalPaymentReferenceLedger),
        'Core payment ledger importer rejects non-canonical payment references before restore'
    );
    $nonCanonicalRefundReasonLedger = $ledger;
    if (isset($nonCanonicalRefundReasonLedger['refunds'][0]) && is_array($nonCanonicalRefundReasonLedger['refunds'][0])) {
        $nonCanonicalRefundReasonLedger['refunds'][0]['reason'] = ' ' . (string) ($nonCanonicalRefundReasonLedger['refunds'][0]['reason'] ?? 'restore refund') . ' ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalRefundReasonLedger),
        'Core payment ledger importer rejects non-canonical refund reasons before restore'
    );
    $invalidWebhookEventLedger = $ledger;
    if (isset($invalidWebhookEventLedger['payments'][0]) && is_array($invalidWebhookEventLedger['payments'][0])) {
        $invalidWebhookEventLedger['webhook_receipts'][] = [
            'id' => 990007,
            'payment_id' => (int) ($invalidWebhookEventLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($invalidWebhookEventLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => "evt-bad\nrestore",
            'payload_hash' => hash('sha256', 'invalid-webhook-event-ledger'),
            'status' => 'received',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $invalidWebhookEventLedger['counts']['webhook_receipts'] = count($invalidWebhookEventLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidWebhookEventLedger),
        'Core payment ledger importer rejects unsafe webhook event ids before restore'
    );
    $nonCanonicalWebhookEventLedger = $ledger;
    if (isset($nonCanonicalWebhookEventLedger['payments'][0]) && is_array($nonCanonicalWebhookEventLedger['payments'][0])) {
        $nonCanonicalWebhookEventLedger['webhook_receipts'][] = [
            'id' => 990008,
            'payment_id' => (int) ($nonCanonicalWebhookEventLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($nonCanonicalWebhookEventLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => ' evt-non-canonical-restore ',
            'payload_hash' => hash('sha256', 'non-canonical-webhook-event-ledger'),
            'status' => 'received',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $nonCanonicalWebhookEventLedger['counts']['webhook_receipts'] = count($nonCanonicalWebhookEventLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalWebhookEventLedger),
        'Core payment ledger importer rejects non-canonical webhook event ids before restore'
    );
    $tokenLikeWebhookEventLedger = $ledger;
    if (isset($tokenLikeWebhookEventLedger['payments'][0]) && is_array($tokenLikeWebhookEventLedger['payments'][0])) {
        $tokenLikeWebhookEventLedger['webhook_receipts'][] = [
            'id' => 990009,
            'payment_id' => (int) ($tokenLikeWebhookEventLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($tokenLikeWebhookEventLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-payment_token%3Draw-restore-webhook-event-token',
            'payload_hash' => hash('sha256', 'token-like-webhook-event-ledger'),
            'status' => 'received',
            'metadata_json' => '{}',
            'received_at' => gmdate('c'),
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $tokenLikeWebhookEventLedger['counts']['webhook_receipts'] = count($tokenLikeWebhookEventLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($tokenLikeWebhookEventLedger),
        'Core payment ledger importer rejects URL-encoded token-like webhook event ids before restore'
    );
    $invalidPaymentTimestampLedger = $ledger;
    if (isset($invalidPaymentTimestampLedger['payments'][0]) && is_array($invalidPaymentTimestampLedger['payments'][0])) {
        $invalidPaymentTimestampLedger['payments'][0]['id'] = 990010;
        $invalidPaymentTimestampLedger['payments'][0]['remote_id'] = 'invalid-payment-timestamp-ledger-remote';
        $invalidPaymentTimestampLedger['payments'][0]['idempotency_key'] = 'invalid-payment-timestamp-ledger-key';
        $invalidPaymentTimestampLedger['payments'][0]['created_at'] = 'not-a-time';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidPaymentTimestampLedger),
        'Core payment ledger importer rejects invalid payment timestamps before restore'
    );
    $nonCanonicalPaymentTimestampLedger = $ledger;
    if (isset($nonCanonicalPaymentTimestampLedger['payments'][0]) && is_array($nonCanonicalPaymentTimestampLedger['payments'][0])) {
        $nonCanonicalPaymentTimestampLedger['payments'][0]['id'] = 990021;
        $nonCanonicalPaymentTimestampLedger['payments'][0]['remote_id'] = 'non-canonical-payment-timestamp-ledger-remote';
        $nonCanonicalPaymentTimestampLedger['payments'][0]['idempotency_key'] = 'non-canonical-payment-timestamp-ledger-key';
        $nonCanonicalPaymentTimestampLedger['payments'][0]['created_at'] = ' ' . (string) ($nonCanonicalPaymentTimestampLedger['payments'][0]['created_at'] ?? gmdate('c')) . ' ';
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonCanonicalPaymentTimestampLedger),
        'Core payment ledger importer rejects non-canonical payment timestamps before restore'
    );
    $nonUtcPaymentTimestampLedger = $ledger;
    if (isset($nonUtcPaymentTimestampLedger['payments'][0]) && is_array($nonUtcPaymentTimestampLedger['payments'][0])) {
        $nonUtcPaymentTimestampLedger['payments'][0]['id'] = 990022;
        $nonUtcPaymentTimestampLedger['payments'][0]['remote_id'] = 'non-utc-payment-timestamp-ledger-remote';
        $nonUtcPaymentTimestampLedger['payments'][0]['idempotency_key'] = 'non-utc-payment-timestamp-ledger-key';
        $nonUtcPaymentTimestampLedger['payments'][0]['created_at'] = gmdate('Y-m-d H:i:s');
    }
    core_payment_throws(
        static fn () => $importer->importLedger($nonUtcPaymentTimestampLedger),
        'Core payment ledger importer rejects strtotime-compatible non-UTC payment timestamps before restore'
    );
    $invalidWebhookTimestampLedger = $ledger;
    if (isset($invalidWebhookTimestampLedger['payments'][0]) && is_array($invalidWebhookTimestampLedger['payments'][0])) {
        $invalidWebhookTimestampLedger['webhook_receipts'][] = [
            'id' => 990010,
            'payment_id' => (int) ($invalidWebhookTimestampLedger['payments'][0]['id'] ?? 1),
            'provider_id' => (string) ($invalidWebhookTimestampLedger['payments'][0]['provider_id'] ?? FixturePaymentProvider::PROVIDER_ID),
            'external_event_id' => 'evt-invalid-webhook-timestamp-restore',
            'payload_hash' => hash('sha256', 'invalid-webhook-timestamp-ledger'),
            'status' => 'received',
            'metadata_json' => '{}',
            'received_at' => "bad\ntime",
            'processed_at' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $invalidWebhookTimestampLedger['counts']['webhook_receipts'] = count($invalidWebhookTimestampLedger['webhook_receipts']);
    }
    core_payment_throws(
        static fn () => $importer->importLedger($invalidWebhookTimestampLedger),
        'Core payment ledger importer rejects invalid webhook timestamps before restore'
    );
    $auditCountBeforePreflight = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.ledger.imported'")->fetchColumn();
    $paymentCountBeforePreflight = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
    $cliPreflightResult = core_payment_run_cli($tmpRoot, 'preflight-payment-ledger', [$packagePath]);
    core_payment_check(
        (string) ($cliPreflightResult['status'] ?? '') === 'Verified'
        && (string) ($cliPreflightResult['package_name'] ?? '') === basename($packagePath)
        && (string) ($cliPreflightResult['package_sha256'] ?? '') === hash_file('sha256', $packagePath)
        && (string) ($cliPreflightResult['summary']['type'] ?? '') === 'core_payment_ledger'
        && (int) ($cliPreflightResult['counts']['payments'] ?? -1) === count($ledger['payments'] ?? [])
        && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.ledger.imported'")->fetchColumn() === $auditCountBeforePreflight
        && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforePreflight,
        'Core CLI preflights official export payment ledger without writing data'
    );
    $unsafeNamedPackagePath = $tmpRoot . "/storage/exports/payment_token=raw-leak\nledger.zip";
    copy($packagePath, $unsafeNamedPackagePath);
    $unsafeNamedPreflightResult = core_payment_run_cli($tmpRoot, 'preflight-payment-ledger', [$unsafeNamedPackagePath]);
    core_payment_check(
        (string) ($unsafeNamedPreflightResult['status'] ?? '') === 'Verified'
        && (string) ($unsafeNamedPreflightResult['package_name'] ?? '') === '[invalid]'
        && (string) ($unsafeNamedPreflightResult['package_sha256'] ?? '') === hash_file('sha256', $unsafeNamedPackagePath)
        && !str_contains(json_encode($unsafeNamedPreflightResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'payment_token=raw-leak'),
        'Core CLI payment ledger preflight sanitizes unsafe package names'
    );
    $encodedUnsafeNamedPackagePath = $tmpRoot . '/storage/exports/payment_token%3Draw-leak-encoded-ledger.zip';
    copy($packagePath, $encodedUnsafeNamedPackagePath);
    $encodedUnsafeNamedPreflightResult = core_payment_run_cli($tmpRoot, 'preflight-payment-ledger', [$encodedUnsafeNamedPackagePath]);
    core_payment_check(
        (string) ($encodedUnsafeNamedPreflightResult['status'] ?? '') === 'Verified'
        && (string) ($encodedUnsafeNamedPreflightResult['package_name'] ?? '') === '[invalid]'
        && (string) ($encodedUnsafeNamedPreflightResult['package_sha256'] ?? '') === hash_file('sha256', $encodedUnsafeNamedPackagePath)
        && !str_contains(json_encode($encodedUnsafeNamedPreflightResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'payment_token%3Draw-leak')
        && !str_contains(json_encode($encodedUnsafeNamedPreflightResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'payment_token=raw-leak'),
        'Core CLI payment ledger preflight sanitizes URL-encoded unsafe package names'
    );
    $missingUnsafePackagePath = $tmpRoot . '/storage/exports/payment_token%3Draw-missing-ledger.zip';
    $auditCountBeforeMissingUnsafePackage = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_audit_logs')->fetchColumn();
    $paymentCountBeforeMissingUnsafePackage = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
    $missingUnsafePreflightResult = core_payment_run_cli_raw($tmpRoot, 'preflight-payment-ledger', [$missingUnsafePackagePath]);
    $missingUnsafePreflightDecoded = json_decode((string) ($missingUnsafePreflightResult['stdout'] ?? ''), true) ?: [];
    core_payment_check(
        (int) ($missingUnsafePreflightResult['code'] ?? 0) === 1
        && (string) ($missingUnsafePreflightDecoded['status'] ?? '') === 'Failed'
        && (string) ($missingUnsafePreflightDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($missingUnsafePreflightDecoded['package_sha256'] ?? 'not-empty') === ''
        && str_contains((string) ($missingUnsafePreflightDecoded['error'] ?? ''), 'package was not found')
        && (string) ($missingUnsafePreflightResult['stderr'] ?? '') === ''
        && !str_contains((string) ($missingUnsafePreflightResult['stdout'] ?? ''), 'payment_token%3Draw-missing')
        && !str_contains((string) ($missingUnsafePreflightResult['stdout'] ?? ''), 'payment_token=raw-missing')
        && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_audit_logs')->fetchColumn() === $auditCountBeforeMissingUnsafePackage
        && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeMissingUnsafePackage,
        'Core CLI payment ledger preflight returns safe JSON for missing unsafe-named packages'
    );
    $missingArgumentPreflightResult = core_payment_run_cli_raw($tmpRoot, 'preflight-payment-ledger');
    $missingArgumentPreflightDecoded = json_decode((string) ($missingArgumentPreflightResult['stdout'] ?? ''), true) ?: [];
    core_payment_check(
        (int) ($missingArgumentPreflightResult['code'] ?? 0) === 1
        && (string) ($missingArgumentPreflightDecoded['status'] ?? '') === 'Failed'
        && (string) ($missingArgumentPreflightDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($missingArgumentPreflightDecoded['package_sha256'] ?? 'not-empty') === ''
        && str_contains((string) ($missingArgumentPreflightDecoded['error'] ?? ''), 'package was not found')
        && (string) ($missingArgumentPreflightResult['stderr'] ?? '') === ''
        && !str_contains((string) ($missingArgumentPreflightResult['stdout'] ?? ''), 'Usage:'),
        'Core CLI payment ledger preflight returns safe JSON when package argument is omitted'
    );
    $missingUnsafeImportResult = core_payment_run_cli_raw($tmpRoot, 'import-payment-ledger', [$missingUnsafePackagePath]);
    $missingUnsafeImportDecoded = json_decode((string) ($missingUnsafeImportResult['stdout'] ?? ''), true) ?: [];
    core_payment_check(
        (int) ($missingUnsafeImportResult['code'] ?? 0) === 1
        && (string) ($missingUnsafeImportDecoded['status'] ?? '') === 'Failed'
        && (string) ($missingUnsafeImportDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($missingUnsafeImportDecoded['package_sha256'] ?? 'not-empty') === ''
        && str_contains((string) ($missingUnsafeImportDecoded['error'] ?? ''), 'package was not found')
        && (string) ($missingUnsafeImportResult['stderr'] ?? '') === ''
        && !str_contains((string) ($missingUnsafeImportResult['stdout'] ?? ''), 'payment_token%3Draw-missing')
        && !str_contains((string) ($missingUnsafeImportResult['stdout'] ?? ''), 'payment_token=raw-missing')
        && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_audit_logs')->fetchColumn() === $auditCountBeforeMissingUnsafePackage
        && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeMissingUnsafePackage,
        'Core CLI payment ledger restore returns safe JSON for missing unsafe-named packages before database access'
    );
    $missingUnsafeImportNoConfigRoot = $tmpRoot . '/payment-ledger-missing-package-no-config';
    mkdir($missingUnsafeImportNoConfigRoot, 0775, true);
    $missingUnsafeImportNoConfigResult = core_payment_run_cli_raw($missingUnsafeImportNoConfigRoot, 'import-payment-ledger', [$missingUnsafePackagePath]);
    $missingUnsafeImportNoConfigDecoded = json_decode((string) ($missingUnsafeImportNoConfigResult['stdout'] ?? ''), true) ?: [];
    core_payment_check(
        (int) ($missingUnsafeImportNoConfigResult['code'] ?? 0) === 1
        && (string) ($missingUnsafeImportNoConfigDecoded['status'] ?? '') === 'Failed'
        && (string) ($missingUnsafeImportNoConfigDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($missingUnsafeImportNoConfigDecoded['package_sha256'] ?? 'not-empty') === ''
        && str_contains((string) ($missingUnsafeImportNoConfigDecoded['error'] ?? ''), 'package was not found')
        && (string) ($missingUnsafeImportNoConfigResult['stderr'] ?? '') === ''
        && !str_contains((string) ($missingUnsafeImportNoConfigResult['stdout'] ?? ''), 'config/app.php')
        && !str_contains((string) ($missingUnsafeImportNoConfigResult['stdout'] ?? ''), 'payment_token%3Draw-missing')
        && !str_contains((string) ($missingUnsafeImportNoConfigResult['stdout'] ?? ''), 'payment_token=raw-missing'),
        'Core CLI payment ledger restore rejects missing packages before loading site configuration'
    );
    $missingArgumentImportNoConfigResult = core_payment_run_cli_raw($missingUnsafeImportNoConfigRoot, 'import-payment-ledger');
    $missingArgumentImportNoConfigDecoded = json_decode((string) ($missingArgumentImportNoConfigResult['stdout'] ?? ''), true) ?: [];
    core_payment_check(
        (int) ($missingArgumentImportNoConfigResult['code'] ?? 0) === 1
        && (string) ($missingArgumentImportNoConfigDecoded['status'] ?? '') === 'Failed'
        && (string) ($missingArgumentImportNoConfigDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($missingArgumentImportNoConfigDecoded['package_sha256'] ?? 'not-empty') === ''
        && str_contains((string) ($missingArgumentImportNoConfigDecoded['error'] ?? ''), 'package was not found')
        && (string) ($missingArgumentImportNoConfigResult['stderr'] ?? '') === ''
        && !str_contains((string) ($missingArgumentImportNoConfigResult['stdout'] ?? ''), 'config/app.php')
        && !str_contains((string) ($missingArgumentImportNoConfigResult['stdout'] ?? ''), 'Usage:'),
        'Core CLI payment ledger restore returns safe JSON when package argument is omitted before loading site configuration'
    );
    $cliImportAuditFailureRoot = $tmpRoot . '/payment-ledger-import-audit-failure';
    mkdir($cliImportAuditFailureRoot . '/config', 0775, true);
    mkdir($cliImportAuditFailureRoot . '/storage/logs', 0775, true);
    $cliImportAuditFailureDbFile = $cliImportAuditFailureRoot . '/payment-ledger-import-audit-failure.sqlite';
    $cliImportAuditFailurePdo = new PDO('sqlite:' . $cliImportAuditFailureDbFile);
    $cliImportAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new MigrationRunner($cliImportAuditFailurePdo, $migrations))->run();
    $cliImportAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_ledger_import_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.ledger.imported' BEGIN SELECT RAISE(ABORT, 'ledger import audit failure'); END");
    file_put_contents($cliImportAuditFailureRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'database' => ['dsn' => 'sqlite:' . $cliImportAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
        'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    ], true) . ";\n");
    $cliImportAuditFailureResult = core_payment_run_cli_raw($cliImportAuditFailureRoot, 'import-payment-ledger', [$packagePath]);
    $cliImportAuditFailureDecoded = json_decode($cliImportAuditFailureResult['stdout'], true) ?: [];
    core_payment_check(
        (int) $cliImportAuditFailureResult['code'] !== 0
        && (string) ($cliImportAuditFailureDecoded['status'] ?? '') === 'Failed'
        && (int) $cliImportAuditFailurePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === 0
        && (int) $cliImportAuditFailurePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.ledger.imported'")->fetchColumn() === 0
        && (int) $cliImportAuditFailurePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.ledger.import_failed'")->fetchColumn() === 1,
        'Core CLI payment ledger restore rolls back imported rows when success audit persistence fails'
    );
    $cliImportResult = core_payment_run_cli($tmpRoot, 'import-payment-ledger', [$packagePath]);
    $cliImportAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.ledger.imported' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $cliImportAuditContext = json_decode((string) $cliImportAudit, true) ?: [];
    core_payment_check(
        (string) ($cliImportResult['status'] ?? '') === 'Completed'
        && (string) ($cliImportResult['package_name'] ?? '') === basename($packagePath)
        && (string) ($cliImportResult['package_sha256'] ?? '') === hash_file('sha256', $packagePath)
        && (int) ($cliImportResult['sections']['payments']['skipped'] ?? -1) === count($ledger['payments'] ?? [])
        && (int) ($adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? []),
        'Core CLI restores official export payment ledger idempotently without plugin runtime'
    );
    core_payment_check(
        (string) ($cliImportAuditContext['package_name'] ?? '') === basename($packagePath)
        && (string) ($cliImportAuditContext['package_sha256'] ?? '') === hash_file('sha256', $packagePath)
        && (int) ($cliImportAuditContext['sections']['payments']['skipped'] ?? -1) === count($ledger['payments'] ?? [])
        && !str_contains((string) $cliImportAudit, 'sk_admin_bootstrap')
        && !str_contains((string) $cliImportAudit, 'payment_token='),
        'Core CLI payment ledger restore writes safe audit context'
    );
    $unsafeNamedImportResult = core_payment_run_cli_raw($tmpRoot, 'import-payment-ledger', [$unsafeNamedPackagePath]);
    $unsafeNamedImportDecoded = json_decode($unsafeNamedImportResult['stdout'], true) ?: [];
    $unsafeNamedImportAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.ledger.imported' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $unsafeNamedImportAuditContext = json_decode((string) $unsafeNamedImportAudit, true) ?: [];
    core_payment_check(
        (int) $unsafeNamedImportResult['code'] === 0
        && (string) ($unsafeNamedImportDecoded['status'] ?? '') === 'Completed'
        && (string) ($unsafeNamedImportDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($unsafeNamedImportDecoded['package_sha256'] ?? '') === hash_file('sha256', $unsafeNamedPackagePath)
        && (string) ($unsafeNamedImportAuditContext['package_name'] ?? '') === '[invalid]'
        && (string) ($unsafeNamedImportAuditContext['package_sha256'] ?? '') === hash_file('sha256', $unsafeNamedPackagePath)
        && !str_contains((string) $unsafeNamedImportResult['stdout'], 'payment_token=raw-leak')
        && !str_contains((string) $unsafeNamedImportAudit, 'payment_token=raw-leak'),
        'Core CLI payment ledger restore sanitizes unsafe package names in success output and audit'
    );
    $encodedUnsafeNamedImportResult = core_payment_run_cli_raw($tmpRoot, 'import-payment-ledger', [$encodedUnsafeNamedPackagePath]);
    $encodedUnsafeNamedImportDecoded = json_decode($encodedUnsafeNamedImportResult['stdout'], true) ?: [];
    $encodedUnsafeNamedImportAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.ledger.imported' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $encodedUnsafeNamedImportAuditContext = json_decode((string) $encodedUnsafeNamedImportAudit, true) ?: [];
    core_payment_check(
        (int) $encodedUnsafeNamedImportResult['code'] === 0
        && (string) ($encodedUnsafeNamedImportDecoded['status'] ?? '') === 'Completed'
        && (string) ($encodedUnsafeNamedImportDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($encodedUnsafeNamedImportDecoded['package_sha256'] ?? '') === hash_file('sha256', $encodedUnsafeNamedPackagePath)
        && (string) ($encodedUnsafeNamedImportAuditContext['package_name'] ?? '') === '[invalid]'
        && (string) ($encodedUnsafeNamedImportAuditContext['package_sha256'] ?? '') === hash_file('sha256', $encodedUnsafeNamedPackagePath)
        && !str_contains((string) $encodedUnsafeNamedImportResult['stdout'], 'payment_token%3Draw-leak')
        && !str_contains((string) $encodedUnsafeNamedImportResult['stdout'], 'payment_token=raw-leak')
        && !str_contains((string) $encodedUnsafeNamedImportAudit, 'payment_token%3Draw-leak')
        && !str_contains((string) $encodedUnsafeNamedImportAudit, 'payment_token=raw-leak'),
        'Core CLI payment ledger restore sanitizes URL-encoded unsafe package names in success output and audit'
    );
    $conflictingPackagePath = $tmpRoot . '/storage/exports/conflicting-payment-ledger.zip';
    copy($packagePath, $conflictingPackagePath);
    $conflictingZip = new ZipArchive();
    if ($conflictingZip->open($conflictingPackagePath) === true) {
        $conflictingPackageLedger = $ledger;
        if (isset($conflictingPackageLedger['payments'][0]) && is_array($conflictingPackageLedger['payments'][0])) {
            $conflictingPackageLedger['payments'][0]['amount_minor'] = (int) ($conflictingPackageLedger['payments'][0]['amount_minor'] ?? 0) + 1;
        }
        $conflictingPayload = json_encode($conflictingPackageLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $conflictingManifest = $manifest;
        $conflictingManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', (string) $conflictingPayload);
        $conflictingZip->addFromString('payments/payment-ledger.json', (string) $conflictingPayload);
        $conflictingZip->addFromString('manifest.json', json_encode($conflictingManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $conflictingZip->close();
    }
    $cliConflictResult = core_payment_run_cli_raw($tmpRoot, 'import-payment-ledger', [$conflictingPackagePath]);
    $cliConflictDecoded = json_decode($cliConflictResult['stdout'], true) ?: [];
    $cliImportFailureAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.ledger.import_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $cliImportFailureAuditContext = json_decode((string) $cliImportFailureAudit, true) ?: [];
    core_payment_check(
        (int) $cliConflictResult['code'] !== 0
        && (string) ($cliConflictDecoded['status'] ?? '') === 'Failed'
        && (string) ($cliConflictDecoded['package_name'] ?? '') === basename($conflictingPackagePath)
        && (string) ($cliConflictDecoded['package_sha256'] ?? '') === hash_file('sha256', $conflictingPackagePath)
        && str_contains((string) ($cliConflictDecoded['error'] ?? ''), 'conflicts with existing Core payment ledger data')
        && (string) ($cliImportFailureAuditContext['package_name'] ?? '') === basename($conflictingPackagePath)
        && (string) ($cliImportFailureAuditContext['package_sha256'] ?? '') === hash_file('sha256', $conflictingPackagePath)
        && !str_contains((string) $cliImportFailureAudit, 'sk_admin_bootstrap')
        && !str_contains((string) $cliImportFailureAudit, 'payment_token='),
        'Core CLI payment ledger restore writes safe failure audit context'
    );
    $cliImportFailureAuditFailureRoot = $tmpRoot . '/payment-ledger-import-failure-audit-failure';
    mkdir($cliImportFailureAuditFailureRoot . '/config', 0775, true);
    mkdir($cliImportFailureAuditFailureRoot . '/storage/logs', 0775, true);
    $cliImportFailureAuditFailureDbFile = $cliImportFailureAuditFailureRoot . '/payment-ledger-import-failure-audit-failure.sqlite';
    $cliImportFailureAuditFailurePdo = new PDO('sqlite:' . $cliImportFailureAuditFailureDbFile);
    $cliImportFailureAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new MigrationRunner($cliImportFailureAuditFailurePdo, $migrations))->run();
    file_put_contents($cliImportFailureAuditFailureRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'database' => ['dsn' => 'sqlite:' . $cliImportFailureAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
        'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    ], true) . ";\n");
    $cliImportFailureAuditFailureSeedResult = core_payment_run_cli($cliImportFailureAuditFailureRoot, 'import-payment-ledger', [$packagePath]);
    $cliImportFailureAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_ledger_import_failure_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.ledger.import_failed' BEGIN SELECT RAISE(ABORT, 'payment_token=raw-failure-audit signature=raw-failure-audit'); END");
    $cliImportFailureAuditFailureResult = core_payment_run_cli_raw($cliImportFailureAuditFailureRoot, 'import-payment-ledger', [$conflictingPackagePath]);
    $cliImportFailureAuditFailureDecoded = json_decode($cliImportFailureAuditFailureResult['stdout'], true) ?: [];
    core_payment_check(
        (string) ($cliImportFailureAuditFailureSeedResult['status'] ?? '') === 'Completed'
        && (int) $cliImportFailureAuditFailureResult['code'] !== 0
        && (string) ($cliImportFailureAuditFailureDecoded['status'] ?? '') === 'Failed'
        && (string) ($cliImportFailureAuditFailureDecoded['package_name'] ?? '') === basename($conflictingPackagePath)
        && (string) ($cliImportFailureAuditFailureDecoded['package_sha256'] ?? '') === hash_file('sha256', $conflictingPackagePath)
        && str_contains((string) ($cliImportFailureAuditFailureDecoded['error'] ?? ''), 'conflicts with existing Core payment ledger data')
        && (string) ($cliImportFailureAuditFailureDecoded['audit_error'] ?? '') === '[invalid]'
        && (string) $cliImportFailureAuditFailureResult['stderr'] === ''
        && !str_contains((string) $cliImportFailureAuditFailureResult['stdout'], 'payment_token=raw-failure-audit')
        && !str_contains((string) $cliImportFailureAuditFailureResult['stdout'], 'signature=raw-failure-audit')
        && (int) $cliImportFailureAuditFailurePdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === count($ledger['payments'] ?? [])
        && (int) $cliImportFailureAuditFailurePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.ledger.import_failed'")->fetchColumn() === 0,
        'Core CLI payment ledger restore returns safe JSON when failure audit persistence fails'
    );
    $unsafeNamedConflictingPackagePath = $tmpRoot . "/storage/exports/payment_token=raw-leak\nconflict.zip";
    copy($conflictingPackagePath, $unsafeNamedConflictingPackagePath);
    $unsafeNamedConflictResult = core_payment_run_cli_raw($tmpRoot, 'import-payment-ledger', [$unsafeNamedConflictingPackagePath]);
    $unsafeNamedConflictDecoded = json_decode($unsafeNamedConflictResult['stdout'], true) ?: [];
    $unsafeNamedFailureAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.ledger.import_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $unsafeNamedFailureAuditContext = json_decode((string) $unsafeNamedFailureAudit, true) ?: [];
    core_payment_check(
        (int) $unsafeNamedConflictResult['code'] !== 0
        && (string) ($unsafeNamedConflictDecoded['status'] ?? '') === 'Failed'
        && (string) ($unsafeNamedConflictDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($unsafeNamedConflictDecoded['package_sha256'] ?? '') === hash_file('sha256', $unsafeNamedConflictingPackagePath)
        && (string) ($unsafeNamedFailureAuditContext['package_name'] ?? '') === '[invalid]'
        && (string) ($unsafeNamedFailureAuditContext['package_sha256'] ?? '') === hash_file('sha256', $unsafeNamedConflictingPackagePath)
        && !str_contains((string) $unsafeNamedConflictResult['stdout'], 'payment_token=raw-leak')
        && !str_contains((string) $unsafeNamedFailureAudit, 'payment_token=raw-leak'),
        'Core CLI payment ledger restore sanitizes unsafe package names in failure output and audit'
    );
    $encodedUnsafeNamedConflictingPackagePath = $tmpRoot . '/storage/exports/payment_token%3Draw-leak-encoded-conflict.zip';
    copy($conflictingPackagePath, $encodedUnsafeNamedConflictingPackagePath);
    $encodedUnsafeNamedConflictResult = core_payment_run_cli_raw($tmpRoot, 'import-payment-ledger', [$encodedUnsafeNamedConflictingPackagePath]);
    $encodedUnsafeNamedConflictDecoded = json_decode($encodedUnsafeNamedConflictResult['stdout'], true) ?: [];
    $encodedUnsafeNamedFailureAudit = $adminPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'payment.ledger.import_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $encodedUnsafeNamedFailureAuditContext = json_decode((string) $encodedUnsafeNamedFailureAudit, true) ?: [];
    core_payment_check(
        (int) $encodedUnsafeNamedConflictResult['code'] !== 0
        && (string) ($encodedUnsafeNamedConflictDecoded['status'] ?? '') === 'Failed'
        && (string) ($encodedUnsafeNamedConflictDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($encodedUnsafeNamedConflictDecoded['package_sha256'] ?? '') === hash_file('sha256', $encodedUnsafeNamedConflictingPackagePath)
        && (string) ($encodedUnsafeNamedFailureAuditContext['package_name'] ?? '') === '[invalid]'
        && (string) ($encodedUnsafeNamedFailureAuditContext['package_sha256'] ?? '') === hash_file('sha256', $encodedUnsafeNamedConflictingPackagePath)
        && !str_contains((string) $encodedUnsafeNamedConflictResult['stdout'], 'payment_token%3Draw-leak')
        && !str_contains((string) $encodedUnsafeNamedConflictResult['stdout'], 'payment_token=raw-leak')
        && !str_contains((string) $encodedUnsafeNamedFailureAudit, 'payment_token%3Draw-leak')
        && !str_contains((string) $encodedUnsafeNamedFailureAudit, 'payment_token=raw-leak'),
        'Core CLI payment ledger restore sanitizes URL-encoded unsafe package names in failure output and audit'
    );
    core_payment_check(
        (int) ($adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() ?: 0) === count($ledger['payments'] ?? []),
        'Core CLI payment ledger restore leaves Core rows unchanged after conflict'
    );
    $tamperedPackagePath = $tmpRoot . '/storage/exports/tampered-payment-ledger.zip';
    copy($packagePath, $tamperedPackagePath);
    $tamperedZip = new ZipArchive();
    if ($tamperedZip->open($tamperedPackagePath) === true) {
        $tamperedZip->addFromString('payments/payment-ledger.json', '{"payments":[]}');
        $tamperedZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($tamperedPackagePath),
        'Core official export reader rejects tampered Core payment ledger checksums'
    );
    $undeclaredPackagePath = $tmpRoot . '/storage/exports/undeclared-entry-payment-ledger.zip';
    copy($packagePath, $undeclaredPackagePath);
    $undeclaredZip = new ZipArchive();
    if ($undeclaredZip->open($undeclaredPackagePath) === true) {
        $undeclaredZip->addFromString('content/uploads/extra.txt', 'undeclared export payload');
        $undeclaredZip->close();
    }
    core_payment_throws(
        static fn () => $reader->verifyPackage($undeclaredPackagePath),
        'Core official export package verifier rejects ZIP entries not declared in the manifest'
    );
    core_payment_throws(
        static fn () => $reader->paymentLedger($undeclaredPackagePath),
        'Core official export payment ledger reader rejects undeclared ZIP entries before import'
    );
    $malformedManifestPackagePath = $tmpRoot . '/storage/exports/malformed-manifest-payment-ledger.zip';
    copy($packagePath, $malformedManifestPackagePath);
    $malformedManifestZip = new ZipArchive();
    if ($malformedManifestZip->open($malformedManifestPackagePath) === true) {
        $malformedManifestZip->addFromString('manifest.json', '{"checksums":');
        $malformedManifestZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($malformedManifestPackagePath),
        'Core official export reader rejects malformed export manifests before reading payment ledgers'
    );
    $malformedPayloadPackagePath = $tmpRoot . '/storage/exports/malformed-payload-payment-ledger.zip';
    copy($packagePath, $malformedPayloadPackagePath);
    $malformedPayloadZip = new ZipArchive();
    if ($malformedPayloadZip->open($malformedPayloadPackagePath) === true) {
        $malformedPayload = '{"schema_version":"1.0.0","payments":';
        $malformedPayloadManifest = $manifest;
        $malformedPayloadManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', $malformedPayload);
        $malformedPayloadZip->addFromString('payments/payment-ledger.json', $malformedPayload);
        $malformedPayloadZip->addFromString('manifest.json', json_encode($malformedPayloadManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $malformedPayloadZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($malformedPayloadPackagePath),
        'Core official export reader rejects malformed Core payment ledger JSON after checksum verification'
    );
    $unsafeNamedTamperedPackagePath = $tmpRoot . "/storage/exports/payment_token=raw-leak\npreflight-tampered.zip";
    copy($tamperedPackagePath, $unsafeNamedTamperedPackagePath);
    $unsafeNamedTamperedPreflightResult = core_payment_run_cli_raw($tmpRoot, 'preflight-payment-ledger', [$unsafeNamedTamperedPackagePath]);
    $unsafeNamedTamperedPreflightDecoded = json_decode($unsafeNamedTamperedPreflightResult['stdout'], true) ?: [];
    core_payment_check(
        (int) $unsafeNamedTamperedPreflightResult['code'] !== 0
        && (string) ($unsafeNamedTamperedPreflightDecoded['status'] ?? '') === 'Failed'
        && (string) ($unsafeNamedTamperedPreflightDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($unsafeNamedTamperedPreflightDecoded['package_sha256'] ?? '') === hash_file('sha256', $unsafeNamedTamperedPackagePath)
        && (string) $unsafeNamedTamperedPreflightResult['stderr'] === ''
        && !str_contains((string) $unsafeNamedTamperedPreflightResult['stdout'], 'payment_token=raw-leak'),
        'Core CLI payment ledger preflight returns safe JSON for invalid unsafe-named packages'
    );
    $encodedUnsafeNamedTamperedPackagePath = $tmpRoot . '/storage/exports/payment_token%3Draw-leak-encoded-preflight-tampered.zip';
    copy($tamperedPackagePath, $encodedUnsafeNamedTamperedPackagePath);
    $encodedUnsafeNamedTamperedPreflightResult = core_payment_run_cli_raw($tmpRoot, 'preflight-payment-ledger', [$encodedUnsafeNamedTamperedPackagePath]);
    $encodedUnsafeNamedTamperedPreflightDecoded = json_decode($encodedUnsafeNamedTamperedPreflightResult['stdout'], true) ?: [];
    core_payment_check(
        (int) $encodedUnsafeNamedTamperedPreflightResult['code'] !== 0
        && (string) ($encodedUnsafeNamedTamperedPreflightDecoded['status'] ?? '') === 'Failed'
        && (string) ($encodedUnsafeNamedTamperedPreflightDecoded['package_name'] ?? '') === '[invalid]'
        && (string) ($encodedUnsafeNamedTamperedPreflightDecoded['package_sha256'] ?? '') === hash_file('sha256', $encodedUnsafeNamedTamperedPackagePath)
        && (string) $encodedUnsafeNamedTamperedPreflightResult['stderr'] === ''
        && !str_contains((string) $encodedUnsafeNamedTamperedPreflightResult['stdout'], 'payment_token%3Draw-leak')
        && !str_contains((string) $encodedUnsafeNamedTamperedPreflightResult['stdout'], 'payment_token=raw-leak'),
        'Core CLI payment ledger preflight returns safe JSON for invalid URL-encoded unsafe-named packages'
    );
    $invalidLedgerPackagePath = $tmpRoot . '/storage/exports/invalid-payment-ledger.zip';
    copy($packagePath, $invalidLedgerPackagePath);
    $invalidZip = new ZipArchive();
    if ($invalidZip->open($invalidLedgerPackagePath) === true) {
        $invalidPayload = '{"schema_version":"1.0.0","exported_at":"2026-08-20T00:00:00+00:00","counts":{"payments":0},"payments":[]}';
        $invalidManifest = $manifest;
        $invalidManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', $invalidPayload);
        $invalidZip->addFromString('payments/payment-ledger.json', $invalidPayload);
        $invalidZip->addFromString('manifest.json', json_encode($invalidManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $invalidZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($invalidLedgerPackagePath),
        'Core official export reader rejects structurally invalid Core payment ledgers'
    );
    $mismatchedCountsPackagePath = $tmpRoot . '/storage/exports/mismatched-counts-payment-ledger.zip';
    copy($packagePath, $mismatchedCountsPackagePath);
    $mismatchedCountsZip = new ZipArchive();
    if ($mismatchedCountsZip->open($mismatchedCountsPackagePath) === true) {
        $mismatchedLedger = $ledger;
        $mismatchedLedger['counts']['payments'] = count($ledger['payments'] ?? []) + 1;
        $mismatchedPayload = json_encode($mismatchedLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $mismatchedManifest = $manifest;
        $mismatchedManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', (string) $mismatchedPayload);
        $mismatchedCountsZip->addFromString('payments/payment-ledger.json', (string) $mismatchedPayload);
        $mismatchedCountsZip->addFromString('manifest.json', json_encode($mismatchedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $mismatchedCountsZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($mismatchedCountsPackagePath),
        'Core official export reader rejects Core payment ledger count mismatches'
    );
    $nonCanonicalCountsPackagePath = $tmpRoot . '/storage/exports/non-canonical-counts-payment-ledger.zip';
    copy($packagePath, $nonCanonicalCountsPackagePath);
    $nonCanonicalCountsZip = new ZipArchive();
    if ($nonCanonicalCountsZip->open($nonCanonicalCountsPackagePath) === true) {
        $nonCanonicalCountsLedger = $ledger;
        $nonCanonicalCountsLedger['counts']['payments'] = count($ledger['payments'] ?? []) . 'junk';
        $nonCanonicalCountsPayload = json_encode($nonCanonicalCountsLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonCanonicalCountsManifest = $manifest;
        $nonCanonicalCountsManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', (string) $nonCanonicalCountsPayload);
        $nonCanonicalCountsManifest['payloads']['payments/payment-ledger.json']['counts']['payments'] = (string) count($ledger['payments'] ?? []);
        $nonCanonicalCountsZip->addFromString('payments/payment-ledger.json', (string) $nonCanonicalCountsPayload);
        $nonCanonicalCountsZip->addFromString('manifest.json', json_encode($nonCanonicalCountsManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $nonCanonicalCountsZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($nonCanonicalCountsPackagePath),
        'Core official export reader rejects non-canonical Core payment ledger counts before integer coercion'
    );
    $nonCanonicalExportTimePackagePath = $tmpRoot . '/storage/exports/non-canonical-export-time-payment-ledger.zip';
    copy($packagePath, $nonCanonicalExportTimePackagePath);
    $nonCanonicalExportTimeZip = new ZipArchive();
    if ($nonCanonicalExportTimeZip->open($nonCanonicalExportTimePackagePath) === true) {
        $nonCanonicalExportTimeLedger = $ledger;
        $nonCanonicalExportTimeLedger['exported_at'] = ' ' . (string) ($ledger['exported_at'] ?? '2026-08-20T00:00:00+00:00') . ' ';
        $nonCanonicalExportTimePayload = json_encode($nonCanonicalExportTimeLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonCanonicalExportTimeManifest = $manifest;
        $nonCanonicalExportTimeManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', (string) $nonCanonicalExportTimePayload);
        $nonCanonicalExportTimeManifest['payloads']['payments/payment-ledger.json']['exported_at'] = $nonCanonicalExportTimeLedger['exported_at'];
        $nonCanonicalExportTimeZip->addFromString('payments/payment-ledger.json', (string) $nonCanonicalExportTimePayload);
        $nonCanonicalExportTimeZip->addFromString('manifest.json', json_encode($nonCanonicalExportTimeManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $nonCanonicalExportTimeZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($nonCanonicalExportTimePackagePath),
        'Core official export reader rejects non-canonical Core payment ledger export times before preflight'
    );
    $arraySummaryTypePackagePath = $tmpRoot . '/storage/exports/array-summary-type-payment-ledger.zip';
    copy($packagePath, $arraySummaryTypePackagePath);
    $arraySummaryTypeZip = new ZipArchive();
    if ($arraySummaryTypeZip->open($arraySummaryTypePackagePath) === true) {
        $arraySummaryTypeManifest = $manifest;
        $arraySummaryTypeManifest['payloads']['payments/payment-ledger.json']['type'] = ['core_payment_ledger'];
        $arraySummaryTypeZip->addFromString('manifest.json', json_encode($arraySummaryTypeManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $arraySummaryTypeZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($arraySummaryTypePackagePath),
        'Core official export reader rejects array-shaped Core payment ledger manifest summary types before string coercion'
    );
    $arraySchemaPackagePath = $tmpRoot . '/storage/exports/array-schema-payment-ledger.zip';
    copy($packagePath, $arraySchemaPackagePath);
    $arraySchemaZip = new ZipArchive();
    if ($arraySchemaZip->open($arraySchemaPackagePath) === true) {
        $arraySchemaLedger = $ledger;
        $arraySchemaLedger['schema_version'] = ['1.0.0'];
        $arraySchemaPayload = json_encode($arraySchemaLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $arraySchemaManifest = $manifest;
        $arraySchemaManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', (string) $arraySchemaPayload);
        $arraySchemaManifest['payloads']['payments/payment-ledger.json']['schema_version'] = '1.0.0';
        $arraySchemaZip->addFromString('payments/payment-ledger.json', (string) $arraySchemaPayload);
        $arraySchemaZip->addFromString('manifest.json', json_encode($arraySchemaManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $arraySchemaZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($arraySchemaPackagePath),
        'Core official export reader rejects array-shaped Core payment ledger schema versions before string coercion'
    );
    $mismatchedSummaryPackagePath = $tmpRoot . '/storage/exports/mismatched-summary-payment-ledger.zip';
    copy($packagePath, $mismatchedSummaryPackagePath);
    $mismatchedSummaryZip = new ZipArchive();
    if ($mismatchedSummaryZip->open($mismatchedSummaryPackagePath) === true) {
        $mismatchedSummaryManifest = $manifest;
        $mismatchedSummaryManifest['payloads']['payments/payment-ledger.json']['counts']['payments'] = count($ledger['payments'] ?? []) + 1;
        $mismatchedSummaryZip->addFromString('manifest.json', json_encode($mismatchedSummaryManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $mismatchedSummaryZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($mismatchedSummaryPackagePath),
        'Core official export reader rejects Core payment ledger manifest summary mismatches'
    );
    $unsupportedLedgerPackagePath = $tmpRoot . '/storage/exports/unsupported-payment-ledger.zip';
    copy($packagePath, $unsupportedLedgerPackagePath);
    $unsupportedZip = new ZipArchive();
    if ($unsupportedZip->open($unsupportedLedgerPackagePath) === true) {
        $unsupportedLedger = $ledger;
        $unsupportedLedger['schema_version'] = '9.9.9';
        $unsupportedPayload = json_encode($unsupportedLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $unsupportedManifest = $manifest;
        $unsupportedManifest['checksums']['payments/payment-ledger.json'] = hash('sha256', (string) $unsupportedPayload);
        $unsupportedZip->addFromString('payments/payment-ledger.json', (string) $unsupportedPayload);
        $unsupportedZip->addFromString('manifest.json', json_encode($unsupportedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $unsupportedZip->close();
    }
    core_payment_throws(
        static fn () => $reader->paymentLedger($unsupportedLedgerPackagePath),
        'Core official export reader rejects unsupported Core payment ledger schema versions'
    );
}
$providerPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
core_payment_check(
    $providerPage->status() === 200
    && (string) ($providerPage->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($providerPage->body(), FixturePaymentProvider::PROVIDER_ID)
    && str_contains($providerPage->body(), 'core.status-only')
    && str_contains($providerPage->body(), '密钥掩码')
    && str_contains($providerPage->body(), '<th>诊断</th>')
    && str_contains($providerPage->body(), '可用于 Core 支付')
    && str_contains($providerPage->body(), '<td>无效</td>')
    && str_contains($providerPage->body(), '默认 Provider 缺少收款能力')
    && str_contains($providerPage->body(), '托管跳转收银台 URL 未配置')
    && str_contains($providerPage->body(), 'Webhook 密钥未配置'),
    'Core admin payment provider settings page renders no-store registered providers with Core diagnostics and invalid default markers'
);
$legacyHostedCiphertext = (string) ($adminPdo->query("SELECT secret_config_ciphertext FROM cms_payment_provider_settings WHERE provider_id = 'core.hosted-redirect'")->fetchColumn() ?: '');
$adminPdo->prepare(
    'INSERT INTO cms_payment_provider_settings
        (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at)
     VALUES
        (:provider_id, :display_name, :status, :public_config_json, :secret_config_ciphertext, :created_at, :updated_at)'
)->execute([
    ':provider_id' => ' CORE.HOSTED-REDIRECT ',
    ':display_name' => 'Legacy Non Canonical Hosted Redirect',
    ':status' => 'enabled',
    ':public_config_json' => json_encode([
        'default_provider' => true,
        'checkout_url' => 'https://psp.example.test/pay',
        'return_url_base' => 'https://cms.example.test',
    ], JSON_UNESCAPED_SLASHES),
    ':secret_config_ciphertext' => $legacyHostedCiphertext,
    ':created_at' => gmdate('c'),
    ':updated_at' => gmdate('c'),
]);
$checkoutProviderIds = array_column((new PaymentProviderSelector($adminPdo, $settings))->enabledProviders(), 'id');
core_payment_check(
    (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID
    && in_array(FixturePaymentProvider::PROVIDER_ID, $checkoutProviderIds, true)
    && !in_array(HostedRedirectPaymentProvider::PROVIDER_ID, $checkoutProviderIds, true)
    && !in_array('core.status-only', $checkoutProviderIds, true),
    'Core checkout Provider selector ignores non-canonical imported Provider ids, enabled default Providers and configured Providers that cannot create payments'
);
$defaultAfterCorruptProvider = $adminProviderSettings->setDefaultProvider(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    (string) ($defaultAfterCorruptProvider['provider_id'] ?? '') === FixturePaymentProvider::PROVIDER_ID
    && (json_decode((string) ($defaultAfterCorruptProvider['public_config_json'] ?? '{}'), true)['default_provider'] ?? null) === true
    && (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID,
    'Core payment Provider settings can set defaults while skipping non-canonical imported Provider rows'
);
$adminProviderSettings->save(ManualPaymentProvider::PROVIDER_ID, 'Manual Payment Default Target', 'enabled', [
    'instructions' => 'Manual operator instructions.',
], ['webhook_secret' => 'whsec_manual_default_target']);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => ManualPaymentProvider::PROVIDER_ID,
    ':public_config_json' => '{"instructions": "Manual operator instructions."}',
]);
$manualFormattedPublicConfigBefore = (string) ($adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '');
core_payment_throws(
    static fn () => $adminProviderSettings->setDefaultProvider(ManualPaymentProvider::PROVIDER_ID),
    'Core payment Provider settings reject formatted non-canonical public config before setting defaults'
);
$manualFormattedPublicConfigAfter = (string) ($adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '');
core_payment_check(
    $manualFormattedPublicConfigAfter === $manualFormattedPublicConfigBefore
    && (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID,
    'Core payment Provider settings do not rewrite non-canonical public config during rejected default changes'
);
$adminProviderSettings->save(ManualPaymentProvider::PROVIDER_ID, 'Manual Payment Default Target', 'enabled', [
    'instructions' => 'Manual operator instructions.',
], ['webhook_secret' => 'whsec_manual_default_target']);
$adminPdo->prepare('DELETE FROM cms_payment_provider_settings WHERE provider_id = :provider_id')->execute([
    ':provider_id' => ' CORE.HOSTED-REDIRECT ',
]);
core_payment_throws(
    static fn () => (new PaymentProviderSelector($adminPdo, $settings))->requireEnabled('core.status-only'),
    'Core checkout Provider selector rejects explicitly requested Providers without payment.create capability'
);
core_payment_throws(
    static fn () => (new PaymentProviderSelector($adminPdo, $settings))->requireEnabled(' ' . FixturePaymentProvider::PROVIDER_ID . ' '),
    'Core checkout Provider selector rejects non-canonical explicit Provider ids'
);
core_payment_throws(
    static fn () => (new PaymentProviderSelector($adminPdo, $settings))->requireEnabled(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested hosted redirect Providers with unavailable checkout configuration'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Imported Default', 'disabled', [], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminPdo->prepare(
    "UPDATE cms_payment_provider_settings SET status = 'enabled', public_config_json = :public_config_json WHERE provider_id = :provider_id"
)->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode(['default_provider' => true], JSON_UNESCAPED_SLASHES),
]);
$invalidHostedDefaultPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$invalidHostedDefaultPosition = strpos($invalidHostedDefaultPage->body(), HostedRedirectPaymentProvider::PROVIDER_ID);
$invalidHostedDefaultLabelPosition = strpos($invalidHostedDefaultPage->body(), '<td>无效</td>', is_int($invalidHostedDefaultPosition) ? $invalidHostedDefaultPosition : 0);
core_payment_check(
    (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID
    && $invalidHostedDefaultPage->status() === 200
    && $invalidHostedDefaultLabelPosition !== false,
    'Core checkout Provider selector and admin default label ignore invalid hosted redirect default markers'
);
$adminPdo->prepare(
    "UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id"
)->execute([
    ':provider_id' => ManualPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'instructions' => 'Manual imported non-boolean default marker.',
        'default_provider' => 'yes',
    ], JSON_UNESCAPED_SLASHES),
]);
$nonBooleanDefaultPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$manualDefaultPosition = strpos($nonBooleanDefaultPage->body(), ManualPaymentProvider::PROVIDER_ID);
$manualInvalidDefaultPosition = strpos($nonBooleanDefaultPage->body(), '<td>无效</td>', is_int($manualDefaultPosition) ? $manualDefaultPosition : 0);
core_payment_check(
    (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID
    && $nonBooleanDefaultPage->status() === 200
    && $manualInvalidDefaultPosition !== false
    && str_contains($nonBooleanDefaultPage->body(), '默认标记不是 Core 布尔值'),
    'Core checkout Provider selector and admin default label reject non-boolean imported default markers'
);
core_payment_throws(
    static fn () => (new PaymentProviderSelector($adminPdo, $settings))->requireEnabled(ManualPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicit Providers with non-boolean imported default markers before checkout'
);
$adminProviderSettings->save('core.status-only', 'Status Only', 'enabled', ['mode' => 'status-only'], ['webhook_secret' => 'whsec_status_only']);
core_payment_throws(
    static fn () => $adminProviderSettings->setDefaultProvider('core.status-only'),
    'Core payment Provider settings repository rejects default Providers that cannot create payments'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Non Canonical Status', ' Enabled ', ['mode' => 'non-canonical-status'], []),
    'Core payment Provider settings repository rejects non-canonical setting statuses'
);
$providerSettingsAuditBeforeQueryCsrfSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$queryCsrfProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => 'Query CSRF Manual Provider',
    'status' => 'enabled',
    'public_config_json' => '{"instructions":"query csrf should not save"}',
], [], []));
$manualConfigAfterQueryCsrfSave = json_decode((string) ($adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $queryCsrfProviderSave->status() === 403
    && (string) ($queryCsrfProviderSave->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && !str_contains((string) ($manualConfigAfterQueryCsrfSave['instructions'] ?? ''), 'query csrf should not save')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditBeforeQueryCsrfSave,
    'Core admin payment Provider settings save accepts CSRF only from POST body with no-store responses before persisting settings or audit'
);
$arrayCsrfProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => [$csrf],
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => 'Array CSRF Manual Provider',
    'status' => 'enabled',
    'public_config_json' => '{"instructions":"array csrf should not save"}',
], []));
$manualConfigAfterArrayCsrfSave = json_decode((string) ($adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $arrayCsrfProviderSave->status() === 403
    && (string) ($arrayCsrfProviderSave->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && !str_contains((string) ($manualConfigAfterArrayCsrfSave['instructions'] ?? ''), 'array csrf should not save')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditBeforeQueryCsrfSave,
    'Core admin payment Provider settings save rejects non-scalar CSRF before persisting settings or audit'
);
$statusOnlyDefaultSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => 'core.status-only',
    'display_name' => 'Status Only Default',
    'status' => 'enabled',
    'default_provider' => '1',
    'public_config_json' => '{"mode":"status-only"}',
    'secrets_text' => '',
], []));
$statusOnlyDefaultConfig = json_decode((string) ($adminProviderSettings->setting('core.status-only')['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $statusOnlyDefaultSave->status() === 400
    && (string) ($statusOnlyDefaultSave->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($statusOnlyDefaultSave->body(), '默认支付 Provider 必须支持创建支付')
    && !($statusOnlyDefaultConfig['default_provider'] ?? false)
    && (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID,
    'Core admin payment Provider settings reject default Providers that cannot create payments'
);
$providerSettingsAuditBeforeNonCanonicalProviderSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$nonCanonicalProviderSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$nonCanonicalProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' ',
    'display_name' => 'Non Canonical Provider',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"non-canonical-provider"}',
    'secrets_text' => '',
], []));
$nonCanonicalProviderSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $nonCanonicalProviderSave->status() === 400
    && (string) ($nonCanonicalProviderSettingAfter['display_name'] ?? '') === (string) ($nonCanonicalProviderSettingBefore['display_name'] ?? '')
    && (string) ($nonCanonicalProviderSettingAfter['public_config_json'] ?? '') === (string) ($nonCanonicalProviderSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditBeforeNonCanonicalProviderSave,
    'Core admin payment Provider settings reject non-canonical Provider ids before saving settings or audit'
);
$providerSettingsAuditBeforeArrayBodySave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$arrayProviderSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$arrayProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => [FixturePaymentProvider::PROVIDER_ID],
    'display_name' => 'Array Provider',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"array-provider"}',
    'secrets_text' => '',
], []));
$arrayStatusSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Array Status',
    'status' => ['enabled'],
    'public_config_json' => '{"mode":"array-status"}',
    'secrets_text' => '',
], []));
$arrayPublicConfigSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Array Public Config',
    'status' => 'enabled',
    'public_config_json' => ['{"mode":"array-public-config"}'],
    'secrets_text' => '',
], []));
$arrayDisplayNameSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => ['Array Display'],
    'status' => 'enabled',
    'public_config_json' => '{"mode":"array-display"}',
    'secrets_text' => '',
], []));
$arrayDefaultProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Array Default',
    'status' => 'enabled',
    'default_provider' => ['1'],
    'public_config_json' => '{"mode":"array-default"}',
    'secrets_text' => '',
], []));
$arraySecretsTextSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Array Secrets',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"array-secrets"}',
    'secrets_text' => ['api_secret=sk_array_secret'],
], []));
$booleanProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => true,
    'display_name' => 'Boolean Provider',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"boolean-provider"}',
    'secrets_text' => '',
], []));
$booleanStatusSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Boolean Status',
    'status' => true,
    'public_config_json' => '{"mode":"boolean-status"}',
    'secrets_text' => '',
], []));
$booleanDefaultProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Boolean Default',
    'status' => 'enabled',
    'default_provider' => true,
    'public_config_json' => '{"mode":"boolean-default"}',
    'secrets_text' => '',
], []));
$arrayProviderSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $arrayProviderSave->status() === 400
    && $arrayStatusSave->status() === 400
    && $arrayPublicConfigSave->status() === 400
    && $arrayDisplayNameSave->status() === 400
    && $arrayDefaultProviderSave->status() === 400
    && $arraySecretsTextSave->status() === 400
    && $booleanProviderSave->status() === 400
    && $booleanStatusSave->status() === 400
    && $booleanDefaultProviderSave->status() === 400
    && (string) ($arrayProviderSettingAfter['display_name'] ?? '') === (string) ($arrayProviderSettingBefore['display_name'] ?? '')
    && (string) ($arrayProviderSettingAfter['public_config_json'] ?? '') === (string) ($arrayProviderSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditBeforeArrayBodySave,
    'Core admin payment Provider settings reject non-string Provider, status, public config, display, default and secret fields before saving settings or audit'
);
$providerSettingsAuditBeforeNonCanonicalStatusSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$nonCanonicalStatusSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$nonCanonicalStatusSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Non Canonical Status',
    'status' => ' Enabled ',
    'public_config_json' => '{"mode":"non-canonical-status"}',
    'secrets_text' => '',
], []));
$nonCanonicalStatusSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $nonCanonicalStatusSave->status() === 400
    && str_contains($nonCanonicalStatusSave->body(), 'Provider 启用状态无效')
    && (string) ($nonCanonicalStatusSettingAfter['display_name'] ?? '') === (string) ($nonCanonicalStatusSettingBefore['display_name'] ?? '')
    && (string) ($nonCanonicalStatusSettingAfter['public_config_json'] ?? '') === (string) ($nonCanonicalStatusSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditBeforeNonCanonicalStatusSave,
    'Core admin payment Provider settings reject non-canonical statuses before saving settings or audit'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json, secret_config_ciphertext = :ciphertext WHERE provider_id = :provider_id')->execute([
    ':public_config_json' => '{"broken":',
    ':ciphertext' => 'not-a-valid-core-secret-ciphertext',
    ':provider_id' => 'core.status-only',
]);
$corruptProviderPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
core_payment_check(
    $corruptProviderPage->status() === 200
    && str_contains($corruptProviderPage->body(), 'core.status-only')
    && str_contains($corruptProviderPage->body(), '公共配置 JSON 无法解析')
    && str_contains($corruptProviderPage->body(), '公共配置不可用')
    && str_contains($corruptProviderPage->body(), '密钥无法解密')
    && str_contains($corruptProviderPage->body(), '密钥不可用'),
    'Core admin payment Provider settings page survives imported Provider config corruption'
);
$providerSettingsAuditBeforeCorruptSecretPreserve = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$corruptSecretSettingBefore = $adminProviderSettings->setting('core.status-only');
$corruptSecretPreserveSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => 'core.status-only',
    'display_name' => 'Status Only Corrupt Secret Preserve',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"status-only-recovered-public"}',
    'secrets_text' => '',
], []));
$corruptSecretSettingAfter = $adminProviderSettings->setting('core.status-only');
core_payment_check(
    $corruptSecretPreserveSave->status() === 400
    && str_contains($corruptSecretPreserveSave->body(), '密钥配置')
    && (string) ($corruptSecretSettingAfter['display_name'] ?? '') === (string) ($corruptSecretSettingBefore['display_name'] ?? '')
    && (string) ($corruptSecretSettingAfter['public_config_json'] ?? '') === (string) ($corruptSecretSettingBefore['public_config_json'] ?? '')
    && (string) ($corruptSecretSettingAfter['secret_config_ciphertext'] ?? '') === (string) ($corruptSecretSettingBefore['secret_config_ciphertext'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditBeforeCorruptSecretPreserve,
    'Core admin payment Provider settings reject blank secret preservation when stored secret ciphertext is corrupt'
);
$statusOnlyRecoveredSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => 'core.status-only',
    'display_name' => 'Status Only Recovered',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"status-only-recovered"}',
    'secrets_text' => 'webhook_secret=whsec_status_only',
], []));
core_payment_check(
    $statusOnlyRecoveredSave->status() === 302
    && (string) ($adminProviderSettings->setting('core.status-only')['display_name'] ?? '') === 'Status Only Recovered',
    'Core admin payment Provider settings can replace corrupt secret ciphertext with new Core secrets'
);
$hostedRedirectInvalidSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Invalid',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":"http://psp.example.test/pay","return_url_base":"https://cms.example.test"}',
    'secrets_text' => 'webhook_secret=whsec_hosted_redirect',
], []));
$hostedRedirectInvalidConfig = json_decode((string) ($adminProviderSettings->setting(HostedRedirectPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $hostedRedirectInvalidSave->status() === 400
    && str_contains($hostedRedirectInvalidSave->body(), '托管跳转收银台 URL 必须使用 HTTPS')
    && !array_key_exists('checkout_url', $hostedRedirectInvalidConfig),
    'Core admin payment Provider settings reject unsafe hosted redirect checkout URLs before saving'
);
$hostedRedirectNonStringUrlAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$hostedRedirectNonStringUrlSettingBefore = $adminProviderSettings->setting(HostedRedirectPaymentProvider::PROVIDER_ID);
$hostedRedirectNonStringCheckoutSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Non String Checkout',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":["https://psp.example.test/pay"],"return_url_base":"https://cms.example.test"}',
    'secrets_text' => 'webhook_secret=whsec_hosted_redirect',
], []));
$hostedRedirectNonStringReturnSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Non String Return',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":"https://psp.example.test/pay","return_url_base":["https://cms.example.test"]}',
    'secrets_text' => 'webhook_secret=whsec_hosted_redirect',
], []));
$hostedRedirectNonStringUrlSettingAfter = $adminProviderSettings->setting(HostedRedirectPaymentProvider::PROVIDER_ID);
core_payment_check(
    $hostedRedirectNonStringCheckoutSave->status() === 400
    && $hostedRedirectNonStringReturnSave->status() === 400
    && str_contains($hostedRedirectNonStringCheckoutSave->body(), '托管跳转收银台 URL 必须是字符串')
    && str_contains($hostedRedirectNonStringReturnSave->body(), '托管跳转回跳域名必须是字符串')
    && (string) ($hostedRedirectNonStringUrlSettingAfter['display_name'] ?? '') === (string) ($hostedRedirectNonStringUrlSettingBefore['display_name'] ?? '')
    && (string) ($hostedRedirectNonStringUrlSettingAfter['public_config_json'] ?? '') === (string) ($hostedRedirectNonStringUrlSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $hostedRedirectNonStringUrlAuditBefore,
    'Core admin payment Provider settings reject non-string hosted redirect URLs before saving settings or audit'
);
$hostedRedirectSensitiveQuerySave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Sensitive Query',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":"https://psp.example.test/pay?api_key=pk_live","return_url_base":"https://cms.example.test"}',
    'secrets_text' => 'webhook_secret=whsec_hosted_redirect',
], []));
core_payment_check(
    $hostedRedirectSensitiveQuerySave->status() === 400
    && str_contains($hostedRedirectSensitiveQuerySave->body(), '托管跳转收银台 URL 不能包含敏感查询参数'),
    'Core admin payment Provider settings reject hosted redirect public URLs with sensitive query parameters'
);
$hostedRedirectFragmentSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Fragment',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":"https://psp.example.test/pay#checkout","return_url_base":"https://cms.example.test"}',
    'secrets_text' => 'webhook_secret=whsec_hosted_redirect',
], []));
core_payment_check(
    $hostedRedirectFragmentSave->status() === 400
    && str_contains($hostedRedirectFragmentSave->body(), '托管跳转收银台 URL 必须使用 HTTPS'),
    'Core admin payment Provider settings reject hosted redirect checkout URLs with fragments'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Spaced URL', 'enabled', [
        'default_provider' => true,
        'checkout_url' => ' https://psp.example.test/pay ',
        'return_url_base' => 'https://cms.example.test',
    ], ['webhook_secret' => 'whsec_hosted_redirect']),
    'Core payment Provider settings repository rejects non-canonical hosted redirect checkout URLs before saving enabled defaults'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Userinfo URL', 'enabled', [
        'default_provider' => true,
        'checkout_url' => 'https://operator:secret@psp.example.test/pay',
        'return_url_base' => 'https://cms.example.test',
    ], ['webhook_secret' => 'whsec_hosted_redirect']),
    'Core payment Provider settings repository rejects hosted redirect checkout URLs with userinfo before saving enabled defaults'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Query Return Base', 'enabled', [
        'default_provider' => true,
        'checkout_url' => 'https://psp.example.test/pay',
        'return_url_base' => 'https://cms.example.test/return?source=checkout',
    ], ['webhook_secret' => 'whsec_hosted_redirect']),
    'Core payment Provider settings repository rejects hosted redirect return URL bases with query parameters before saving enabled defaults'
);
$hostedRedirectReturnBaseQuerySave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Query Return Base',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":"https://psp.example.test/pay","return_url_base":"https://cms.example.test/return?source=checkout"}',
    'secrets_text' => 'webhook_secret=whsec_hosted_redirect',
], []));
core_payment_check(
    $hostedRedirectReturnBaseQuerySave->status() === 400,
    'Core admin payment Provider settings reject hosted redirect return URL bases with query parameters'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Imported Sensitive Query', 'disabled', [
    'checkout_url' => 'https://psp.example.test/pay',
], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminPdo->prepare(
    "UPDATE cms_payment_provider_settings SET status = 'enabled', public_config_json = :public_config_json WHERE provider_id = :provider_id"
)->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'default_provider' => true,
        'checkout_url' => 'https://psp.example.test/pay?api_key=pk_live',
        'return_url_base' => 'https://cms.example.test/complete?return_token=unsafe',
    ], JSON_UNESCAPED_SLASHES),
]);
$sensitiveImportedSelector = new PaymentProviderSelector($adminPdo, $settings);
$sensitiveImportedProviderIds = array_column($sensitiveImportedSelector->enabledProviders(), 'id');
core_payment_check(
    $sensitiveImportedSelector->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID
    && !in_array(HostedRedirectPaymentProvider::PROVIDER_ID, $sensitiveImportedProviderIds, true),
    'Core checkout Provider selector ignores imported hosted redirect defaults with sensitive public URL query parameters'
);
core_payment_throws(
    static fn () => $sensitiveImportedSelector->requireEnabled(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested hosted redirect Providers with sensitive public URL query parameters'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Imported Return Query', 'disabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminPdo->prepare(
    "UPDATE cms_payment_provider_settings SET status = 'enabled', public_config_json = :public_config_json WHERE provider_id = :provider_id"
)->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'default_provider' => true,
        'checkout_url' => 'https://psp.example.test/pay',
        'return_url_base' => 'https://cms.example.test/return?source=checkout',
    ], JSON_UNESCAPED_SLASHES),
]);
$returnQueryImportedSelector = new PaymentProviderSelector($adminPdo, $settings);
$returnQueryImportedProviderIds = array_column($returnQueryImportedSelector->enabledProviders(), 'id');
core_payment_check(
    $returnQueryImportedSelector->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID
    && !in_array(HostedRedirectPaymentProvider::PROVIDER_ID, $returnQueryImportedProviderIds, true),
    'Core checkout Provider selector ignores imported hosted redirect defaults with return URL base query parameters'
);
core_payment_throws(
    static fn () => $returnQueryImportedSelector->requireEnabled(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested hosted redirect Providers with return URL base query parameters'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Imported Non String URL', 'disabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminPdo->prepare("UPDATE cms_payment_provider_settings SET status = 'enabled', public_config_json = :public_config_json WHERE provider_id = :provider_id")->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'default_provider' => true,
        'checkout_url' => true,
        'return_url_base' => 'https://cms.example.test',
    ], JSON_UNESCAPED_SLASHES),
]);
$nonStringUrlImportedSelector = new PaymentProviderSelector($adminPdo, $settings);
$nonStringUrlImportedProviderIds = array_column($nonStringUrlImportedSelector->enabledProviders(), 'id');
core_payment_check(
    $nonStringUrlImportedSelector->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID
    && !in_array(HostedRedirectPaymentProvider::PROVIDER_ID, $nonStringUrlImportedProviderIds, true),
    'Core checkout Provider selector ignores imported hosted redirect Providers with non-string public URLs'
);
core_payment_throws(
    static fn () => $nonStringUrlImportedSelector->requireEnabled(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested hosted redirect Providers with non-string public URLs'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Imported Spaced URL', 'disabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminPdo->prepare("UPDATE cms_payment_provider_settings SET status = 'enabled', public_config_json = :public_config_json WHERE provider_id = :provider_id")->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'checkout_url' => ' https://psp.example.test/pay ',
        'return_url_base' => 'https://cms.example.test',
    ], JSON_UNESCAPED_SLASHES),
]);
$spacedImportedSelector = new PaymentProviderSelector($adminPdo, $settings);
$spacedImportedProviderIds = array_column($spacedImportedSelector->enabledProviders(), 'id');
core_payment_check(
    !in_array(HostedRedirectPaymentProvider::PROVIDER_ID, $spacedImportedProviderIds, true),
    'Core checkout Provider selector ignores imported hosted redirect Providers with non-canonical public URLs'
);
core_payment_throws(
    static fn () => $spacedImportedSelector->requireEnabled(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested hosted redirect Providers with non-canonical public URLs'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect Imported Userinfo URL', 'disabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], ['webhook_secret' => 'whsec_hosted_redirect']);
$adminPdo->prepare("UPDATE cms_payment_provider_settings SET status = 'enabled', public_config_json = :public_config_json WHERE provider_id = :provider_id")->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'checkout_url' => 'https://operator:secret@psp.example.test/pay',
        'return_url_base' => 'https://cms.example.test#return',
    ], JSON_UNESCAPED_SLASHES),
]);
$userinfoImportedSelector = new PaymentProviderSelector($adminPdo, $settings);
$userinfoImportedProviderIds = array_column($userinfoImportedSelector->enabledProviders(), 'id');
core_payment_check(
    !in_array(HostedRedirectPaymentProvider::PROVIDER_ID, $userinfoImportedProviderIds, true),
    'Core checkout Provider selector ignores imported hosted redirect Providers with userinfo or fragment public URLs'
);
core_payment_throws(
    static fn () => $userinfoImportedSelector->requireEnabled(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested hosted redirect Providers with userinfo or fragment public URLs'
);
core_payment_throws(
    static fn () => $adminProviderSettings->setDefaultProvider(HostedRedirectPaymentProvider::PROVIDER_ID),
    'Core payment Provider settings repository rejects unsafe hosted redirect default Provider configuration'
);
$hostedRedirectValidSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => 'Hosted Redirect Valid',
    'status' => 'enabled',
    'public_config_json' => '{"checkout_url":"https://psp.example.test/pay","return_url_base":"https://cms.example.test"}',
    'secrets_text' => "checkout_secret=hosted-checkout-secret\nwebhook_secret=whsec_hosted_redirect",
], []));
$hostedRedirectValidConfig = json_decode((string) ($adminProviderSettings->setting(HostedRedirectPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $hostedRedirectValidSave->status() === 302
    && (string) ($hostedRedirectValidSave->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($hostedRedirectValidConfig['checkout_url'] ?? '') === 'https://psp.example.test/pay'
    && ($adminProviderSettings->secrets(HostedRedirectPaymentProvider::PROVIDER_ID)['webhook_secret'] ?? '') === 'whsec_hosted_redirect',
    'Core admin payment Provider settings save valid hosted redirect checkout configuration with no-store redirect'
);
$adminProviderSettings->setDefaultProvider(HostedRedirectPaymentProvider::PROVIDER_ID);
$validHostedDefaultConfig = json_decode((string) ($adminProviderSettings->setting(HostedRedirectPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && ($validHostedDefaultConfig['default_provider'] ?? false) === true,
    'Core payment Provider settings repository accepts safe hosted redirect default Provider configuration'
);
$providerSettingsAuditCountBeforeFixtureSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$providerSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"admin-test","publishable_key":"pk_admin"}',
    'secrets_text' => "api_secret=sk_admin_987654\nwebhook_secret=whsec_admin_secret",
], []));
core_payment_check(
    $providerSave->status() === 302
    && (string) ($providerSave->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && ($adminProviderSettings->secrets(FixturePaymentProvider::PROVIDER_ID)['api_secret'] ?? '') === 'sk_admin_987654'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeFixtureSave + 1,
    'Core admin payment provider settings save encrypts secrets, writes audit and redirects no-store'
);
$providerSettingsAuditFailureDbFile = $tmpRoot . '/payment-provider-settings-audit-failure.sqlite';
$providerSettingsAuditFailurePdo = new PDO('sqlite:' . $providerSettingsAuditFailureDbFile);
$providerSettingsAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new MigrationRunner($providerSettingsAuditFailurePdo, $migrations))->run();
$providerSettingsAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_provider_settings_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.provider_settings.saved' BEGIN SELECT RAISE(ABORT, 'provider settings audit failure'); END");
$providerSettingsAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $providerSettingsAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$providerSettingsAuditFailureResponse = $providerSettingsAuditFailureController->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Audit Failure Fixture',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"audit-failure","publishable_key":"pk_audit_failure"}',
    'secrets_text' => "api_secret=sk_audit_failure\nwebhook_secret=whsec_audit_failure",
], []));
$providerSettingsAuditFailureRepo = new PaymentProviderSettingsRepository($providerSettingsAuditFailurePdo, 'core-payment-admin-settings-key');
core_payment_check(
    $providerSettingsAuditFailureResponse->status() === 500
    && $providerSettingsAuditFailureRepo->setting(FixturePaymentProvider::PROVIDER_ID) === null,
    'Core admin payment Provider settings save rolls back configuration and encrypted secrets when audit persistence fails'
);
$providerBlankSecretSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Renamed',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"admin-test-2"}',
    'secrets_text' => '',
], []));
core_payment_check(
    $providerBlankSecretSave->status() === 302
    && ($adminProviderSettings->secrets(FixturePaymentProvider::PROVIDER_ID)['api_secret'] ?? '') === 'sk_admin_987654'
    && ($adminProviderSettings->secrets(FixturePaymentProvider::PROVIDER_ID)['webhook_secret'] ?? '') === 'whsec_admin_secret',
    'Core admin payment provider settings preserve existing secrets when secret fields are left blank'
);
$providerSettingsAuditCountBeforeSpacedDisplay = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$providerSpacedDisplaySave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => ' Admin Fixture Spaced ',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"admin-test-spaced"}',
    'secrets_text' => '',
], []));
$fixtureSettingAfterSpacedDisplay = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $providerSpacedDisplaySave->status() === 400
    && is_array($fixtureSettingAfterSpacedDisplay)
    && (string) ($fixtureSettingAfterSpacedDisplay['display_name'] ?? '') === 'Admin Fixture Renamed'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeSpacedDisplay,
    'Core admin payment provider settings reject non-canonical display names before saving settings or audit'
);
$providerSettingsAuditCountBeforeTokenDisplay = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$providerTokenDisplaySave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'payment_token%3Draw-admin-provider-label-token',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"admin-test-token-display"}',
    'secrets_text' => '',
], []));
$fixtureSettingAfterTokenDisplay = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $providerTokenDisplaySave->status() === 400
    && is_array($fixtureSettingAfterTokenDisplay)
    && (string) ($fixtureSettingAfterTokenDisplay['display_name'] ?? '') === 'Admin Fixture Renamed'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeTokenDisplay,
    'Core admin payment provider settings reject token-like display names before saving settings or audit'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET display_name = :display_name WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':display_name' => ' Legacy Fixture Label ',
]);
$legacyDisplayProviders = (new PaymentProviderSelector($adminPdo, $settings))->enabledProviders();
$legacyDisplayLabels = array_column($legacyDisplayProviders, 'label', 'id');
core_payment_check(
    ($legacyDisplayLabels[FixturePaymentProvider::PROVIDER_ID] ?? '') === (new FixturePaymentProvider())->displayName()
    && ($legacyDisplayLabels[FixturePaymentProvider::PROVIDER_ID] ?? '') !== 'Legacy Fixture Label',
    'Core checkout Provider selector does not trim non-canonical legacy display names into public labels'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET display_name = :display_name WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':display_name' => 'payment_token%3Draw-public-provider-label-token',
]);
$legacyTokenDisplayPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$legacyTokenDisplayProviders = (new PaymentProviderSelector($adminPdo, $settings))->enabledProviders();
$legacyTokenDisplayLabels = array_column($legacyTokenDisplayProviders, 'label', 'id');
core_payment_check(
    $legacyTokenDisplayPage->status() === 200
    && ($legacyTokenDisplayLabels[FixturePaymentProvider::PROVIDER_ID] ?? '') === (new FixturePaymentProvider())->displayName()
    && !str_contains($legacyTokenDisplayPage->body(), 'payment_token%3Draw-public-provider-label-token')
    && !str_contains($legacyTokenDisplayPage->body(), 'payment_token=raw-public-provider-label-token')
    && !str_contains((string) ($legacyTokenDisplayLabels[FixturePaymentProvider::PROVIDER_ID] ?? ''), 'payment_token'),
    'Core Provider settings page and checkout selector fall back from token-like legacy Provider display names'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET display_name = :display_name WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':display_name' => 'Admin Fixture Renamed',
]);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-unsafe-public-config',
        'default_provider' => true,
        'api_key' => 'raw-public-config-secret',
        'checkout_url' => 'https://psp.example.test/pay?api_key=raw-public-config-key',
        'return_url_base' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$unsafePublicProviderPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$unsafePublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$unsafePublicProviderIds = array_column($unsafePublicSelector->enabledProviders(), 'id');
core_payment_check(
    $unsafePublicProviderPage->status() === 200
    && str_contains($unsafePublicProviderPage->body(), FixturePaymentProvider::PROVIDER_ID)
    && str_contains($unsafePublicProviderPage->body(), '公共配置包含不安全字段')
    && str_contains($unsafePublicProviderPage->body(), '公共配置不可用')
    && !str_contains($unsafePublicProviderPage->body(), 'raw-public-config-secret')
    && !str_contains($unsafePublicProviderPage->body(), 'raw-public-config-key'),
    'Core admin payment Provider settings page redacts unsafe legacy public config before rendering'
);
core_payment_check(
    $unsafePublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $unsafePublicProviderIds, true),
    'Core checkout Provider selector ignores enabled Providers with unsafe legacy public config'
);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_download', 'unsafe-public-config-runtime', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'unsafe-public-config-runtime-key'),
    'Core PaymentService rejects unsafe legacy Provider public config before Provider calls'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-return-base-query',
        'return_url_base' => 'https://cms.example.test/return?source=checkout',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_download', 'unsafe-return-base-runtime', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'unsafe-return-base-runtime-key'),
    'Core PaymentService rejects query-bearing Provider return URL bases before Provider calls'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-key-only-public-config',
        'api_key' => 'raw-public-config-secret',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$keyOnlyUnsafePublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$keyOnlyUnsafePublicProviderIds = array_column($keyOnlyUnsafePublicSelector->enabledProviders(), 'id');
core_payment_check(
    $keyOnlyUnsafePublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $keyOnlyUnsafePublicProviderIds, true),
    'Core checkout Provider selector ignores enabled Providers with key-like legacy public config fields'
);
core_payment_throws(
    static fn () => $keyOnlyUnsafePublicSelector->requireEnabled(FixturePaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicitly requested Providers with key-like legacy public config fields'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-token-like-public-config',
        'operator_note' => 'manual import with payment_token=raw-public-config-token',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$tokenLikeUnsafePublicPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$tokenLikeUnsafePublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$tokenLikeUnsafePublicProviderIds = array_column($tokenLikeUnsafePublicSelector->enabledProviders(), 'id');
core_payment_check(
    $tokenLikeUnsafePublicPage->status() === 200
    && str_contains($tokenLikeUnsafePublicPage->body(), '公共配置包含不安全字段')
    && str_contains($tokenLikeUnsafePublicPage->body(), '公共配置不可用')
    && !str_contains($tokenLikeUnsafePublicPage->body(), 'raw-public-config-token')
    && $tokenLikeUnsafePublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $tokenLikeUnsafePublicProviderIds, true),
    'Core admin Provider settings page and checkout selector reject token-like legacy public config values'
);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_download', 'token-like-public-config-runtime', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'token-like-public-config-runtime-key'),
    'Core PaymentService rejects token-like legacy Provider public config values before Provider calls'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-encoded-token-like-public-config',
        'operator_note' => 'manual import with payment_token%3Draw-public-config-encoded-token',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$encodedTokenLikeUnsafePublicPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$encodedTokenLikeUnsafePublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$encodedTokenLikeUnsafePublicProviderIds = array_column($encodedTokenLikeUnsafePublicSelector->enabledProviders(), 'id');
core_payment_check(
    $encodedTokenLikeUnsafePublicPage->status() === 200
    && str_contains($encodedTokenLikeUnsafePublicPage->body(), '公共配置包含不安全字段')
    && str_contains($encodedTokenLikeUnsafePublicPage->body(), '公共配置不可用')
    && !str_contains($encodedTokenLikeUnsafePublicPage->body(), 'payment_token%3Draw-public-config-encoded-token')
    && !str_contains($encodedTokenLikeUnsafePublicPage->body(), 'payment_token=raw-public-config-encoded-token')
    && $encodedTokenLikeUnsafePublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $encodedTokenLikeUnsafePublicProviderIds, true),
    'Core admin Provider settings page and checkout selector reject URL-encoded token-like legacy public config values'
);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_download', 'encoded-token-like-public-config-runtime', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'encoded-token-like-public-config-runtime-key'),
    'Core PaymentService rejects URL-encoded token-like legacy Provider public config values before Provider calls'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-token-url-public-config',
        'callback_url' => 'https://provider.example.test/callback?safe=payment_token%3Draw-public-url-token',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$tokenUrlUnsafePublicPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$tokenUrlUnsafePublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$tokenUrlUnsafePublicProviderIds = array_column($tokenUrlUnsafePublicSelector->enabledProviders(), 'id');
core_payment_check(
    $tokenUrlUnsafePublicPage->status() === 200
    && str_contains($tokenUrlUnsafePublicPage->body(), '公共配置包含不安全字段')
    && str_contains($tokenUrlUnsafePublicPage->body(), '公共配置不可用')
    && !str_contains($tokenUrlUnsafePublicPage->body(), 'raw-public-url-token')
    && $tokenUrlUnsafePublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $tokenUrlUnsafePublicProviderIds, true),
    'Core admin Provider settings page and checkout selector reject token-like legacy public config URL values'
);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_download', 'token-like-public-config-url-runtime', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'token-like-public-config-url-runtime-key'),
    'Core PaymentService rejects token-like legacy Provider public config URL values before Provider calls'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => ' {"mode":"legacy-spaced-public-json"} ',
]);
$spacedLegacyPublicJsonPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
core_payment_check(
    $spacedLegacyPublicJsonPage->status() === 200
    && str_contains($spacedLegacyPublicJsonPage->body(), '公共配置 JSON 不是规范格式')
    && str_contains($spacedLegacyPublicJsonPage->body(), '公共配置不可用')
    && !str_contains($spacedLegacyPublicJsonPage->body(), 'legacy-spaced-public-json'),
    'Core admin payment Provider settings page rejects non-canonical imported public config JSON before rendering'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => '{"mode":',
]);
$malformedLegacyPublicPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$malformedLegacyPublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$malformedLegacyPublicProviderIds = array_column($malformedLegacyPublicSelector->enabledProviders(), 'id');
core_payment_check(
    $malformedLegacyPublicPage->status() === 200
    && str_contains($malformedLegacyPublicPage->body(), '公共配置 JSON 无法解析')
    && str_contains($malformedLegacyPublicPage->body(), '公共配置不可用')
    && $malformedLegacyPublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $malformedLegacyPublicProviderIds, true),
    'Core admin Provider settings page and checkout selector reject malformed imported public config JSON'
);
core_payment_throws(
    static fn () => $malformedLegacyPublicSelector->requireEnabled(FixturePaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicit Providers with malformed public config JSON'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => '{"mode": "legacy-formatted-public-json"}',
]);
$formattedLegacyPublicPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$formattedLegacyPublicSelector = new PaymentProviderSelector($adminPdo, $settings);
$formattedLegacyPublicProviderIds = array_column($formattedLegacyPublicSelector->enabledProviders(), 'id');
core_payment_check(
    $formattedLegacyPublicPage->status() === 200
    && str_contains($formattedLegacyPublicPage->body(), '公共配置 JSON 不是规范格式')
    && str_contains($formattedLegacyPublicPage->body(), '公共配置不可用')
    && !str_contains($formattedLegacyPublicPage->body(), 'legacy-formatted-public-json')
    && $formattedLegacyPublicSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $formattedLegacyPublicProviderIds, true),
    'Core admin Provider settings page and checkout selector reject formatted non-canonical imported public config JSON'
);
core_payment_throws(
    static fn () => $formattedLegacyPublicSelector->requireEnabled(FixturePaymentProvider::PROVIDER_ID),
    'Core checkout Provider selector rejects explicit Providers with formatted non-canonical public config JSON'
);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_download', 'formatted-public-config-runtime', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'formatted-public-config-runtime-key'),
    'Core PaymentService rejects formatted non-canonical Provider public config before Provider calls'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => ' legacy-spaced-public-value ',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$spacedLegacyPublicValuePage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
core_payment_check(
    $spacedLegacyPublicValuePage->status() === 200
    && str_contains($spacedLegacyPublicValuePage->body(), '公共配置包含不安全字段')
    && str_contains($spacedLegacyPublicValuePage->body(), '公共配置不可用')
    && !str_contains($spacedLegacyPublicValuePage->body(), 'legacy-spaced-public-value'),
    'Core admin payment Provider settings page rejects non-canonical imported public config values before rendering'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'mode' => 'legacy-malformed-public-url',
        'callback_url' => 'not a url',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$malformedLegacyPublicUrlPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
$malformedLegacyPublicUrlSelector = new PaymentProviderSelector($adminPdo, $settings);
$malformedLegacyPublicUrlProviderIds = array_column($malformedLegacyPublicUrlSelector->enabledProviders(), 'id');
core_payment_check(
    $malformedLegacyPublicUrlPage->status() === 200
    && str_contains($malformedLegacyPublicUrlPage->body(), '公共配置包含不安全字段')
    && str_contains($malformedLegacyPublicUrlPage->body(), '公共配置不可用')
    && !str_contains($malformedLegacyPublicUrlPage->body(), 'legacy-malformed-public-url')
    && $malformedLegacyPublicUrlSelector->defaultProviderId() === HostedRedirectPaymentProvider::PROVIDER_ID
    && !in_array(FixturePaymentProvider::PROVIDER_ID, $malformedLegacyPublicUrlProviderIds, true),
    'Core admin Provider settings and checkout selector reject malformed imported public config URL values'
);
$adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Admin Fixture Renamed', 'enabled', ['mode' => 'admin-test-2'], []);
$providerSettingsAuditCountBeforeInvalidPublicSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$invalidPublicSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$invalidPublicSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Invalid Public',
    'status' => 'enabled',
    'public_config_json' => '{"bad key":"value"}',
    'secrets_text' => '',
], []));
$invalidPublicSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $invalidPublicSave->status() === 400
    && (string) ($invalidPublicSettingAfter['display_name'] ?? '') === (string) ($invalidPublicSettingBefore['display_name'] ?? '')
    && (string) ($invalidPublicSettingAfter['public_config_json'] ?? '') === (string) ($invalidPublicSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeInvalidPublicSave,
    'Core admin payment Provider settings reject unsafe public config before saving settings or audit'
);
$providerSettingsAuditCountBeforeTokenLikePublicSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$tokenLikePublicSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$tokenLikePublicSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Token Public',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"Bearer raw-admin-public-config-token"}',
    'secrets_text' => '',
], []));
$tokenLikePublicSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $tokenLikePublicSave->status() === 400
    && (string) ($tokenLikePublicSettingAfter['display_name'] ?? '') === (string) ($tokenLikePublicSettingBefore['display_name'] ?? '')
    && (string) ($tokenLikePublicSettingAfter['public_config_json'] ?? '') === (string) ($tokenLikePublicSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeTokenLikePublicSave,
    'Core admin payment Provider settings reject token-like public config values before saving settings or audit'
);
$providerSettingsAuditCountBeforeEncodedTokenLikePublicSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$encodedTokenLikePublicSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$encodedTokenLikePublicSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Encoded Token Public',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"payment_token%3Draw-admin-public-config-token"}',
    'secrets_text' => '',
], []));
$encodedTokenLikePublicSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $encodedTokenLikePublicSave->status() === 400
    && (string) ($encodedTokenLikePublicSettingAfter['display_name'] ?? '') === (string) ($encodedTokenLikePublicSettingBefore['display_name'] ?? '')
    && (string) ($encodedTokenLikePublicSettingAfter['public_config_json'] ?? '') === (string) ($encodedTokenLikePublicSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeEncodedTokenLikePublicSave,
    'Core admin payment Provider settings reject URL-encoded token-like public config values before saving settings or audit'
);
$providerSettingsAuditCountBeforeTokenUrlPublicSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$tokenUrlPublicSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$tokenUrlPublicSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Token URL Public',
    'status' => 'enabled',
    'public_config_json' => '{"callback_url":"https://provider.example.test/callback?safe=payment_token%3Draw-admin-public-url-token"}',
    'secrets_text' => '',
], []));
$tokenUrlPublicSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $tokenUrlPublicSave->status() === 400
    && (string) ($tokenUrlPublicSettingAfter['display_name'] ?? '') === (string) ($tokenUrlPublicSettingBefore['display_name'] ?? '')
    && (string) ($tokenUrlPublicSettingAfter['public_config_json'] ?? '') === (string) ($tokenUrlPublicSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeTokenUrlPublicSave,
    'Core admin payment Provider settings reject token-like public URL values before saving settings or audit'
);
$providerSettingsAuditCountBeforeSpacedPublicJsonSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$spacedPublicJsonSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$spacedPublicJsonSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Spaced Public JSON',
    'status' => 'enabled',
    'public_config_json' => ' {"mode":"admin-test"} ',
    'secrets_text' => '',
], []));
$spacedPublicJsonSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $spacedPublicJsonSave->status() === 400
    && (string) ($spacedPublicJsonSettingAfter['display_name'] ?? '') === (string) ($spacedPublicJsonSettingBefore['display_name'] ?? '')
    && (string) ($spacedPublicJsonSettingAfter['public_config_json'] ?? '') === (string) ($spacedPublicJsonSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeSpacedPublicJsonSave,
    'Core admin payment Provider settings reject non-canonical public config JSON before saving settings or audit'
);
$providerSettingsAuditCountBeforeFormattedPublicJsonSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$formattedPublicJsonSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$formattedPublicJsonSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Formatted Public JSON',
    'status' => 'enabled',
    'public_config_json' => '{ "mode": "admin-test" }',
    'secrets_text' => '',
], []));
$formattedPublicJsonSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $formattedPublicJsonSave->status() === 400
    && (string) ($formattedPublicJsonSettingAfter['display_name'] ?? '') === (string) ($formattedPublicJsonSettingBefore['display_name'] ?? '')
    && (string) ($formattedPublicJsonSettingAfter['public_config_json'] ?? '') === (string) ($formattedPublicJsonSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeFormattedPublicJsonSave,
    'Core admin payment Provider settings reject formatted public config JSON before saving settings or audit'
);
$providerSettingsAuditCountBeforeNestedPublicSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$nestedPublicSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$nestedPublicSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Nested Public',
    'status' => 'enabled',
    'public_config_json' => '{"mode":{"nested":true}}',
    'secrets_text' => '',
], []));
$nestedPublicSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $nestedPublicSave->status() === 400
    && (string) ($nestedPublicSettingAfter['display_name'] ?? '') === (string) ($nestedPublicSettingBefore['display_name'] ?? '')
    && (string) ($nestedPublicSettingAfter['public_config_json'] ?? '') === (string) ($nestedPublicSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeNestedPublicSave,
    'Core admin payment Provider settings reject non-scalar public config before saving settings or audit'
);
$providerSettingsAuditCountBeforeSpacedPublicSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$spacedPublicSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$spacedPublicSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Spaced Public',
    'status' => 'enabled',
    'public_config_json' => '{"mode":" admin-test "}',
    'secrets_text' => '',
], []));
$spacedPublicSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $spacedPublicSave->status() === 400
    && (string) ($spacedPublicSettingAfter['display_name'] ?? '') === (string) ($spacedPublicSettingBefore['display_name'] ?? '')
    && (string) ($spacedPublicSettingAfter['public_config_json'] ?? '') === (string) ($spacedPublicSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeSpacedPublicSave,
    'Core admin payment Provider settings reject non-canonical public config values before saving settings or audit'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Invalid Secret Key', 'enabled', ['mode' => 'direct-test'], ['bad key' => 'value']),
    'Core payment Provider settings repository rejects unsafe secret keys'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Empty Secret Key', 'enabled', ['mode' => 'direct-test'], ['' => 'value']),
    'Core payment Provider settings repository rejects empty secret keys before encryption'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Non Canonical Secret Key', 'enabled', ['mode' => 'direct-test'], [' webhook_secret ' => 'whsec_admin_secret']),
    'Core payment Provider settings repository rejects non-canonical secret keys'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Invalid Secret Value', 'enabled', ['mode' => 'direct-test'], ['webhook_secret' => "bad\nsecret"]),
    'Core payment Provider settings repository rejects unsafe secret values'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Empty Secret Value', 'enabled', ['mode' => 'direct-test'], ['api_secret' => '']),
    'Core payment Provider settings repository rejects empty secret values before encryption'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Non Canonical Secret Value', 'enabled', ['mode' => 'direct-test'], ['webhook_secret' => ' whsec_admin_secret ']),
    'Core payment Provider settings repository rejects non-canonical secret values'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Short Webhook Secret', 'enabled', ['mode' => 'direct-test'], ['webhook_secret' => 'short']),
    'Core payment Provider settings repository rejects short webhook signing secrets'
);
core_payment_throws(
    static fn () => $adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Short Checkout Secret', 'enabled', ['checkout_url' => 'https://psp.example.test/pay'], ['checkout_secret' => 'short']),
    'Core payment Provider settings repository rejects short hosted checkout signing secrets'
);
$providerSettingsAuditCountBeforeInvalidSecretSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$invalidSecretSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$invalidSecretSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Invalid Secret',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"admin-test-invalid-secret"}',
    'secrets_text' => 'bad key=value',
], []));
$invalidSecretSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $invalidSecretSave->status() === 400
    && (string) ($invalidSecretSettingAfter['display_name'] ?? '') === (string) ($invalidSecretSettingBefore['display_name'] ?? '')
    && (string) ($invalidSecretSettingAfter['public_config_json'] ?? '') === (string) ($invalidSecretSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeInvalidSecretSave,
    'Core admin payment Provider settings reject unsafe secret text before saving settings or audit'
);
$providerSettingsAuditCountBeforeNonCanonicalSecretSave = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn();
$nonCanonicalSecretSettingBefore = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$nonCanonicalSecretSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => FixturePaymentProvider::PROVIDER_ID,
    'display_name' => 'Admin Fixture Non Canonical Secret',
    'status' => 'enabled',
    'public_config_json' => '{"mode":"admin-test-non-canonical-secret"}',
    'secrets_text' => 'api_secret= sk_admin_spaced_secret ',
], []));
$nonCanonicalSecretSettingAfter = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
core_payment_check(
    $nonCanonicalSecretSave->status() === 400
    && (string) ($nonCanonicalSecretSettingAfter['display_name'] ?? '') === (string) ($nonCanonicalSecretSettingBefore['display_name'] ?? '')
    && (string) ($nonCanonicalSecretSettingAfter['public_config_json'] ?? '') === (string) ($nonCanonicalSecretSettingBefore['public_config_json'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider_settings.saved'")->fetchColumn() === $providerSettingsAuditCountBeforeNonCanonicalSecretSave,
    'Core admin payment Provider settings reject non-canonical secret text before saving settings or audit'
);
$manualDefaultSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => 'Manual Default',
    'status' => 'enabled',
    'default_provider' => '1',
    'public_config_json' => '{"instructions":"Manual payment is the default Core checkout path."}',
    'secrets_text' => '',
], []));
$fixtureDefaultConfig = json_decode((string) ($adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
$manualDefaultConfig = json_decode((string) ($adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
$providerPageAfterDefault = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
core_payment_check(
    $manualDefaultSave->status() === 302
    && (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === ManualPaymentProvider::PROVIDER_ID
    && !($fixtureDefaultConfig['default_provider'] ?? false)
    && ($manualDefaultConfig['default_provider'] ?? false) === true
    && str_contains($providerPageAfterDefault->body(), '<th>默认</th>')
    && str_contains($providerPageAfterDefault->body(), '设为默认 Provider'),
    'Core admin payment Provider settings can designate one enabled Core default Provider'
);
$disabledDefaultSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => 'Manual Disabled',
    'status' => 'disabled',
    'public_config_json' => '{"instructions":"Manual disabled."}',
    'secrets_text' => '',
], []));
$manualDisabledConfig = json_decode((string) ($adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID)['public_config_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $disabledDefaultSave->status() === 302
    && !($manualDisabledConfig['default_provider'] ?? false)
    && (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID,
    'Core payment default Provider falls back to enabled Providers when the previous default is disabled'
);
$manualReenabledSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => 'Manual Reenabled',
    'status' => 'enabled',
    'public_config_json' => '{"instructions":"Manual payment is available but not default."}',
    'secrets_text' => '',
], []));
core_payment_check(
    $manualReenabledSave->status() === 302
    && (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId() === FixturePaymentProvider::PROVIDER_ID,
    'Core payment Provider settings can re-enable a non-default manual Provider for explicit checkout'
);
$fixtureDefaultBeforeAmbiguous = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$manualDefaultBeforeAmbiguous = $adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID);
$ambiguousFixtureDefaultConfig = json_decode((string) ($fixtureDefaultBeforeAmbiguous['public_config_json'] ?? '{}'), true) ?: [];
$ambiguousManualDefaultConfig = json_decode((string) ($manualDefaultBeforeAmbiguous['public_config_json'] ?? '{}'), true) ?: [];
$ambiguousFixtureDefaultConfig['default_provider'] = true;
$ambiguousManualDefaultConfig['default_provider'] = true;
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode($ambiguousFixtureDefaultConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => ManualPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode($ambiguousManualDefaultConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
core_payment_throws(
    static fn () => (new PaymentProviderSelector($adminPdo, $settings))->defaultProviderId(),
    'Core checkout Provider selector rejects ambiguous runtime default Provider markers'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => (string) ($fixtureDefaultBeforeAmbiguous['public_config_json'] ?? '{}'),
]);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => ManualPaymentProvider::PROVIDER_ID,
    ':public_config_json' => (string) ($manualDefaultBeforeAmbiguous['public_config_json'] ?? '{}'),
]);
$unregisteredProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => 'core.unregistered-provider',
    'display_name' => 'Unregistered Provider',
    'status' => 'enabled',
    'public_config_json' => '{}',
    'secrets_text' => '',
], []));
core_payment_check($unregisteredProviderSave->status() === 400 && str_contains($unregisteredProviderSave->body(), '该支付 Provider 尚未注册，不能启用'), 'Core admin payment provider settings reject enabling unregistered providers');
$webhookController = new PaymentWebhookController($settings);
$adminProviderSettings->save('core.unregistered-webhook', 'Unregistered Webhook', 'enabled', ['mode' => 'direct-test'], ['webhook_secret' => 'whsec_unregistered']);
$unregisteredProviderPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', [], [], []));
core_payment_check(
    $unregisteredProviderPage->status() === 200
    && str_contains($unregisteredProviderPage->body(), 'core.unregistered-webhook')
    && str_contains($unregisteredProviderPage->body(), '未注册，不能启用或接收回调'),
    'Core admin payment Provider diagnostics flag enabled but unregistered Provider settings'
);
$unregisteredWebhookPayload = '{"id":"evt-unregistered-webhook"}';
$unregisteredWebhookTimestamp = (string) time();
$unregisteredWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/core.unregistered-webhook', [], [], [
    'RAW_BODY' => $unregisteredWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $unregisteredWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $unregisteredWebhookTimestamp . '.' . $unregisteredWebhookPayload, 'whsec_unregistered'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-unregistered-webhook',
]));
core_payment_check(
    $unregisteredWebhookResponse->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-unregistered-webhook'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects enabled but unregistered providers before storing receipts'
);
$webhookPayload = '{"id":"evt-core-webhook"}';
$webhookTimestamp = (string) time();
$webhookSignature = hash_hmac('sha256', $webhookTimestamp . '.' . $webhookPayload, 'whsec_admin_secret');
$webhookRequest = new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'CONTENT_TYPE' => 'application/json; charset=utf-8',
    'REMOTE_ADDR' => '203.0.113.44',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => $webhookSignature,
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook',
]);
$webhookResponse = $webhookController->receive($webhookRequest);
$webhookReceiptMetadataJson = (string) ($adminPdo->query("SELECT metadata_json FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook'")->fetchColumn() ?: '{}');
$webhookReceiptMeta = json_decode($webhookReceiptMetadataJson, true) ?: [];
core_payment_check(
    $webhookResponse->status() === 200
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE provider_id = " . $adminPdo->quote(FixturePaymentProvider::PROVIDER_ID) . " AND external_event_id = 'evt-core-webhook'")->fetchColumn() === 1
    && (int) ($webhookReceiptMeta['payload_size'] ?? 0) === strlen($webhookPayload)
    && (string) ($webhookReceiptMeta['content_type'] ?? '') === 'application/json'
    && (string) ($webhookReceiptMeta['webhook_timestamp'] ?? '') === $webhookTimestamp
    && strlen((string) ($webhookReceiptMeta['source_ip_hash'] ?? '')) === 64
    && !str_contains($webhookReceiptMetadataJson, '203.0.113.44'),
    'Core payment webhook endpoint verifies signed provider events and stores redacted receipt trace metadata'
);
$arrayHeaderWebhookPayload = '{"id":"evt-core-webhook-array-header"}';
$arrayHeaderWebhookTimestamp = (string) time();
$arrayHeaderWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $arrayHeaderWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => [$arrayHeaderWebhookTimestamp],
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayHeaderWebhookTimestamp . '.' . $arrayHeaderWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-header',
]));
$integerHeaderWebhookPayload = '{"id":"evt-core-webhook-integer-header"}';
$integerHeaderWebhookTimestamp = time();
$integerHeaderWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $integerHeaderWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $integerHeaderWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', (string) $integerHeaderWebhookTimestamp . '.' . $integerHeaderWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-integer-header',
]));
$arrayPayloadEventWebhookPayload = json_encode(['id' => ['evt-core-webhook-array-payload-event']], JSON_UNESCAPED_SLASHES);
$arrayPayloadEventWebhookTimestamp = (string) time();
$arrayPayloadEventWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $arrayPayloadEventWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $arrayPayloadEventWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayPayloadEventWebhookTimestamp . '.' . (string) $arrayPayloadEventWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-payload-event',
]));
$integerPayloadEventWebhookPayload = json_encode(['id' => 123456], JSON_UNESCAPED_SLASHES);
$integerPayloadEventWebhookTimestamp = (string) time();
$integerPayloadEventWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $integerPayloadEventWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $integerPayloadEventWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $integerPayloadEventWebhookTimestamp . '.' . (string) $integerPayloadEventWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => '123456',
]));
$arrayRawBodyWebhookTimestamp = (string) time();
$arrayRawBodyWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => ['id' => 'evt-core-webhook-array-raw-body'],
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $arrayRawBodyWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayRawBodyWebhookTimestamp . '.{"id":"evt-core-webhook-array-raw-body"}', 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-raw-body',
]));
$malformedJsonWebhookPayload = '{"id":"evt-core-webhook-malformed-json"';
$malformedJsonWebhookTimestamp = (string) time();
$malformedJsonWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $malformedJsonWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $malformedJsonWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $malformedJsonWebhookTimestamp . '.' . $malformedJsonWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-malformed-json',
]));
$arrayContentLengthWebhookPayload = '{"id":"evt-core-webhook-array-content-length"}';
$arrayContentLengthWebhookTimestamp = (string) time();
$arrayContentLengthWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $arrayContentLengthWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'CONTENT_LENGTH' => [strlen($arrayContentLengthWebhookPayload)],
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $arrayContentLengthWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayContentLengthWebhookTimestamp . '.' . $arrayContentLengthWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-content-length',
]));
$nonCanonicalContentLengthWebhookPayload = '{"id":"evt-core-webhook-noncanonical-content-length"}';
$nonCanonicalContentLengthWebhookTimestamp = (string) time();
$nonCanonicalContentLengthWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $nonCanonicalContentLengthWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'CONTENT_LENGTH' => '0' . strlen($nonCanonicalContentLengthWebhookPayload),
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $nonCanonicalContentLengthWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $nonCanonicalContentLengthWebhookTimestamp . '.' . $nonCanonicalContentLengthWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-noncanonical-content-length',
]));
$oversizedContentLengthWebhookPayload = '{"id":"evt-core-webhook-oversized-content-length"}';
$oversizedContentLengthWebhookTimestamp = (string) time();
$oversizedContentLengthWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $oversizedContentLengthWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'CONTENT_LENGTH' => '262145',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $oversizedContentLengthWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $oversizedContentLengthWebhookTimestamp . '.' . $oversizedContentLengthWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-oversized-content-length',
]));
$mismatchedContentLengthWebhookPayload = '{"id":"evt-core-webhook-mismatched-content-length"}';
$mismatchedContentLengthWebhookTimestamp = (string) time();
$mismatchedContentLengthWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $mismatchedContentLengthWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'CONTENT_LENGTH' => (string) (strlen($mismatchedContentLengthWebhookPayload) - 1),
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $mismatchedContentLengthWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $mismatchedContentLengthWebhookTimestamp . '.' . $mismatchedContentLengthWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-mismatched-content-length',
]));
core_payment_check(
    $arrayHeaderWebhook->status() === 400
    && $integerHeaderWebhook->status() === 400
    && $arrayPayloadEventWebhook->status() === 400
    && $integerPayloadEventWebhook->status() === 400
    && $arrayRawBodyWebhook->status() === 400
    && $malformedJsonWebhook->status() === 400
    && $arrayContentLengthWebhook->status() === 400
    && $nonCanonicalContentLengthWebhook->status() === 400
    && $oversizedContentLengthWebhook->status() === 413
    && $mismatchedContentLengthWebhook->status() === 400
    && str_contains($arrayRawBodyWebhook->body(), 'Payment webhook payload is invalid.')
    && str_contains($malformedJsonWebhook->body(), 'Payment webhook payload must be a JSON object.')
    && str_contains($nonCanonicalContentLengthWebhook->body(), 'Payment webhook content length is invalid.')
    && str_contains($oversizedContentLengthWebhook->body(), 'Payment webhook payload is too large.')
    && str_contains($mismatchedContentLengthWebhook->body(), 'Payment webhook content length does not match payload.')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id IN ('evt-core-webhook-array-header', 'evt-core-webhook-integer-header', 'evt-core-webhook-array-payload-event', '123456', 'evt-core-webhook-array-raw-body', 'evt-core-webhook-malformed-json', 'evt-core-webhook-array-content-length', 'evt-core-webhook-noncanonical-content-length', 'evt-core-webhook-oversized-content-length', 'evt-core-webhook-mismatched-content-length')")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-string headers, content lengths, raw payloads, malformed JSON and payload event ids before storing receipts'
);
$spacedContentTypeWebhookPayload = '{"id":"evt-core-webhook-spaced-content-type"}';
$spacedContentTypeWebhookTimestamp = (string) time();
$spacedContentTypeWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $spacedContentTypeWebhookPayload,
    'CONTENT_TYPE' => ' application/json ',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedContentTypeWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedContentTypeWebhookTimestamp . '.' . $spacedContentTypeWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-content-type',
]));
core_payment_check(
    $spacedContentTypeWebhook->status() === 415
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-content-type'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical content types before storing receipts'
);
$spacedRemoteWebhookPayload = '{"id":"evt-core-webhook-spaced-remote"}';
$spacedRemoteWebhookTimestamp = (string) time();
$spacedRemoteWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $spacedRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => ' 203.0.113.45 ',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedRemoteWebhookTimestamp . '.' . $spacedRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-remote',
]));
$spacedRemoteWebhookMetaJson = (string) ($adminPdo->query("SELECT metadata_json FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-remote'")->fetchColumn() ?: '{}');
$spacedRemoteWebhookMeta = json_decode($spacedRemoteWebhookMetaJson, true) ?: [];
core_payment_check(
    $spacedRemoteWebhook->status() === 200
    && !array_key_exists('source_ip_hash', $spacedRemoteWebhookMeta)
    && !str_contains($spacedRemoteWebhookMetaJson, '203.0.113.45'),
    'Core payment webhook endpoint omits non-canonical source address trace metadata instead of trimming it'
);
$invalidRemoteWebhookPayload = '{"id":"evt-core-webhook-invalid-remote"}';
$invalidRemoteWebhookTimestamp = (string) time();
$invalidRemoteWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $invalidRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => 'not-an-ip.example.test',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $invalidRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $invalidRemoteWebhookTimestamp . '.' . $invalidRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-invalid-remote',
]));
$invalidRemoteWebhookMetaJson = (string) ($adminPdo->query("SELECT metadata_json FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-invalid-remote'")->fetchColumn() ?: '{}');
$invalidRemoteWebhookMeta = json_decode($invalidRemoteWebhookMetaJson, true) ?: [];
core_payment_check(
    $invalidRemoteWebhook->status() === 200
    && !array_key_exists('source_ip_hash', $invalidRemoteWebhookMeta)
    && !str_contains($invalidRemoteWebhookMetaJson, 'not-an-ip.example.test'),
    'Core payment webhook endpoint omits non-IP source address trace metadata instead of hashing it'
);
$spacedWebhookProviderPayload = '{"id":"evt-core-webhook-spaced-provider"}';
$spacedWebhookProviderTimestamp = (string) time();
$spacedWebhookProviderResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/%20' . FixturePaymentProvider::PROVIDER_ID . '%20', [], [], [
    'RAW_BODY' => $spacedWebhookProviderPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedWebhookProviderTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedWebhookProviderTimestamp . '.' . $spacedWebhookProviderPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-provider',
]));
$encodedWebhookProviderPayload = '{"id":"evt-core-webhook-encoded-provider"}';
$encodedWebhookProviderTimestamp = (string) time();
$encodedWebhookProviderResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/core%2Efixture-payment', [], [], [
    'RAW_BODY' => $encodedWebhookProviderPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $encodedWebhookProviderTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $encodedWebhookProviderTimestamp . '.' . $encodedWebhookProviderPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-encoded-provider',
]));
core_payment_check(
    $spacedWebhookProviderResponse->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-provider'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical Provider path ids before storing receipts'
);
core_payment_check(
    $encodedWebhookProviderResponse->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-encoded-provider'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects encoded Provider path ids before storing receipts'
);
$duplicateWebhook = $webhookController->receive($webhookRequest);
core_payment_check(
    $duplicateWebhook->status() === 200
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook'")->fetchColumn() === 1,
    'Core payment webhook endpoint is idempotent for duplicate provider events'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET secret_config_ciphertext = :ciphertext WHERE provider_id = :provider_id')->execute([
    ':ciphertext' => 'not-a-valid-webhook-secret-ciphertext',
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
]);
$corruptSecretWebhookPayload = '{"id":"evt-core-webhook-corrupt-secret"}';
$corruptSecretWebhookTimestamp = (string) time();
$corruptSecretWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $corruptSecretWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $corruptSecretWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $corruptSecretWebhookTimestamp . '.' . $corruptSecretWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-corrupt-secret',
]));
core_payment_check(
    $corruptSecretWebhookResponse->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-corrupt-secret'")->fetchColumn() === 0,
    'Core payment webhook endpoint fails closed without storing receipts when Provider secrets are unreadable'
);
$adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Admin Fixture', 'enabled', ['mode' => 'admin-test-2'], ['api_secret' => 'sk_admin_987654', 'webhook_secret' => 'whsec_admin_secret']);
$webhookAppliedPayment = $adminService->createProviderPayment('paid_download', 'webhook-authorized', FixturePaymentProvider::PROVIDER_ID, 1400, 'USD', 'admin-webhook-authorized-key', 'authorized');
$webhookApplyPayload = json_encode([
    'id' => 'evt-core-webhook-apply',
    'provider_payment_id' => (string) ($webhookAppliedPayment['remote_id'] ?? ''),
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$webhookApplyTimestamp = (string) time();
$webhookApplyResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $webhookApplyPayload,
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => '203.0.113.45',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookApplyTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookApplyTimestamp . '.' . (string) $webhookApplyPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-apply',
]));
$webhookApplyBody = json_decode($webhookApplyResponse->body(), true) ?: [];
core_payment_check(
    $webhookApplyResponse->status() === 200
    && (int) ($webhookApplyBody['applied_payment_id'] ?? 0) === (int) ($webhookAppliedPayment['id'] ?? 0)
    && (string) ($adminRepo->payment((int) ($webhookAppliedPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && (int) ($adminRepo->webhookReceiptById((int) ($webhookApplyBody['receipt_id'] ?? 0))['payment_id'] ?? 0) === (int) ($webhookAppliedPayment['id'] ?? 0)
    && (string) ($adminRepo->webhookReceiptById((int) ($webhookApplyBody['receipt_id'] ?? 0))['status'] ?? '') === 'processed',
    'Core payment webhook endpoint applies signed provider-neutral payment status updates and binds the receipt to its payment'
);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%evt-core-webhook-apply%'")->fetchColumn() === 1
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%provider_payment_id%'")->fetchColumn() === 0
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%\"payment_status\":\"paid\"%'")->fetchColumn() >= 1,
    'Core payment webhook status application writes a CMS audit event without raw webhook payload'
);
$directMalformedWebhookReceipt = $adminService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-core-webhook-direct-malformed', '{"id":"evt-core-webhook-direct-malformed"', 'received', []);
core_payment_throws(
    static fn () => $adminService->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($directMalformedWebhookReceipt['id'] ?? 0), '{"id":"evt-core-webhook-direct-malformed"'),
    'Core PaymentService rejects malformed webhook JSON before applying trusted payment status'
);
$arrayStatusWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-array-status', FixturePaymentProvider::PROVIDER_ID, 1410, 'USD', 'admin-webhook-array-status-key', 'authorized');
$arrayStatusWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-array-status',
    'provider_payment_id' => (string) ($arrayStatusWebhookPayment['remote_id'] ?? ''),
    'status' => ['paid'],
], JSON_UNESCAPED_SLASHES);
$arrayStatusWebhookTimestamp = (string) time();
$arrayStatusWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $arrayStatusWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $arrayStatusWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayStatusWebhookTimestamp . '.' . (string) $arrayStatusWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-status',
]));
$arrayStatusWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-array-status'")->fetchColumn();
$arrayStatusWebhookReceipt = $adminRepo->webhookReceiptById($arrayStatusWebhookReceiptId);
core_payment_check(
    $arrayStatusWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($arrayStatusWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && is_array($arrayStatusWebhookReceipt)
    && (string) ($arrayStatusWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($arrayStatusWebhookReceipt['payment_id'] ?? 0) === 0,
    'Core payment webhook application rejects array status payload fields without updating trusted payment state'
);
$booleanStatusWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-boolean-status', FixturePaymentProvider::PROVIDER_ID, 1412, 'USD', 'admin-webhook-boolean-status-key', 'authorized');
$booleanStatusWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-boolean-status',
    'provider_payment_id' => (string) ($booleanStatusWebhookPayment['remote_id'] ?? ''),
    'status' => true,
], JSON_UNESCAPED_SLASHES);
$booleanStatusWebhookTimestamp = (string) time();
$booleanStatusWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $booleanStatusWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $booleanStatusWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $booleanStatusWebhookTimestamp . '.' . (string) $booleanStatusWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-boolean-status',
]));
$booleanStatusWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-boolean-status'")->fetchColumn();
$booleanStatusWebhookReceipt = $adminRepo->webhookReceiptById($booleanStatusWebhookReceiptId);
core_payment_check(
    $booleanStatusWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($booleanStatusWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && is_array($booleanStatusWebhookReceipt)
    && (string) ($booleanStatusWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($booleanStatusWebhookReceipt['payment_id'] ?? 0) === 0,
    'Core payment webhook application rejects boolean status payload fields without updating trusted payment state'
);
$integerStatusWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-integer-status', FixturePaymentProvider::PROVIDER_ID, 1413, 'USD', 'admin-webhook-integer-status-key', 'authorized');
$integerStatusWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-integer-status',
    'provider_payment_id' => (string) ($integerStatusWebhookPayment['remote_id'] ?? ''),
    'status' => 123456,
], JSON_UNESCAPED_SLASHES);
$integerStatusWebhookTimestamp = (string) time();
$integerStatusWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $integerStatusWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $integerStatusWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $integerStatusWebhookTimestamp . '.' . (string) $integerStatusWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-integer-status',
]));
$integerStatusWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-integer-status'")->fetchColumn();
$integerStatusWebhookReceipt = $adminRepo->webhookReceiptById($integerStatusWebhookReceiptId);
core_payment_check(
    $integerStatusWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($integerStatusWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && is_array($integerStatusWebhookReceipt)
    && (string) ($integerStatusWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($integerStatusWebhookReceipt['payment_id'] ?? 0) === 0,
    'Core payment webhook application rejects integer status payload fields without updating trusted payment state'
);
$arrayRemoteWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-array-remote', FixturePaymentProvider::PROVIDER_ID, 1415, 'USD', 'admin-webhook-array-remote-key', 'authorized');
$arrayRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-array-remote',
    'provider_payment_id' => ['remote' => (string) ($arrayRemoteWebhookPayment['remote_id'] ?? '')],
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$arrayRemoteWebhookTimestamp = (string) time();
$arrayRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $arrayRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $arrayRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayRemoteWebhookTimestamp . '.' . (string) $arrayRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-remote',
]));
$arrayRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-array-remote'")->fetchColumn();
$arrayRemoteWebhookReceipt = $adminRepo->webhookReceiptById($arrayRemoteWebhookReceiptId);
core_payment_check(
    $arrayRemoteWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($arrayRemoteWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && is_array($arrayRemoteWebhookReceipt)
    && (string) ($arrayRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($arrayRemoteWebhookReceipt['payment_id'] ?? 0) === 0,
    'Core payment webhook application rejects array remote payment references without binding receipts'
);
$integerRemoteWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-integer-remote', FixturePaymentProvider::PROVIDER_ID, 1417, 'USD', 'admin-webhook-integer-remote-key', 'authorized');
$integerRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-integer-remote',
    'provider_payment_id' => 123456,
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$integerRemoteWebhookTimestamp = (string) time();
$integerRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $integerRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $integerRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $integerRemoteWebhookTimestamp . '.' . (string) $integerRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-integer-remote',
]));
$integerRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-integer-remote'")->fetchColumn();
$integerRemoteWebhookReceipt = $adminRepo->webhookReceiptById($integerRemoteWebhookReceiptId);
core_payment_check(
    $integerRemoteWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($integerRemoteWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && is_array($integerRemoteWebhookReceipt)
    && (string) ($integerRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($integerRemoteWebhookReceipt['payment_id'] ?? 0) === 0,
    'Core payment webhook application rejects integer remote payment references without binding receipts'
);
$payloadBoundWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-payload-bound', FixturePaymentProvider::PROVIDER_ID, 1425, 'USD', 'admin-webhook-payload-bound-key', 'authorized');
$payloadBoundWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-payload-bound',
    'provider_payment_id' => (string) ($payloadBoundWebhookPayment['remote_id'] ?? ''),
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$payloadBoundWebhookReceipt = $adminService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-core-webhook-payload-bound', (string) $payloadBoundWebhookPayload);
$payloadMismatchWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-payload-bound',
    'provider_payment_id' => (string) ($payloadBoundWebhookPayment['remote_id'] ?? ''),
    'status' => 'failed',
], JSON_UNESCAPED_SLASHES);
core_payment_throws(
    static fn () => $adminService->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($payloadBoundWebhookReceipt['id'] ?? 0), (string) $payloadMismatchWebhookPayload),
    'Core payment webhook application rejects payloads that do not match the recorded receipt hash before touching payment state'
);
$payloadBoundWebhookReceiptAfterMismatch = $adminRepo->webhookReceiptById((int) ($payloadBoundWebhookReceipt['id'] ?? 0));
core_payment_check(
    (string) ($adminRepo->payment((int) ($payloadBoundWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && is_array($payloadBoundWebhookReceiptAfterMismatch)
    && (string) ($payloadBoundWebhookReceiptAfterMismatch['status'] ?? '') === 'received'
    && (int) ($payloadBoundWebhookReceiptAfterMismatch['payment_id'] ?? 0) === 0,
    'Core payment webhook payload hash mismatches leave receipt and payment state unchanged'
);
$nonCanonicalWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-noncanonical-status', FixturePaymentProvider::PROVIDER_ID, 1450, 'USD', 'admin-webhook-noncanonical-status-key', 'authorized');
$nonCanonicalWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-noncanonical-status',
    'provider_payment_id' => (string) ($nonCanonicalWebhookPayment['remote_id'] ?? ''),
    'status' => ' Paid ',
], JSON_UNESCAPED_SLASHES);
$nonCanonicalWebhookTimestamp = (string) time();
$nonCanonicalWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $nonCanonicalWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $nonCanonicalWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $nonCanonicalWebhookTimestamp . '.' . (string) $nonCanonicalWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-noncanonical-status',
]));
$nonCanonicalWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-noncanonical-status'")->fetchColumn();
$nonCanonicalWebhookReceipt = $adminRepo->webhookReceiptById($nonCanonicalWebhookReceiptId);
core_payment_check(
    $nonCanonicalWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($nonCanonicalWebhookPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && (string) ($nonCanonicalWebhookReceipt['status'] ?? '') === 'failed',
    'Core payment webhook endpoint rejects non-canonical payment statuses without updating trusted payment state'
);
$invalidRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-invalid-remote-reference',
    'provider_payment_id' => "invalid\nremote",
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$invalidRemoteWebhookTimestamp = (string) time();
$invalidRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $invalidRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $invalidRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $invalidRemoteWebhookTimestamp . '.' . (string) $invalidRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-invalid-remote-reference',
]));
$invalidRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-invalid-remote-reference'")->fetchColumn();
$invalidRemoteWebhookReceipt = $adminRepo->webhookReceiptById($invalidRemoteWebhookReceiptId);
$invalidRemoteWebhookReceiptMeta = json_decode((string) ($invalidRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $invalidRemoteWebhookResponse->status() === 400
    && is_array($invalidRemoteWebhookReceipt)
    && (string) ($invalidRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($invalidRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($invalidRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'remote payment reference'),
    'Core payment webhook rejects Provider payment references with control characters before binding ledger rows'
);
$spacedRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-spaced-remote-reference',
    'provider_payment_id' => ' ' . (string) ($webhookAppliedPayment['remote_id'] ?? '') . ' ',
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$spacedRemoteWebhookTimestamp = (string) time();
$spacedRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $spacedRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedRemoteWebhookTimestamp . '.' . (string) $spacedRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-remote-reference',
]));
$spacedRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-remote-reference'")->fetchColumn();
$spacedRemoteWebhookReceipt = $adminRepo->webhookReceiptById($spacedRemoteWebhookReceiptId);
$spacedRemoteWebhookReceiptMeta = json_decode((string) ($spacedRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $spacedRemoteWebhookResponse->status() === 400
    && is_array($spacedRemoteWebhookReceipt)
    && (string) ($spacedRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($spacedRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($spacedRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'remote payment reference'),
    'Core payment webhook rejects non-canonical Provider payment references before binding ledger rows'
);
$tokenLikeRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-token-like-remote-reference',
    'provider_payment_id' => 'payment_token%3Draw-webhook-remote-reference',
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$tokenLikeRemoteWebhookTimestamp = (string) time();
$tokenLikeRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $tokenLikeRemoteWebhookPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $tokenLikeRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $tokenLikeRemoteWebhookTimestamp . '.' . (string) $tokenLikeRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-token-like-remote-reference',
]));
$tokenLikeRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-token-like-remote-reference'")->fetchColumn();
$tokenLikeRemoteWebhookReceipt = $adminRepo->webhookReceiptById($tokenLikeRemoteWebhookReceiptId);
$tokenLikeRemoteWebhookReceiptMeta = json_decode((string) ($tokenLikeRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $tokenLikeRemoteWebhookResponse->status() === 400
    && is_array($tokenLikeRemoteWebhookReceipt)
    && (string) ($tokenLikeRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($tokenLikeRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($tokenLikeRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'remote payment reference')
    && !str_contains((string) ($tokenLikeRemoteWebhookReceipt['metadata_json'] ?? ''), 'raw-webhook-remote-reference'),
    'Core payment webhook rejects URL-encoded token-like Provider payment references before binding ledger rows'
);
$processedWebhookReceiptId = (int) ($webhookApplyBody['receipt_id'] ?? 0);
$terminalWebhookStatusResponse = $admin->paymentWebhookStatus(new Request(
    'POST',
    '/admin/payments/' . (int) ($webhookAppliedPayment['id'] ?? 0) . '/webhooks/' . $processedWebhookReceiptId . '/status',
    [],
    ['_csrf' => $csrf, 'status' => 'ignored'],
    []
));
core_payment_check(
    $terminalWebhookStatusResponse->status() === 400
    && (string) ($adminRepo->webhookReceiptById($processedWebhookReceiptId)['status'] ?? '') === 'processed'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === 0,
    'Core admin webhook status action cannot rewrite terminal processed receipts'
);
$staleWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-stale-status',
    'provider_payment_id' => (string) ($webhookAppliedPayment['remote_id'] ?? ''),
    'status' => 'failed',
], JSON_UNESCAPED_SLASHES);
$staleWebhookTimestamp = (string) time();
$staleWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $staleWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $staleWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $staleWebhookTimestamp . '.' . (string) $staleWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-stale-status',
]));
$staleWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-stale-status'")->fetchColumn();
$staleWebhookReceipt = $adminRepo->webhookReceiptById($staleWebhookReceiptId);
$staleWebhookReceiptMeta = json_decode((string) ($staleWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $staleWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($webhookAppliedPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && (string) ($adminService->trustedStatus('paid_download', 'webhook-authorized')['status'] ?? '') === 'paid'
    && is_array($staleWebhookReceipt)
    && ($staleWebhookReceipt['payment_id'] ?? null) === null
    && (string) ($staleWebhookReceipt['status'] ?? '') === 'failed'
    && str_contains((string) ($staleWebhookReceiptMeta['failure_error'] ?? ''), 'transition'),
    'Core payment webhook endpoint rejects stale signed status events without leaving a payment binding'
);
$lateWebhookRemoteId = 'core-fixture-pay-' . substr(hash('sha256', implode(':', [
    'pay',
    'paid_download',
    'webhook-late-target',
    '777',
    'USD',
    'admin-webhook-late-key',
])), 0, 24);
$lateWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-late-target',
    'provider_payment_id' => $lateWebhookRemoteId,
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$lateWebhookTimestamp = (string) time();
$lateWebhookRequest = new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $lateWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $lateWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $lateWebhookTimestamp . '.' . (string) $lateWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-late-target',
]);
$lateWebhookFirstResponse = $webhookController->receive($lateWebhookRequest);
$lateWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-late-target'")->fetchColumn();
$lateWebhookFirstReceipt = $adminRepo->webhookReceiptById($lateWebhookReceiptId);
$lateWebhookFirstMeta = json_decode((string) ($lateWebhookFirstReceipt['metadata_json'] ?? '{}'), true) ?: [];
$lateWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-late-target', FixturePaymentProvider::PROVIDER_ID, 777, 'USD', 'admin-webhook-late-key');
$lateWebhookReplayResponse = $webhookController->receive($lateWebhookRequest);
$lateWebhookReplayReceipt = $adminRepo->webhookReceiptById($lateWebhookReceiptId);
$lateWebhookReplayMeta = json_decode((string) ($lateWebhookReplayReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $lateWebhookFirstResponse->status() === 400
    && is_array($lateWebhookFirstReceipt)
    && (string) ($lateWebhookFirstReceipt['status'] ?? '') === 'failed'
    && str_contains((string) ($lateWebhookFirstMeta['failure_error'] ?? ''), 'target payment')
    && $lateWebhookReplayResponse->status() === 200
    && (int) ($lateWebhookReplayReceipt['payment_id'] ?? 0) === (int) ($lateWebhookPayment['id'] ?? 0)
    && (string) ($lateWebhookReplayReceipt['status'] ?? '') === 'processed'
    && !array_key_exists('failure_error', $lateWebhookReplayMeta)
    && !array_key_exists('failed_at', $lateWebhookReplayMeta),
    'Core payment webhook replay can recover a failed receipt after the target payment appears and clears stale failure diagnostics'
);
$webhookRefundPayment = $adminService->createProviderPayment('paid_download', 'webhook-refund-target', FixturePaymentProvider::PROVIDER_ID, 1600, 'USD', 'admin-webhook-refund-key');
$nonCanonicalWebhookEventTypePayload = json_encode([
    'id' => 'evt-core-webhook-noncanonical-event-type',
    'event_type' => ' Payment.Refund.Completed ',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-noncanonical-event-type',
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$nonCanonicalWebhookEventTypeTimestamp = (string) time();
$nonCanonicalWebhookEventTypeCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$nonCanonicalWebhookEventTypeResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $nonCanonicalWebhookEventTypePayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $nonCanonicalWebhookEventTypeTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $nonCanonicalWebhookEventTypeTimestamp . '.' . (string) $nonCanonicalWebhookEventTypePayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-noncanonical-event-type',
]));
$nonCanonicalWebhookEventTypeReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-noncanonical-event-type'")->fetchColumn();
$nonCanonicalWebhookEventTypeReceipt = $adminRepo->webhookReceiptById($nonCanonicalWebhookEventTypeReceiptId);
$nonCanonicalWebhookEventTypeReceiptMeta = json_decode((string) ($nonCanonicalWebhookEventTypeReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $nonCanonicalWebhookEventTypeResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $nonCanonicalWebhookEventTypeCountBefore
    && is_array($nonCanonicalWebhookEventTypeReceipt)
    && (string) ($nonCanonicalWebhookEventTypeReceipt['status'] ?? '') === 'failed'
    && (int) ($nonCanonicalWebhookEventTypeReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($nonCanonicalWebhookEventTypeReceiptMeta['failure_error'] ?? ''), 'event type'),
    'Core payment webhook rejects non-canonical event types before writing refund rows'
);
$integerWebhookEventTypePayload = json_encode([
    'id' => 'evt-core-webhook-integer-event-type',
    'event_type' => 123,
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-integer-event-type',
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$integerWebhookEventTypeTimestamp = (string) time();
$integerWebhookEventTypeCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$integerWebhookEventTypeResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $integerWebhookEventTypePayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $integerWebhookEventTypeTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $integerWebhookEventTypeTimestamp . '.' . (string) $integerWebhookEventTypePayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-integer-event-type',
]));
$integerWebhookEventTypeReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-integer-event-type'")->fetchColumn();
$integerWebhookEventTypeReceipt = $adminRepo->webhookReceiptById($integerWebhookEventTypeReceiptId);
$integerWebhookEventTypeReceiptMeta = json_decode((string) ($integerWebhookEventTypeReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $integerWebhookEventTypeResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $integerWebhookEventTypeCountBefore
    && is_array($integerWebhookEventTypeReceipt)
    && (string) ($integerWebhookEventTypeReceipt['status'] ?? '') === 'failed'
    && (int) ($integerWebhookEventTypeReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($integerWebhookEventTypeReceiptMeta['failure_error'] ?? ''), 'event type'),
    'Core payment webhook rejects integer event types before writing refund rows'
);
$invalidRefundRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-invalid-refund-reference',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => "invalid\nrefund",
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$invalidRefundRemoteWebhookTimestamp = (string) time();
$invalidRefundRemoteCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$invalidRefundRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $invalidRefundRemoteWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $invalidRefundRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $invalidRefundRemoteWebhookTimestamp . '.' . (string) $invalidRefundRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-invalid-refund-reference',
]));
$invalidRefundRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-invalid-refund-reference'")->fetchColumn();
$invalidRefundRemoteWebhookReceipt = $adminRepo->webhookReceiptById($invalidRefundRemoteWebhookReceiptId);
$invalidRefundRemoteWebhookReceiptMeta = json_decode((string) ($invalidRefundRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $invalidRefundRemoteWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $invalidRefundRemoteCountBefore
    && is_array($invalidRefundRemoteWebhookReceipt)
    && (string) ($invalidRefundRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($invalidRefundRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($invalidRefundRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'remote refund reference'),
    'Core payment webhook rejects Provider refund references with control characters before writing refund rows'
);
$spacedRefundRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-spaced-refund-reference',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => ' rf-core-webhook-spaced-refund ',
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$spacedRefundRemoteWebhookTimestamp = (string) time();
$spacedRefundRemoteCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$spacedRefundRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $spacedRefundRemoteWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedRefundRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedRefundRemoteWebhookTimestamp . '.' . (string) $spacedRefundRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-refund-reference',
]));
$spacedRefundRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-refund-reference'")->fetchColumn();
$spacedRefundRemoteWebhookReceipt = $adminRepo->webhookReceiptById($spacedRefundRemoteWebhookReceiptId);
$spacedRefundRemoteWebhookReceiptMeta = json_decode((string) ($spacedRefundRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $spacedRefundRemoteWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $spacedRefundRemoteCountBefore
    && is_array($spacedRefundRemoteWebhookReceipt)
    && (string) ($spacedRefundRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($spacedRefundRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($spacedRefundRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'remote refund reference'),
    'Core payment webhook rejects non-canonical Provider refund references before writing refund rows'
);
$arrayRefundRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-array-refund-reference',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => ['rf-core-webhook-array-refund'],
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$arrayRefundRemoteWebhookTimestamp = (string) time();
$arrayRefundRemoteCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$arrayRefundRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $arrayRefundRemoteWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $arrayRefundRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $arrayRefundRemoteWebhookTimestamp . '.' . (string) $arrayRefundRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-array-refund-reference',
]));
$arrayRefundRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-array-refund-reference'")->fetchColumn();
$arrayRefundRemoteWebhookReceipt = $adminRepo->webhookReceiptById($arrayRefundRemoteWebhookReceiptId);
$arrayRefundRemoteWebhookReceiptMeta = json_decode((string) ($arrayRefundRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $arrayRefundRemoteWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $arrayRefundRemoteCountBefore
    && is_array($arrayRefundRemoteWebhookReceipt)
    && (string) ($arrayRefundRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($arrayRefundRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($arrayRefundRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'payload field'),
    'Core payment webhook rejects non-scalar Provider refund references before writing refund rows'
);
$integerRefundRemoteWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-integer-refund-reference',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 24680,
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$integerRefundRemoteWebhookTimestamp = (string) time();
$integerRefundRemoteCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$integerRefundRemoteWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $integerRefundRemoteWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $integerRefundRemoteWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $integerRefundRemoteWebhookTimestamp . '.' . (string) $integerRefundRemoteWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-integer-refund-reference',
]));
$integerRefundRemoteWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-integer-refund-reference'")->fetchColumn();
$integerRefundRemoteWebhookReceipt = $adminRepo->webhookReceiptById($integerRefundRemoteWebhookReceiptId);
$integerRefundRemoteWebhookReceiptMeta = json_decode((string) ($integerRefundRemoteWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $integerRefundRemoteWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $integerRefundRemoteCountBefore
    && is_array($integerRefundRemoteWebhookReceipt)
    && (string) ($integerRefundRemoteWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($integerRefundRemoteWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($integerRefundRemoteWebhookReceiptMeta['failure_error'] ?? ''), 'payload field'),
    'Core payment webhook rejects integer Provider refund references before writing refund rows'
);
$invalidRefundAmountWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-invalid-refund-amount',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-invalid-amount',
        'amount_minor' => '100abc',
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$invalidRefundAmountWebhookTimestamp = (string) time();
$invalidRefundAmountCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$invalidRefundAmountWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $invalidRefundAmountWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $invalidRefundAmountWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $invalidRefundAmountWebhookTimestamp . '.' . (string) $invalidRefundAmountWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-invalid-refund-amount',
]));
$invalidRefundAmountWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-invalid-refund-amount'")->fetchColumn();
$invalidRefundAmountWebhookReceipt = $adminRepo->webhookReceiptById($invalidRefundAmountWebhookReceiptId);
$invalidRefundAmountWebhookReceiptMeta = json_decode((string) ($invalidRefundAmountWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $invalidRefundAmountWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $invalidRefundAmountCountBefore
    && is_array($invalidRefundAmountWebhookReceipt)
    && (string) ($invalidRefundAmountWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($invalidRefundAmountWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($invalidRefundAmountWebhookReceiptMeta['failure_error'] ?? ''), 'refund amount'),
    'Core payment webhook rejects non-integer refund amounts before writing refund rows'
);
$oversizedRefundAmountWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-oversized-refund-amount',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-oversized-amount',
        'amount_minor' => '123456789012345678901234567890',
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$oversizedRefundAmountWebhookTimestamp = (string) time();
$oversizedRefundAmountCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$oversizedRefundAmountWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $oversizedRefundAmountWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $oversizedRefundAmountWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $oversizedRefundAmountWebhookTimestamp . '.' . (string) $oversizedRefundAmountWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-oversized-refund-amount',
]));
$oversizedRefundAmountWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-oversized-refund-amount'")->fetchColumn();
$oversizedRefundAmountWebhookReceipt = $adminRepo->webhookReceiptById($oversizedRefundAmountWebhookReceiptId);
$oversizedRefundAmountWebhookReceiptMeta = json_decode((string) ($oversizedRefundAmountWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $oversizedRefundAmountWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $oversizedRefundAmountCountBefore
    && is_array($oversizedRefundAmountWebhookReceipt)
    && (string) ($oversizedRefundAmountWebhookReceipt['status'] ?? '') === 'failed'
    && (int) ($oversizedRefundAmountWebhookReceipt['payment_id'] ?? 0) === 0
    && str_contains((string) ($oversizedRefundAmountWebhookReceiptMeta['failure_error'] ?? ''), 'refund amount'),
    'Core payment webhook rejects oversized numeric-string refund amounts before integer conversion'
);
$spacedRefundAmountWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-spaced-refund-amount',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-spaced-amount',
        'amount_minor' => ' 100 ',
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$spacedRefundAmountWebhookTimestamp = (string) time();
$spacedRefundAmountCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$spacedRefundAmountWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $spacedRefundAmountWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedRefundAmountWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedRefundAmountWebhookTimestamp . '.' . (string) $spacedRefundAmountWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-refund-amount',
]));
$spacedRefundAmountWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-refund-amount'")->fetchColumn();
$spacedRefundAmountWebhookReceipt = $adminRepo->webhookReceiptById($spacedRefundAmountWebhookReceiptId);
core_payment_check(
    $spacedRefundAmountWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $spacedRefundAmountCountBefore
    && is_array($spacedRefundAmountWebhookReceipt)
    && (string) ($spacedRefundAmountWebhookReceipt['status'] ?? '') === 'failed',
    'Core payment webhook rejects non-canonical refund amounts before writing refund rows'
);
$leadingZeroRefundAmountWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-leading-zero-refund-amount',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-leading-zero-amount',
        'amount_minor' => '00100',
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$leadingZeroRefundAmountWebhookTimestamp = (string) time();
$leadingZeroRefundAmountCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$leadingZeroRefundAmountWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $leadingZeroRefundAmountWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $leadingZeroRefundAmountWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $leadingZeroRefundAmountWebhookTimestamp . '.' . (string) $leadingZeroRefundAmountWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-leading-zero-refund-amount',
]));
$leadingZeroRefundAmountWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-leading-zero-refund-amount'")->fetchColumn();
$leadingZeroRefundAmountWebhookReceipt = $adminRepo->webhookReceiptById($leadingZeroRefundAmountWebhookReceiptId);
core_payment_check(
    $leadingZeroRefundAmountWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $leadingZeroRefundAmountCountBefore
    && is_array($leadingZeroRefundAmountWebhookReceipt)
    && (string) ($leadingZeroRefundAmountWebhookReceipt['status'] ?? '') === 'failed',
    'Core payment webhook rejects leading-zero refund amounts before writing refund rows'
);
$nonCanonicalRefundStatusWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-noncanonical-refund-status',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-noncanonical-status',
        'amount_minor' => 100,
        'status' => ' Completed ',
    ],
], JSON_UNESCAPED_SLASHES);
$nonCanonicalRefundStatusWebhookTimestamp = (string) time();
$nonCanonicalRefundStatusCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$nonCanonicalRefundStatusWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $nonCanonicalRefundStatusWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $nonCanonicalRefundStatusWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $nonCanonicalRefundStatusWebhookTimestamp . '.' . (string) $nonCanonicalRefundStatusWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-noncanonical-refund-status',
]));
$nonCanonicalRefundStatusWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-noncanonical-refund-status'")->fetchColumn();
$nonCanonicalRefundStatusWebhookReceipt = $adminRepo->webhookReceiptById($nonCanonicalRefundStatusWebhookReceiptId);
core_payment_check(
    $nonCanonicalRefundStatusWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $nonCanonicalRefundStatusCountBefore
    && is_array($nonCanonicalRefundStatusWebhookReceipt)
    && (string) ($nonCanonicalRefundStatusWebhookReceipt['status'] ?? '') === 'failed',
    'Core payment webhook rejects non-canonical refund statuses before writing refund rows'
);
$booleanRefundStatusWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-boolean-refund-status',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-boolean-status',
        'amount_minor' => 100,
        'status' => true,
    ],
], JSON_UNESCAPED_SLASHES);
$booleanRefundStatusWebhookTimestamp = (string) time();
$booleanRefundStatusCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$booleanRefundStatusWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $booleanRefundStatusWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $booleanRefundStatusWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $booleanRefundStatusWebhookTimestamp . '.' . (string) $booleanRefundStatusWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-boolean-refund-status',
]));
$booleanRefundStatusWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-boolean-refund-status'")->fetchColumn();
$booleanRefundStatusWebhookReceipt = $adminRepo->webhookReceiptById($booleanRefundStatusWebhookReceiptId);
$booleanRefundStatusWebhookReceiptMeta = json_decode((string) ($booleanRefundStatusWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $booleanRefundStatusWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $booleanRefundStatusCountBefore
    && is_array($booleanRefundStatusWebhookReceipt)
    && (string) ($booleanRefundStatusWebhookReceipt['status'] ?? '') === 'failed'
    && str_contains((string) ($booleanRefundStatusWebhookReceiptMeta['failure_error'] ?? ''), 'payload field'),
    'Core payment webhook rejects boolean refund statuses before writing refund rows'
);
$missingRefundStatusWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-missing-refund-status',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-missing-status',
        'amount_minor' => 100,
    ],
], JSON_UNESCAPED_SLASHES);
$missingRefundStatusWebhookTimestamp = (string) time();
$missingRefundStatusCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$missingRefundStatusWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $missingRefundStatusWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $missingRefundStatusWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $missingRefundStatusWebhookTimestamp . '.' . (string) $missingRefundStatusWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-missing-refund-status',
]));
$missingRefundStatusWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-missing-refund-status'")->fetchColumn();
$missingRefundStatusWebhookReceipt = $adminRepo->webhookReceiptById($missingRefundStatusWebhookReceiptId);
$missingRefundStatusWebhookReceiptMeta = json_decode((string) ($missingRefundStatusWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $missingRefundStatusWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $missingRefundStatusCountBefore
    && is_array($missingRefundStatusWebhookReceipt)
    && (string) ($missingRefundStatusWebhookReceipt['status'] ?? '') === 'failed'
    && str_contains((string) ($missingRefundStatusWebhookReceiptMeta['failure_error'] ?? ''), 'refund payload is incomplete'),
    'Core payment webhook rejects missing refund statuses before writing refund rows'
);
$nonCanonicalRefundReasonWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-noncanonical-refund-reason',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-noncanonical-reason',
        'amount_minor' => 100,
        'status' => 'completed',
        'reason' => ' provider refund webhook ',
    ],
], JSON_UNESCAPED_SLASHES);
$nonCanonicalRefundReasonWebhookTimestamp = (string) time();
$nonCanonicalRefundReasonCountBefore = count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0)));
$nonCanonicalRefundReasonWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $nonCanonicalRefundReasonWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $nonCanonicalRefundReasonWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $nonCanonicalRefundReasonWebhookTimestamp . '.' . (string) $nonCanonicalRefundReasonWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-noncanonical-refund-reason',
]));
$nonCanonicalRefundReasonWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-noncanonical-refund-reason'")->fetchColumn();
$nonCanonicalRefundReasonWebhookReceipt = $adminRepo->webhookReceiptById($nonCanonicalRefundReasonWebhookReceiptId);
$nonCanonicalRefundReasonWebhookReceiptMeta = json_decode((string) ($nonCanonicalRefundReasonWebhookReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $nonCanonicalRefundReasonWebhookResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === $nonCanonicalRefundReasonCountBefore
    && is_array($nonCanonicalRefundReasonWebhookReceipt)
    && (string) ($nonCanonicalRefundReasonWebhookReceipt['status'] ?? '') === 'failed'
    && str_contains((string) ($nonCanonicalRefundReasonWebhookReceiptMeta['failure_error'] ?? ''), 'refund reason'),
    'Core payment webhook rejects non-canonical refund reasons before writing refund rows'
);
$corruptWebhookRefundAmountPayment = $adminService->createProviderPayment('paid_download', 'webhook-corrupt-amount-target', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'admin-webhook-corrupt-amount-key');
$adminPdo->prepare('UPDATE cms_payments SET amount_minor = :amount WHERE id = :id')->execute([
    ':amount' => '1000abc',
    ':id' => (int) ($corruptWebhookRefundAmountPayment['id'] ?? 0),
]);
$corruptWebhookRefundAmountPayload = json_encode([
    'id' => 'evt-core-webhook-corrupt-ledger-amount',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($corruptWebhookRefundAmountPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-corrupt-ledger-amount',
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$corruptWebhookRefundAmountTimestamp = (string) time();
$corruptWebhookRefundAmountCountBefore = count($adminRepo->refundsForPayment((int) ($corruptWebhookRefundAmountPayment['id'] ?? 0)));
$corruptWebhookRefundAmountResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $corruptWebhookRefundAmountPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $corruptWebhookRefundAmountTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $corruptWebhookRefundAmountTimestamp . '.' . (string) $corruptWebhookRefundAmountPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-corrupt-ledger-amount',
]));
$corruptWebhookRefundAmountReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-corrupt-ledger-amount'")->fetchColumn();
$corruptWebhookRefundAmountReceipt = $adminRepo->webhookReceiptById($corruptWebhookRefundAmountReceiptId);
$corruptWebhookRefundAmountReceiptMeta = json_decode((string) ($corruptWebhookRefundAmountReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $corruptWebhookRefundAmountResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($corruptWebhookRefundAmountPayment['id'] ?? 0))) === $corruptWebhookRefundAmountCountBefore
    && (string) ($adminRepo->payment((int) ($corruptWebhookRefundAmountPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && is_array($corruptWebhookRefundAmountReceipt)
    && (string) ($corruptWebhookRefundAmountReceipt['status'] ?? '') === 'failed'
    && ($corruptWebhookRefundAmountReceipt['payment_id'] ?? null) === null
    && str_contains((string) ($corruptWebhookRefundAmountReceiptMeta['failure_error'] ?? ''), 'ledger amount'),
    'Core payment webhook rejects corrupted source ledger amounts before writing refund rows or binding receipts'
);
$corruptWebhookRefundCurrencyPayment = $adminService->createProviderPayment('paid_download', 'webhook-corrupt-currency-target', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'admin-webhook-corrupt-currency-key');
$adminPdo->prepare('UPDATE cms_payments SET currency = :currency WHERE id = :id')->execute([
    ':currency' => 'usd',
    ':id' => (int) ($corruptWebhookRefundCurrencyPayment['id'] ?? 0),
]);
$corruptWebhookRefundCurrencyPayload = json_encode([
    'id' => 'evt-core-webhook-corrupt-ledger-currency',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($corruptWebhookRefundCurrencyPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-corrupt-ledger-currency',
        'amount_minor' => 100,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$corruptWebhookRefundCurrencyTimestamp = (string) time();
$corruptWebhookRefundCurrencyCountBefore = count($adminRepo->refundsForPayment((int) ($corruptWebhookRefundCurrencyPayment['id'] ?? 0)));
$corruptWebhookRefundCurrencyResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $corruptWebhookRefundCurrencyPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $corruptWebhookRefundCurrencyTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $corruptWebhookRefundCurrencyTimestamp . '.' . (string) $corruptWebhookRefundCurrencyPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-corrupt-ledger-currency',
]));
$corruptWebhookRefundCurrencyReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-corrupt-ledger-currency'")->fetchColumn();
$corruptWebhookRefundCurrencyReceipt = $adminRepo->webhookReceiptById($corruptWebhookRefundCurrencyReceiptId);
$corruptWebhookRefundCurrencyReceiptMeta = json_decode((string) ($corruptWebhookRefundCurrencyReceipt['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    $corruptWebhookRefundCurrencyResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($corruptWebhookRefundCurrencyPayment['id'] ?? 0))) === $corruptWebhookRefundCurrencyCountBefore
    && (string) ($adminRepo->payment((int) ($corruptWebhookRefundCurrencyPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && is_array($corruptWebhookRefundCurrencyReceipt)
    && (string) ($corruptWebhookRefundCurrencyReceipt['status'] ?? '') === 'failed'
    && str_contains((string) ($corruptWebhookRefundCurrencyReceiptMeta['failure_error'] ?? ''), 'ledger currency'),
    'Core payment webhook rejects corrupted source ledger currencies before writing refund rows'
);
$webhookRefundPayload = json_encode([
    'id' => 'evt-core-webhook-refund',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-1',
        'amount_minor' => 600,
        'status' => 'completed',
        'reason' => 'provider refund webhook',
    ],
], JSON_UNESCAPED_SLASHES);
$webhookRefundTimestamp = (string) time();
$webhookRefundRequest = new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $webhookRefundPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookRefundTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookRefundTimestamp . '.' . (string) $webhookRefundPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-refund',
]);
$webhookRefundResponse = $webhookController->receive($webhookRefundRequest);
$webhookRefundBody = json_decode($webhookRefundResponse->body(), true) ?: [];
$webhookRefundRepeat = $webhookController->receive($webhookRefundRequest);
$webhookRefundAuthorizationId = $adminRepo->insertAuthorization([
    'payment_id' => (int) ($webhookRefundPayment['id'] ?? 0),
    'subject_type' => 'paid_download',
    'subject_id' => 'webhook-refund-target',
    'token_hash' => hash('sha256', 'webhook-refund-token-secret'),
    'status' => 'active',
    'max_uses' => 0,
    'used_count' => 0,
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => ['source' => 'webhook-refund-test'],
]);
$webhookRefundEntitlement = $entitlements->grantFromPayment((int) ($webhookRefundPayment['id'] ?? 0), 'member', 'webhook-refund-member', $webhookRefundAuthorizationId, '', ['source' => 'webhook-refund-test']);
core_payment_check(
    $webhookRefundResponse->status() === 200
    && $webhookRefundRepeat->status() === 200
    && (string) ($webhookRefundResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($webhookRefundRepeat->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (int) ($webhookRefundBody['applied_payment_id'] ?? 0) === (int) ($webhookRefundPayment['id'] ?? 0)
    && (string) ($adminRepo->payment((int) ($webhookRefundPayment['id'] ?? 0))['status'] ?? '') === 'partially_refunded'
    && (string) (($adminRepo->authorization($webhookRefundAuthorizationId)['status'] ?? '')) === 'active'
    && (string) (($adminRepo->entitlement((int) ($webhookRefundEntitlement['id'] ?? 0))['status'] ?? '')) === 'active'
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === 1
    && (int) ($adminRepo->webhookReceiptById((int) ($webhookRefundBody['receipt_id'] ?? 0))['payment_id'] ?? 0) === (int) ($webhookRefundPayment['id'] ?? 0)
    && (string) ($adminRepo->webhookReceiptById((int) ($webhookRefundBody['receipt_id'] ?? 0))['status'] ?? '') === 'processed',
    'Core payment webhook endpoint records signed provider-neutral partial refunds idempotently with no-store responses without revoking access'
);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%evt-core-webhook-refund%'")->fetchColumn() === 1
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%\"refund_amount_minor\":600%'")->fetchColumn() === 1,
    'Core payment webhook refund application writes one CMS audit event across duplicate delivery'
);
$stalePaidRefundPayment = $adminService->createProviderPayment('paid_download', 'webhook-stale-paid-refund-target', FixturePaymentProvider::PROVIDER_ID, 1000, 'USD', 'admin-webhook-stale-paid-refund-key');
$stalePaidRefundPayload = json_encode([
    'id' => 'evt-core-webhook-refund-stale-paid',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($stalePaidRefundPayment['remote_id'] ?? ''),
    'payment_status' => 'paid',
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-stale-paid',
        'amount_minor' => 400,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$stalePaidRefundTimestamp = (string) time();
$stalePaidRefundResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $stalePaidRefundPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $stalePaidRefundTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $stalePaidRefundTimestamp . '.' . (string) $stalePaidRefundPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-refund-stale-paid',
]));
core_payment_check(
    $stalePaidRefundResponse->status() === 200
    && (string) ($adminRepo->payment((int) ($stalePaidRefundPayment['id'] ?? 0))['status'] ?? '') === 'partially_refunded'
    && count($adminRepo->refundsForPayment((int) ($stalePaidRefundPayment['id'] ?? 0))) === 1,
    'Core payment webhook refund keeps refund-derived status when Provider also sends stale paid status'
);
$atomicWebhookPayment = $adminService->createProviderPayment('paid_download', 'webhook-atomic-target', FixturePaymentProvider::PROVIDER_ID, 900, 'USD', 'admin-webhook-atomic-key');
$atomicWebhookRefundCountBefore = count($adminRepo->refundsForPayment((int) ($atomicWebhookPayment['id'] ?? 0)));
$atomicWebhookAuditCountBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%evt-core-webhook-atomic-failure%'")->fetchColumn();
$atomicWebhookPayload = json_encode([
    'id' => 'evt-core-webhook-atomic-failure',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($atomicWebhookPayment['remote_id'] ?? ''),
    'payment_status' => 'failed',
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-atomic',
        'amount_minor' => 300,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$atomicWebhookTimestamp = (string) time();
$atomicWebhookResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $atomicWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $atomicWebhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $atomicWebhookTimestamp . '.' . (string) $atomicWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-atomic-failure',
]));
$atomicWebhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-atomic-failure'")->fetchColumn();
$atomicWebhookReceipt = $adminRepo->webhookReceiptById($atomicWebhookReceiptId);
core_payment_check(
    $atomicWebhookResponse->status() === 400
    && (string) ($adminRepo->payment((int) ($atomicWebhookPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && count($adminRepo->refundsForPayment((int) ($atomicWebhookPayment['id'] ?? 0))) === $atomicWebhookRefundCountBefore
    && is_array($atomicWebhookReceipt)
    && ($atomicWebhookReceipt['payment_id'] ?? null) === null
    && (string) ($atomicWebhookReceipt['status'] ?? '') === 'failed'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.applied' AND context_json LIKE '%evt-core-webhook-atomic-failure%'")->fetchColumn() === $atomicWebhookAuditCountBefore,
    'Core payment webhook application rolls back receipt binding and refund side effects atomically when a later status transition fails'
);
$webhookDuplicateRefundPayload = json_encode([
    'id' => 'evt-core-webhook-refund-duplicate',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-1',
        'amount_minor' => 600,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$webhookDuplicateRefundTimestamp = (string) time();
$webhookDuplicateRefundResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $webhookDuplicateRefundPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookDuplicateRefundTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookDuplicateRefundTimestamp . '.' . (string) $webhookDuplicateRefundPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-refund-duplicate',
]));
core_payment_check(
    $webhookDuplicateRefundResponse->status() === 400
    && count($adminRepo->refundsForPayment((int) ($webhookRefundPayment['id'] ?? 0))) === 1,
    'Core payment webhook endpoint rejects duplicate Provider refund references across events'
);
$webhookFullRefundPayload = json_encode([
    'id' => 'evt-core-webhook-refund-full',
    'event_type' => 'payment.refund.completed',
    'provider_payment_id' => (string) ($webhookRefundPayment['remote_id'] ?? ''),
    'refund' => [
        'provider_refund_id' => 'rf-core-webhook-2',
        'amount_minor' => 1000,
        'status' => 'completed',
    ],
], JSON_UNESCAPED_SLASHES);
$webhookFullRefundTimestamp = (string) time();
$webhookFullRefundResponse = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $webhookFullRefundPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookFullRefundTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookFullRefundTimestamp . '.' . (string) $webhookFullRefundPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-refund-full',
]));
$webhookFullRefundAuthorization = $adminRepo->authorization($webhookRefundAuthorizationId);
$webhookFullRefundEntitlement = $adminRepo->entitlement((int) ($webhookRefundEntitlement['id'] ?? 0));
core_payment_check(
    $webhookFullRefundResponse->status() === 200
    && (string) ($adminRepo->payment((int) ($webhookRefundPayment['id'] ?? 0))['status'] ?? '') === 'refunded'
    && is_array($webhookFullRefundAuthorization)
    && (string) ($webhookFullRefundAuthorization['status'] ?? '') === 'revoked'
    && is_array($webhookFullRefundEntitlement)
    && (string) ($webhookFullRefundEntitlement['status'] ?? '') === 'revoked'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $webhookRefundAuthorizationId . " AND event_type = 'revoked'")->fetchColumn() === 1
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.access.revoked_after_refund' AND context_json LIKE '%webhook_refund%' AND context_json LIKE '%\"payment_id\":" . (int) ($webhookRefundPayment['id'] ?? 0) . "%'")->fetchColumn() === 1,
    'Core webhook full refund revokes payment-backed authorization and entitlement state'
);
$webhookReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook'")->fetchColumn();
$webhookReceiptBeforeQueryCsrf = $adminRepo->webhookReceiptById($webhookReceiptId);
$webhookStatusAuditBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn();
$queryCsrfWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $webhookReceiptId . '/status', [
    '_csrf' => $csrf,
    'status' => 'ignored',
], [], []));
$webhookReceiptAfterQueryCsrf = $adminRepo->webhookReceiptById($webhookReceiptId);
core_payment_check(
    $queryCsrfWebhookStatusResponse->status() === 403
    && (string) ($queryCsrfWebhookStatusResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($webhookReceiptBeforeQueryCsrf)
    && is_array($webhookReceiptAfterQueryCsrf)
    && (string) ($webhookReceiptAfterQueryCsrf['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterQueryCsrf['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeQueryCsrf,
    'Core admin payment webhook status action accepts CSRF only from POST body with no-store responses before receipt status changes or audit'
);
$nonCanonicalWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $webhookReceiptId . '/status', [], ['_csrf' => $csrf, 'status' => ' Ignored '], []));
$webhookReceiptAfterNonCanonicalStatus = $adminRepo->webhookReceiptById($webhookReceiptId);
core_payment_check(
    $nonCanonicalWebhookStatusResponse->status() === 400
    && (string) ($nonCanonicalWebhookStatusResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($webhookReceiptAfterNonCanonicalStatus)
    && (string) ($webhookReceiptAfterNonCanonicalStatus['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterNonCanonicalStatus['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeQueryCsrf,
    'Core admin payment webhook status action rejects non-canonical statuses with no-store responses before receipt changes or audit'
);
$arrayWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $webhookReceiptId . '/status', [], ['_csrf' => $csrf, 'status' => ['ignored']], []));
$webhookReceiptAfterArrayStatus = $adminRepo->webhookReceiptById($webhookReceiptId);
$booleanWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $webhookReceiptId . '/status', [], ['_csrf' => $csrf, 'status' => true], []));
$webhookReceiptAfterBooleanStatus = $adminRepo->webhookReceiptById($webhookReceiptId);
core_payment_check(
    $arrayWebhookStatusResponse->status() === 400
    && $booleanWebhookStatusResponse->status() === 400
    && is_array($webhookReceiptAfterArrayStatus)
    && is_array($webhookReceiptAfterBooleanStatus)
    && (string) ($webhookReceiptAfterArrayStatus['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterArrayStatus['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (string) ($webhookReceiptAfterBooleanStatus['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterBooleanStatus['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeQueryCsrf,
    'Core admin payment webhook status action rejects non-string statuses before receipt changes or audit'
);
$trailingWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $webhookReceiptId . '/status/extra', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$webhookReceiptAfterTrailingStatus = $adminRepo->webhookReceiptById($webhookReceiptId);
core_payment_check(
    $trailingWebhookStatusResponse->status() === 400
    && is_array($webhookReceiptAfterTrailingStatus)
    && (string) ($webhookReceiptAfterTrailingStatus['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterTrailingStatus['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeQueryCsrf,
    'Core admin payment webhook status action rejects non-canonical action paths before receipt changes or audit'
);
$oversizedWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/1234567890123456789/webhooks/1234567890123456789/status', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$webhookReceiptAfterOversizedPathStatus = $adminRepo->webhookReceiptById($webhookReceiptId);
core_payment_check(
    $oversizedWebhookStatusResponse->status() === 400
    && is_array($webhookReceiptAfterOversizedPathStatus)
    && (string) ($webhookReceiptAfterOversizedPathStatus['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterOversizedPathStatus['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeQueryCsrf,
    'Core admin payment webhook status action rejects oversized path ids before receipt changes or audit'
);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => $webhookReceiptId,
    ':payment_id' => (string) ((int) ($adminPayment['id'] ?? 0)) . 'abc',
]);
$corruptPaymentIdWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $webhookReceiptId . '/status', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$webhookReceiptAfterCorruptPaymentIdStatus = $adminRepo->webhookReceiptById($webhookReceiptId);
core_payment_check(
    $corruptPaymentIdWebhookStatusResponse->status() === 400
    && is_array($webhookReceiptAfterCorruptPaymentIdStatus)
    && (string) ($webhookReceiptAfterCorruptPaymentIdStatus['status'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['status'] ?? '')
    && (string) ($webhookReceiptAfterCorruptPaymentIdStatus['processed_at'] ?? '') === (string) ($webhookReceiptBeforeQueryCsrf['processed_at'] ?? '')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeQueryCsrf,
    'Core admin payment webhook receipt status action rejects corrupted payment ids instead of casting restored rows'
);
$adminWebhookAuditFailureDbFile = $tmpRoot . '/payment-admin-webhook-audit-failure.sqlite';
$adminWebhookAuditFailurePdo = new PDO('sqlite:' . $adminWebhookAuditFailureDbFile);
(new MigrationRunner($adminWebhookAuditFailurePdo, $migrations))->run();
$adminWebhookAuditFailureRepo = new PaymentRepository($adminWebhookAuditFailurePdo);
$adminWebhookAuditFailureSettings = new PaymentProviderSettingsRepository($adminWebhookAuditFailurePdo, 'core-payment-admin-settings-key');
$adminWebhookAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_audit_failure']);
$adminWebhookAuditFailureService = new PaymentService($adminWebhookAuditFailurePdo, $adminWebhookAuditFailureRepo);
$adminWebhookAuditFailurePayment = $adminWebhookAuditFailureService->createProviderPayment('paid_download', 'admin-webhook-audit-failure', FixturePaymentProvider::PROVIDER_ID, 600, 'USD', 'admin-webhook-audit-failure-payment');
$adminWebhookAuditFailureReceipt = $adminWebhookAuditFailureService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-admin-webhook-audit-failure', '{"id":"evt-admin-webhook-audit-failure"}');
$adminWebhookAuditFailurePdo->exec('DROP TABLE cms_audit_logs');
$adminWebhookAuditFailureSettingsObject = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminWebhookAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]);
$adminWebhookAuditFailureController = new AdminController($adminWebhookAuditFailureSettingsObject, new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminWebhookAuditFailureResponse = $adminWebhookAuditFailureController->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) ($adminWebhookAuditFailurePayment['id'] ?? 0) . '/webhooks/' . (int) ($adminWebhookAuditFailureReceipt['id'] ?? 0) . '/status', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$adminWebhookAuditFailureAfter = $adminWebhookAuditFailureRepo->webhookReceiptById((int) ($adminWebhookAuditFailureReceipt['id'] ?? 0));
core_payment_check(
    $adminWebhookAuditFailureResponse->status() === 500
    && (string) ($adminWebhookAuditFailureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($adminWebhookAuditFailureAfter)
    && (string) ($adminWebhookAuditFailureAfter['status'] ?? '') === 'received'
    && ($adminWebhookAuditFailureAfter['processed_at'] ?? null) === null,
    'Core admin payment webhook receipt status action rolls back receipt changes when audit persistence fails'
);
$adminActionWebhookReceipt = $adminService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-admin-webhook-status-action', '{"id":"evt-admin-webhook-status-action"}');
$adminActionWebhookReceiptId = (int) ($adminActionWebhookReceipt['id'] ?? 0);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => $adminActionWebhookReceiptId,
    ':payment_id' => (int) ($adminPayment['id'] ?? 0),
]);
$webhookStatusAuditBeforeAction = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn();
$webhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $adminActionWebhookReceiptId . '/status', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$webhookReceiptAfterStatus = $adminRepo->webhookReceiptById($adminActionWebhookReceiptId);
core_payment_check(
    $webhookStatusResponse->status() === 302
    && (string) ($webhookStatusResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($webhookReceiptAfterStatus)
    && (string) $webhookReceiptAfterStatus['status'] === 'ignored'
    && (string) ($webhookReceiptAfterStatus['processed_at'] ?? '') !== ''
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $webhookStatusAuditBeforeAction + 1,
    'Core admin payment webhook receipt status action marks receipt, writes audit and redirects no-store'
);
$boundOtherPaymentWebhookReceiptId = (int) ($webhookRefundBody['receipt_id'] ?? 0);
$crossPaymentWebhookStatusAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn();
$crossPaymentWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $boundOtherPaymentWebhookReceiptId . '/status', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$crossPaymentWebhookReceiptAfter = $adminRepo->webhookReceiptById($boundOtherPaymentWebhookReceiptId);
core_payment_check(
    $crossPaymentWebhookStatusResponse->status() === 400
    && is_array($crossPaymentWebhookReceiptAfter)
    && (int) ($crossPaymentWebhookReceiptAfter['payment_id'] ?? 0) === (int) ($webhookRefundPayment['id'] ?? 0)
    && (string) ($crossPaymentWebhookReceiptAfter['status'] ?? '') === 'processed'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $crossPaymentWebhookStatusAuditBefore,
    'Core admin payment webhook receipt status action rejects receipts bound to another payment'
);
$badWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => 'bad-signature',
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-bad',
]));
$methodWebhook = $webhookController->receive(new Request('GET', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], []));
core_payment_check(
    $badWebhook->status() === 400
    && (string) ($badWebhook->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'Core payment webhook endpoint rejects invalid signatures with no-store responses'
);
core_payment_check(
	    $methodWebhook->status() === 405
	    && (string) ($methodWebhook->headers()['Allow'] ?? '') === 'POST'
	    && (string) ($methodWebhook->headers()['Cache-Control'] ?? '') === 'private, no-store'
	    && str_contains($methodWebhook->body(), '支付通知接口只接受 POST 请求')
	    && !str_contains($methodWebhook->body(), 'Method Not Allowed'),
	    'Core payment webhook endpoint rejects non-POST methods with no-store responses'
	);
$badSignatureFormatWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => str_repeat('z', 64),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-bad-signature-format',
]));
core_payment_check(
    $badSignatureFormatWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-bad-signature-format'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects malformed signature headers before storing receipts'
);
$uppercaseSignatureWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => strtoupper(hash_hmac('sha256', $webhookTimestamp . '.' . $webhookPayload, 'whsec_admin_secret')),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-uppercase-signature',
]));
core_payment_check(
    $uppercaseSignatureWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-uppercase-signature'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical uppercase signatures before storing receipts'
);
$nonCanonicalTimestamp = '0' . $webhookTimestamp;
$nonCanonicalTimestampWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $nonCanonicalTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $nonCanonicalTimestamp . '.' . $webhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-noncanonical-timestamp',
]));
core_payment_check(
    $nonCanonicalTimestampWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-noncanonical-timestamp'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical signature timestamps before storing receipts'
);
$oldTimestamp = (string) (time() - 600);
$oldWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $oldTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $oldTimestamp . '.' . $webhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-old',
]));
core_payment_check($oldWebhook->status() === 400, 'Core payment webhook endpoint rejects stale signed events');
$badContentTypeWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'CONTENT_TYPE' => 'text/plain',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $webhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-bad-content-type',
]));
core_payment_check(
    $badContentTypeWebhook->status() === 415
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-bad-content-type'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-JSON content types before storing receipts'
);
$oversizedWebhookPayload = str_repeat('x', 262145);
$oversizedWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $oversizedWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $oversizedWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-oversized',
]));
core_payment_check(
    $oversizedWebhook->status() === 413
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-oversized'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects oversized payloads before storing receipts'
);
$wideWebhookLimitSettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    'payment' => ['webhook_max_payload_bytes' => '1048576'],
]);
$wideWebhookLimitPayload = json_encode([
    'id' => 'evt-core-webhook-wide-max-payload',
    'pad' => str_repeat('x', 262145),
], JSON_UNESCAPED_SLASHES);
$wideWebhookLimitTimestamp = (string) time();
$wideWebhookLimitResponse = (new PaymentWebhookController($wideWebhookLimitSettings))->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $wideWebhookLimitPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $wideWebhookLimitTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $wideWebhookLimitTimestamp . '.' . (string) $wideWebhookLimitPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-wide-max-payload',
]));
core_payment_check(
    $wideWebhookLimitResponse->status() === 200
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-wide-max-payload'")->fetchColumn() === 1,
    'Core payment webhook endpoint accepts canonical expanded payload limit settings'
);
$spacedWebhookLimitSettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    'payment' => ['webhook_max_payload_bytes' => ' 1048576 '],
]);
$spacedWebhookLimitPayload = json_encode([
    'id' => 'evt-core-webhook-spaced-max-payload',
    'pad' => str_repeat('x', 262145),
], JSON_UNESCAPED_SLASHES);
$spacedWebhookLimitTimestamp = (string) time();
$spacedWebhookLimitResponse = (new PaymentWebhookController($spacedWebhookLimitSettings))->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $spacedWebhookLimitPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $spacedWebhookLimitTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $spacedWebhookLimitTimestamp . '.' . (string) $spacedWebhookLimitPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-max-payload',
]));
core_payment_check(
    $spacedWebhookLimitResponse->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-spaced-max-payload'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical payload limit settings before storing receipts'
);
$missingEventWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $webhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $webhookPayload, 'whsec_admin_secret'),
]));
core_payment_check(
    $missingEventWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = ''")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects missing event ids before storing receipts'
);
core_payment_throws(
    static fn () => $adminService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, "evt-bad\nservice", '{"id":"evt-bad-service"}'),
    'Core payment service rejects unsafe webhook event ids before storing receipts'
);
core_payment_throws(
    static fn () => $adminService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-service-payment_token%3Draw-service-event-token', '{"id":"evt-service-payment-token"}'),
    'Core payment service rejects URL-encoded token-like webhook event ids before storing receipts'
);
$terminalWebhookPayload = '{"id":"evt-core-terminal-corrupt-payment-id"}';
$terminalWebhookReceipt = $adminService->recordWebhookReceipt(FixturePaymentProvider::PROVIDER_ID, 'evt-core-terminal-corrupt-payment-id', $terminalWebhookPayload);
$adminRepo->updateWebhookReceiptStatus((int) ($terminalWebhookReceipt['id'] ?? 0), 'processed');
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET payment_id = :payment_id WHERE id = :id')->execute([
    ':payment_id' => (string) ($adminPayment['id'] ?? '') . 'abc',
    ':id' => (int) ($terminalWebhookReceipt['id'] ?? 0),
]);
core_payment_check(
    $adminService->applyWebhookPaymentStatus(FixturePaymentProvider::PROVIDER_ID, (int) ($terminalWebhookReceipt['id'] ?? 0), $terminalWebhookPayload) === null,
    'Core payment service ignores corrupted terminal webhook payment ids instead of casting restored receipts'
);
$unsafeEventIdWebhookPayload = '{"status":"paid"}';
$unsafeEventIdWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $unsafeEventIdWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $unsafeEventIdWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => "evt-core-webhook\nbad-event-id",
]));
core_payment_check(
    $unsafeEventIdWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id LIKE 'evt-core-webhook%' AND external_event_id LIKE '%bad-event-id%'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects unsafe event ids before storing receipts'
);
$encodedTokenEventIdWebhookPayload = '{"status":"paid"}';
$encodedTokenEventIdWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $encodedTokenEventIdWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $encodedTokenEventIdWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-payment_token%3Draw-header-event-token',
]));
core_payment_check(
    $encodedTokenEventIdWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id LIKE '%raw-header-event-token%'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects URL-encoded token-like event ids before storing receipts'
);
$spacedHeaderEventIdWebhookPayload = '{"status":"paid"}';
$spacedHeaderEventIdWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $spacedHeaderEventIdWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $spacedHeaderEventIdWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => ' evt-core-webhook-spaced-header-event-id ',
]));
core_payment_check(
    $spacedHeaderEventIdWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id LIKE '%evt-core-webhook-spaced-header-event-id%'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical header event ids before storing receipts'
);
$spacedPayloadEventIdWebhookPayload = '{"id":" evt-core-webhook-spaced-payload-event-id ","status":"paid"}';
$spacedPayloadEventIdWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $spacedPayloadEventIdWebhookPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $spacedPayloadEventIdWebhookPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-spaced-payload-event-id',
]));
core_payment_check(
    $spacedPayloadEventIdWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id LIKE '%evt-core-webhook-spaced-payload-event-id%'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects non-canonical payload event ids before storing receipts'
);
$mismatchPayload = '{"id":"evt-core-webhook-payload-id","status":"paid"}';
$mismatchWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $mismatchPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $mismatchPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-header-id',
]));
core_payment_check(
    $mismatchWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id IN ('evt-core-webhook-payload-id','evt-core-webhook-header-id')")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects mismatched header and payload event ids before storing receipts'
);
$matchingEventIdPayload = '{"event_id":"evt-core-webhook-matching-event-id"}';
$matchingEventIdWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $matchingEventIdPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $matchingEventIdPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-matching-event-id',
]));
$matchingEventIdReceipt = $adminRepo->webhookReceiptById((int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-matching-event-id'")->fetchColumn());
core_payment_check(
    $matchingEventIdWebhook->status() === 200
    && (string) ($matchingEventIdWebhook->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($matchingEventIdReceipt)
    && (string) ($matchingEventIdReceipt['status'] ?? '') === 'ignored'
    && (string) ($matchingEventIdReceipt['processed_at'] ?? '') !== ''
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook.ignored' AND context_json LIKE '%evt-core-webhook-matching-event-id%'")->fetchColumn() === 1,
    'Core payment webhook endpoint accepts matching header and payload event ids with no-store response, then terminally ignores non-payment events'
);
$ignoredWebhookReceiptId = (int) ($matchingEventIdReceipt['id'] ?? 0);
$ignoredTerminalRewriteAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn();
$ignoredTerminalRewriteResponse = $admin->paymentWebhookStatus(new Request(
    'POST',
    '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . $ignoredWebhookReceiptId . '/status',
    [],
    ['_csrf' => $csrf, 'status' => 'processed'],
    []
));
core_payment_check(
    $ignoredTerminalRewriteResponse->status() === 400
    && (string) ($adminRepo->webhookReceiptById($ignoredWebhookReceiptId)['status'] ?? '') === 'ignored'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $ignoredTerminalRewriteAuditBefore,
    'Core admin webhook status action cannot rewrite terminal ignored receipts'
);
$emptyPayloadWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => '',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.', 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-empty-payload',
]));
core_payment_check(
    $emptyPayloadWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-empty-payload'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects empty payloads before storing receipts'
);
$malformedPayload = '{"id":';
$malformedWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => $malformedPayload,
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestamp,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestamp . '.' . $malformedPayload, 'whsec_admin_secret'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-webhook-malformed-payload',
]));
core_payment_check(
    $malformedWebhook->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id = 'evt-core-webhook-malformed-payload'")->fetchColumn() === 0,
    'Core payment webhook endpoint rejects malformed JSON payloads before storing receipts'
);
$_SERVER['REQUEST_URI'] = '/admin/payments/' . (int) $adminPayment['id'];
$detailResponse = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) $adminPayment['id'], [], [], []));
core_payment_check(
    $detailResponse->status() === 200
    && (string) ($detailResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($detailResponse->body(), '支付详情')
    && str_contains($detailResponse->body(), 'Subject 可信状态')
    && str_contains($detailResponse->body(), '净支付')
    && str_contains($detailResponse->body(), 'USD 12.00')
    && str_contains($detailResponse->body(), '发起退款'),
    'Core admin payment detail renders no-store refund controls and subject trusted payment status'
);
$oversizedAdminDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/1234567890123456789', [], [], []));
core_payment_check(
    $oversizedAdminDetail->status() === 400
    && (string) ($oversizedAdminDetail->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'Core admin payment detail rejects oversized path ids with no-store responses before querying payment state'
);
$adminRefundCountBeforeInvalidAmount = count($adminRepo->refundsForPayment((int) $adminPayment['id']));
$adminRefundAuditCountBeforeInvalidAmount = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn();
$invalidAdminRefundAmount = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100abc',
    'reason' => 'invalid admin refund amount',
    'idempotency_key' => 'admin-invalid-refund-amount',
], []));
core_payment_check(
    $invalidAdminRefundAmount->status() === 400
    && (string) ($invalidAdminRefundAmount->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($invalidAdminRefundAmount->body(), 'positive integer minor-unit')
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeInvalidAmount
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeInvalidAmount
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-integer amounts with no-store responses before writing refunds or audit'
);
$spacedAdminRefundAmount = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => ' 100 ',
    'reason' => 'spaced admin refund amount',
    'idempotency_key' => 'admin-spaced-refund-amount',
], []));
core_payment_check(
    $spacedAdminRefundAmount->status() === 400
    && str_contains($spacedAdminRefundAmount->body(), 'positive integer minor-unit')
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeInvalidAmount
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeInvalidAmount
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-canonical amounts before writing refunds or audit'
);
$arrayAdminRefundAmount = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => ['100'],
    'reason' => 'array admin refund amount',
    'idempotency_key' => 'admin-array-refund-amount',
], []));
$booleanAdminRefundAmount = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => true,
    'reason' => 'boolean admin refund amount',
    'idempotency_key' => 'admin-boolean-refund-amount',
], []));
core_payment_check(
    $arrayAdminRefundAmount->status() === 400
    && $booleanAdminRefundAmount->status() === 400
    && str_contains($arrayAdminRefundAmount->body(), 'positive integer minor-unit')
    && str_contains($booleanAdminRefundAmount->body(), 'positive integer minor-unit')
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeInvalidAmount
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeInvalidAmount
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-string amounts before writing refunds or audit'
);
$adminRefundCountBeforeNonCanonicalReason = count($adminRepo->refundsForPayment((int) $adminPayment['id']));
$adminRefundAuditCountBeforeNonCanonicalReason = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn();
$nonCanonicalAdminRefundReason = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => ' admin refund reason ',
    'idempotency_key' => 'admin-non-canonical-refund-reason',
], []));
core_payment_check(
    $nonCanonicalAdminRefundReason->status() === 400
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeNonCanonicalReason
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeNonCanonicalReason
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-canonical reasons before writing refunds or audit'
);
$adminRefundCountBeforeQueryCsrf = count($adminRepo->refundsForPayment((int) $adminPayment['id']));
$adminRefundAuditCountBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn();
$queryCsrfAdminRefund = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'query csrf refund',
    'idempotency_key' => 'admin-query-csrf-refund',
], [], []));
core_payment_check(
    $queryCsrfAdminRefund->status() === 403
    && (string) ($queryCsrfAdminRefund->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeQueryCsrf
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeQueryCsrf
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action accepts CSRF only from POST body with no-store responses before writing refunds or audit'
);
$arrayCsrfAdminRefund = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => [$csrf],
    'amount_minor' => '100',
    'reason' => 'array csrf refund',
    'idempotency_key' => 'admin-array-csrf-refund',
], []));
core_payment_check(
    $arrayCsrfAdminRefund->status() === 403
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeQueryCsrf
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeQueryCsrf
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-scalar CSRF before writing refunds or audit'
);
$adminRefundCountBeforeNonCanonicalKey = count($adminRepo->refundsForPayment((int) $adminPayment['id']));
$adminRefundAuditCountBeforeNonCanonicalKey = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn();
$nonCanonicalAdminRefundKey = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'non-canonical admin refund key',
    'idempotency_key' => ' admin-non-canonical-refund ',
], []));
core_payment_check(
    $nonCanonicalAdminRefundKey->status() === 400
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeNonCanonicalKey
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeNonCanonicalKey
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-canonical idempotency keys before writing refunds or audit'
);
$arrayAdminRefundReason = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => ['array admin refund reason'],
    'idempotency_key' => 'admin-array-refund-reason',
], []));
$arrayAdminRefundKey = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'array admin refund key',
    'idempotency_key' => ['admin-array-refund-key'],
], []));
$booleanAdminRefundReason = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => false,
    'idempotency_key' => 'admin-boolean-refund-reason',
], []));
$booleanAdminRefundKey = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'boolean admin refund key',
    'idempotency_key' => true,
], []));
core_payment_check(
    $arrayAdminRefundReason->status() === 400
    && $arrayAdminRefundKey->status() === 400
    && $booleanAdminRefundReason->status() === 400
    && $booleanAdminRefundKey->status() === 400
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeNonCanonicalKey
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeNonCanonicalKey
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-string reason and idempotency fields before writing refunds or audit'
);
$trailingAdminRefundPath = $admin->paymentRefund(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/refund/extra', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'trailing admin refund path',
    'idempotency_key' => 'admin-trailing-refund-path',
], []));
core_payment_check(
    $trailingAdminRefundPath->status() === 400
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeNonCanonicalKey
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeNonCanonicalKey
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects non-canonical action paths before writing refunds or audit'
);
$oversizedAdminRefundPath = $admin->paymentRefund(new Request('POST', '/admin/payments/1234567890123456789/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'oversized admin refund path',
    'idempotency_key' => 'admin-oversized-refund-path',
], []));
core_payment_check(
    $oversizedAdminRefundPath->status() === 400
    && count($adminRepo->refundsForPayment((int) $adminPayment['id'])) === $adminRefundCountBeforeNonCanonicalKey
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.refund.created'")->fetchColumn() === $adminRefundAuditCountBeforeNonCanonicalKey
    && (string) ($adminRepo->payment((int) $adminPayment['id'])['status'] ?? '') === 'paid',
    'Core admin payment refund action rejects oversized path ids before writing refunds or audit'
);
$adminRefundAuditFailureDbFile = $tmpRoot . '/payment-admin-refund-audit-failure.sqlite';
$adminRefundAuditFailurePdo = new PDO('sqlite:' . $adminRefundAuditFailureDbFile);
$adminRefundAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new MigrationRunner($adminRefundAuditFailurePdo, $migrations))->run();
$adminRefundAuditFailureRepo = new PaymentRepository($adminRefundAuditFailurePdo);
$adminRefundAuditFailureSettings = new PaymentProviderSettingsRepository($adminRefundAuditFailurePdo, 'core-payment-admin-settings-key');
$adminRefundAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_refund_audit_failure']);
$adminRefundAuditFailureService = new PaymentService($adminRefundAuditFailurePdo, $adminRefundAuditFailureRepo);
$adminRefundAuditFailurePayment = $adminRefundAuditFailureService->createProviderPayment('paid_download', 'admin-refund-audit-failure', FixturePaymentProvider::PROVIDER_ID, 900, 'USD', 'admin-refund-audit-failure-payment');
$adminRefundAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_admin_refund_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.refund.created' BEGIN SELECT RAISE(ABORT, 'admin refund audit failure'); END");
$adminRefundAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminRefundAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminRefundAuditFailureResponse = $adminRefundAuditFailureController->paymentRefund(new Request('POST', '/admin/payments/' . (int) ($adminRefundAuditFailurePayment['id'] ?? 0) . '/refund', [], [
    '_csrf' => $csrf,
    'amount_minor' => '100',
    'reason' => 'admin refund audit failure',
    'idempotency_key' => 'admin-refund-audit-failure-refund',
], []));
$adminRefundAuditFailureAfter = $adminRefundAuditFailureRepo->payment((int) ($adminRefundAuditFailurePayment['id'] ?? 0));
core_payment_check(
    $adminRefundAuditFailureResponse->status() === 500
    && (string) ($adminRefundAuditFailureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && count($adminRefundAuditFailureRepo->refundsForPayment((int) ($adminRefundAuditFailurePayment['id'] ?? 0))) === 0
    && is_array($adminRefundAuditFailureAfter)
    && (string) ($adminRefundAuditFailureAfter['status'] ?? '') === 'paid'
    && (int) $adminRefundAuditFailurePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.refunded'")->fetchColumn() === 0,
    'Core admin payment refund action rolls back refund ledger, provider audit and parent payment state when admin audit persistence fails'
);
$webhookAppliedDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($webhookAppliedPayment['id'] ?? 0), [], [], []));
core_payment_check(
    $webhookAppliedDetail->status() === 200
    && !str_contains($webhookAppliedDetail->body(), 'Payment status transition is not allowed.')
    && !str_contains($webhookAppliedDetail->body(), 'evt-core-webhook-refund'),
    'Core admin payment detail omits unbound failed webhook diagnostics after atomic application rollback'
);
$webhookReceiptDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($webhookApplyBody['applied_payment_id'] ?? 0), [], [], []));
core_payment_check(
    $webhookReceiptDetail->status() === 200
    && str_contains($webhookReceiptDetail->body(), '摘要')
    && str_contains($webhookReceiptDetail->body(), 'payload=')
    && str_contains($webhookReceiptDetail->body(), 'type=application/json')
    && !str_contains($webhookReceiptDetail->body(), '203.0.113.44'),
    'Core admin payment detail shows webhook trace summaries without exposing source IPs'
);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => (int) ($webhookApplyBody['receipt_id'] ?? 0),
    ':metadata' => json_encode([
        'payload_size' => 123,
        'content_type' => ' application/x-corrupt-trace ',
        'webhook_timestamp' => ' 1234567890 ',
        'source_ip_hash' => ' ' . str_repeat('a', 64) . ' ',
        'failure_error' => '{"id":"raw-admin-webhook-payload"}',
    ], JSON_UNESCAPED_SLASHES),
]);
$corruptWebhookTraceDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($webhookApplyBody['applied_payment_id'] ?? 0), [], [], []));
core_payment_check(
    $corruptWebhookTraceDetail->status() === 200
    && str_contains($corruptWebhookTraceDetail->body(), 'payload=123B')
    && !str_contains($corruptWebhookTraceDetail->body(), 'application/x-corrupt-trace')
    && !str_contains($corruptWebhookTraceDetail->body(), 'ts=1234567890')
    && !str_contains($corruptWebhookTraceDetail->body(), 'src=aaaaaaaaaaaa')
    && !str_contains($corruptWebhookTraceDetail->body(), 'raw-admin-webhook-payload'),
    'Core admin payment detail omits non-canonical webhook trace metadata and raw diagnostics instead of trimming them for display'
);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => (int) ($webhookApplyBody['receipt_id'] ?? 0),
    ':metadata' => json_encode([
        'payload_size' => '123',
        'content_type' => 'application/json',
    ], JSON_UNESCAPED_SLASHES),
]);
$nonCanonicalPayloadSizeTraceDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($webhookApplyBody['applied_payment_id'] ?? 0), [], [], []));
core_payment_check(
    $nonCanonicalPayloadSizeTraceDetail->status() === 200
    && !str_contains($nonCanonicalPayloadSizeTraceDetail->body(), 'payload=123B')
    && str_contains($nonCanonicalPayloadSizeTraceDetail->body(), 'type=application/json'),
    'Core admin payment detail omits non-canonical webhook payload sizes instead of casting them for trace display'
);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => (int) ($webhookApplyBody['receipt_id'] ?? 0),
    ':metadata' => json_encode([
        'payload_size' => 123,
        'failure_error' => 'Provider failed payment_token%3Draw-admin-encoded-webhook-token',
    ], JSON_UNESCAPED_SLASHES),
]);
$secretLikeWebhookDiagnosticDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($webhookApplyBody['applied_payment_id'] ?? 0), [], [], []));
core_payment_check(
    $secretLikeWebhookDiagnosticDetail->status() === 200
    && !str_contains($secretLikeWebhookDiagnosticDetail->body(), 'payment_token%3Draw-admin-encoded-webhook-token')
    && !str_contains($secretLikeWebhookDiagnosticDetail->body(), 'payment_token=raw-admin-encoded-webhook-token'),
    'Core admin payment detail omits token-like legacy webhook failure diagnostics'
);
$authorizedDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) $adminAuthorizedPayment['id'], [], [], []));
core_payment_check($authorizedDetail->status() === 200 && str_contains($authorizedDetail->body(), '捕获支付') && str_contains($authorizedDetail->body(), '同步 Provider 状态'), 'Core admin payment detail renders provider lifecycle actions');
$captureAuditCountBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn();
$queryCsrfCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture', [
    '_csrf' => $csrf,
    'idempotency_key' => 'admin-query-csrf-capture',
], [], []));
core_payment_check(
    $queryCsrfCaptureResponse->status() === 403
    && (string) ($queryCsrfCaptureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === $captureAuditCountBeforeQueryCsrf,
    'Core admin payment lifecycle actions accept CSRF only from POST body with no-store responses before Provider lifecycle changes or audit'
);
$arrayCsrfCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture', [], [
    '_csrf' => [$csrf],
    'idempotency_key' => 'admin-array-csrf-capture',
], []));
core_payment_check(
    $arrayCsrfCaptureResponse->status() === 403
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === $captureAuditCountBeforeQueryCsrf,
    'Core admin payment lifecycle actions reject non-scalar CSRF before Provider lifecycle changes or audit'
);
$adminCancelCsrfPayment = $adminService->createProviderPayment('paid_download', 'admin-cancel-csrf', FixturePaymentProvider::PROVIDER_ID, 1310, 'USD', 'admin-cancel-csrf-key', 'authorized');
$cancelAuditCountBeforeArrayCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.cancel.requested'")->fetchColumn();
$arrayCsrfCancelResponse = $admin->paymentCancel(new Request('POST', '/admin/payments/' . (int) ($adminCancelCsrfPayment['id'] ?? 0) . '/cancel', [], [
    '_csrf' => [$csrf],
    'idempotency_key' => 'admin-array-csrf-cancel',
], []));
core_payment_check(
    $arrayCsrfCancelResponse->status() === 403
    && (string) ($adminRepo->payment((int) ($adminCancelCsrfPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.cancel.requested'")->fetchColumn() === $cancelAuditCountBeforeArrayCsrf,
    'Core admin payment cancel action rejects non-scalar CSRF before Provider lifecycle changes or audit'
);
$adminSyncCsrfPayment = $adminService->createProviderPayment('paid_download', 'admin-sync-csrf', FixturePaymentProvider::PROVIDER_ID, 1320, 'USD', 'admin-sync-csrf-key', 'authorized');
$syncAuditCountBeforeArrayCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.sync.requested'")->fetchColumn();
$arrayCsrfSyncResponse = $admin->paymentSync(new Request('POST', '/admin/payments/' . (int) ($adminSyncCsrfPayment['id'] ?? 0) . '/sync', [], [
    '_csrf' => [$csrf],
], []));
core_payment_check(
    $arrayCsrfSyncResponse->status() === 403
    && (string) ($adminRepo->payment((int) ($adminSyncCsrfPayment['id'] ?? 0))['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.sync.requested'")->fetchColumn() === $syncAuditCountBeforeArrayCsrf,
    'Core admin payment sync action rejects non-scalar CSRF before Provider lifecycle changes or audit'
);
$captureAuditCountBeforeNonCanonicalKey = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn();
$nonCanonicalCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => ' admin-capture-key ',
], []));
core_payment_check(
    $nonCanonicalCaptureResponse->status() === 400
    && (string) ($nonCanonicalCaptureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === $captureAuditCountBeforeNonCanonicalKey,
    'Core admin payment lifecycle actions reject non-canonical idempotency keys with no-store responses before Provider lifecycle changes or audit'
);
$arrayCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => ['admin-array-capture-key'],
], []));
$booleanCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => true,
], []));
core_payment_check(
    $arrayCaptureResponse->status() === 400
    && $booleanCaptureResponse->status() === 400
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === $captureAuditCountBeforeNonCanonicalKey,
    'Core admin payment lifecycle actions reject non-string idempotency keys before Provider lifecycle changes or audit'
);
$trailingCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture/extra', [], [
    '_csrf' => $csrf,
    'idempotency_key' => 'admin-trailing-capture-path',
], []));
core_payment_check(
    $trailingCaptureResponse->status() === 400
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === $captureAuditCountBeforeNonCanonicalKey,
    'Core admin payment lifecycle actions reject non-canonical action paths before Provider lifecycle changes or audit'
);
$oversizedCaptureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/1234567890123456789/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => 'admin-oversized-capture-path',
], []));
core_payment_check(
    $oversizedCaptureResponse->status() === 400
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'authorized'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === $captureAuditCountBeforeNonCanonicalKey,
    'Core admin payment lifecycle actions reject oversized path ids before Provider lifecycle changes or audit'
);
$adminCaptureAuditFailureDbFile = $tmpRoot . '/payment-admin-capture-audit-failure.sqlite';
$adminCaptureAuditFailurePdo = new PDO('sqlite:' . $adminCaptureAuditFailureDbFile);
$adminCaptureAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new MigrationRunner($adminCaptureAuditFailurePdo, $migrations))->run();
$adminCaptureAuditFailureRepo = new PaymentRepository($adminCaptureAuditFailurePdo);
$adminCaptureAuditFailureSettings = new PaymentProviderSettingsRepository($adminCaptureAuditFailurePdo, 'core-payment-admin-settings-key');
$adminCaptureAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_capture_audit_failure']);
$adminCaptureAuditFailureService = new PaymentService($adminCaptureAuditFailurePdo, $adminCaptureAuditFailureRepo);
$adminCaptureAuditFailurePayment = $adminCaptureAuditFailureService->createProviderPayment('paid_download', 'admin-capture-audit-failure', FixturePaymentProvider::PROVIDER_ID, 900, 'USD', 'admin-capture-audit-failure-payment', 'authorized');
$adminCaptureAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_admin_capture_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.capture.requested' BEGIN SELECT RAISE(ABORT, 'admin capture audit failure'); END");
$adminCaptureAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminCaptureAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminCaptureAuditFailureResponse = $adminCaptureAuditFailureController->paymentCapture(new Request('POST', '/admin/payments/' . (int) ($adminCaptureAuditFailurePayment['id'] ?? 0) . '/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => 'admin-capture-audit-failure-capture',
], []));
$adminCaptureAuditFailureAfter = $adminCaptureAuditFailureRepo->payment((int) ($adminCaptureAuditFailurePayment['id'] ?? 0));
core_payment_check(
    $adminCaptureAuditFailureResponse->status() === 500
    && is_array($adminCaptureAuditFailureAfter)
    && (string) ($adminCaptureAuditFailureAfter['status'] ?? '') === 'authorized'
    && (int) $adminCaptureAuditFailurePdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.provider.captured'")->fetchColumn() === 0,
    'Core admin payment lifecycle action rolls back provider capture audit and payment state when admin audit persistence fails'
);
$captureResponse = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) $adminAuthorizedPayment['id'] . '/capture', [], ['_csrf' => $csrf, 'idempotency_key' => 'admin-capture-key'], []));
core_payment_check(
    $captureResponse->status() === 302
    && (string) ($captureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($adminRepo->payment((int) $adminAuthorizedPayment['id'])['status'] ?? '') === 'paid'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.capture.requested'")->fetchColumn() === 1,
    'Core admin payment capture action updates trusted payment status, writes audit and redirects no-store'
);

mkdir($tmpRoot . '/content/uploads', 0775, true);
mkdir($tmpRoot . '/storage/tmp', 0775, true);
$mediaLibrary = new MediaLibrary($adminPdo, $tmpRoot . '/content/uploads');
$mediaMetadataJson = new ReflectionMethod(MediaLibrary::class, 'json');
core_payment_throws(
    static fn () => $mediaMetadataJson->invoke($mediaLibrary, ['original_name' => "\xC3\x28"]),
    'Core media library rejects invalid UTF-8 media metadata before storing JSON for paid attachments'
);
$paidFile = $tmpRoot . '/storage/tmp/paid-guide.txt';
$partialRefundPaidFile = $tmpRoot . '/storage/tmp/partial-refund-paid-guide.txt';
$freeFile = $tmpRoot . '/storage/tmp/free-note.txt';
file_put_contents($paidFile, 'paid download body');
file_put_contents($partialRefundPaidFile, 'partial refund paid download body');
file_put_contents($freeFile, 'free download body');
$paidMediaId = $mediaLibrary->registerLocalFile($paidFile, 'paid-guide.txt');
$partialRefundPaidMediaId = $mediaLibrary->registerLocalFile($partialRefundPaidFile, 'partial-refund-paid-guide.txt');
$freeMediaId = $mediaLibrary->registerLocalFile($freeFile, 'free-note.txt');
$contentRepo = new ContentRepository($adminPdo, ContentTypeRegistry::defaults());
$contentCountBeforeInvalidPaidConfig = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_contents')->fetchColumn();
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Invalid Paid Content Price', 'invalid-paid-content-price', [
        ['type' => 'paragraph', 'data' => ['text' => 'Invalid paid content price']],
    ], 'published', [
        'paid_content_enabled' => true,
        'paid_content_price_minor' => '899abc',
        'paid_content_currency' => 'USD',
        'paid_content_preview_blocks' => 1,
    ]),
    'Core content repository rejects non-integer paid content prices before creating paid subjects'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Spaced Paid Content Price', 'spaced-paid-content-price', [
        ['type' => 'paragraph', 'data' => ['text' => 'Spaced paid content price']],
    ], 'published', [
        'paid_content_enabled' => true,
        'paid_content_price_minor' => ' 899 ',
        'paid_content_currency' => 'USD',
        'paid_content_preview_blocks' => 1,
    ]),
    'Core content repository rejects non-canonical paid content prices before creating paid subjects'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Invalid Paid Content Preview', 'invalid-paid-content-preview', [
        ['type' => 'paragraph', 'data' => ['text' => 'Invalid paid content preview']],
    ], 'published', [
        'paid_content_enabled' => true,
        'paid_content_price_minor' => 899,
        'paid_content_currency' => 'USD',
        'paid_content_preview_blocks' => '101',
    ]),
    'Core content repository rejects paid content preview counts outside the Core range'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Lowercase Paid Content Currency', 'lowercase-paid-content-currency', [
        ['type' => 'paragraph', 'data' => ['text' => 'Lowercase paid content currency']],
    ], 'published', [
        'paid_content_enabled' => true,
        'paid_content_price_minor' => 899,
        'paid_content_currency' => 'usd',
        'paid_content_preview_blocks' => 1,
    ]),
    'Core content repository rejects non-canonical paid content currencies before creating paid subjects'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Invalid Paid Content Encoding', 'invalid-paid-content-encoding', [
        ['type' => 'paragraph', 'data' => ['text' => 'Invalid paid content encoding']],
    ], 'published', [
        'paid_content_enabled' => true,
        'paid_content_price_minor' => 899,
        'paid_content_currency' => 'USD',
        'paid_content_label' => "\xC3\x28",
        'paid_content_preview_blocks' => 1,
    ]),
    'Core content repository rejects invalid UTF-8 paid content metadata before storing JSON'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Invalid Paid Download Price', 'invalid-paid-download-price', [[
        'type' => 'attachment',
        'data' => [
            'media_id' => $paidMediaId,
            'display_name' => 'Invalid Paid Download',
            'paid_enabled' => true,
            'price_minor' => '699abc',
            'currency' => 'USD',
        ],
    ]], 'published'),
    'Core attachment sanitizer rejects non-integer paid download prices before creating paid subjects'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Spaced Paid Download Price', 'spaced-paid-download-price', [[
        'type' => 'attachment',
        'data' => [
            'media_id' => $paidMediaId,
            'display_name' => 'Spaced Paid Download',
            'paid_enabled' => true,
            'price_minor' => ' 699 ',
            'currency' => 'USD',
        ],
    ]], 'published'),
    'Core attachment sanitizer rejects non-canonical paid download prices before creating paid subjects'
);
core_payment_throws(
    static fn () => $contentRepo->create('article', 'Lowercase Paid Download Currency', 'lowercase-paid-download-currency', [[
        'type' => 'attachment',
        'data' => [
            'media_id' => $paidMediaId,
            'display_name' => 'Lowercase Paid Download',
            'paid_enabled' => true,
            'price_minor' => 699,
            'currency' => 'usd',
        ],
    ]], 'published'),
    'Core attachment sanitizer rejects non-canonical paid download currencies before creating paid subjects'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_contents')->fetchColumn() === $contentCountBeforeInvalidPaidConfig,
    'Core content ledger stays unchanged after invalid paid pricing attempts'
);
$paidContentId = $contentRepo->create('article', 'Paid Download Article', 'paid-download-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $paidMediaId,
        'display_name' => 'Paid Guide',
        'paid_enabled' => true,
        'price_minor' => 699,
        'currency' => 'USD',
        'payment_label' => '购买下载',
    ],
]], 'published');
$partialRefundPaidContentId = $contentRepo->create('article', 'Partial Refund Paid Download Article', 'partial-refund-paid-download-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $partialRefundPaidMediaId,
        'display_name' => 'Partial Refund Paid Guide',
        'paid_enabled' => true,
        'price_minor' => 699,
        'currency' => 'USD',
        'payment_label' => '购买下载',
    ],
]], 'published');
$freeContentId = $contentRepo->create('article', 'Free Download Article', 'free-download-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $freeMediaId,
        'display_name' => 'Free Note',
        'paid_enabled' => false,
    ],
]], 'published');
$paidArticleId = $contentRepo->create('article', 'Core Paid Article', 'core-paid-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Public preview paragraph']],
    ['type' => 'paragraph', 'data' => ['text' => 'Subscriber-only paragraph']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 899,
    'paid_content_currency' => 'USD',
    'paid_content_label' => '解锁文章',
    'paid_content_preview_blocks' => 1,
]);
$partialRefundPaidArticleId = $contentRepo->create('article', 'Core Partial Refund Paid Article', 'core-partial-refund-paid-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Partial refund public preview']],
    ['type' => 'paragraph', 'data' => ['text' => 'Partial refund subscriber paragraph']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 899,
    'paid_content_currency' => 'USD',
    'paid_content_label' => '解锁文章',
    'paid_content_preview_blocks' => 1,
]);
core_payment_check($paidContentId > 0 && $partialRefundPaidContentId > 0 && $freeContentId > 0, 'Core content can store paid and free attachment blocks');

$paidContent = new PaidContentService($adminPdo, $settings);
$paidContentController = new PaidContentController($settings);
$paidDownloadController = new PaidDownloadController($settings);
$fixtureDefaultBeforeAmbiguousCheckout = $adminProviderSettings->setting(FixturePaymentProvider::PROVIDER_ID);
$manualDefaultBeforeAmbiguousCheckout = $adminProviderSettings->setting(ManualPaymentProvider::PROVIDER_ID);
$ambiguousCheckoutFixtureConfig = json_decode((string) ($fixtureDefaultBeforeAmbiguousCheckout['public_config_json'] ?? '{}'), true) ?: [];
$ambiguousCheckoutManualConfig = json_decode((string) ($manualDefaultBeforeAmbiguousCheckout['public_config_json'] ?? '{}'), true) ?: [];
$ambiguousCheckoutFixtureConfig['default_provider'] = true;
$ambiguousCheckoutManualConfig['default_provider'] = true;
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode($ambiguousCheckoutFixtureConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => ManualPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode($ambiguousCheckoutManualConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$ambiguousDefaultCheckoutPaymentCount = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$ambiguousDefaultCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf], []));
core_payment_check(
    $ambiguousDefaultCheckout->status() === 400
    && str_contains($ambiguousDefaultCheckout->body(), 'ambiguous')
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $ambiguousDefaultCheckoutPaymentCount,
    'Core public paid content checkout rejects ambiguous default Providers before creating payments'
);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => FixturePaymentProvider::PROVIDER_ID,
    ':public_config_json' => (string) ($fixtureDefaultBeforeAmbiguousCheckout['public_config_json'] ?? '{}'),
]);
$adminPdo->prepare('UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id')->execute([
    ':provider_id' => ManualPaymentProvider::PROVIDER_ID,
    ':public_config_json' => (string) ($manualDefaultBeforeAmbiguousCheckout['public_config_json'] ?? '{}'),
]);
core_payment_throws(
    static fn () => $paidContent->subjectId(0),
    'Core paid content subject ids reject non-positive content ids before payment subject creation'
);
$paidArticle = $contentRepo->find($paidArticleId) ?? [];
$paidArticleConfig = $paidContent->configFor($paidArticle);
core_payment_check(
    is_array($paidArticleConfig)
    && (string) $paidArticleConfig['subject_type'] === 'paid_content'
    && (string) $paidArticleConfig['subject_id'] === 'content:' . $paidArticleId
    && (int) $paidArticleConfig['amount_minor'] === 899
    && (string) $paidArticleConfig['currency'] === 'USD',
    'Core paid content reads pricing and trusted subject from content meta'
);
$nonCanonicalIdPaidArticle = $paidArticle;
$nonCanonicalIdPaidArticle['id'] = '0' . $paidArticleId;
core_payment_check(
    $paidContent->configFor($nonCanonicalIdPaidArticle) === null,
    'Core paid content config rejects non-canonical restored content ids before creating paid subjects'
);
$spacedLabelArticleId = $contentRepo->create('article', 'Spaced Paid Content Label', 'spaced-paid-content-label', [
    ['type' => 'paragraph', 'data' => ['text' => 'Spaced label preview']],
    ['type' => 'paragraph', 'data' => ['text' => 'Spaced label secret']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 899,
    'paid_content_currency' => 'USD',
    'paid_content_label' => '解锁标签文章',
    'paid_content_preview_blocks' => 1,
]);
$adminPdo->prepare('UPDATE cms_contents SET meta_json = :meta WHERE id = :id')->execute([
    ':id' => $spacedLabelArticleId,
    ':meta' => json_encode([
        'paid_content_enabled' => true,
        'paid_content_price_minor' => 899,
        'paid_content_currency' => 'USD',
        'paid_content_label' => ' 解锁标签文章 ',
        'paid_content_preview_blocks' => 1,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$spacedLabelArticleConfig = $paidContent->configFor($contentRepo->find($spacedLabelArticleId) ?? []);
core_payment_check(
    is_array($spacedLabelArticleConfig)
    && ($spacedLabelArticleConfig['available'] ?? false) === true
    && (string) ($spacedLabelArticleConfig['label'] ?? '') === '解锁全文',
    'Core paid content falls back instead of trimming non-canonical restored checkout labels'
);
$front = new ContentFrontController(CMS_ROOT, $settings, new FileLogger($tmpRoot . '/storage/logs/front.log'));
$lockedArticle = $front->article(new Request('GET', '/articles/core-paid-article', [], [], []));
core_payment_check(
    $lockedArticle->status() === 200
    && str_contains($lockedArticle->body(), 'Public preview paragraph')
    && !str_contains($lockedArticle->body(), 'Subscriber-only paragraph')
    && str_contains($lockedArticle->body(), '/paid-content/' . $paidArticleId . '/checkout')
    && str_contains($lockedArticle->body(), 'name="_csrf"')
    && str_contains($lockedArticle->body(), 'name="provider_id"')
    && str_contains($lockedArticle->body(), 'value="' . FixturePaymentProvider::PROVIDER_ID . '"')
    && str_contains($lockedArticle->body(), '解锁文章'),
    'Core paid content renders preview and CSRF-protected Core checkout wall with Core Provider selection before authorization'
);
$arrayTokenLockedArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => ['array-token']], [], []));
core_payment_check(
    $arrayTokenLockedArticle->status() === 200
    && str_contains($arrayTokenLockedArticle->body(), 'Public preview paragraph')
    && !str_contains($arrayTokenLockedArticle->body(), 'Subscriber-only paragraph')
    && str_contains($arrayTokenLockedArticle->body(), '/paid-content/' . $paidArticleId . '/checkout'),
    'Core paid content front controller ignores non-scalar payment tokens without unlocking content'
);
$frontProviderFields = new ReflectionMethod(ContentFrontController::class, 'paymentProviderFields');
$unsafeFrontProviderFields = $frontProviderFields->invoke($front, [[
    'id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' ',
    'label' => 'Invalid Provider ID',
], [
    'id' => FixturePaymentProvider::PROVIDER_ID,
    'label' => ' Core Fixture Label ',
], [
    'id' => ManualPaymentProvider::PROVIDER_ID,
    'label' => "Manual\nPayment",
], [
    'id' => 'core.hosted-checkout',
    'label' => 'Hosted Checkout',
]]);
core_payment_check(
    is_string($unsafeFrontProviderFields)
    && !str_contains($unsafeFrontProviderFields, 'Invalid Provider ID')
    && !str_contains($unsafeFrontProviderFields, ' Core Fixture Label ')
    && !str_contains($unsafeFrontProviderFields, "Manual\nPayment")
    && str_contains($unsafeFrontProviderFields, 'value="' . FixturePaymentProvider::PROVIDER_ID . '" checked')
    && str_contains($unsafeFrontProviderFields, '> ' . FixturePaymentProvider::PROVIDER_ID . '</label>')
    && str_contains($unsafeFrontProviderFields, '> ' . ManualPaymentProvider::PROVIDER_ID . '</label>')
    && str_contains($unsafeFrontProviderFields, '> Hosted Checkout</label>'),
    'Core paid content Provider form rendering rejects non-canonical Provider ids and does not trim unsafe labels'
);
$paidContentWall = new ReflectionMethod(ContentFrontController::class, 'paidContentWallHtml');
$unsafePaidContentPriceWall = $paidContentWall->invoke($front, [
    'enabled' => true,
    'authorized' => false,
    'available' => true,
    'amount_minor' => '899',
    'currency' => 'usd',
    'label' => '解锁文章',
    'checkout_url' => '/paid-content/' . $paidArticleId . '/checkout',
    'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
]);
core_payment_check(
    is_string($unsafePaidContentPriceWall)
    && str_contains($unsafePaidContentPriceWall, '支付配置暂不可用')
    && !str_contains($unsafePaidContentPriceWall, 'name="_csrf"')
    && !str_contains($unsafePaidContentPriceWall, 'name="provider_id"')
    && !str_contains($unsafePaidContentPriceWall, 'USD 8.99')
    && !str_contains($unsafePaidContentPriceWall, 'usd 8.99'),
    'Core paid content wall does not cast or uppercase unsafe view-model prices into checkout forms'
);
$unsafePaidContentActionWall = $paidContentWall->invoke($front, [
    'enabled' => true,
    'authorized' => false,
    'available' => true,
    'amount_minor' => 899,
    'currency' => 'USD',
    'label' => '解锁文章',
    'checkout_url' => 'https://evil.example.test/paid-content/' . $paidArticleId . '/checkout?payment_token=unsafe',
    'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
]);
$nonCanonicalPaidContentActionWall = $paidContentWall->invoke($front, [
    'enabled' => true,
    'authorized' => false,
    'available' => true,
    'amount_minor' => 899,
    'currency' => 'USD',
    'label' => '解锁文章',
    'checkout_url' => '/paid-content/0' . $paidArticleId . '/checkout',
    'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
]);
$oversizedPaidContentActionWall = $paidContentWall->invoke($front, [
    'enabled' => true,
    'authorized' => false,
    'available' => true,
    'amount_minor' => 899,
    'currency' => 'USD',
    'label' => '解锁文章',
    'checkout_url' => '/paid-content/1234567890123456789/checkout',
    'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
]);
core_payment_check(
    is_string($unsafePaidContentActionWall)
    && is_string($nonCanonicalPaidContentActionWall)
    && is_string($oversizedPaidContentActionWall)
    && str_contains($unsafePaidContentActionWall, '支付配置暂不可用')
    && str_contains($nonCanonicalPaidContentActionWall, '支付配置暂不可用')
    && str_contains($oversizedPaidContentActionWall, '支付配置暂不可用')
    && !str_contains($unsafePaidContentActionWall, 'evil.example.test')
    && !str_contains($unsafePaidContentActionWall, 'payment_token=')
    && !str_contains($oversizedPaidContentActionWall, '1234567890123456789')
    && !str_contains($unsafePaidContentActionWall, 'name="_csrf"')
    && !str_contains($unsafePaidContentActionWall, 'name="provider_id"')
    && !str_contains($nonCanonicalPaidContentActionWall, 'name="_csrf"')
    && !str_contains($nonCanonicalPaidContentActionWall, 'name="provider_id"')
    && !str_contains($oversizedPaidContentActionWall, 'name="_csrf"')
    && !str_contains($oversizedPaidContentActionWall, 'name="provider_id"'),
    'Core paid content wall refuses unsafe, oversized or non-canonical checkout action paths before rendering submit forms'
);
$corruptPaidArticleId = $contentRepo->create('article', 'Corrupt Paid Article', 'corrupt-paid-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Corrupt paid preview']],
    ['type' => 'paragraph', 'data' => ['text' => 'Corrupt paid secret']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 499,
    'paid_content_currency' => 'USD',
    'paid_content_label' => '解锁异常文章',
    'paid_content_preview_blocks' => 1,
]);
$adminPdo->prepare('UPDATE cms_contents SET meta_json = :meta WHERE id = :id')->execute([
    ':id' => $corruptPaidArticleId,
    ':meta' => json_encode([
        'paid_content_enabled' => true,
        'paid_content_price_minor' => ' 499 ',
        'paid_content_currency' => ['USD'],
        'paid_content_label' => ['解锁异常文章'],
        'paid_content_preview_blocks' => 1,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$corruptPaidArticle = $contentRepo->find($corruptPaidArticleId) ?? [];
$corruptPaidArticleConfig = $paidContent->configFor($corruptPaidArticle);
$corruptPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$corruptLockedArticle = $front->article(new Request('GET', '/articles/corrupt-paid-article', [], [], []));
$corruptPaidContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $corruptPaidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_check(
    is_array($corruptPaidArticleConfig)
    && ($corruptPaidArticleConfig['available'] ?? true) === false
    && (int) ($corruptPaidArticleConfig['amount_minor'] ?? -1) === 0
    && (string) ($corruptPaidArticleConfig['currency'] ?? 'USD') === ''
    && (array) ($corruptPaidArticleConfig['invalid_reasons'] ?? []) === ['amount_minor', 'currency']
    && $corruptLockedArticle->status() === 200
    && str_contains($corruptLockedArticle->body(), 'Corrupt paid preview')
    && !str_contains($corruptLockedArticle->body(), 'Corrupt paid secret')
    && !str_contains($corruptLockedArticle->body(), 'name="provider_id"')
    && !str_contains($corruptLockedArticle->body(), 'name="_csrf"')
    && !str_contains($corruptLockedArticle->body(), 'USD 0.00')
    && str_contains($corruptLockedArticle->body(), '支付配置暂不可用')
    && $corruptPaidContentCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $corruptPaymentCountBefore,
    'Core paid content fails closed for corrupted enabled pricing without unlocking content or creating payments'
);
$corruptPreviewArticleId = $contentRepo->create('article', 'Corrupt Paid Preview Article', 'corrupt-paid-preview-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Corrupt preview public']],
    ['type' => 'paragraph', 'data' => ['text' => 'Corrupt preview secret']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 499,
    'paid_content_currency' => 'USD',
    'paid_content_label' => '解锁异常预览文章',
    'paid_content_preview_blocks' => 1,
]);
$adminPdo->prepare('UPDATE cms_contents SET meta_json = :meta WHERE id = :id')->execute([
    ':id' => $corruptPreviewArticleId,
    ':meta' => json_encode([
        'paid_content_enabled' => true,
        'paid_content_price_minor' => 499,
        'paid_content_currency' => 'USD',
        'paid_content_label' => '解锁异常预览文章',
        'paid_content_preview_blocks' => '101',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$corruptPreviewArticle = $contentRepo->find($corruptPreviewArticleId) ?? [];
$corruptPreviewConfig = $paidContent->configFor($corruptPreviewArticle);
$corruptPreviewLockedArticle = $front->article(new Request('GET', '/articles/corrupt-paid-preview-article', [], [], []));
core_payment_check(
    is_array($corruptPreviewConfig)
    && ($corruptPreviewConfig['available'] ?? true) === false
    && (int) ($corruptPreviewConfig['preview_blocks'] ?? -1) === 0
    && (array) ($corruptPreviewConfig['invalid_reasons'] ?? []) === ['preview_blocks']
    && $corruptPreviewLockedArticle->status() === 200
    && !str_contains($corruptPreviewLockedArticle->body(), 'Corrupt preview public')
    && !str_contains($corruptPreviewLockedArticle->body(), 'Corrupt preview secret')
    && !str_contains($corruptPreviewLockedArticle->body(), 'name="provider_id"'),
    'Core paid content fails closed for corrupted preview limits without leaking extra paid blocks'
);
$malformedMetaArticleId = $contentRepo->create('article', 'Malformed Paid Meta Article', 'malformed-paid-meta-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Malformed meta public']],
    ['type' => 'paragraph', 'data' => ['text' => 'Malformed meta secret']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 499,
    'paid_content_currency' => 'USD',
    'paid_content_label' => '解锁损坏配置文章',
    'paid_content_preview_blocks' => 1,
]);
$adminPdo->prepare('UPDATE cms_contents SET meta_json = :meta WHERE id = :id')->execute([
    ':id' => $malformedMetaArticleId,
    ':meta' => '{"paid_content_enabled":true,"paid_content_price_minor":499',
]);
$malformedMetaArticle = $contentRepo->find($malformedMetaArticleId) ?? [];
$malformedMetaConfig = $paidContent->configFor($malformedMetaArticle);
$malformedMetaPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$malformedMetaLockedArticle = $front->article(new Request('GET', '/articles/malformed-paid-meta-article', [], [], []));
$malformedMetaCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $malformedMetaArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_check(
    is_array($malformedMetaConfig)
    && ($malformedMetaConfig['available'] ?? true) === false
    && (int) ($malformedMetaConfig['preview_blocks'] ?? -1) === 0
    && (array) ($malformedMetaConfig['invalid_reasons'] ?? []) === ['meta_json']
    && $malformedMetaLockedArticle->status() === 200
    && !str_contains($malformedMetaLockedArticle->body(), 'Malformed meta public')
    && !str_contains($malformedMetaLockedArticle->body(), 'Malformed meta secret')
    && !str_contains($malformedMetaLockedArticle->body(), 'name="provider_id"')
    && !str_contains($malformedMetaLockedArticle->body(), 'name="_csrf"')
    && str_contains($malformedMetaLockedArticle->body(), '支付配置暂不可用')
    && $malformedMetaCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $malformedMetaPaymentCountBefore,
    'Core paid content fails closed for malformed paid meta JSON without leaking content or creating payments'
);
$lockedDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'checkout_url' => '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout',
            'amount_minor' => 699,
            'currency' => 'USD',
            'label' => '购买下载',
            'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
core_payment_check(
    str_contains($lockedDownloadArticle, '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout')
    && str_contains($lockedDownloadArticle, 'name="_csrf"')
    && str_contains($lockedDownloadArticle, 'name="provider_id"')
    && str_contains($lockedDownloadArticle, 'value="' . FixturePaymentProvider::PROVIDER_ID . '"'),
    'Core paid download renders CSRF-protected checkout form with Core Provider selection before authorization'
);
$unsafeProviderDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'checkout_url' => '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout',
            'amount_minor' => 699,
            'currency' => 'USD',
            'label' => '购买下载',
            'payment_providers' => [[
                'id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' ',
                'label' => 'Invalid Provider ID',
            ], [
                'id' => FixturePaymentProvider::PROVIDER_ID,
                'label' => ' Core Fixture Label ',
            ], [
                'id' => ManualPaymentProvider::PROVIDER_ID,
                'label' => "Manual\nPayment",
            ]],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
core_payment_check(
    !str_contains($unsafeProviderDownloadArticle, 'Invalid Provider ID')
    && !str_contains($unsafeProviderDownloadArticle, ' Core Fixture Label ')
    && !str_contains($unsafeProviderDownloadArticle, "Manual\nPayment")
    && str_contains($unsafeProviderDownloadArticle, 'value="' . FixturePaymentProvider::PROVIDER_ID . '" checked')
    && str_contains($unsafeProviderDownloadArticle, '> ' . FixturePaymentProvider::PROVIDER_ID . '</label>')
    && str_contains($unsafeProviderDownloadArticle, '> ' . ManualPaymentProvider::PROVIDER_ID . '</label>'),
    'Core paid download Provider form rendering rejects non-canonical Provider ids and does not trim unsafe labels'
);
$unsafePriceDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'available' => true,
            'checkout_url' => '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout',
            'amount_minor' => '699',
            'currency' => 'usd',
            'label' => '购买下载',
            'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
core_payment_check(
    str_contains($unsafePriceDownloadArticle, '支付配置暂不可用')
    && !str_contains($unsafePriceDownloadArticle, 'name="_csrf"')
    && !str_contains($unsafePriceDownloadArticle, 'name="provider_id"')
    && !str_contains($unsafePriceDownloadArticle, 'USD 6.99')
    && !str_contains($unsafePriceDownloadArticle, 'usd 6.99'),
    'Core paid download block renderer does not cast or uppercase unsafe view-model prices into checkout forms'
);
$unsafeCheckoutPathDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'available' => true,
            'checkout_url' => '//evil.example.test/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout?payment_token=unsafe',
            'amount_minor' => 699,
            'currency' => 'USD',
            'label' => '购买下载',
            'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
$nonCanonicalCheckoutPathDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'available' => true,
            'checkout_url' => '/paid-download/0' . $paidContentId . '/0' . $paidMediaId . '/checkout',
            'amount_minor' => 699,
            'currency' => 'USD',
            'label' => '购买下载',
            'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
$oversizedCheckoutPathDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'available' => true,
            'checkout_url' => '/paid-download/1234567890123456789/1234567890123456789/checkout',
            'amount_minor' => 699,
            'currency' => 'USD',
            'label' => '购买下载',
            'payment_providers' => [['id' => FixturePaymentProvider::PROVIDER_ID, 'label' => '核心模拟支付']],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
core_payment_check(
    str_contains($unsafeCheckoutPathDownloadArticle, '支付配置暂不可用')
    && str_contains($nonCanonicalCheckoutPathDownloadArticle, '支付配置暂不可用')
    && str_contains($oversizedCheckoutPathDownloadArticle, '支付配置暂不可用')
    && !str_contains($unsafeCheckoutPathDownloadArticle, 'evil.example.test')
    && !str_contains($unsafeCheckoutPathDownloadArticle, 'payment_token=')
    && !str_contains($nonCanonicalCheckoutPathDownloadArticle, '/paid-download/0' . $paidContentId)
    && !str_contains($oversizedCheckoutPathDownloadArticle, '1234567890123456789')
    && !str_contains($unsafeCheckoutPathDownloadArticle, 'name="_csrf"')
    && !str_contains($unsafeCheckoutPathDownloadArticle, 'name="provider_id"')
    && !str_contains($oversizedCheckoutPathDownloadArticle, 'name="_csrf"')
    && !str_contains($oversizedCheckoutPathDownloadArticle, 'name="provider_id"'),
    'Core paid download block renderer refuses unsafe, oversized or non-canonical checkout action paths before rendering submit forms'
);
$unsafeAuthorizedDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'download_url' => 'https://evil.example.test/media/' . $paidMediaId . '?download=1&content_id=' . $paidContentId . '&payment_token=unsafe',
        'paid_download' => [
            'enabled' => true,
            'authorized' => true,
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
$nonCanonicalAuthorizedDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'download_url' => '/media/0' . $paidMediaId . '?download=1&content_id=0' . $paidContentId . '&payment_token=short.token',
        'paid_download' => [
            'enabled' => true,
            'authorized' => true,
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
$arrayQueryAuthorizedDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'download_url' => '/media/' . $paidMediaId . '?download=1&content_id=' . $paidContentId . '&payment_token[]=raw-array-token',
        'paid_download' => [
            'enabled' => true,
            'authorized' => true,
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
$oversizedIdAuthorizedDownloadArticle = (new BlockRenderer([
    $paidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Paid Guide',
        'filename' => 'paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 18,
        'download_url' => '/media/1234567890123456789?download=1&content_id=1234567890123456789&payment_token=' . str_repeat('a', 24) . '.' . str_repeat('b', 64),
        'paid_download' => [
            'enabled' => true,
            'authorized' => true,
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $paidMediaId, 'display_name' => 'Paid Guide'],
]]);
core_payment_check(
    str_contains($unsafeAuthorizedDownloadArticle, '支付配置暂不可用')
    && str_contains($nonCanonicalAuthorizedDownloadArticle, '支付配置暂不可用')
    && str_contains($arrayQueryAuthorizedDownloadArticle, '支付配置暂不可用')
    && str_contains($oversizedIdAuthorizedDownloadArticle, '支付配置暂不可用')
    && !str_contains($unsafeAuthorizedDownloadArticle, 'evil.example.test')
    && !str_contains($unsafeAuthorizedDownloadArticle, 'payment_token=unsafe')
    && !str_contains($nonCanonicalAuthorizedDownloadArticle, 'short.token')
    && !str_contains($arrayQueryAuthorizedDownloadArticle, 'raw-array-token')
    && !str_contains($oversizedIdAuthorizedDownloadArticle, '1234567890123456789')
    && !str_contains($unsafeAuthorizedDownloadArticle, '<a href=')
    && !str_contains($nonCanonicalAuthorizedDownloadArticle, '<a href=')
    && !str_contains($arrayQueryAuthorizedDownloadArticle, '<a href=')
    && !str_contains($oversizedIdAuthorizedDownloadArticle, '<a href='),
    'Core paid download block renderer refuses unsafe, array-shaped, oversized or non-canonical authorized download URLs before rendering file links'
);
$corruptPaidFile = $tmpRoot . '/storage/tmp/corrupt-paid-guide.txt';
file_put_contents($corruptPaidFile, 'corrupt paid download body');
$corruptPaidMediaId = $mediaLibrary->registerLocalFile($corruptPaidFile, 'corrupt-paid-guide.txt');
$corruptPaidDownloadContentId = $contentRepo->create('article', 'Corrupt Paid Download Article', 'corrupt-paid-download-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $corruptPaidMediaId,
        'display_name' => 'Corrupt Paid Guide',
        'paid_enabled' => true,
        'price_minor' => 699,
        'currency' => 'USD',
        'payment_label' => '购买异常下载',
    ],
]], 'published');
$adminPdo->prepare('UPDATE cms_contents SET blocks_json = :blocks WHERE id = :id')->execute([
    ':id' => $corruptPaidDownloadContentId,
    ':blocks' => json_encode([[
        'type' => 'attachment',
        'data' => [
            'media_id' => $corruptPaidMediaId,
            'display_name' => 'Corrupt Paid Guide',
            'paid_enabled' => true,
            'price_minor' => ' 699 ',
            'currency' => ['USD'],
            'payment_label' => ['购买异常下载'],
        ],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$corruptPaidDownloads = new PaidDownloadService($adminPdo, $settings);
$corruptDownloadConfig = $corruptPaidDownloads->configFor($corruptPaidDownloadContentId, $corruptPaidMediaId);
$corruptDownloadPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$corruptDownloadBlocked = (new MediaController($tmpRoot, $settings))->show(new Request('GET', '/media/' . $corruptPaidMediaId, ['download' => '1'], [], []));
$corruptDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $corruptPaidDownloadContentId . '/' . $corruptPaidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
$corruptLockedDownloadArticle = (new BlockRenderer([
    $corruptPaidMediaId => [
        'available' => true,
        'media_type' => 'attachment',
        'title' => 'Corrupt Paid Guide',
        'filename' => 'corrupt-paid-guide.txt',
        'extension' => 'txt',
        'byte_size' => 26,
        'paid_download' => [
            'enabled' => true,
            'authorized' => false,
            'available' => (bool) ($corruptDownloadConfig['available'] ?? true),
            'checkout_url' => '/paid-download/' . $corruptPaidDownloadContentId . '/' . $corruptPaidMediaId . '/checkout',
            'amount_minor' => (int) ($corruptDownloadConfig['amount_minor'] ?? 0),
            'currency' => (string) ($corruptDownloadConfig['currency'] ?? 'USD'),
            'label' => '支付配置不可用',
            'payment_providers' => [],
        ],
    ],
]))->render([[
    'type' => 'attachment',
    'data' => ['media_id' => $corruptPaidMediaId, 'display_name' => 'Corrupt Paid Guide'],
]]);
core_payment_check(
    is_array($corruptDownloadConfig)
    && ($corruptDownloadConfig['available'] ?? true) === false
    && (int) ($corruptDownloadConfig['amount_minor'] ?? -1) === 0
    && (string) ($corruptDownloadConfig['currency'] ?? 'USD') === ''
    && (array) ($corruptDownloadConfig['invalid_reasons'] ?? []) === ['amount_minor', 'currency']
    && $corruptPaidDownloads->requiresAuthorizationForMedia($corruptPaidMediaId)
    && $corruptDownloadBlocked->status() === 402
    && str_contains($corruptLockedDownloadArticle, '支付配置暂不可用')
    && !str_contains($corruptLockedDownloadArticle, 'name="_csrf"')
    && !str_contains($corruptLockedDownloadArticle, 'USD 0.00')
    && $corruptDownloadCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $corruptDownloadPaymentCountBefore,
    'Core paid download fails closed for corrupted enabled pricing without exposing files or creating payments'
);
$corruptMediaIdFile = $tmpRoot . '/storage/tmp/corrupt-media-id-paid-guide.txt';
file_put_contents($corruptMediaIdFile, 'corrupt media id paid download body');
$corruptMediaIdPaidMediaId = $mediaLibrary->registerLocalFile($corruptMediaIdFile, 'corrupt-media-id-paid-guide.txt');
$corruptMediaIdContentId = $contentRepo->create('article', 'Corrupt Media Id Paid Download Article', 'corrupt-media-id-paid-download-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $corruptMediaIdPaidMediaId,
        'display_name' => 'Corrupt Media Id Paid Guide',
        'paid_enabled' => true,
        'price_minor' => 699,
        'currency' => 'USD',
        'payment_label' => '购买异常下载',
    ],
]], 'published');
$adminPdo->prepare('UPDATE cms_contents SET blocks_json = :blocks WHERE id = :id')->execute([
    ':id' => $corruptMediaIdContentId,
    ':blocks' => json_encode([[
        'type' => 'attachment',
        'data' => [
            'media_id' => '0' . $corruptMediaIdPaidMediaId,
            'display_name' => 'Corrupt Media Id Paid Guide',
            'paid_enabled' => true,
            'price_minor' => 699,
            'currency' => 'USD',
            'payment_label' => '购买异常下载',
        ],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$corruptMediaIdPaidDownloads = new PaidDownloadService($adminPdo, $settings);
$corruptMediaIdDownloadConfig = $corruptMediaIdPaidDownloads->configFor($corruptMediaIdContentId, $corruptMediaIdPaidMediaId);
$corruptMediaIdPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$corruptMediaIdBlocked = (new MediaController($tmpRoot, $settings))->show(new Request('GET', '/media/' . $corruptMediaIdPaidMediaId, ['download' => '1'], [], []));
$corruptMediaIdCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $corruptMediaIdContentId . '/' . $corruptMediaIdPaidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $corruptMediaIdDownloadConfig === null
    && $corruptMediaIdPaidDownloads->requiresAuthorizationForMedia($corruptMediaIdPaidMediaId)
    && $corruptMediaIdBlocked->status() === 402
    && $corruptMediaIdCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $corruptMediaIdPaymentCountBefore,
    'Core paid download fails closed for non-canonical restored media ids while preserving direct-file protection'
);
$malformedBlocksPaidFile = $tmpRoot . '/storage/tmp/malformed-blocks-paid-guide.txt';
file_put_contents($malformedBlocksPaidFile, 'malformed blocks paid download body');
$malformedBlocksPaidMediaId = $mediaLibrary->registerLocalFile($malformedBlocksPaidFile, 'malformed-blocks-paid-guide.txt');
$malformedBlocksContentId = $contentRepo->create('article', 'Malformed Blocks Paid Download Article', 'malformed-blocks-paid-download-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $malformedBlocksPaidMediaId,
        'display_name' => 'Malformed Blocks Paid Guide',
        'paid_enabled' => true,
        'price_minor' => 699,
        'currency' => 'USD',
        'payment_label' => '购买异常下载',
    ],
]], 'published');
$adminPdo->prepare('UPDATE cms_contents SET blocks_json = :blocks WHERE id = :id')->execute([
    ':id' => $malformedBlocksContentId,
    ':blocks' => '[{"type":"attachment","data":{"media_id":' . $malformedBlocksPaidMediaId . ',"paid_enabled":true',
]);
$malformedBlocksPaidDownloads = new PaidDownloadService($adminPdo, $settings);
$malformedBlocksPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$malformedBlocksDirectDownload = (new MediaController($tmpRoot, $settings))->show(new Request('GET', '/media/' . $malformedBlocksPaidMediaId, ['download' => '1'], [], []));
$malformedBlocksCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $malformedBlocksContentId . '/' . $malformedBlocksPaidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_throws(
    static fn () => $malformedBlocksPaidDownloads->configFor($malformedBlocksContentId, $malformedBlocksPaidMediaId),
    'Core paid download rejects malformed content block JSON before treating payment configuration as absent'
);
core_payment_check(
    $malformedBlocksPaidDownloads->requiresAuthorizationForMedia($malformedBlocksPaidMediaId)
    && $malformedBlocksDirectDownload->status() === 402
    && $malformedBlocksCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $malformedBlocksPaymentCountBefore,
    'Core paid download fails closed for malformed content block JSON while preserving direct-file protection'
);
$publicCheckoutPaymentCount = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$forgedPaidContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], [], []));
$forgedPaidDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], [], []));
core_payment_check(
    $forgedPaidContentCheckout->status() === 403
    && $forgedPaidDownloadCheckout->status() === 403
    && (string) ($forgedPaidContentCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($forgedPaidDownloadCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout POST requires CSRF and returns no-store before creating payment records'
);
$queryCsrfPaidContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], [], []));
$queryCsrfPaidDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], [], []));
core_payment_check(
    $queryCsrfPaidContentCheckout->status() === 403
    && $queryCsrfPaidDownloadCheckout->status() === 403
    && (string) ($queryCsrfPaidContentCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($queryCsrfPaidDownloadCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout accepts CSRF and Provider selection only from POST body and returns no-store before creating payment records'
);
$arrayCsrfPaidContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => [$csrf], 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
$arrayCsrfPaidDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => [$csrf], 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $arrayCsrfPaidContentCheckout->status() === 403
    && $arrayCsrfPaidDownloadCheckout->status() === 403
    && (string) ($arrayCsrfPaidContentCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($arrayCsrfPaidDownloadCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout rejects non-scalar CSRF and returns no-store before creating payment records'
);
$forgedProviderContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unregistered-checkout'], []));
$forgedProviderDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unregistered-checkout'], []));
core_payment_check(
    $forgedProviderContentCheckout->status() === 400
    && $forgedProviderDownloadCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout rejects forged Provider selection before creating payment records'
);
$nonCanonicalProviderContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' '], []));
$nonCanonicalProviderDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ' ' . FixturePaymentProvider::PROVIDER_ID . ' '], []));
core_payment_check(
    $nonCanonicalProviderContentCheckout->status() === 400
    && $nonCanonicalProviderDownloadCheckout->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout rejects non-canonical Provider selection before creating payment records'
);
$leadingZeroPathContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/0' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
$leadingZeroPathDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/0' . $paidContentId . '/0' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
$oversizedPathContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/1234567890123456789/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
$oversizedPathDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/1234567890123456789/1234567890123456789/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $leadingZeroPathContentCheckout->status() === 400
    && $leadingZeroPathDownloadCheckout->status() === 400
    && $oversizedPathContentCheckout->status() === 400
    && $oversizedPathDownloadCheckout->status() === 400
    && !str_contains((string) ($leadingZeroPathContentCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && !str_contains((string) ($leadingZeroPathDownloadCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && !str_contains((string) ($oversizedPathContentCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && !str_contains((string) ($oversizedPathDownloadCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout rejects non-canonical or oversized path ids before creating payment records'
);
$providerStatusSnapshot = $adminPdo->query('SELECT provider_id, status FROM cms_payment_provider_settings')->fetchAll(PDO::FETCH_ASSOC);
$adminPdo->exec("UPDATE cms_payment_provider_settings SET status = 'disabled'");
$disabledProviderInvalidPathContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/0' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf], []));
$disabledProviderInvalidPathDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/0' . $paidContentId . '/0' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf], []));
foreach ($providerStatusSnapshot as $providerStatusRow) {
    $adminPdo->prepare('UPDATE cms_payment_provider_settings SET status = :status WHERE provider_id = :provider_id')->execute([
        ':provider_id' => (string) ($providerStatusRow['provider_id'] ?? ''),
        ':status' => (string) ($providerStatusRow['status'] ?? 'disabled'),
    ]);
}
core_payment_check(
    $disabledProviderInvalidPathContentCheckout->status() === 400
    && $disabledProviderInvalidPathDownloadCheckout->status() === 400
    && str_contains($disabledProviderInvalidPathContentCheckout->body(), 'Paid content is not available.')
    && str_contains($disabledProviderInvalidPathDownloadCheckout->body(), 'Paid download is not available.')
    && !str_contains($disabledProviderInvalidPathContentCheckout->body(), 'No enabled payment provider')
    && !str_contains($disabledProviderInvalidPathDownloadCheckout->body(), 'No enabled payment provider')
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $publicCheckoutPaymentCount,
    'Core public paid checkout rejects non-canonical path ids before default Provider lookup'
);
$publicPaidContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
$publicPaidDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => FixturePaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $publicPaidContentCheckout->status() === 302
    && str_contains((string) ($publicPaidContentCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($publicPaidContentCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $publicPaidDownloadCheckout->status() === 302
    && str_contains((string) ($publicPaidDownloadCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($publicPaidDownloadCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'Core public paid checkout accepts valid CSRF and Core Provider selection before redirecting to no-store tokenized access URLs'
);
$directPaidContentAuthorizationCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$directPaidContentCheckout = $paidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-single-authorization-' . $paidArticleId);
$directPaidContentPaymentId = (int) ($directPaidContentCheckout['payment']['id'] ?? 0);
$directPaidContentAuthorizationCountAfterFirst = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
core_payment_throws(
    static fn () => $paidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-single-authorization-' . $paidArticleId),
    'Core paid content service rejects duplicate immediate checkout authorization attempts for one paid payment'
);
core_payment_check(
    $directPaidContentPaymentId > 0
    && is_array($directPaidContentCheckout['authorization'] ?? null)
    && $directPaidContentAuthorizationCountAfterFirst === $directPaidContentAuthorizationCountBefore + 1
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $directPaidContentAuthorizationCountAfterFirst,
    'Core paid content immediate checkout creates only one active authorization per payment'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($directPaidContentCheckout['authorization']['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 60),
]);
core_payment_throws(
    static fn () => $paidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-single-authorization-' . $paidArticleId),
    'Core paid content service rejects duplicate immediate checkout authorization attempts after the original grant expires'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $directPaidContentAuthorizationCountAfterFirst,
    'Core paid content immediate checkout never re-mints authorization for one payment after expiry'
);
$manualAuthorizationCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$manualContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ManualPaymentProvider::PROVIDER_ID], []));
$manualDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ManualPaymentProvider::PROVIDER_ID], []));
$manualPayments = $adminPdo->query("SELECT * FROM cms_payments WHERE provider_id = 'core.manual-payment' ORDER BY id ASC")->fetchAll();
$manualPaymentStatuses = $adminPdo->query("SELECT status FROM cms_payments WHERE provider_id = 'core.manual-payment' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
preg_match('#href="([^"]*/paid-content/' . $paidArticleId . '/complete\?[^"]+)"#', $manualContentCheckout->body(), $manualContentCompleteMatches);
preg_match('#href="([^"]*/paid-download/' . $paidContentId . '/' . $paidMediaId . '/complete\?[^"]+)"#', $manualDownloadCheckout->body(), $manualDownloadCompleteMatches);
$manualContentCompleteUrl = html_entity_decode((string) ($manualContentCompleteMatches[1] ?? ''), ENT_QUOTES, 'UTF-8');
$manualDownloadCompleteUrl = html_entity_decode((string) ($manualDownloadCompleteMatches[1] ?? ''), ENT_QUOTES, 'UTF-8');
$manualContentCompleteParts = parse_url($manualContentCompleteUrl);
$manualDownloadCompleteParts = parse_url($manualDownloadCompleteUrl);
parse_str((string) ($manualContentCompleteParts['query'] ?? ''), $manualContentCompleteQuery);
parse_str((string) ($manualDownloadCompleteParts['query'] ?? ''), $manualDownloadCompleteQuery);
core_payment_check(
    $manualContentCheckout->status() === 202
    && $manualDownloadCheckout->status() === 202
    && (string) ($manualContentCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($manualDownloadCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($manualContentCheckout->body(), '等待支付确认')
    && str_contains($manualDownloadCheckout->body(), '等待支付确认')
    && str_contains($manualContentCheckout->body(), '检查确认状态')
    && str_contains($manualDownloadCheckout->body(), '检查确认状态')
    && str_starts_with($manualContentCompleteUrl, '/paid-content/' . $paidArticleId . '/complete?')
    && str_starts_with($manualDownloadCompleteUrl, '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/complete?')
    && str_contains($manualContentCheckout->body(), 'Manual payment is available but not default.')
    && str_contains($manualDownloadCheckout->body(), 'Manual payment is available but not default.')
    && !str_contains($manualContentCheckout->body(), 'payment_token=')
    && !str_contains($manualDownloadCheckout->body(), 'payment_token=')
    && !isset($manualContentCheckout->headers()['Location'])
    && !isset($manualDownloadCheckout->headers()['Location'])
    && $manualPaymentStatuses === ['pending', 'pending']
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $manualAuthorizationCountBefore,
    'Core public manual payment checkout renders no-store pending confirmation without issuing authorization tokens'
);
$pendingContentPage = new ReflectionMethod(PaidContentController::class, 'pendingResponse');
$pendingDownloadPage = new ReflectionMethod(PaidDownloadController::class, 'pendingResponse');
$unsafePendingContent = $pendingContentPage->invoke($paidContentController, [
    'payment' => ['remote_id' => ' spaced-reference '],
    'instructions' => ' Manual payment is available but not default. ',
    'content_url' => ' https://evil.example.test/return ',
    'completion_url' => '/paid-content/' . $paidArticleId . '/complete?payment_token=unsafe',
], '付费内容');
$unsafePendingDownload = $pendingDownloadPage->invoke($paidDownloadController, [
    'payment' => ['remote_id' => "bad\nreference"],
    'instructions' => "Manual payment\ninstructions",
    'download_url' => '//evil.example.test/download',
    'completion_url' => '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/complete?payment_key=ok&claim=bad&payment_token=unsafe',
], '付费下载');
$nonCanonicalPendingContent = $pendingContentPage->invoke($paidContentController, [
    'payment' => ['remote_id' => 'manual-reference-safe'],
    'instructions' => 'Manual payment instructions',
    'content_url' => '/admin/payments/1',
    'completion_url' => '/paid-content/0' . $paidArticleId . '/complete?payment_key=ok&claim=' . str_repeat('a', 64),
], '付费内容');
$nonCanonicalPendingDownload = $pendingDownloadPage->invoke($paidDownloadController, [
    'payment' => ['remote_id' => 'manual-reference-safe'],
    'instructions' => 'Manual payment instructions',
    'download_url' => '/media/' . $paidMediaId,
    'completion_url' => '/paid-download/0' . $paidContentId . '/0' . $paidMediaId . '/complete?payment_key=bad key&claim=' . str_repeat('a', 64),
], '付费下载');
core_payment_check(
    $unsafePendingContent instanceof Response
    && $unsafePendingDownload instanceof Response
    && $nonCanonicalPendingContent instanceof Response
    && $nonCanonicalPendingDownload instanceof Response
    && $unsafePendingContent->status() === 202
    && $unsafePendingDownload->status() === 202
    && $nonCanonicalPendingContent->status() === 202
    && $nonCanonicalPendingDownload->status() === 202
    && (string) ($unsafePendingContent->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($unsafePendingDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($nonCanonicalPendingContent->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($nonCanonicalPendingDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && !str_contains($unsafePendingContent->body(), 'spaced-reference')
    && !str_contains($unsafePendingContent->body(), 'Manual payment is available but not default.')
    && !str_contains($unsafePendingContent->body(), 'evil.example.test')
    && !str_contains($unsafePendingContent->body(), 'payment_token=')
    && !str_contains($unsafePendingDownload->body(), "bad\nreference")
    && !str_contains($unsafePendingDownload->body(), 'claim=bad')
    && str_contains($unsafePendingDownload->body(), 'Manual payment')
    && str_contains($unsafePendingDownload->body(), 'instructions')
    && !str_contains($unsafePendingDownload->body(), 'evil.example.test')
    && !str_contains($unsafePendingDownload->body(), 'payment_token=')
    && !str_contains($nonCanonicalPendingContent->body(), '/admin/payments/1')
    && !str_contains($nonCanonicalPendingContent->body(), '/paid-content/0' . $paidArticleId)
    && !str_contains($nonCanonicalPendingDownload->body(), '/media/' . $paidMediaId)
    && !str_contains($nonCanonicalPendingDownload->body(), '/paid-download/0' . $paidContentId)
    && !str_contains($nonCanonicalPendingDownload->body(), 'bad key'),
    'Core pending manual payment pages are no-store and do not trim unsafe references or render tokenized, external or non-canonical pending links'
);
$manualMetadataContent = new PaidContentService($adminPdo, $settings);
$manualMetadataContentResult = $manualMetadataContent->checkout($paidArticleId, ManualPaymentProvider::PROVIDER_ID, 'paid-content-manual-metadata-safety-' . $paidArticleId);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => (int) ($manualMetadataContentResult['payment']['id'] ?? 0),
    ':metadata' => json_encode([
        'manual_instructions' => ' Manual payment is available but not default. ',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$manualMetadataContentRetry = $manualMetadataContent->checkout($paidArticleId, ManualPaymentProvider::PROVIDER_ID, 'paid-content-manual-metadata-safety-' . $paidArticleId);
core_payment_check(
    (string) ($manualMetadataContentRetry['instructions'] ?? '') === ''
    && (string) ($manualMetadataContentRetry['payment']['status'] ?? '') === 'pending',
    'Core public manual payment checkout retries do not trim non-canonical restored instructions into pending receipts'
);
$manualMetadataDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($manualMetadataContentResult['payment']['id'] ?? 0), [], [], []));
core_payment_check(
    $manualMetadataDetail->status() === 200
    && str_contains($manualMetadataDetail->body(), '人工确认信息')
    && str_contains($manualMetadataDetail->body(), '付款说明')
    && !str_contains($manualMetadataDetail->body(), 'Manual payment is available but not default.'),
    'Core admin payment detail does not trim non-canonical restored manual instructions into operator guidance'
);
$manualContentPendingComplete = $paidContentController->complete(new Request('GET', (string) ($manualContentCompleteParts['path'] ?? ''), $manualContentCompleteQuery, [], []));
$manualDownloadPendingComplete = $paidDownloadController->complete(new Request('GET', (string) ($manualDownloadCompleteParts['path'] ?? ''), $manualDownloadCompleteQuery, [], []));
core_payment_check(
    $manualContentPendingComplete->status() === 400
    && $manualDownloadPendingComplete->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $manualAuthorizationCountBefore,
    'Core manual payment completion links do not unlock access before administrator capture'
);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => (int) ($manualPayments[0]['id'] ?? 0),
    ':metadata' => json_encode([
        'manual_reference' => 'payment_token%3Draw-manual-reference-token',
        'manual_instructions' => 'Manual payment is available but not default.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$manualDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . (int) ($manualPayments[0]['id'] ?? 0), [], [], []));
core_payment_check(
    $manualDetail->status() === 200
    && str_contains($manualDetail->body(), '人工确认信息')
    && str_contains($manualDetail->body(), '支付参考号')
    && str_contains($manualDetail->body(), (string) ($manualPayments[0]['remote_id'] ?? ''))
    && !str_contains($manualDetail->body(), 'payment_token%3Draw-manual-reference-token')
    && !str_contains($manualDetail->body(), 'payment_token=raw-manual-reference-token')
    && str_contains($manualDetail->body(), 'Manual payment is available but not default.')
    && str_contains($manualDetail->body(), '同步状态不会把人工支付自动确认为已支付')
    && str_contains($manualDetail->body(), '捕获支付')
    && !str_contains($manualDetail->body(), 'payment_token='),
    'Core admin payment detail highlights manual payment confirmation context without bearer tokens or non-canonical references'
);
$manualContentCapture = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) ($manualPayments[0]['id'] ?? 0) . '/capture', [], ['_csrf' => $csrf, 'idempotency_key' => 'manual-content-capture'], []));
$manualDownloadCapture = $admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) ($manualPayments[1]['id'] ?? 0) . '/capture', [], ['_csrf' => $csrf, 'idempotency_key' => 'manual-download-capture'], []));
$manualContentComplete = $paidContentController->complete(new Request('GET', (string) ($manualContentCompleteParts['path'] ?? ''), $manualContentCompleteQuery, [], []));
$manualDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($manualDownloadCompleteParts['path'] ?? ''), $manualDownloadCompleteQuery, [], []));
core_payment_check(
    $manualContentCapture->status() === 302
    && $manualDownloadCapture->status() === 302
    && $manualContentComplete->status() === 302
    && str_contains((string) ($manualContentComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($manualContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $manualDownloadComplete->status() === 302
    && str_contains((string) ($manualDownloadComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($manualDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $manualAuthorizationCountBefore + 2,
    'Core manual payment completion links unlock no-store tokenized access only after administrator capture'
);
$partialManualPaymentOffset = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.manual-payment'")->fetchColumn();
$partialManualContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ManualPaymentProvider::PROVIDER_ID], []));
$partialManualDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ManualPaymentProvider::PROVIDER_ID], []));
preg_match('#href="([^"]*/paid-content/' . $paidArticleId . '/complete\?[^"]+)"#', $partialManualContentCheckout->body(), $partialManualContentMatches);
preg_match('#href="([^"]*/paid-download/' . $paidContentId . '/' . $paidMediaId . '/complete\?[^"]+)"#', $partialManualDownloadCheckout->body(), $partialManualDownloadMatches);
$partialManualContentUrl = html_entity_decode((string) ($partialManualContentMatches[1] ?? ''), ENT_QUOTES, 'UTF-8');
$partialManualDownloadUrl = html_entity_decode((string) ($partialManualDownloadMatches[1] ?? ''), ENT_QUOTES, 'UTF-8');
$partialManualContentParts = parse_url($partialManualContentUrl);
$partialManualDownloadParts = parse_url($partialManualDownloadUrl);
parse_str((string) ($partialManualContentParts['query'] ?? ''), $partialManualContentQuery);
parse_str((string) ($partialManualDownloadParts['query'] ?? ''), $partialManualDownloadQuery);
$partialManualPayments = $adminPdo->query("SELECT * FROM cms_payments WHERE provider_id = 'core.manual-payment' ORDER BY id ASC")->fetchAll();
$partialManualContentPayment = is_array($partialManualPayments[$partialManualPaymentOffset] ?? null) ? $partialManualPayments[$partialManualPaymentOffset] : [];
$partialManualDownloadPayment = is_array($partialManualPayments[$partialManualPaymentOffset + 1] ?? null) ? $partialManualPayments[$partialManualPaymentOffset + 1] : [];
$admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) ($partialManualContentPayment['id'] ?? 0) . '/capture', [], ['_csrf' => $csrf, 'idempotency_key' => 'manual-content-partial-capture'], []));
$admin->paymentCapture(new Request('POST', '/admin/payments/' . (int) ($partialManualDownloadPayment['id'] ?? 0) . '/capture', [], ['_csrf' => $csrf, 'idempotency_key' => 'manual-download-partial-capture'], []));
$adminService->refundProviderPayment((int) ($partialManualContentPayment['id'] ?? 0), 100, 'manual partial content refund before completion', 'manual-content-partial-refund-before-completion');
$adminService->refundProviderPayment((int) ($partialManualDownloadPayment['id'] ?? 0), 100, 'manual partial download refund before completion', 'manual-download-partial-refund-before-completion');
$partialManualContentComplete = $paidContentController->complete(new Request('GET', (string) ($partialManualContentParts['path'] ?? ''), $partialManualContentQuery, [], []));
$partialManualDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($partialManualDownloadParts['path'] ?? ''), $partialManualDownloadQuery, [], []));
core_payment_check(
    (string) (($adminRepo->payment((int) ($partialManualContentPayment['id'] ?? 0))['status'] ?? '')) === 'partially_refunded'
    && (string) (($adminRepo->payment((int) ($partialManualDownloadPayment['id'] ?? 0))['status'] ?? '')) === 'partially_refunded'
    && $partialManualContentComplete->status() === 302
    && str_contains((string) ($partialManualContentComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($partialManualContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $partialManualDownloadComplete->status() === 302
    && str_contains((string) ($partialManualDownloadComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($partialManualDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'Core manual payment completion uses trusted net paid state after partial refunds while redirecting to no-store tokenized access'
);
$manualSpacedInstructionsPaymentCountBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.manual-payment'")->fetchColumn();
core_payment_throws(
    static fn () => $adminProviderSettings->save(ManualPaymentProvider::PROVIDER_ID, 'Manual Bank Transfer', 'enabled', ['instructions' => ' Manual payment is available but not default. '], []),
    'Core manual payment settings reject non-canonical Provider instructions before checkout'
);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.manual-payment'")->fetchColumn() === $manualSpacedInstructionsPaymentCountBefore,
    'Core manual payment settings rejection leaves payment records unchanged before checkout'
);
$adminProviderSettings->save(ManualPaymentProvider::PROVIDER_ID, 'Manual Bank Transfer', 'enabled', ['instructions' => 'Manual payment is available but not default.'], []);
PaymentProviderRegistry::register('core.hosted-checkout', new HostedCheckoutPaymentProvider());
$adminProviderSettings->save('core.hosted-checkout', 'Hosted Checkout', 'enabled', ['mode' => 'hosted'], []);
$unsafeCheckoutPaymentCountBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.unsafe-checkout'")->fetchColumn();
PaymentProviderRegistry::register('core.unsafe-checkout', new UnsafeCheckoutPaymentProvider());
$adminProviderSettings->save('core.unsafe-checkout', 'Unsafe Checkout', 'enabled', ['mode' => 'unsafe'], []);
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'http://payments.example.test/checkout';
$unsafeHttpContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unsafe-checkout'], []));
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'https://payments.example.test/checkout?api_key=pk_live';
$unsafeSensitiveDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unsafe-checkout'], []));
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'https://payments.example.test/checkout?safe=payment_token%3Draw-provider-return-token';
$unsafeEncodedSensitiveContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unsafe-checkout'], []));
UnsafeCheckoutPaymentProvider::$checkoutUrl = ' https://payments.example.test/checkout ';
$unsafeSpacedContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unsafe-checkout'], []));
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'https://payments.example.test/checkout?cms_signature=not-a-core-signature';
$unsafeCmsSignatureContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.unsafe-checkout'], []));
core_payment_check(
    $unsafeHttpContentCheckout->status() === 400
    && $unsafeSensitiveDownloadCheckout->status() === 400
    && $unsafeEncodedSensitiveContentCheckout->status() === 400
    && $unsafeSpacedContentCheckout->status() === 400
    && $unsafeCmsSignatureContentCheckout->status() === 400
    && !isset($unsafeHttpContentCheckout->headers()['Location'])
    && !isset($unsafeSensitiveDownloadCheckout->headers()['Location'])
    && !isset($unsafeEncodedSensitiveContentCheckout->headers()['Location'])
    && !isset($unsafeSpacedContentCheckout->headers()['Location'])
    && !isset($unsafeCmsSignatureContentCheckout->headers()['Location'])
    && !str_contains($unsafeHttpContentCheckout->body(), 'payment_token=')
    && !str_contains($unsafeSensitiveDownloadCheckout->body(), 'payment_token=')
    && !str_contains($unsafeEncodedSensitiveContentCheckout->body(), 'payment_token=')
    && !str_contains($unsafeSpacedContentCheckout->body(), 'payment_token=')
    && !str_contains($unsafeCmsSignatureContentCheckout->body(), 'payment_token=')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.unsafe-checkout'")->fetchColumn() === $unsafeCheckoutPaymentCountBefore,
    'Core public paid checkout rejects Provider checkout URLs that are non-HTTPS, non-canonical, contain sensitive query parameters or carry malformed Core signatures before creating payment records'
);
$arrayProviderPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$arrayProviderContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ['core.hosted-checkout']], []));
$arrayProviderDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => ['core.hosted-checkout']], []));
$booleanProviderContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => true], []));
$booleanProviderDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => false], []));
core_payment_check(
    $arrayProviderContentCheckout->status() === 400
    && $arrayProviderDownloadCheckout->status() === 400
    && $booleanProviderContentCheckout->status() === 400
    && $booleanProviderDownloadCheckout->status() === 400
    && !isset($arrayProviderContentCheckout->headers()['Location'])
    && !isset($arrayProviderDownloadCheckout->headers()['Location'])
    && !isset($booleanProviderContentCheckout->headers()['Location'])
    && !isset($booleanProviderDownloadCheckout->headers()['Location'])
    && !str_contains($arrayProviderContentCheckout->body(), 'payment_token=')
    && !str_contains($arrayProviderDownloadCheckout->body(), 'payment_token=')
    && !str_contains($booleanProviderContentCheckout->body(), 'payment_token=')
    && !str_contains($booleanProviderDownloadCheckout->body(), 'payment_token=')
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $arrayProviderPaymentCountBefore,
    'Core public paid checkout rejects non-string Provider selections before payment creation'
);
$hostedAuthorizationCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$hostedContentCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.hosted-checkout'], []));
$hostedDownloadCheckout = $paidDownloadController->checkout(new Request('POST', '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => 'core.hosted-checkout'], []));
$hostedPaymentStatuses = $adminPdo->query("SELECT status FROM cms_payments WHERE provider_id = 'core.hosted-checkout' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$hostedPayments = $adminPdo->query("SELECT * FROM cms_payments WHERE provider_id = 'core.hosted-checkout' ORDER BY id ASC")->fetchAll();
$hostedContentPayment = is_array($hostedPayments[0] ?? null) ? $hostedPayments[0] : [];
$hostedDownloadPayment = is_array($hostedPayments[1] ?? null) ? $hostedPayments[1] : [];
$hostedContentMetadata = json_decode((string) ($hostedContentPayment['metadata_json'] ?? '{}'), true) ?: [];
$hostedDownloadMetadata = json_decode((string) ($hostedDownloadPayment['metadata_json'] ?? '{}'), true) ?: [];
$hostedContentSuccessUrl = (string) (HostedCheckoutPaymentProvider::$commands[0]['metadata']['success_url'] ?? '');
$hostedDownloadSuccessUrl = (string) (HostedCheckoutPaymentProvider::$commands[1]['metadata']['success_url'] ?? '');
$hostedContentSuccess = parse_url($hostedContentSuccessUrl);
$hostedDownloadSuccess = parse_url($hostedDownloadSuccessUrl);
parse_str((string) ($hostedContentSuccess['query'] ?? ''), $hostedContentCompleteQuery);
parse_str((string) ($hostedDownloadSuccess['query'] ?? ''), $hostedDownloadCompleteQuery);
core_payment_check(
    $hostedContentCheckout->status() === 302
    && str_starts_with((string) ($hostedContentCheckout->headers()['Location'] ?? ''), 'https://payments.example.test/checkout/')
    && !str_contains((string) ($hostedContentCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && $hostedDownloadCheckout->status() === 302
    && str_starts_with((string) ($hostedDownloadCheckout->headers()['Location'] ?? ''), 'https://payments.example.test/checkout/')
    && !str_contains((string) ($hostedDownloadCheckout->headers()['Location'] ?? ''), 'payment_token=')
    && $hostedPaymentStatuses === ['pending', 'pending']
    && str_starts_with($hostedContentSuccessUrl, '/paid-content/' . $paidArticleId . '/complete?')
    && str_starts_with($hostedDownloadSuccessUrl, '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/complete?')
    && str_contains((string) (HostedCheckoutPaymentProvider::$commands[0]['metadata']['success_url'] ?? ''), '/paid-content/' . $paidArticleId . '/complete?')
    && str_contains((string) (HostedCheckoutPaymentProvider::$commands[1]['metadata']['success_url'] ?? ''), '/paid-download/' . $paidContentId . '/' . $paidMediaId . '/complete?')
    && str_contains((string) ($hostedContentMetadata['success_url'] ?? ''), 'claim=%5Bredacted%5D')
    && str_contains((string) ($hostedDownloadMetadata['success_url'] ?? ''), 'claim=%5Bredacted%5D')
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $hostedAuthorizationCountBefore,
    'Core public paid checkout redirects pending hosted Provider sessions without creating local authorization tokens'
);
$badHostedContentCompleteQuery = $hostedContentCompleteQuery;
$badHostedContentCompleteQuery['claim'] = 'bad-claim';
$badHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $badHostedContentCompleteQuery, [], []));
$badHostedDownloadCompleteQuery = $hostedDownloadCompleteQuery;
$badHostedDownloadCompleteQuery['payment_key'] = "bad\npayment-key";
$badHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $badHostedDownloadCompleteQuery, [], []));
$spacedHostedContentCompleteQuery = $hostedContentCompleteQuery;
$spacedHostedContentCompleteQuery['payment_key'] = ' ' . (string) ($hostedContentCompleteQuery['payment_key'] ?? '') . ' ';
$spacedHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $spacedHostedContentCompleteQuery, [], []));
$spacedHostedDownloadCompleteQuery = $hostedDownloadCompleteQuery;
$spacedHostedDownloadCompleteQuery['payment_key'] = ' ' . (string) ($hostedDownloadCompleteQuery['payment_key'] ?? '') . ' ';
$spacedHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $spacedHostedDownloadCompleteQuery, [], []));
$tokenLikeHostedContentCompletionKey = 'hosted-payment_token%3Draw-content-completion-key';
$tokenLikeHostedContentCompleteQuery = [
    'payment_key' => $tokenLikeHostedContentCompletionKey,
    'claim' => hash_hmac('sha256', 'paid_content|' . $paidArticleId . '|' . $tokenLikeHostedContentCompletionKey, (string) $settings->get('security.encryption_key', '')),
];
$tokenLikeHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $tokenLikeHostedContentCompleteQuery, [], []));
$tokenLikeHostedDownloadCompletionKey = 'hosted-payment_token%3Draw-download-completion-key';
$tokenLikeHostedDownloadCompleteQuery = [
    'payment_key' => $tokenLikeHostedDownloadCompletionKey,
    'claim' => hash_hmac('sha256', 'paid_download|' . $paidContentId . '|' . $paidMediaId . '|' . $tokenLikeHostedDownloadCompletionKey, (string) $settings->get('security.encryption_key', '')),
];
$tokenLikeHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $tokenLikeHostedDownloadCompleteQuery, [], []));
$uppercaseClaimHostedDownloadCompleteQuery = $hostedDownloadCompleteQuery;
$uppercaseClaimHostedDownloadCompleteQuery['claim'] = strtoupper((string) ($hostedDownloadCompleteQuery['claim'] ?? ''));
$uppercaseClaimHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $uppercaseClaimHostedDownloadCompleteQuery, [], []));
$oversizedHostedContentCompleteQuery = $hostedContentCompleteQuery;
$oversizedHostedContentCompleteQuery['claim'] = str_repeat('a', 4096);
$oversizedHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $oversizedHostedContentCompleteQuery, [], []));
$arrayHostedContentCompleteQuery = $hostedContentCompleteQuery;
$arrayHostedContentCompleteQuery['payment_key'] = ['array-key'];
$arrayHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $arrayHostedContentCompleteQuery, [], []));
$arrayHostedDownloadCompleteQuery = $hostedDownloadCompleteQuery;
$arrayHostedDownloadCompleteQuery['claim'] = ['array-claim'];
$arrayHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $arrayHostedDownloadCompleteQuery, [], []));
$integerHostedContentCompleteQuery = $hostedContentCompleteQuery;
$integerHostedContentCompleteQuery['payment_key'] = 123456;
$integerHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $integerHostedContentCompleteQuery, [], []));
$integerHostedDownloadCompleteQuery = $hostedDownloadCompleteQuery;
$integerHostedDownloadCompleteQuery['claim'] = 123456;
$integerHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $integerHostedDownloadCompleteQuery, [], []));
$postHostedContentComplete = $paidContentController->complete(new Request('POST', (string) ($hostedContentSuccess['path'] ?? ''), $hostedContentCompleteQuery, [], []));
$postHostedDownloadComplete = $paidDownloadController->complete(new Request('POST', (string) ($hostedDownloadSuccess['path'] ?? ''), $hostedDownloadCompleteQuery, [], []));
$bodyOnlyHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), [], $hostedContentCompleteQuery, []));
$bodyOnlyHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), [], $hostedDownloadCompleteQuery, []));
$bodyOverrideHostedContentQuery = $badHostedContentCompleteQuery;
$bodyOverrideHostedDownloadQuery = $badHostedDownloadCompleteQuery;
$bodyOverrideHostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $bodyOverrideHostedContentQuery, $hostedContentCompleteQuery, []));
$bodyOverrideHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $bodyOverrideHostedDownloadQuery, $hostedDownloadCompleteQuery, []));
$leadingZeroHostedContentPath = '/paid-content/0' . $paidArticleId . '/complete';
$leadingZeroHostedDownloadPath = '/paid-download/0' . $paidContentId . '/0' . $paidMediaId . '/complete';
$leadingZeroHostedContentComplete = $paidContentController->complete(new Request('GET', $leadingZeroHostedContentPath, $hostedContentCompleteQuery, [], []));
$leadingZeroHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', $leadingZeroHostedDownloadPath, $hostedDownloadCompleteQuery, [], []));
$oversizedHostedContentComplete = $paidContentController->complete(new Request('GET', '/paid-content/1234567890123456789/complete', $hostedContentCompleteQuery, [], []));
$oversizedHostedDownloadComplete = $paidDownloadController->complete(new Request('GET', '/paid-download/1234567890123456789/1234567890123456789/complete', $hostedDownloadCompleteQuery, [], []));
$hostedInvalidReturnAuthorizationCount = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$hostedInvalidReturnPaymentStatuses = $adminPdo->query("SELECT status FROM cms_payments WHERE provider_id = 'core.hosted-checkout' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$hostedContentComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $hostedContentCompleteQuery, [], []));
$hostedDownloadComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $hostedDownloadCompleteQuery, [], []));
$hostedContentReplayComplete = $paidContentController->complete(new Request('GET', (string) ($hostedContentSuccess['path'] ?? ''), $hostedContentCompleteQuery, [], []));
$hostedDownloadReplayComplete = $paidDownloadController->complete(new Request('GET', (string) ($hostedDownloadSuccess['path'] ?? ''), $hostedDownloadCompleteQuery, [], []));
core_payment_check(
    $badHostedContentComplete->status() === 400
    && $badHostedDownloadComplete->status() === 400
    && $spacedHostedContentComplete->status() === 400
    && $spacedHostedDownloadComplete->status() === 400
    && $tokenLikeHostedContentComplete->status() === 400
    && $tokenLikeHostedContentComplete->body() === 'Paid content completion key is invalid.'
    && $tokenLikeHostedDownloadComplete->status() === 400
    && $tokenLikeHostedDownloadComplete->body() === 'Paid download completion key is invalid.'
    && $uppercaseClaimHostedDownloadComplete->status() === 400
    && $oversizedHostedContentComplete->status() === 400
    && $arrayHostedContentComplete->status() === 400
    && $arrayHostedDownloadComplete->status() === 400
    && $integerHostedContentComplete->status() === 400
    && $integerHostedDownloadComplete->status() === 400
    && $arrayHostedContentComplete->body() === 'Payment completion parameter is invalid.'
    && $arrayHostedDownloadComplete->body() === 'Payment completion parameter is invalid.'
    && $integerHostedContentComplete->body() === 'Payment completion parameter is invalid.'
    && $integerHostedDownloadComplete->body() === 'Payment completion parameter is invalid.'
    && (string) ($tokenLikeHostedContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($tokenLikeHostedDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($arrayHostedContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($arrayHostedDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $postHostedContentComplete->status() === 405
    && $postHostedDownloadComplete->status() === 405
    && $bodyOnlyHostedContentComplete->status() === 400
    && $bodyOnlyHostedDownloadComplete->status() === 400
    && $bodyOverrideHostedContentComplete->status() === 400
    && $bodyOverrideHostedDownloadComplete->status() === 400
    && $leadingZeroHostedContentComplete->status() === 400
    && $leadingZeroHostedDownloadComplete->status() === 400
    && $oversizedHostedContentComplete->status() === 400
    && $oversizedHostedDownloadComplete->status() === 400
    && (string) ($bodyOnlyHostedContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($bodyOnlyHostedDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($leadingZeroHostedContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($leadingZeroHostedDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && !str_contains((string) ($postHostedContentComplete->headers()['Location'] ?? ''), 'payment_token=')
    && !str_contains((string) ($postHostedDownloadComplete->headers()['Location'] ?? ''), 'payment_token=')
    && $hostedInvalidReturnAuthorizationCount === $hostedAuthorizationCountBefore
    && $hostedInvalidReturnPaymentStatuses === ['pending', 'pending']
    && $hostedContentComplete->status() === 302
    && str_contains((string) ($hostedContentComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($hostedContentComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $hostedDownloadComplete->status() === 302
    && str_contains((string) ($hostedDownloadComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($hostedDownloadComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $hostedContentReplayComplete->status() === 400
    && $hostedDownloadReplayComplete->status() === 400
    && (string) ($adminRepo->payment((int) ($hostedContentPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && (string) ($adminRepo->payment((int) ($hostedDownloadPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $hostedAuthorizationCountBefore + 2,
    'Core hosted Provider return validates string completion parameters and completes no-store authorization redirect once after trusted paid status and signed claim'
);
$sensitiveStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-sensitive-url');
$sensitiveStoredDownloadService = new PaidDownloadService($adminPdo, $settings);
$sensitiveStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-sensitive-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout?auth=restored-secret'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($sensitiveStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout?api_key=restored-secret'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($sensitiveStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$sensitiveStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-sensitive-url');
$sensitiveStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-sensitive-url');
core_payment_check(
    ($sensitiveStoredContentRetry['provider_redirect'] ?? true) === false
    && ($sensitiveStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_starts_with((string) ($sensitiveStoredContentRetry['content_url'] ?? ''), 'https://payments.example.test/')
    && ($sensitiveStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($sensitiveStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_starts_with((string) ($sensitiveStoredDownloadRetry['download_url'] ?? ''), 'https://payments.example.test/'),
    'Core public paid checkout refuses sensitive stored Provider redirect URLs before redirecting visitors'
);
$encodedSensitiveStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-encoded-sensitive-url');
$encodedSensitiveStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-encoded-sensitive-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout?safe=payment_token%3Draw-stored-content-token'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($encodedSensitiveStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout/sk%5Flive_stored_download_path_token'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($encodedSensitiveStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$encodedSensitiveStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-encoded-sensitive-url');
$encodedSensitiveStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-encoded-sensitive-url');
core_payment_check(
    ($encodedSensitiveStoredContentRetry['provider_redirect'] ?? true) === false
    && ($encodedSensitiveStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($encodedSensitiveStoredContentRetry['content_url'] ?? ''), 'raw-stored-content-token')
    && ($encodedSensitiveStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($encodedSensitiveStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($encodedSensitiveStoredDownloadRetry['download_url'] ?? ''), 'sk_live_stored_download_path_token'),
    'Core public paid checkout refuses token-like stored Provider redirect URL values before redirecting visitors'
);
$nonCanonicalStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-spaced-url');
$nonCanonicalStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-spaced-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => ' https://payments.example.test/checkout/spaced-content '], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($nonCanonicalStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => ' https://payments.example.test/checkout/spaced-download '], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($nonCanonicalStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$nonCanonicalStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-spaced-url');
$nonCanonicalStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-spaced-url');
core_payment_check(
    ($nonCanonicalStoredContentRetry['provider_redirect'] ?? true) === false
    && ($nonCanonicalStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_starts_with((string) ($nonCanonicalStoredContentRetry['content_url'] ?? ''), 'https://payments.example.test/')
    && ($nonCanonicalStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($nonCanonicalStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_starts_with((string) ($nonCanonicalStoredDownloadRetry['download_url'] ?? ''), 'https://payments.example.test/'),
    'Core public paid checkout refuses non-canonical stored Provider redirect URLs before redirecting visitors'
);
$arrayStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-array-url');
$arrayStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-array-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => ['https://payments.example.test/checkout/array-content']], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($arrayStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => ['https://payments.example.test/checkout/array-download']], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($arrayStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$arrayStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-array-url');
$arrayStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-array-url');
core_payment_check(
    ($arrayStoredContentRetry['provider_redirect'] ?? true) === false
    && ($arrayStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($arrayStoredContentRetry['content_url'] ?? ''), 'array-content')
    && ($arrayStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($arrayStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($arrayStoredDownloadRetry['download_url'] ?? ''), 'array-download'),
    'Core public paid checkout refuses non-string stored Provider redirect URLs before redirecting visitors'
);
$userinfoStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-userinfo-url');
$userinfoStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-userinfo-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://operator:secret@payments.example.test/checkout/userinfo-content'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($userinfoStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://operator:secret@payments.example.test/checkout/userinfo-download'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($userinfoStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$userinfoStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-userinfo-url');
$userinfoStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-userinfo-url');
core_payment_check(
    ($userinfoStoredContentRetry['provider_redirect'] ?? true) === false
    && ($userinfoStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($userinfoStoredContentRetry['content_url'] ?? ''), '@payments.example.test')
    && ($userinfoStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($userinfoStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($userinfoStoredDownloadRetry['download_url'] ?? ''), '@payments.example.test'),
    'Core public paid checkout refuses userinfo-bearing stored Provider redirect URLs before redirecting visitors'
);
$fragmentStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-fragment-url');
$fragmentStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-fragment-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout/fragment-content#pay'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($fragmentStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout/fragment-download#pay'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($fragmentStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$fragmentStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-fragment-url');
$fragmentStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-fragment-url');
core_payment_check(
    ($fragmentStoredContentRetry['provider_redirect'] ?? true) === false
    && ($fragmentStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($fragmentStoredContentRetry['content_url'] ?? ''), '#pay')
    && ($fragmentStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($fragmentStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($fragmentStoredDownloadRetry['download_url'] ?? ''), '#pay'),
    'Core public paid checkout refuses fragment-bearing stored Provider redirect URLs before redirecting visitors'
);
$signatureStoredContentCheckout = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-signature-url');
$signatureStoredDownloadCheckout = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-signature-url');
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout/signature-content?cms_signature=ABC123'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($signatureStoredContentCheckout['payment']['id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':metadata' => json_encode(['checkout_url' => 'https://payments.example.test/checkout/signature-download?cms_signature=not-a-core-signature'], JSON_UNESCAPED_SLASHES),
    ':id' => (int) ($signatureStoredDownloadCheckout['payment']['id'] ?? 0),
]);
$signatureStoredContentRetry = $paidContent->checkout($paidArticleId, 'core.hosted-checkout', 'hosted-content-stored-signature-url');
$signatureStoredDownloadRetry = $sensitiveStoredDownloadService->checkout($paidContentId, $paidMediaId, 'core.hosted-checkout', 'hosted-download-stored-signature-url');
core_payment_check(
    ($signatureStoredContentRetry['provider_redirect'] ?? true) === false
    && ($signatureStoredContentRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($signatureStoredContentRetry['content_url'] ?? ''), 'ABC123')
    && ($signatureStoredDownloadRetry['provider_redirect'] ?? true) === false
    && ($signatureStoredDownloadRetry['pending_confirmation'] ?? false) === true
    && !str_contains((string) ($signatureStoredDownloadRetry['download_url'] ?? ''), 'not-a-core-signature'),
    'Core public paid checkout refuses malformed Core signature stored Provider redirect URLs before redirecting visitors'
);
$hostedRedirectPaymentCountBeforeUnsafeReturn = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.hosted-redirect'")->fetchColumn();
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect', 'enabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], [
    'checkout_secret' => 'hosted-checkout-secret',
    'webhook_secret' => 'whsec_hosted_redirect',
]);
$adminPdo->prepare("UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id")->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'checkout_url' => 'https://psp.example.test/pay',
        'return_url_base' => 'http://cms.example.test',
    ], JSON_UNESCAPED_SLASHES),
]);
$unsafeHostedRedirectCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $unsafeHostedRedirectCheckout->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.hosted-redirect'")->fetchColumn() === $hostedRedirectPaymentCountBeforeUnsafeReturn,
    'Core hosted redirect Provider fails closed without creating payment records when return URL base is unsafe'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect', 'enabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], [
    'checkout_secret' => 'hosted-checkout-secret',
    'webhook_secret' => 'whsec_hosted_redirect',
]);
$adminPdo->prepare("UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id")->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'checkout_url' => 'https://psp.example.test/pay?signature=unsafe',
        'return_url_base' => 'https://cms.example.test',
    ], JSON_UNESCAPED_SLASHES),
]);
$sensitiveHostedRedirectCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $sensitiveHostedRedirectCheckout->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.hosted-redirect'")->fetchColumn() === $hostedRedirectPaymentCountBeforeUnsafeReturn,
    'Core hosted redirect Provider fails closed without creating payment records when public checkout URL contains sensitive query parameters'
);
$adminPdo->prepare("UPDATE cms_payment_provider_settings SET public_config_json = :public_config_json WHERE provider_id = :provider_id")->execute([
    ':provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    ':public_config_json' => json_encode([
        'checkout_url' => 'https://psp.example.test/pay#checkout',
        'return_url_base' => 'https://cms.example.test',
    ], JSON_UNESCAPED_SLASHES),
]);
$fragmentHostedRedirectCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID], []));
core_payment_check(
    $fragmentHostedRedirectCheckout->status() === 400
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payments WHERE provider_id = 'core.hosted-redirect'")->fetchColumn() === $hostedRedirectPaymentCountBeforeUnsafeReturn,
    'Core hosted redirect Provider fails closed without creating payment records when public checkout URL contains fragments'
);
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'https://operator:secret@payments.example.test/checkout';
core_payment_throws(
    static fn () => $service->createProviderPayment('paid_download', 'unsafe-userinfo-checkout', UnsafeCheckoutPaymentProvider::PROVIDER_ID, 500, 'USD', 'unsafe-userinfo-checkout', 'success'),
    'Core payment service rejects Provider checkout URLs with userinfo before writing payment rows'
);
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'https://payments.example.test/checkout#fragment';
core_payment_throws(
    static fn () => $service->createProviderPayment('paid_download', 'unsafe-fragment-checkout', UnsafeCheckoutPaymentProvider::PROVIDER_ID, 500, 'USD', 'unsafe-fragment-checkout', 'success'),
    'Core payment service rejects Provider checkout URLs with fragments before writing payment rows'
);
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'https://payments.example.test/checkout?cms_signature=abc123';
core_payment_throws(
    static fn () => $service->createProviderPayment('paid_download', 'unsafe-cms-signature-checkout', UnsafeCheckoutPaymentProvider::PROVIDER_ID, 500, 'USD', 'unsafe-cms-signature-checkout', 'success'),
    'Core payment service rejects Provider checkout URLs with malformed Core signatures before writing payment rows'
);
UnsafeCheckoutPaymentProvider::$checkoutUrl = 'http://payments.example.test/checkout';
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-canonical-return',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-canonical-return',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => ' /paid-content/1/complete?payment_key=bad ',
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-canonical return URLs before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-userinfo-checkout',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-userinfo-checkout',
        'provider_public_config' => [
            'checkout_url' => 'https://operator:secret@psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects checkout URLs with userinfo before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-token-value-checkout',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-token-value-checkout',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay?safe=payment_token%3Draw-hosted-public-url-token',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects checkout URLs with token-like query values before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-fragment-return-base',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-fragment-return-base',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test#return',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects return URL bases with fragments before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-query-return-base',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-query-return-base',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test/return?source=checkout',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects return URL bases with query parameters before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-external-return',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-external-return',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => 'https://evil.example.test/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects absolute external return URLs before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-protocol-relative-cancel',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-protocol-relative-cancel',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '//evil.example.test/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects protocol-relative cancel URLs before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-string-checkout-url',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-string-checkout-url',
        'provider_public_config' => [
            'checkout_url' => true,
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-string checkout URLs before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-string-return-url-base',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-string-return-url-base',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => ['https://cms.example.test'],
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-string return URL bases before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-string-success-url',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-string-success-url',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => ['/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64)],
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-string return path metadata before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => ['paid_content'],
        'subject_id' => 'hosted-redirect-non-string-subject-type',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-string-subject-type',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-string command subjects before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-string-idempotency',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => ['hosted-redirect-non-string-idempotency'],
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=ok&claim=' . str_repeat('a', 64),
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-string idempotency keys before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-missing-return-base',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-missing-return-base',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=bad',
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects relative return URLs when return URL base is missing before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-canonical-amount',
        'amount_minor' => ' 500 ',
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-canonical-amount',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=bad',
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-canonical amounts before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-canonical-currency',
        'amount_minor' => 500,
        'currency' => 'usd',
        'idempotency_key' => 'hosted-redirect-non-canonical-currency',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=bad',
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-canonical currencies before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => 'hosted-redirect-non-canonical-checkout-secret',
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-non-canonical-checkout-secret',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => ' hosted-checkout-secret ',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=bad',
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects non-canonical checkout signing secrets before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->getPaymentStatus((object) [
        'current_status' => ' Paid ',
        'provider_payment_id' => 'core-hosted-pay-non-canonical-status',
    ]),
    'Core hosted redirect Provider rejects non-canonical current status values during status sync'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->getPaymentStatus((object) [
        'current_status' => ['paid'],
        'provider_payment_id' => 'core-hosted-pay-non-string-status',
    ]),
    'Core hosted redirect Provider rejects non-string current statuses during status sync'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->getPaymentStatus((object) [
        'current_status' => 'paid',
        'provider_payment_id' => ['core-hosted-pay-non-string-remote'],
    ]),
    'Core hosted redirect Provider rejects non-string remote references during status sync'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->createPayment((object) [
        'subject_type' => 'paid_content',
        'subject_id' => "hosted\nunsafe-subject",
        'amount_minor' => 500,
        'currency' => 'USD',
        'idempotency_key' => 'hosted-redirect-unsafe-command-subject',
        'provider_public_config' => [
            'checkout_url' => 'https://psp.example.test/pay',
            'return_url_base' => 'https://cms.example.test',
        ],
        'provider_secret_config' => [
            'checkout_secret' => 'hosted-checkout-secret',
        ],
        'metadata' => [
            'success_url' => '/paid-content/1/complete?payment_key=bad',
            'cancel_url' => '/articles/1',
        ],
    ]),
    'Core hosted redirect Provider rejects unsafe command subjects before external checkout creation'
);
core_payment_throws(
    static fn () => (new HostedRedirectPaymentProvider())->getPaymentStatus((object) [
        'current_status' => 'paid',
        'provider_payment_id' => 'payment_token%3Draw-hosted-remote-token',
    ]),
    'Core hosted redirect Provider rejects token-like remote references during status sync'
);
$adminProviderSettings->save(HostedRedirectPaymentProvider::PROVIDER_ID, 'Hosted Redirect', 'enabled', [
    'checkout_url' => 'https://psp.example.test/pay',
    'return_url_base' => 'https://cms.example.test',
], [
    'checkout_secret' => 'hosted-checkout-secret',
    'webhook_secret' => 'whsec_hosted_redirect',
]);
$hostedRedirectAuthorizationCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$hostedRedirectCheckout = $paidContentController->checkout(new Request('POST', '/paid-content/' . $paidArticleId . '/checkout', [], ['_csrf' => $csrf, 'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID], []));
$hostedRedirectPayment = $adminPdo->query("SELECT * FROM cms_payments WHERE provider_id = 'core.hosted-redirect' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$hostedRedirectMetadata = is_array($hostedRedirectPayment) ? (json_decode((string) ($hostedRedirectPayment['metadata_json'] ?? '{}'), true) ?: []) : [];
$hostedRedirectUrl = (string) ($hostedRedirectCheckout->headers()['Location'] ?? '');
$hostedRedirectParts = parse_url($hostedRedirectUrl);
parse_str((string) ($hostedRedirectParts['query'] ?? ''), $hostedRedirectQuery);
$hostedRedirectReturnUrl = (string) ($hostedRedirectQuery['return_url'] ?? '');
$hostedRedirectReturnParts = parse_url($hostedRedirectReturnUrl);
parse_str((string) ($hostedRedirectReturnParts['query'] ?? ''), $hostedRedirectReturnQuery);
$hostedRedirectEarlyComplete = $paidContentController->complete(new Request('GET', (string) ($hostedRedirectReturnParts['path'] ?? ''), $hostedRedirectReturnQuery, [], []));
core_payment_check(
    $hostedRedirectCheckout->status() === 302
    && str_starts_with($hostedRedirectUrl, 'https://psp.example.test/pay?')
    && (string) ($hostedRedirectQuery['cms_payment_id'] ?? '') === (string) ($hostedRedirectPayment['remote_id'] ?? '')
    && (string) ($hostedRedirectQuery['return_url'] ?? '') !== ''
    && str_starts_with((string) ($hostedRedirectQuery['return_url'] ?? ''), 'https://cms.example.test/paid-content/' . $paidArticleId . '/complete?')
    && (string) ($hostedRedirectQuery['cms_signature'] ?? '') !== ''
    && str_contains((string) ($hostedRedirectMetadata['checkout_url'] ?? ''), 'cms_signature=%5Bredacted%5D')
    && !str_contains($hostedRedirectUrl, 'payment_token=')
    && $hostedRedirectEarlyComplete->status() === 400
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $hostedRedirectAuthorizationCountBefore,
    'Core hosted redirect Provider creates external checkout without trusting return URLs before signed webhook confirmation'
);
$hostedRedirectPayload = json_encode([
    'event_id' => 'evt-core-hosted-redirect-paid',
    'provider_payment_id' => (string) ($hostedRedirectPayment['remote_id'] ?? ''),
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$hostedRedirectTimestamp = (string) time();
$hostedRedirectWebhook = $webhookController->receive(new Request('POST', '/payment/webhooks/' . HostedRedirectPaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $hostedRedirectPayload,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $hostedRedirectTimestamp,
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-core-hosted-redirect-paid',
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $hostedRedirectTimestamp . '.' . (string) $hostedRedirectPayload, 'whsec_hosted_redirect'),
]));
$hostedRedirectComplete = $paidContentController->complete(new Request('GET', (string) ($hostedRedirectReturnParts['path'] ?? ''), $hostedRedirectReturnQuery, [], []));
core_payment_check(
    $hostedRedirectWebhook->status() === 200
    && $hostedRedirectComplete->status() === 302
    && str_contains((string) ($hostedRedirectComplete->headers()['Location'] ?? ''), 'payment_token=')
    && (string) ($hostedRedirectComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($adminRepo->payment((int) ($hostedRedirectPayment['id'] ?? 0))['status'] ?? '') === 'paid'
    && (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $hostedRedirectAuthorizationCountBefore + 1,
    'Core hosted redirect Provider unlocks no-store tokenized access only after signed Core webhook updates trusted payment state'
);
$paidContentStoredRedirect = new ReflectionMethod(PaidContentService::class, 'providerCheckoutUrl');
$paidContentPendingInstructions = new ReflectionMethod(PaidContentService::class, 'pendingInstructions');
$paidDownloadStoredRedirect = new ReflectionMethod(PaidDownloadService::class, 'providerCheckoutUrl');
$paidDownloadPendingInstructions = new ReflectionMethod(PaidDownloadService::class, 'pendingInstructions');
$paidDownloadRuntimeMetadataReader = new PaidDownloadService($adminPdo, $settings);
$malformedPaymentMetadataRow = [
    'metadata_json' => '{"checkout_url":"https://psp.example.test/pay","manual_instructions":"Wire payment to operator"',
];
$nonStringPaymentMetadataRow = [
    'metadata_json' => ['checkout_url' => 'https://psp.example.test/pay', 'manual_instructions' => 'Wire payment to operator'],
];
core_payment_check(
    $paidContentStoredRedirect->invoke($paidContent, $malformedPaymentMetadataRow) === ''
    && $paidContentPendingInstructions->invoke($paidContent, $malformedPaymentMetadataRow) === ''
    && $paidDownloadStoredRedirect->invoke($paidDownloadRuntimeMetadataReader, $malformedPaymentMetadataRow) === ''
    && $paidDownloadPendingInstructions->invoke($paidDownloadRuntimeMetadataReader, $malformedPaymentMetadataRow) === '',
    'Core paid checkout runtime ignores malformed restored payment metadata before redirect or pending instructions'
);
core_payment_check(
    $paidContentStoredRedirect->invoke($paidContent, $nonStringPaymentMetadataRow) === ''
    && $paidContentPendingInstructions->invoke($paidContent, $nonStringPaymentMetadataRow) === ''
    && $paidDownloadStoredRedirect->invoke($paidDownloadRuntimeMetadataReader, $nonStringPaymentMetadataRow) === ''
    && $paidDownloadPendingInstructions->invoke($paidDownloadRuntimeMetadataReader, $nonStringPaymentMetadataRow) === '',
    'Core paid checkout runtime ignores non-string restored payment metadata before redirect or pending instructions'
);
PaymentProviderRegistry::register('core.fixed-remote', new FixedRemotePaymentProvider());
$adminProviderSettings->save('core.fixed-remote', 'Fixed Remote', 'enabled', ['mode' => 'admin-test'], []);
$foreignWebhookPayment = $adminService->createProviderPayment('paid_download', 'foreign-webhook-provider', 'core.fixed-remote', 1500, 'USD', 'admin-foreign-webhook-key');
$foreignWebhookReceipt = $adminService->recordWebhookReceipt('core.fixed-remote', 'evt-foreign-webhook', '{"id":"evt-foreign-webhook"}');
$foreignWebhookStatusAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn();
$foreignWebhookStatusResponse = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . (int) $adminPayment['id'] . '/webhooks/' . (int) ($foreignWebhookReceipt['id'] ?? 0) . '/status', [], ['_csrf' => $csrf, 'status' => 'ignored'], []));
$foreignWebhookReceiptAfter = $adminRepo->webhookReceiptById((int) ($foreignWebhookReceipt['id'] ?? 0));
core_payment_check(
    (int) ($foreignWebhookPayment['id'] ?? 0) > 0
    && $foreignWebhookStatusResponse->status() === 400
    && is_array($foreignWebhookReceiptAfter)
    && (string) ($foreignWebhookReceiptAfter['status'] ?? '') === 'received'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $foreignWebhookStatusAuditBefore,
    'Core admin payment webhook receipt status action rejects receipts from another provider context'
);
$paidContentCheckout = $paidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-test-' . $paidArticleId);
$paidContentAuthorization = is_array($paidContentCheckout['authorization'] ?? null) ? $paidContentCheckout['authorization'] : [];
$paidContentUrl = parse_url((string) ($paidContentCheckout['content_url'] ?? ''));
parse_str((string) ($paidContentUrl['query'] ?? ''), $paidContentQuery);
$malformedPaidContentTokenArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => 'bad.token!'], [], []));
$oversizedPaidContentTokenArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => str_repeat('a', 2048) . '.sig'], [], []));
$integerPaidContentTokenArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => 123456], [], []));
$signedMalformedJsonPaidContentToken = core_payment_signed_raw_token('{"authorization_id":', (string) $settings->get('security.encryption_key', ''));
$signedMalformedJsonPaidContentArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $signedMalformedJsonPaidContentToken], [], []));
core_payment_check(
    $malformedPaidContentTokenArticle->status() === 200
    && (string) ($malformedPaidContentTokenArticle->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $oversizedPaidContentTokenArticle->status() === 200
    && $integerPaidContentTokenArticle->status() === 200
    && $signedMalformedJsonPaidContentArticle->status() === 200
    && !str_contains($malformedPaidContentTokenArticle->body(), 'Subscriber-only paragraph')
    && !str_contains($oversizedPaidContentTokenArticle->body(), 'Subscriber-only paragraph')
    && !str_contains($integerPaidContentTokenArticle->body(), 'Subscriber-only paragraph')
    && !str_contains($signedMalformedJsonPaidContentArticle->body(), 'Subscriber-only paragraph'),
    'Core paid content authorization rejects malformed, oversized, non-string and signed malformed-JSON public tokens before unlock and marks tokenized responses no-store'
);
$nonCanonicalContentTokenPayload = core_payment_token_payload((string) ($paidContentQuery['payment_token'] ?? ''));
$nonCanonicalContentTokenPayload['content_id'] = (string) $paidArticleId;
$nonCanonicalContentToken = core_payment_signed_token($nonCanonicalContentTokenPayload, (string) $settings->get('security.encryption_key', ''));
$nonCanonicalContentTokenArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $nonCanonicalContentToken], [], []));
core_payment_check(
    $nonCanonicalContentTokenArticle->status() === 200
    && !str_contains($nonCanonicalContentTokenArticle->body(), 'Subscriber-only paragraph'),
    'Core paid content authorization rejects signed tokens with non-canonical payload integers'
);
$futureContentTokenPayload = core_payment_token_payload((string) ($paidContentQuery['payment_token'] ?? ''));
$futureContentTokenPayload['issued_at'] = time() + 3600;
$futureContentToken = core_payment_signed_token($futureContentTokenPayload, (string) $settings->get('security.encryption_key', ''));
$futureContentTokenArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $futureContentToken], [], []));
core_payment_check(
    $futureContentTokenArticle->status() === 200
    && !str_contains($futureContentTokenArticle->body(), 'Subscriber-only paragraph'),
    'Core paid content authorization rejects signed tokens with future issued-at payloads'
);
$mismatchedContentSubjectPayload = core_payment_token_payload((string) ($paidContentQuery['payment_token'] ?? ''));
$mismatchedContentSubjectPayload['subject_id'] = 'content:' . ($paidArticleId + 1);
$mismatchedContentSubjectToken = core_payment_signed_token($mismatchedContentSubjectPayload, (string) $settings->get('security.encryption_key', ''));
$mismatchedContentSubjectArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $mismatchedContentSubjectToken], [], []));
core_payment_check(
    $mismatchedContentSubjectArticle->status() === 200
    && !str_contains($mismatchedContentSubjectArticle->body(), 'Subscriber-only paragraph'),
    'Core paid content authorization rejects signed tokens whose subject payload does not match the requested Core subject'
);
$mismatchedContentExpiryPayload = core_payment_token_payload((string) ($paidContentQuery['payment_token'] ?? ''));
$mismatchedContentExpiryPayload['expires_at'] = gmdate('c', time() + 7200);
$mismatchedContentExpiryToken = core_payment_signed_token($mismatchedContentExpiryPayload, (string) $settings->get('security.encryption_key', ''));
$mismatchedContentExpiryArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $mismatchedContentExpiryToken], [], []));
core_payment_check(
    $mismatchedContentExpiryArticle->status() === 200
    && !str_contains($mismatchedContentExpiryArticle->body(), 'Subscriber-only paragraph'),
    'Core paid content authorization rejects signed tokens whose expiry payload does not match the ledger'
);
$originalPaidContentAuthorizationExpiry = (string) ($paidContentAuthorization['expires_at'] ?? '');
$naturalLanguageContentExpiryPayload = core_payment_token_payload((string) ($paidContentQuery['payment_token'] ?? ''));
$naturalLanguageContentExpiryPayload['expires_at'] = '+1 day';
$naturalLanguageContentExpiryToken = core_payment_signed_token($naturalLanguageContentExpiryPayload, (string) $settings->get('security.encryption_key', ''));
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':expires_at' => '+1 day',
]);
$naturalLanguageContentExpiryArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $naturalLanguageContentExpiryToken], [], []));
core_payment_check(
    $naturalLanguageContentExpiryArticle->status() === 200
    && !str_contains($naturalLanguageContentExpiryArticle->body(), 'Subscriber-only paragraph'),
    'Core paid content authorization rejects natural-language token expiries even when restored ledger expiry matches'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':expires_at' => $originalPaidContentAuthorizationExpiry,
]);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':payment_id' => (string) ((int) ($paidContentAuthorization['payment_id'] ?? 0)) . 'abc',
]);
$corruptAuthorizationPaymentContentArticle = $front->article(new Request('GET', '/articles/core-paid-article', $paidContentQuery, [], []));
core_payment_check(
    $corruptAuthorizationPaymentContentArticle->status() === 200
    && !str_contains($corruptAuthorizationPaymentContentArticle->body(), 'Subscriber-only paragraph')
    && str_contains($corruptAuthorizationPaymentContentArticle->body(), '/paid-content/' . $paidArticleId . '/checkout'),
    'Core paid content authorization rejects corrupted payment ids instead of casting restored rows'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':payment_id' => (int) ($paidContentAuthorization['payment_id'] ?? 0),
]);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':max_uses' => -1,
    ':used_count' => ' 0 ',
]);
$badCounterPaidContentArticle = $front->article(new Request('GET', '/articles/core-paid-article', $paidContentQuery, [], []));
core_payment_check(
    $badCounterPaidContentArticle->status() === 200
    && !str_contains($badCounterPaidContentArticle->body(), 'Subscriber-only paragraph')
    && str_contains($badCounterPaidContentArticle->body(), '/paid-content/' . $paidArticleId . '/checkout'),
    'Core paid content authorization rejects non-canonical restored use counters without clamping rows'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':max_uses' => 0,
    ':used_count' => 0,
]);
$unlockedArticle = $front->article(new Request('GET', '/articles/core-paid-article', $paidContentQuery, [], []));
core_payment_check(
    (int) ($paidContentAuthorization['payment_id'] ?? 0) === (int) ($paidContentCheckout['payment']['id'] ?? 0)
    && (string) ($paidContentAuthorization['subject_type'] ?? '') === 'paid_content'
    && $unlockedArticle->status() === 200
    && (string) ($unlockedArticle->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($unlockedArticle->body(), 'Subscriber-only paragraph')
    && !str_contains($unlockedArticle->body(), '/paid-content/' . $paidArticleId . '/checkout'),
    'Core paid content checkout grants tokenized no-store full-content access after trusted payment'
);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE payment_id = " . (int) ($paidContentCheckout['payment']['id'] ?? 0) . " AND event_type = 'created'")->fetchColumn() === 1,
    'Core paid content checkout records authorization creation event'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':expires_at' => ' ' . gmdate('c', time() + 3600) . ' ',
]);
$spacedContentExpiryArticle = $front->article(new Request('GET', '/articles/core-paid-article', $paidContentQuery, [], []));
core_payment_check(
    $spacedContentExpiryArticle->status() === 200
    && !str_contains($spacedContentExpiryArticle->body(), 'Subscriber-only paragraph')
    && str_contains($spacedContentExpiryArticle->body(), '/paid-content/' . $paidArticleId . '/checkout'),
    'Core paid content authorization rejects non-canonical restored authorization expiries without trimming rows'
);
$impossibleCalendarContentExpiryPayload = core_payment_token_payload((string) ($paidContentQuery['payment_token'] ?? ''));
$impossibleCalendarContentExpiryPayload['expires_at'] = '2099-02-31T00:00:00+00:00';
$impossibleCalendarContentExpiryToken = core_payment_signed_token($impossibleCalendarContentExpiryPayload, 'core-payment-admin-settings-key');
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':expires_at' => '2099-02-31T00:00:00+00:00',
]);
$impossibleCalendarContentExpiryArticle = $front->article(new Request('GET', '/articles/core-paid-article', ['payment_token' => $impossibleCalendarContentExpiryToken], [], []));
core_payment_check(
    $impossibleCalendarContentExpiryArticle->status() === 200
    && !str_contains($impossibleCalendarContentExpiryArticle->body(), 'Subscriber-only paragraph')
    && str_contains($impossibleCalendarContentExpiryArticle->body(), '/paid-content/' . $paidArticleId . '/checkout'),
    'Core paid content authorization rejects impossible calendar expiries without normalizing restored rows'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($paidContentAuthorization['id'] ?? 0),
    ':expires_at' => $originalPaidContentAuthorizationExpiry,
]);
$paidContentEntitlement = $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', '42', 0, gmdate('c', time() + 3600), [
    'plan' => 'article-pass',
    'email' => 'member@example.test',
    'auth_code' => 'raw-entitlement-auth-code',
    'operator_note' => 'member arrived with payment_token=raw-entitlement-token',
    'encoded_note' => 'member arrived with payment_token%3Draw-entitlement-encoded-token',
    'control_note' => "member\nentitlement control",
    'encoded_control_note' => 'member%0Aentitlement-control',
    'encoded_spaced_note' => '%20member-entitlement-spaced%20',
    'receipt_url' => 'https://provider.example.test/entitlement?api_key=raw-entitlement-key&safe=1#payment_token=raw-entitlement-fragment-token',
    'encoded_receipt_url' => 'https://provider.example.test/entitlement/payment_token%3Draw-entitlement-path-token',
    'array_query_url' => 'https://provider.example.test/entitlement?safe[]=raw-entitlement-array-value',
    'bad key' => 'unsafe entitlement metadata key',
    'nested' => ['drop' => true],
]);
$paidContentEntitlementMeta = json_decode((string) ($paidContentEntitlement['metadata_json'] ?? '{}'), true) ?: [];
core_payment_check(
    (string) ($paidContentEntitlement['principal_type'] ?? '') === 'member'
    && (string) ($paidContentEntitlement['principal_id'] ?? '') === '42'
    && ($paidContentEntitlementMeta['email'] ?? '') === '[redacted]'
    && ($paidContentEntitlementMeta['auth_code'] ?? '') === '[redacted]'
    && ($paidContentEntitlementMeta['operator_note'] ?? '') === '[redacted]'
    && ($paidContentEntitlementMeta['encoded_note'] ?? '') === '[redacted]'
    && ($paidContentEntitlementMeta['control_note'] ?? '') === '[redacted]'
    && ($paidContentEntitlementMeta['encoded_control_note'] ?? '') === '[redacted]'
    && ($paidContentEntitlementMeta['encoded_spaced_note'] ?? '') === '[redacted]'
    && (string) ($paidContentEntitlementMeta['receipt_url'] ?? '') === 'https://provider.example.test/entitlement?api_key=%5Bredacted%5D&safe=1'
    && (string) ($paidContentEntitlementMeta['encoded_receipt_url'] ?? '') === '[redacted]'
    && (string) ($paidContentEntitlementMeta['array_query_url'] ?? '') === '[redacted]'
    && !array_key_exists('bad key', $paidContentEntitlementMeta)
    && !array_key_exists('nested', $paidContentEntitlementMeta)
    && !str_contains((string) ($paidContentEntitlementMeta['receipt_url'] ?? ''), 'raw-entitlement-fragment-token')
    && $paidContent->isEntitled($paidArticleId, 'member', '42'),
    'Core payment entitlement grants tokenless member access to paid content with redacted URL, token-like metadata and unsafe keys filtered'
);
$paidContentEntitlementCountAfterFirstGrant = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn();
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', '42', 0, gmdate('c', time() + 3600), ['source' => 'duplicate-entitlement-grant']),
    'Core payment entitlement service rejects duplicate grants for one payment principal and subject'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === $paidContentEntitlementCountAfterFirstGrant,
    'Core payment entitlement service creates only one grant per payment principal and subject'
);
$revokedDuplicateEntitlement = $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', 'revoked-duplicate-member', 0, gmdate('c', time() + 3600), ['source' => 'revoked-duplicate-entitlement']);
$revokedDuplicateEntitlementCountAfterFirstGrant = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn();
$entitlements->revoke((int) ($revokedDuplicateEntitlement['id'] ?? 0));
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', 'revoked-duplicate-member', 0, gmdate('c', time() + 3600), ['source' => 'regrant-after-revoke']),
    'Core payment entitlement service rejects duplicate grants after a same-payment entitlement is revoked'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === $revokedDuplicateEntitlementCountAfterFirstGrant,
    'Core payment entitlement service never re-grants the same payment principal and subject after revoke'
);
core_payment_check(
    !$paidContent->isEntitled(0, 'member', '42'),
    'Core paid content entitlement checks fail closed for non-positive content ids'
);
$entitlementCountBeforeInvalidServiceIds = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn();
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), ' Member ', 'bad-member-type', 0, gmdate('c', time() + 3600), ['source' => 'invalid-principal-type']),
    'Core payment entitlement service rejects non-canonical principal types before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', ' bad-member ', 0, gmdate('c', time() + 3600), ['source' => 'invalid-principal-id']),
    'Core payment entitlement service rejects non-canonical principal ids before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', "bad\nmember", 0, gmdate('c', time() + 3600), ['source' => 'invalid-principal']),
    'Core payment entitlement service rejects unsafe principal ids before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', 'payment_token%3Draw-grant-principal-token', 0, gmdate('c', time() + 3600), ['source' => 'token-like-principal']),
    'Core payment entitlement service rejects URL-encoded token-like principal ids before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', 'bad-member-expiry', 0, ' ' . gmdate('c', time() + 3600) . ' ', ['source' => 'invalid-expiry']),
    'Core payment entitlement service rejects non-canonical expiries before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', 'bad-member-natural-language-expiry', 0, '+1 day', ['source' => 'invalid-natural-language-expiry']),
    'Core payment entitlement service rejects natural-language expiries before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 'member', 'bad-source-authorization-id', -1, gmdate('c', time() + 3600), ['source' => 'invalid-source-authorization-id']),
    'Core payment entitlement service rejects negative source authorization ids before writing entitlement rows'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled(' Member ', '42', 'paid_content', 'content:' . $paidArticleId),
    'Core payment entitlement service rejects non-canonical principal types before entitlement lookup'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled('member', ' 42 ', 'paid_content', 'content:' . $paidArticleId),
    'Core payment entitlement service rejects non-canonical principal ids before entitlement lookup'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled('member', '42', ' Paid_Content ', 'content:' . $paidArticleId),
    'Core payment entitlement service rejects non-canonical subject types before entitlement lookup'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled('member', '42', 'paid_content', ' content:' . $paidArticleId . ' '),
    'Core payment entitlement service rejects non-canonical subject ids before entitlement lookup'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled('member', "bad\nmember", 'paid_content', 'content:' . $paidArticleId),
    'Core payment entitlement service rejects unsafe principal ids before entitlement lookup'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled('member', 'payment_token%3Draw-lookup-principal-token', 'paid_content', 'content:' . $paidArticleId),
    'Core payment entitlement service rejects URL-encoded token-like principal ids before entitlement lookup'
);
core_payment_throws(
    static fn () => $entitlements->isEntitled('member', '42', 'paid_content', 'payment_token%3Draw-lookup-subject-token'),
    'Core payment entitlement service rejects URL-encoded token-like subject ids before entitlement lookup'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === $entitlementCountBeforeInvalidServiceIds,
    'Core payment entitlement ledger stays unchanged after invalid entitlement service inputs'
);
$entitlementRefund = $adminService->refundProviderPayment((int) ($paidContentCheckout['payment']['id'] ?? 0), 899, 'entitlement refund', 'refund-paid-content-entitlement');
$refundedPaidContentAuthorization = $adminRepo->authorization((int) ($paidContentAuthorization['id'] ?? 0));
$refundedPaidContentEntitlement = $adminRepo->entitlement((int) ($paidContentEntitlement['id'] ?? 0));
core_payment_check(
    (string) ($entitlementRefund['status'] ?? '') === 'completed'
    && is_array($refundedPaidContentAuthorization)
    && (string) ($refundedPaidContentAuthorization['status'] ?? '') === 'revoked'
    && is_array($refundedPaidContentEntitlement)
    && (string) ($refundedPaidContentEntitlement['status'] ?? '') === 'revoked'
    && !$paidContent->isEntitled($paidArticleId, 'member', '42')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . (int) ($paidContentAuthorization['id'] ?? 0) . " AND event_type = 'revoked'")->fetchColumn() === 1
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.access.revoked_after_refund' AND context_json LIKE '%\"payment_id\":" . (int) ($paidContentCheckout['payment']['id'] ?? 0) . "%'")->fetchColumn() === 1,
    'Core full refund revokes payment-backed content authorization and entitlement state'
);
$partialRefundPaidContentCheckout = $paidContent->checkout($partialRefundPaidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-partial-refund-' . $partialRefundPaidArticleId);
$partialRefundPaidContentUrl = parse_url((string) ($partialRefundPaidContentCheckout['content_url'] ?? ''));
parse_str((string) ($partialRefundPaidContentUrl['query'] ?? ''), $partialRefundPaidContentQuery);
$adminService->refundProviderPayment((int) ($partialRefundPaidContentCheckout['payment']['id'] ?? 0), 100, 'partial paid content refund', 'refund-paid-content-partial-' . $partialRefundPaidArticleId);
$partialRefundUnlockedArticle = $front->article(new Request('GET', '/articles/core-partial-refund-paid-article', $partialRefundPaidContentQuery, [], []));
$partialRefundPaidContentAuthorization = $adminRepo->authorization((int) ($partialRefundPaidContentCheckout['authorization']['id'] ?? 0));
$adminService->refundProviderPayment((int) ($partialRefundPaidContentCheckout['payment']['id'] ?? 0), 799, 'remaining paid content refund', 'refund-paid-content-full-' . $partialRefundPaidArticleId);
$fullRefundLockedArticle = $front->article(new Request('GET', '/articles/core-partial-refund-paid-article', $partialRefundPaidContentQuery, [], []));
$fullRefundPaidContentAuthorization = $adminRepo->authorization((int) ($partialRefundPaidContentCheckout['authorization']['id'] ?? 0));
core_payment_check(
    $partialRefundUnlockedArticle->status() === 200
    && str_contains($partialRefundUnlockedArticle->body(), 'Partial refund subscriber paragraph')
    && is_array($partialRefundPaidContentAuthorization)
    && (string) ($partialRefundPaidContentAuthorization['status'] ?? '') === 'active'
    && $fullRefundLockedArticle->status() === 200
    && !str_contains($fullRefundLockedArticle->body(), 'Partial refund subscriber paragraph'),
    'Core paid content token remains active after partial refund and fails after full refund'
);
core_payment_check(
    is_array($fullRefundPaidContentAuthorization)
    && (string) ($fullRefundPaidContentAuthorization['status'] ?? '') === 'revoked',
    'Core full refund marks paid content authorization revoked'
);

$paidDownloads = new PaidDownloadService($adminPdo, $settings);
core_payment_throws(
    static fn () => $paidDownloads->subjectId($paidContentId, 0),
    'Core paid download subject ids reject non-positive media ids before payment subject creation'
);
$paidConfig = $paidDownloads->configFor($paidContentId, $paidMediaId);
core_payment_check(
    is_array($paidConfig)
    && (int) $paidConfig['amount_minor'] === 699
    && (string) $paidConfig['currency'] === 'USD'
    && (string) $paidConfig['subject_id'] === 'content:' . $paidContentId . ':media:' . $paidMediaId,
    'Core paid download reads published attachment pricing from content blocks'
);
$spacedDownloadLabelContentId = $contentRepo->create('article', 'Spaced Paid Download Label Article', 'spaced-paid-download-label-article', [[
    'type' => 'attachment',
    'data' => [
        'media_id' => $paidMediaId,
        'display_name' => 'Paid Guide',
        'paid_enabled' => true,
        'price_minor' => 699,
        'currency' => 'USD',
        'payment_label' => '购买标签下载',
    ],
]], 'published');
$adminPdo->prepare('UPDATE cms_contents SET blocks_json = :blocks WHERE id = :id')->execute([
    ':id' => $spacedDownloadLabelContentId,
    ':blocks' => json_encode([[
        'type' => 'attachment',
        'data' => [
            'media_id' => $paidMediaId,
            'display_name' => 'Paid Guide',
            'paid_enabled' => true,
            'price_minor' => 699,
            'currency' => 'USD',
            'payment_label' => ' 购买标签下载 ',
        ],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$spacedDownloadLabelConfig = $paidDownloads->configFor($spacedDownloadLabelContentId, $paidMediaId);
core_payment_check(
    is_array($spacedDownloadLabelConfig)
    && ($spacedDownloadLabelConfig['available'] ?? false) === true
    && (string) ($spacedDownloadLabelConfig['label'] ?? '') === '解锁下载',
    'Core paid download falls back instead of trimming non-canonical restored checkout labels'
);

$mediaController = new MediaController($tmpRoot, $settings);
$blockedDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1'], [], []));
core_payment_check(
    $blockedDownload->status() === 402
    && (string) ($blockedDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($blockedDownload->body(), '该文件需要完成支付后才能下载')
    && !str_contains($blockedDownload->body(), 'Payment Required'),
    'Core media controller blocks direct access to unpaid paid attachments with localized no-store responses'
);

$checkout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-test-' . $paidContentId . '-' . $paidMediaId);
$authorization = is_array($checkout['authorization'] ?? null) ? $checkout['authorization'] : [];
core_payment_check(
    (int) ($authorization['payment_id'] ?? 0) === (int) ($checkout['payment']['id'] ?? 0)
    && (string) ($authorization['subject_id'] ?? '') === 'content:' . $paidContentId . ':media:' . $paidMediaId
    && (string) ($authorization['status'] ?? '') === 'active'
    && strtotime((string) ($authorization['expires_at'] ?? '')) > time(),
    'Core paid download checkout stores expiring authorization records'
);
$directPaidDownloadAuthorizationCountAfterFirst = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
core_payment_throws(
    static fn () => $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-test-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download service rejects duplicate immediate checkout authorization attempts for one paid payment'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $directPaidDownloadAuthorizationCountAfterFirst,
    'Core paid download immediate checkout creates only one active authorization per payment'
);
$exhaustedDirectDownloadCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-single-exhausted-authorization-' . $paidContentId . '-' . $paidMediaId);
$exhaustedDirectDownloadAuthorizationCountAfterFirst = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn();
$adminPdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => (int) ($exhaustedDirectDownloadCheckout['authorization']['id'] ?? 0),
    ':max_uses' => 1,
    ':used_count' => 1,
]);
core_payment_throws(
    static fn () => $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-single-exhausted-authorization-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download service rejects duplicate immediate checkout authorization attempts after the original grant is exhausted'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_authorizations')->fetchColumn() === $exhaustedDirectDownloadAuthorizationCountAfterFirst,
    'Core paid download immediate checkout never re-mints authorization for one payment after exhaustion'
);
$parts = parse_url((string) $checkout['download_url']);
parse_str((string) ($parts['query'] ?? ''), $downloadQuery);
$malformedDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => 'bad.token!'], [], []));
$oversizedDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => str_repeat('a', 2048) . '.sig'], [], []));
$arrayTokenDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => ['array-token']], [], []));
$integerTokenDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => 123456], [], []));
$signedMalformedJsonDownloadToken = core_payment_signed_raw_token('{"authorization_id":', (string) $settings->get('security.encryption_key', ''));
$signedMalformedJsonDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => $signedMalformedJsonDownloadToken], [], []));
$authorizationAfterBadPublicTokens = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $malformedDownload->status() === 402
    && $oversizedDownload->status() === 402
    && $arrayTokenDownload->status() === 402
    && $integerTokenDownload->status() === 402
    && $signedMalformedJsonDownload->status() === 402
    && (string) ($malformedDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($arrayTokenDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($authorizationAfterBadPublicTokens)
    && (int) ($authorizationAfterBadPublicTokens['used_count'] ?? -1) === 0,
    'Core paid download authorization rejects malformed, oversized, non-string and signed malformed-JSON public tokens with no-store responses and without consuming access'
);
$nonCanonicalDownloadTokenPayload = core_payment_token_payload((string) ($downloadQuery['payment_token'] ?? ''));
$nonCanonicalDownloadTokenPayload['media_id'] = (string) $paidMediaId;
$nonCanonicalDownloadToken = core_payment_signed_token($nonCanonicalDownloadTokenPayload, (string) $settings->get('security.encryption_key', ''));
$nonCanonicalDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => $nonCanonicalDownloadToken], [], []));
$authorizationAfterNonCanonicalPayloadToken = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $nonCanonicalDownload->status() === 402
    && is_array($authorizationAfterNonCanonicalPayloadToken)
    && (int) ($authorizationAfterNonCanonicalPayloadToken['used_count'] ?? -1) === 0,
    'Core paid download authorization rejects signed tokens with non-canonical payload integers without consuming access'
);
$futureDownloadTokenPayload = core_payment_token_payload((string) ($downloadQuery['payment_token'] ?? ''));
$futureDownloadTokenPayload['issued_at'] = time() + 3600;
$futureDownloadToken = core_payment_signed_token($futureDownloadTokenPayload, (string) $settings->get('security.encryption_key', ''));
$futureIssuedDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => $futureDownloadToken], [], []));
$authorizationAfterFutureToken = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $futureIssuedDownload->status() === 402
    && is_array($authorizationAfterFutureToken)
    && (int) ($authorizationAfterFutureToken['used_count'] ?? -1) === 0,
    'Core paid download authorization rejects signed tokens with future issued-at payloads without consuming access'
);
$mismatchedDownloadSubjectPayload = core_payment_token_payload((string) ($downloadQuery['payment_token'] ?? ''));
$mismatchedDownloadSubjectPayload['subject_id'] = 'content:' . ($paidContentId + 1) . ':media:' . $paidMediaId;
$mismatchedDownloadSubjectToken = core_payment_signed_token($mismatchedDownloadSubjectPayload, (string) $settings->get('security.encryption_key', ''));
$mismatchedSubjectDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => $mismatchedDownloadSubjectToken], [], []));
$authorizationAfterMismatchedSubjectToken = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $mismatchedSubjectDownload->status() === 402
    && is_array($authorizationAfterMismatchedSubjectToken)
    && (int) ($authorizationAfterMismatchedSubjectToken['used_count'] ?? -1) === 0,
    'Core paid download authorization rejects signed tokens whose subject payload does not match the requested Core subject without consuming access'
);
$mismatchedDownloadExpiryPayload = core_payment_token_payload((string) ($downloadQuery['payment_token'] ?? ''));
$mismatchedDownloadExpiryPayload['expires_at'] = gmdate('c', time() + 7200);
$mismatchedDownloadExpiryToken = core_payment_signed_token($mismatchedDownloadExpiryPayload, (string) $settings->get('security.encryption_key', ''));
$mismatchedExpiryDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => $mismatchedDownloadExpiryToken], [], []));
$authorizationAfterMismatchedExpiryToken = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $mismatchedExpiryDownload->status() === 402
    && is_array($authorizationAfterMismatchedExpiryToken)
    && (int) ($authorizationAfterMismatchedExpiryToken['used_count'] ?? -1) === 0,
    'Core paid download authorization rejects signed tokens whose expiry payload does not match the ledger without consuming access'
);
$originalPaidDownloadAuthorizationExpiry = (string) ($authorization['expires_at'] ?? '');
$naturalLanguageDownloadExpiryPayload = core_payment_token_payload((string) ($downloadQuery['payment_token'] ?? ''));
$naturalLanguageDownloadExpiryPayload['expires_at'] = '+1 day';
$naturalLanguageDownloadExpiryToken = core_payment_signed_token($naturalLanguageDownloadExpiryPayload, (string) $settings->get('security.encryption_key', ''));
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($authorization['id'] ?? 0),
    ':expires_at' => '+1 day',
]);
$naturalLanguageExpiryDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => $naturalLanguageDownloadExpiryToken], [], []));
$authorizationAfterNaturalLanguageExpiryToken = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $naturalLanguageExpiryDownload->status() === 402
    && is_array($authorizationAfterNaturalLanguageExpiryToken)
    && (int) ($authorizationAfterNaturalLanguageExpiryToken['used_count'] ?? -1) === 0,
    'Core paid download authorization rejects natural-language token expiries without consuming access even when restored ledger expiry matches'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($authorization['id'] ?? 0),
    ':expires_at' => $originalPaidDownloadAuthorizationExpiry,
]);
$spacedContentIdDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => ' ' . $paidContentId . ' ', 'payment_token' => (string) ($downloadQuery['payment_token'] ?? '')], [], []));
$leadingZeroContentIdDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => '0' . $paidContentId, 'payment_token' => (string) ($downloadQuery['payment_token'] ?? '')], [], []));
$nonCanonicalMediaPathDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId . 'x', ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => (string) ($downloadQuery['payment_token'] ?? '')], [], []));
$oversizedContentIdDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, ['download' => '1', 'content_id' => str_repeat('9', 40), 'payment_token' => (string) ($downloadQuery['payment_token'] ?? '')], [], []));
$oversizedMediaPathDownload = $mediaController->show(new Request('GET', '/media/' . str_repeat('9', 40), ['download' => '1', 'content_id' => (string) $paidContentId, 'payment_token' => (string) ($downloadQuery['payment_token'] ?? '')], [], []));
$authorizationAfterNonCanonicalMediaInputs = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $spacedContentIdDownload->status() === 402
    && $leadingZeroContentIdDownload->status() === 402
    && $nonCanonicalMediaPathDownload->status() === 404
    && $oversizedContentIdDownload->status() === 402
    && $oversizedMediaPathDownload->status() === 404
    && is_array($authorizationAfterNonCanonicalMediaInputs)
    && (int) ($authorizationAfterNonCanonicalMediaInputs['used_count'] ?? -1) === 0,
    'Core media controller rejects non-canonical or oversized paid download ids without consuming authorization'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($authorization['id'] ?? 0),
    ':payment_id' => (string) ((int) ($authorization['payment_id'] ?? 0)) . 'abc',
]);
$corruptAuthorizationPaymentDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, $downloadQuery, [], []));
$authorizationAfterCorruptPaymentId = $adminRepo->authorization((int) ($authorization['id'] ?? 0));
core_payment_check(
    $corruptAuthorizationPaymentDownload->status() === 402
    && is_array($authorizationAfterCorruptPaymentId)
    && (string) ($authorizationAfterCorruptPaymentId['used_count'] ?? '') === '0',
    'Core paid download authorization rejects corrupted payment ids without consuming access'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($authorization['id'] ?? 0),
    ':payment_id' => (int) ($authorization['payment_id'] ?? 0),
]);
$authorizedDownload = $mediaController->show(new Request('GET', '/media/' . $paidMediaId, $downloadQuery, [], []));
core_payment_check(
    $authorizedDownload->status() === 200
    && $authorizedDownload->body() === 'paid download body'
    && (string) ($authorizedDownload->headers()['Content-Disposition'] ?? '') !== ''
    && (string) ($authorizedDownload->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'Core paid download checkout grants tokenized no-store attachment access after trusted payment'
);
$paidPaymentId = (int) ($checkout['payment']['id'] ?? 0);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE payment_id = " . $paidPaymentId . " AND event_type = 'created'")->fetchColumn() === 1
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE payment_id = " . $paidPaymentId . " AND event_type = 'consumed'")->fetchColumn() === 1,
    'Core paid download authorization records creation and consumption events'
);
$partialRefundPaidDownloadCheckout = $paidDownloads->checkout($partialRefundPaidContentId, $partialRefundPaidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-partial-refund-' . $partialRefundPaidContentId . '-' . $partialRefundPaidMediaId);
$partialRefundDownloadParts = parse_url((string) $partialRefundPaidDownloadCheckout['download_url']);
parse_str((string) ($partialRefundDownloadParts['query'] ?? ''), $partialRefundDownloadQuery);
$adminService->refundProviderPayment((int) ($partialRefundPaidDownloadCheckout['payment']['id'] ?? 0), 100, 'partial paid download refund', 'refund-paid-download-partial-' . $partialRefundPaidContentId . '-' . $partialRefundPaidMediaId);
$partialRefundPaidDownloadAuthorized = $paidDownloads->isAuthorized($partialRefundPaidContentId, $partialRefundPaidMediaId, (string) ($partialRefundDownloadQuery['payment_token'] ?? ''));
$partialRefundPaidDownloadAuthorization = $adminRepo->authorization((int) ($partialRefundPaidDownloadCheckout['authorization']['id'] ?? 0));
$adminService->refundProviderPayment((int) ($partialRefundPaidDownloadCheckout['payment']['id'] ?? 0), 599, 'remaining paid download refund', 'refund-paid-download-full-' . $partialRefundPaidContentId . '-' . $partialRefundPaidMediaId);
$fullRefundPaidDownloadAuthorization = $adminRepo->authorization((int) ($partialRefundPaidDownloadCheckout['authorization']['id'] ?? 0));
core_payment_check(
    $partialRefundPaidDownloadAuthorized
    && is_array($partialRefundPaidDownloadAuthorization)
    && (string) ($partialRefundPaidDownloadAuthorization['status'] ?? '') === 'active'
    && !$paidDownloads->isAuthorized($partialRefundPaidContentId, $partialRefundPaidMediaId, (string) ($partialRefundDownloadQuery['payment_token'] ?? '')),
    'Core paid download token remains active after partial refund and fails after full refund'
);
core_payment_check(
    is_array($fullRefundPaidDownloadAuthorization)
    && (string) ($fullRefundPaidDownloadAuthorization['status'] ?? '') === 'revoked',
    'Core full refund marks paid download authorization revoked'
);
$downloadEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member', (int) ($authorization['id'] ?? 0), '', ['source' => 'paid-download-checkout']);
core_payment_check(
    (string) ($downloadEntitlement['subject_type'] ?? '') === 'paid_download'
    && (int) ($downloadEntitlement['source_authorization_id'] ?? 0) === (int) ($authorization['id'] ?? 0)
    && $paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member'),
    'Core payment entitlement can bind member access to a paid download authorization without exposing bearer tokens'
);
$serviceCorruptAuthorizationCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-entitlement-service-corrupt-authorization-' . $paidContentId . '-' . $paidMediaId);
$serviceCorruptAuthorization = is_array($serviceCorruptAuthorizationCheckout['authorization'] ?? null) ? $serviceCorruptAuthorizationCheckout['authorization'] : [];
$entitlementCountBeforeCorruptServiceAuthorization = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn();
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($serviceCorruptAuthorization['id'] ?? 0),
    ':payment_id' => (string) ((int) ($serviceCorruptAuthorization['payment_id'] ?? 0)) . 'abc',
]);
core_payment_throws(
    static fn () => $entitlements->grantFromPayment((int) ($serviceCorruptAuthorizationCheckout['payment']['id'] ?? 0), 'member', 'download-member-corrupt-source-authorization', (int) ($serviceCorruptAuthorization['id'] ?? 0), '', ['source' => 'corrupt-service-source-authorization']),
    'Core payment entitlement service rejects corrupted source authorization payment ids before writing entitlement rows'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === $entitlementCountBeforeCorruptServiceAuthorization,
    'Core payment entitlement service leaves entitlement ledger unchanged after corrupted source authorization rows'
);
core_payment_check(
    !$paidDownloads->isEntitled($paidContentId, 0, 'member', 'download-member'),
    'Core paid download entitlement checks fail closed for non-positive media ids'
);
$spacedExpiryEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member-spaced-expiry', (int) ($authorization['id'] ?? 0), gmdate('c', time() + 3600), ['source' => 'spaced-entitlement-expiry']);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($spacedExpiryEntitlement['id'] ?? 0),
    ':expires_at' => ' ' . gmdate('c', time() + 3600) . ' ',
]);
core_payment_check(
    !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member-spaced-expiry'),
    'Core payment entitlement checks reject non-canonical active entitlement expiries without trimming restored rows'
);
$impossibleCalendarExpiryEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member-impossible-expiry', (int) ($authorization['id'] ?? 0), gmdate('c', time() + 3600), ['source' => 'impossible-entitlement-expiry']);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($impossibleCalendarExpiryEntitlement['id'] ?? 0),
    ':expires_at' => '2099-02-31T00:00:00+00:00',
]);
core_payment_check(
    !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member-impossible-expiry'),
    'Core payment entitlement checks reject impossible calendar active entitlement expiries'
);
$spacedAuthorizationCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-spaced-source-authorization-expiry-' . $paidContentId . '-' . $paidMediaId);
$spacedAuthorization = is_array($spacedAuthorizationCheckout['authorization'] ?? null) ? $spacedAuthorizationCheckout['authorization'] : [];
$spacedAuthorizationEntitlement = $entitlements->grantFromPayment((int) ($spacedAuthorizationCheckout['payment']['id'] ?? 0), 'member', 'download-member-spaced-source-authorization', (int) ($spacedAuthorization['id'] ?? 0), '', ['source' => 'spaced-source-authorization-expiry']);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($spacedAuthorization['id'] ?? 0),
    ':expires_at' => ' ' . gmdate('c', time() + 3600) . ' ',
]);
core_payment_check(
    !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member-spaced-source-authorization'),
    'Core payment entitlement checks reject non-canonical source authorization expiries without trimming restored rows'
);
$mismatchedAuthorizationCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-mismatched-entitlement-authorization-' . $paidContentId . '-' . $paidMediaId);
$mismatchedAuthorization = is_array($mismatchedAuthorizationCheckout['authorization'] ?? null) ? $mismatchedAuthorizationCheckout['authorization'] : [];
$mismatchedAuthorizationEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member-mismatched-source-authorization', (int) ($authorization['id'] ?? 0), '', ['source' => 'mismatched-source-authorization']);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET source_authorization_id = :source_authorization_id WHERE id = :id')->execute([
    ':id' => (int) ($mismatchedAuthorizationEntitlement['id'] ?? 0),
    ':source_authorization_id' => (int) ($mismatchedAuthorization['id'] ?? 0),
]);
core_payment_check(
    !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member-mismatched-source-authorization'),
    'Core payment entitlement checks reject source authorizations from a different payment before granting tokenless access'
);
$serviceRevokedEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member-service-revoke', (int) ($authorization['id'] ?? 0), '', ['source' => 'service-revoke-test']);
$revokedEntitlement = $entitlements->revoke((int) ($serviceRevokedEntitlement['id'] ?? 0));
core_payment_check(
    (string) ($revokedEntitlement['status'] ?? '') === 'revoked'
    && !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member-service-revoke'),
    'Core payment entitlement revoke invalidates tokenless member access'
);
$expiredAdminEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member-expire-admin', (int) ($authorization['id'] ?? 0), gmdate('c', time() + 3600), ['source' => 'admin-expiry-test']);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($expiredAdminEntitlement['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 60),
]);
core_payment_check(
    !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member-expire-admin'),
    'Core payment entitlement checks reject expired active entitlement timestamps'
);
$entitlementExpireAuditBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked'")->fetchColumn();
$getEntitlementExpireResponse = $admin->paymentEntitlementsExpire(new Request('GET', '/admin/payments/entitlements/expire', [], ['_csrf' => $csrf], []));
$expiredAdminEntitlementAfterGet = $adminRepo->entitlement((int) ($expiredAdminEntitlement['id'] ?? 0));
core_payment_check(
    $getEntitlementExpireResponse->status() === 405
    && (string) ($getEntitlementExpireResponse->headers()['Allow'] ?? '') === 'POST'
    && (string) ($getEntitlementExpireResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($getEntitlementExpireResponse->body(), '必须通过 POST')
    && is_array($expiredAdminEntitlementAfterGet)
    && (string) ($expiredAdminEntitlementAfterGet['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked'")->fetchColumn() === $entitlementExpireAuditBeforeQueryCsrf,
    'Core admin payment entitlement expiry rejects non-POST methods before marking grants or audit'
);
$queryCsrfEntitlementExpireResponse = $admin->paymentEntitlementsExpire(new Request('POST', '/admin/payments/entitlements/expire', ['_csrf' => $csrf], [], []));
$expiredAdminEntitlementAfterQueryCsrf = $adminRepo->entitlement((int) ($expiredAdminEntitlement['id'] ?? 0));
core_payment_check(
    $queryCsrfEntitlementExpireResponse->status() === 403
    && (string) ($queryCsrfEntitlementExpireResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($expiredAdminEntitlementAfterQueryCsrf)
    && (string) ($expiredAdminEntitlementAfterQueryCsrf['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked'")->fetchColumn() === $entitlementExpireAuditBeforeQueryCsrf,
    'Core admin payment entitlement expiry accepts CSRF only from POST body with no-store responses before marking grants or audit'
);
$arrayCsrfEntitlementExpireResponse = $admin->paymentEntitlementsExpire(new Request('POST', '/admin/payments/entitlements/expire', [], ['_csrf' => [$csrf]], []));
$expiredAdminEntitlementAfterArrayCsrf = $adminRepo->entitlement((int) ($expiredAdminEntitlement['id'] ?? 0));
core_payment_check(
    $arrayCsrfEntitlementExpireResponse->status() === 403
    && is_array($expiredAdminEntitlementAfterArrayCsrf)
    && (string) ($expiredAdminEntitlementAfterArrayCsrf['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked'")->fetchColumn() === $entitlementExpireAuditBeforeQueryCsrf,
    'Core admin payment entitlement expiry rejects non-scalar CSRF before marking grants or audit'
);
$adminEntitlementExpiryAuditFailureDbFile = $tmpRoot . '/payment-admin-entitlement-expiry-audit-failure.sqlite';
$adminEntitlementExpiryAuditFailurePdo = new PDO('sqlite:' . $adminEntitlementExpiryAuditFailureDbFile);
(new MigrationRunner($adminEntitlementExpiryAuditFailurePdo, $migrations))->run();
$adminEntitlementExpiryAuditFailureRepo = new PaymentRepository($adminEntitlementExpiryAuditFailurePdo);
$adminEntitlementExpiryAuditFailureSettings = new PaymentProviderSettingsRepository($adminEntitlementExpiryAuditFailurePdo, 'core-payment-admin-settings-key');
$adminEntitlementExpiryAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_entitlement_expiry_audit_failure']);
$adminEntitlementExpiryAuditFailureService = new PaymentService($adminEntitlementExpiryAuditFailurePdo, $adminEntitlementExpiryAuditFailureRepo);
$adminEntitlementExpiryAuditFailurePayment = $adminEntitlementExpiryAuditFailureService->createProviderPayment('paid_download', 'admin-entitlement-expiry-audit-failure', FixturePaymentProvider::PROVIDER_ID, 710, 'USD', 'admin-entitlement-expiry-audit-failure-payment');
$adminEntitlementExpiryAuditFailureGrant = (new PaymentEntitlementService($adminEntitlementExpiryAuditFailurePdo, $adminEntitlementExpiryAuditFailureRepo))->grantFromPayment(
    (int) ($adminEntitlementExpiryAuditFailurePayment['id'] ?? 0),
    'member',
    'admin-entitlement-expiry-audit-failure-member',
    0,
    gmdate('c', time() + 3600)
);
$adminEntitlementExpiryAuditFailurePdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($adminEntitlementExpiryAuditFailureGrant['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 60),
]);
$adminEntitlementExpiryAuditFailurePdo->exec('DROP TABLE cms_audit_logs');
$adminEntitlementExpiryAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminEntitlementExpiryAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminEntitlementExpiryAuditFailureResponse = $adminEntitlementExpiryAuditFailureController->paymentEntitlementsExpire(new Request('POST', '/admin/payments/entitlements/expire', [], ['_csrf' => $csrf], []));
$adminEntitlementExpiryAuditFailureAfter = $adminEntitlementExpiryAuditFailureRepo->entitlement((int) ($adminEntitlementExpiryAuditFailureGrant['id'] ?? 0));
core_payment_check(
    $adminEntitlementExpiryAuditFailureResponse->status() === 500
    && (string) ($adminEntitlementExpiryAuditFailureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($adminEntitlementExpiryAuditFailureAfter)
    && (string) ($adminEntitlementExpiryAuditFailureAfter['status'] ?? '') === 'active',
    'Core admin payment entitlement expiry rolls back entitlement state when audit persistence fails'
);
$entitlementExpireResponse = $admin->paymentEntitlementsExpire(new Request('POST', '/admin/payments/entitlements/expire', [], ['_csrf' => $csrf], []));
$expiredAdminEntitlementAfterSweep = $adminRepo->entitlement((int) ($expiredAdminEntitlement['id'] ?? 0));
core_payment_check(
    $entitlementExpireResponse->status() === 302
    && (string) ($entitlementExpireResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($expiredAdminEntitlementAfterSweep)
    && (string) ($expiredAdminEntitlementAfterSweep['status'] ?? '') === 'expired'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked'")->fetchColumn() === 1,
    'Core admin payment entitlement expiry marks stale active entitlements, writes audit and redirects no-store'
);
$expiredCliEntitlement = $entitlements->grantFromPayment($paidPaymentId, 'member', 'download-member-expire-cli', (int) ($authorization['id'] ?? 0), gmdate('c', time() + 3600), ['source' => 'cli-expiry-test']);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($expiredCliEntitlement['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 120),
]);
$invalidCliEntitlementExpiryAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked' AND actor_type = 'cli'")->fetchColumn();
$invalidCliEntitlementExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-entitlements', ['0']);
$invalidCliEntitlementExpiryJson = json_decode((string) ($invalidCliEntitlementExpiry['stdout'] ?? ''), true) ?: [];
$nonCanonicalCliEntitlementExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-entitlements', [' 10 ']);
$nonCanonicalCliEntitlementExpiryJson = json_decode((string) ($nonCanonicalCliEntitlementExpiry['stdout'] ?? ''), true) ?: [];
$leadingZeroCliEntitlementExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-entitlements', ['010']);
$leadingZeroCliEntitlementExpiryJson = json_decode((string) ($leadingZeroCliEntitlementExpiry['stdout'] ?? ''), true) ?: [];
$oversizedCliEntitlementExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-entitlements', ['1001']);
$oversizedCliEntitlementExpiryJson = json_decode((string) ($oversizedCliEntitlementExpiry['stdout'] ?? ''), true) ?: [];
$entitlementExpiryInvalidLimitNoConfigRoot = $tmpRoot . '/payment-cli-entitlement-expiry-invalid-limit-no-config';
mkdir($entitlementExpiryInvalidLimitNoConfigRoot, 0775, true);
$entitlementExpiryInvalidLimitNoConfig = core_payment_run_cli_raw($entitlementExpiryInvalidLimitNoConfigRoot, 'expire-payment-entitlements', [' 10 ']);
$entitlementExpiryInvalidLimitNoConfigJson = json_decode((string) ($entitlementExpiryInvalidLimitNoConfig['stdout'] ?? ''), true) ?: [];
$expiredCliEntitlementAfterInvalidSweep = $adminRepo->entitlement((int) ($expiredCliEntitlement['id'] ?? 0));
core_payment_check(
    (int) ($invalidCliEntitlementExpiry['code'] ?? 0) === 1
    && (string) ($invalidCliEntitlementExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($invalidCliEntitlementExpiryJson['error'] ?? ''), 'positive integer')
    && (int) ($nonCanonicalCliEntitlementExpiry['code'] ?? 0) === 1
    && (string) ($nonCanonicalCliEntitlementExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($nonCanonicalCliEntitlementExpiryJson['error'] ?? ''), 'positive integer')
    && (int) ($leadingZeroCliEntitlementExpiry['code'] ?? 0) === 1
    && (string) ($leadingZeroCliEntitlementExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($leadingZeroCliEntitlementExpiryJson['error'] ?? ''), 'positive integer')
    && (int) ($oversizedCliEntitlementExpiry['code'] ?? 0) === 1
    && (string) ($oversizedCliEntitlementExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($oversizedCliEntitlementExpiryJson['error'] ?? ''), 'Core bounds')
    && is_array($expiredCliEntitlementAfterInvalidSweep)
    && (string) ($expiredCliEntitlementAfterInvalidSweep['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked' AND actor_type = 'cli'")->fetchColumn() === $invalidCliEntitlementExpiryAuditBefore,
    'Core CLI payment entitlement expiry rejects invalid or out-of-bounds limits before marking grants or audit'
);
core_payment_check(
    (int) ($entitlementExpiryInvalidLimitNoConfig['code'] ?? 0) === 1
    && (string) ($entitlementExpiryInvalidLimitNoConfigJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($entitlementExpiryInvalidLimitNoConfigJson['error'] ?? ''), 'positive integer')
    && (string) ($entitlementExpiryInvalidLimitNoConfig['stderr'] ?? '') === ''
    && !str_contains((string) ($entitlementExpiryInvalidLimitNoConfig['stdout'] ?? ''), 'config/app.php'),
    'Core CLI payment entitlement expiry rejects invalid limits before loading site configuration'
);
$cliEntitlementExpiryAuditFailureRoot = $tmpRoot . '/payment-cli-entitlement-expiry-audit-failure';
mkdir($cliEntitlementExpiryAuditFailureRoot . '/config', 0775, true);
mkdir($cliEntitlementExpiryAuditFailureRoot . '/storage/logs', 0775, true);
$cliEntitlementExpiryAuditFailureDbFile = $cliEntitlementExpiryAuditFailureRoot . '/payment-cli-entitlement-expiry-audit-failure.sqlite';
$cliEntitlementExpiryAuditFailurePdo = new PDO('sqlite:' . $cliEntitlementExpiryAuditFailureDbFile);
$cliEntitlementExpiryAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new MigrationRunner($cliEntitlementExpiryAuditFailurePdo, $migrations))->run();
$cliEntitlementExpiryAuditFailureRepo = new PaymentRepository($cliEntitlementExpiryAuditFailurePdo);
$cliEntitlementExpiryAuditFailureSettings = new PaymentProviderSettingsRepository($cliEntitlementExpiryAuditFailurePdo, 'core-payment-admin-settings-key');
$cliEntitlementExpiryAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'CLI Audit Failure Fixture', 'enabled', ['mode' => 'cli-audit-failure'], ['api_secret' => 'sk_cli_entitlement_expiry_audit_failure']);
$cliEntitlementExpiryAuditFailureService = new PaymentService($cliEntitlementExpiryAuditFailurePdo, $cliEntitlementExpiryAuditFailureRepo);
$cliEntitlementExpiryAuditFailurePayment = $cliEntitlementExpiryAuditFailureService->createProviderPayment('paid_download', 'cli-entitlement-expiry-audit-failure', FixturePaymentProvider::PROVIDER_ID, 710, 'USD', 'cli-entitlement-expiry-audit-failure-payment');
$cliEntitlementExpiryAuditFailureGrant = (new PaymentEntitlementService($cliEntitlementExpiryAuditFailurePdo, $cliEntitlementExpiryAuditFailureRepo))->grantFromPayment(
    (int) ($cliEntitlementExpiryAuditFailurePayment['id'] ?? 0),
    'member',
    'cli-entitlement-expiry-audit-failure-member',
    0,
    gmdate('c', time() + 3600)
);
$cliEntitlementExpiryAuditFailurePdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($cliEntitlementExpiryAuditFailureGrant['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 120),
]);
$cliEntitlementExpiryAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_cli_entitlement_expiry_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.entitlement.expired_marked' BEGIN SELECT RAISE(ABORT, 'cli entitlement expiry audit failure signature=raw-leak'); END");
file_put_contents($cliEntitlementExpiryAuditFailureRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
    'database' => ['dsn' => 'sqlite:' . $cliEntitlementExpiryAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
], true) . ";\n");
$cliEntitlementExpiryAuditFailure = core_payment_run_cli_raw($cliEntitlementExpiryAuditFailureRoot, 'expire-payment-entitlements', ['10']);
$cliEntitlementExpiryAuditFailureJson = json_decode((string) ($cliEntitlementExpiryAuditFailure['stdout'] ?? ''), true) ?: [];
$cliEntitlementExpiryAuditFailureAfter = $cliEntitlementExpiryAuditFailureRepo->entitlement((int) ($cliEntitlementExpiryAuditFailureGrant['id'] ?? 0));
core_payment_check(
    (int) ($cliEntitlementExpiryAuditFailure['code'] ?? 0) === 1
    && (string) ($cliEntitlementExpiryAuditFailureJson['status'] ?? '') === 'Failed'
    && (string) ($cliEntitlementExpiryAuditFailureJson['error'] ?? '') === '[invalid]'
    && !str_contains((string) ($cliEntitlementExpiryAuditFailure['stdout'] ?? ''), 'payment_token=raw-leak')
    && !str_contains((string) ($cliEntitlementExpiryAuditFailure['stdout'] ?? ''), 'signature=raw-leak')
    && is_array($cliEntitlementExpiryAuditFailureAfter)
    && (string) ($cliEntitlementExpiryAuditFailureAfter['status'] ?? '') === 'active',
    'Core CLI payment entitlement expiry rolls back entitlement state and sanitizes failure output when audit persistence fails'
);
$cliEntitlementExpiry = core_payment_run_cli($tmpRoot, 'expire-payment-entitlements', ['10']);
$expiredCliEntitlementAfterSweep = $adminRepo->entitlement((int) ($expiredCliEntitlement['id'] ?? 0));
core_payment_check(
    (string) ($cliEntitlementExpiry['status'] ?? '') === 'Completed'
    && (int) ($cliEntitlementExpiry['expired'] ?? 0) === 1
    && (int) ($cliEntitlementExpiry['limit'] ?? 0) === 10
    && is_array($expiredCliEntitlementAfterSweep)
    && (string) ($expiredCliEntitlementAfterSweep['status'] ?? '') === 'expired'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.expired_marked' AND actor_type = 'cli' AND context_json LIKE '%\"count\":1%' AND context_json LIKE '%\"limit\":10%'")->fetchColumn() === 1,
    'Core CLI payment entitlement expiry marks stale grants and writes audit for scheduled maintenance'
);
$paidPaymentDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
core_payment_check($paidPaymentDetail->status() === 200 && str_contains($paidPaymentDetail->body(), '授权记录') && str_contains($paidPaymentDetail->body(), '授权事件') && str_contains($paidPaymentDetail->body(), '会员权益') && str_contains($paidPaymentDetail->body(), 'download-member') && str_contains($paidPaymentDetail->body(), '已使用') && str_contains($paidPaymentDetail->body(), 'paid_download') && str_contains($paidPaymentDetail->body(), '撤销授权') && str_contains($paidPaymentDetail->body(), '撤销权益'), 'Core admin payment detail lists payment authorizations, entitlements and events');
$adminDetailTraceProviderId = (string) $adminPdo->query('SELECT provider_id FROM cms_payments WHERE id = ' . $paidPaymentId)->fetchColumn();
$adminPdo->prepare(
    'INSERT INTO cms_payment_webhook_receipts
        (payment_id, provider_id, external_event_id, payload_hash, status, metadata_json, received_at, processed_at, created_at, updated_at)
     VALUES
        (:payment_id, :provider_id, :external_event_id, :payload_hash, :status, :metadata_json, :received_at, NULL, :created_at, :updated_at)'
)->execute([
    ':payment_id' => $paidPaymentId,
    ':provider_id' => $adminDetailTraceProviderId,
    ':external_event_id' => 'evt-admin-detail-leading-zero-trace',
    ':payload_hash' => hash('sha256', 'admin-detail-leading-zero-trace'),
    ':status' => 'received',
    ':metadata_json' => '{"payload_size":123,"webhook_timestamp":"000123456789"}',
    ':received_at' => gmdate('c'),
    ':created_at' => gmdate('c'),
    ':updated_at' => gmdate('c'),
]);
$adminDetailLeadingZeroTraceResponse = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
core_payment_check(
    $adminDetailLeadingZeroTraceResponse->status() === 200
    && str_contains($adminDetailLeadingZeroTraceResponse->body(), 'payload=123B')
    && !str_contains($adminDetailLeadingZeroTraceResponse->body(), 'ts=000123456789'),
    'Core admin payment detail rejects leading-zero webhook trace timestamps before rendering receipt summaries'
);
$adminDetailCorruptTimeAuthorizationId = (int) $adminPdo->query("SELECT id FROM cms_payment_authorizations WHERE payment_id = " . $paidPaymentId . " ORDER BY id LIMIT 1")->fetchColumn();
$adminDetailCorruptTimeAuthorizationExpiry = (string) $adminPdo->query('SELECT expires_at FROM cms_payment_authorizations WHERE id = ' . $adminDetailCorruptTimeAuthorizationId)->fetchColumn();
$adminDetailCorruptTimeEventId = (int) $adminPdo->query("SELECT id FROM cms_payment_authorization_events WHERE payment_id = " . $paidPaymentId . " ORDER BY id LIMIT 1")->fetchColumn();
$adminDetailCorruptTimeEventCreatedAt = (string) $adminPdo->query('SELECT created_at FROM cms_payment_authorization_events WHERE id = ' . $adminDetailCorruptTimeEventId)->fetchColumn();
$adminDetailCorruptTimeEntitlementId = (int) $adminPdo->query("SELECT id FROM cms_payment_entitlements WHERE source_payment_id = " . $paidPaymentId . " ORDER BY id LIMIT 1")->fetchColumn();
$adminDetailCorruptTimeEntitlementExpiry = $adminPdo->query('SELECT expires_at FROM cms_payment_entitlements WHERE id = ' . $adminDetailCorruptTimeEntitlementId)->fetchColumn();
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':expires_at' => 'next week',
    ':id' => $adminDetailCorruptTimeAuthorizationId,
]);
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET created_at = :created_at WHERE id = :id')->execute([
    ':created_at' => 'tomorrow',
    ':id' => $adminDetailCorruptTimeEventId,
]);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':expires_at' => 'later',
    ':id' => $adminDetailCorruptTimeEntitlementId,
]);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET received_at = :received_at, processed_at = :processed_at WHERE external_event_id = :event_id')->execute([
    ':received_at' => 'yesterday',
    ':processed_at' => 'next month',
    ':event_id' => 'evt-admin-detail-leading-zero-trace',
]);
$adminDetailCorruptTimeResponse = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
core_payment_check(
    $adminDetailCorruptTimeResponse->status() === 200
    && str_contains($adminDetailCorruptTimeResponse->body(), '支付时间无效')
    && !str_contains($adminDetailCorruptTimeResponse->body(), '<td>next week</td>')
    && !str_contains($adminDetailCorruptTimeResponse->body(), '<td>tomorrow</td>')
    && !str_contains($adminDetailCorruptTimeResponse->body(), '<td>later</td>')
    && !str_contains($adminDetailCorruptTimeResponse->body(), '<td>next month</td>')
    && !str_contains($adminDetailCorruptTimeResponse->body(), '<td>yesterday</td>'),
    'Core admin payment detail marks corrupted ledger timestamps invalid instead of rendering them as payment evidence'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':expires_at' => $adminDetailCorruptTimeAuthorizationExpiry,
    ':id' => $adminDetailCorruptTimeAuthorizationId,
]);
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET created_at = :created_at WHERE id = :id')->execute([
    ':created_at' => $adminDetailCorruptTimeEventCreatedAt,
    ':id' => $adminDetailCorruptTimeEventId,
]);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET expires_at = :expires_at WHERE id = :id')->execute([
    ':expires_at' => $adminDetailCorruptTimeEntitlementExpiry === false ? null : $adminDetailCorruptTimeEntitlementExpiry,
    ':id' => $adminDetailCorruptTimeEntitlementId,
]);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET received_at = :received_at, processed_at = NULL WHERE external_event_id = :event_id')->execute([
    ':received_at' => gmdate('c'),
    ':event_id' => 'evt-admin-detail-leading-zero-trace',
]);
$adminDetailCorruptCounterAuthorizationId = (int) $adminPdo->query("SELECT id FROM cms_payment_authorizations WHERE payment_id = " . $paidPaymentId . " ORDER BY id LIMIT 1")->fetchColumn();
$adminPdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => $adminDetailCorruptCounterAuthorizationId,
    ':max_uses' => -1,
    ':used_count' => ' 0 ',
]);
$adminDetailCorruptCounterResponse = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
core_payment_check(
    $adminDetailCorruptCounterResponse->status() === 200
    && str_contains($adminDetailCorruptCounterResponse->body(), '无效')
    && !str_contains($adminDetailCorruptCounterResponse->body(), '-1 /'),
    'Core admin payment detail marks non-canonical authorization counters invalid instead of casting them for display'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => $adminDetailCorruptCounterAuthorizationId,
    ':max_uses' => 0,
    ':used_count' => 0,
]);
$authorizationEventIdForDisplay = (int) $adminPdo->query("SELECT id FROM cms_payment_authorization_events WHERE payment_id = " . $paidPaymentId . " ORDER BY id LIMIT 1")->fetchColumn();
$authorizationEventAuthorizationIdForDisplay = (int) $adminPdo->query("SELECT authorization_id FROM cms_payment_authorization_events WHERE id = " . $authorizationEventIdForDisplay)->fetchColumn();
$authorizationEventTypeForDisplay = (string) $adminPdo->query("SELECT event_type FROM cms_payment_authorization_events WHERE id = " . $authorizationEventIdForDisplay)->fetchColumn();
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET authorization_id = :authorization_id WHERE id = :id')->execute([
    ':id' => $authorizationEventIdForDisplay,
    ':authorization_id' => (string) $authorizationEventAuthorizationIdForDisplay . 'abc',
]);
$adminDetailCorruptEventRelationResponse = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
core_payment_check(
    $adminDetailCorruptEventRelationResponse->status() === 200
    && str_contains($adminDetailCorruptEventRelationResponse->body(), '无效')
    && !str_contains($adminDetailCorruptEventRelationResponse->body(), '<td>' . $authorizationEventAuthorizationIdForDisplay . '</td><td>' . $authorizationEventTypeForDisplay . '</td>'),
    'Core admin payment detail marks non-canonical authorization event relations invalid instead of casting them for display'
);
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET authorization_id = :authorization_id WHERE id = :id')->execute([
    ':id' => $authorizationEventIdForDisplay,
    ':authorization_id' => $authorizationEventAuthorizationIdForDisplay,
]);
$adminDetailCorruptStatusAuthorizationStatus = (string) $adminPdo->query('SELECT status FROM cms_payment_authorizations WHERE id = ' . $adminDetailCorruptCounterAuthorizationId)->fetchColumn();
$adminDetailCorruptStatusEntitlementId = (int) $adminPdo->query("SELECT id FROM cms_payment_entitlements WHERE source_payment_id = " . $paidPaymentId . " ORDER BY id LIMIT 1")->fetchColumn();
$adminDetailCorruptStatusEntitlementStatus = (string) $adminPdo->query('SELECT status FROM cms_payment_entitlements WHERE id = ' . $adminDetailCorruptStatusEntitlementId)->fetchColumn();
$adminDetailCorruptStatusReceiptId = (int) $adminPdo->query("SELECT id FROM cms_payment_webhook_receipts WHERE payment_id = " . $paidPaymentId . " ORDER BY id DESC LIMIT 1")->fetchColumn();
$adminDetailCorruptStatusReceiptStatus = (string) $adminPdo->query('SELECT status FROM cms_payment_webhook_receipts WHERE id = ' . $adminDetailCorruptStatusReceiptId)->fetchColumn();
$adminDetailCorruptStatusEventType = (string) $adminPdo->query('SELECT event_type FROM cms_payment_authorization_events WHERE id = ' . $authorizationEventIdForDisplay)->fetchColumn();
$adminPdo->prepare('UPDATE cms_payment_authorizations SET status = :status WHERE id = :id')->execute([
    ':status' => 'usable',
    ':id' => $adminDetailCorruptCounterAuthorizationId,
]);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET status = :status WHERE id = :id')->execute([
    ':status' => 'granted',
    ':id' => $adminDetailCorruptStatusEntitlementId,
]);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET status = :status WHERE id = :id')->execute([
    ':status' => 'done',
    ':id' => $adminDetailCorruptStatusReceiptId,
]);
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET event_type = :event_type WHERE id = :id')->execute([
    ':event_type' => 'renewed',
    ':id' => $authorizationEventIdForDisplay,
]);
$adminDetailCorruptAccessStatusResponse = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
$adminDetailCorruptWebhookAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn();
$adminDetailCorruptWebhookStatusAction = $admin->paymentWebhookStatus(new Request('POST', '/admin/payments/' . $paidPaymentId . '/webhooks/' . $adminDetailCorruptStatusReceiptId . '/status', [], [
    '_csrf' => $csrf,
    'status' => 'processed',
], []));
core_payment_check(
    $adminDetailCorruptAccessStatusResponse->status() === 200
    && $adminDetailCorruptWebhookStatusAction->status() === 400
    && str_contains($adminDetailCorruptAccessStatusResponse->body(), '授权状态无效')
    && str_contains($adminDetailCorruptAccessStatusResponse->body(), '权益状态无效')
    && str_contains($adminDetailCorruptAccessStatusResponse->body(), 'Webhook 状态无效')
    && str_contains($adminDetailCorruptAccessStatusResponse->body(), '授权事件无效')
    && !str_contains($adminDetailCorruptAccessStatusResponse->body(), '/webhooks/' . $adminDetailCorruptStatusReceiptId . '/status')
    && !str_contains($adminDetailCorruptAccessStatusResponse->body(), '>usable<')
    && !str_contains($adminDetailCorruptAccessStatusResponse->body(), '>granted<')
    && !str_contains($adminDetailCorruptAccessStatusResponse->body(), '>done<')
    && !str_contains($adminDetailCorruptAccessStatusResponse->body(), '>renewed<')
    && (string) $adminPdo->query('SELECT status FROM cms_payment_webhook_receipts WHERE id = ' . $adminDetailCorruptStatusReceiptId)->fetchColumn() === 'done'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.webhook_receipt.status_changed'")->fetchColumn() === $adminDetailCorruptWebhookAuditBefore,
    'Core admin payment detail and actions mark corrupted access/webhook states invalid instead of rendering or mutating them as payment evidence'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET status = :status WHERE id = :id')->execute([
    ':status' => $adminDetailCorruptStatusAuthorizationStatus,
    ':id' => $adminDetailCorruptCounterAuthorizationId,
]);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET status = :status WHERE id = :id')->execute([
    ':status' => $adminDetailCorruptStatusEntitlementStatus,
    ':id' => $adminDetailCorruptStatusEntitlementId,
]);
$adminPdo->prepare('UPDATE cms_payment_webhook_receipts SET status = :status WHERE id = :id')->execute([
    ':status' => $adminDetailCorruptStatusReceiptStatus,
    ':id' => $adminDetailCorruptStatusReceiptId,
]);
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET event_type = :event_type WHERE id = :id')->execute([
    ':event_type' => $adminDetailCorruptStatusEventType,
    ':id' => $authorizationEventIdForDisplay,
]);
$paidPaymentLedgerBeforeDisplayCorruption = $adminRepo->payment($paidPaymentId) ?? [];
$adminPdo->prepare('UPDATE cms_payments SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => $paidPaymentId,
	    ':metadata' => json_encode([
	        'customer_email' => 'admin-detail-legacy@example.test',
	        'api_key' => 'raw-admin-detail-api-key',
	        'checkout_url' => 'https://payments.example.test/checkout?claim=raw-admin-detail-claim&safe=1#payment_token=raw-admin-detail-fragment-token',
	        'status_url' => 'https://payments.example.test/status/sk_live_admin_detail_path_token',
	        'encoded_status_url' => 'https://payments.example.test/status/payment_token%3Draw-admin-detail-path-token',
	        'receipt_url' => 'https://payments.example.test/receipt?safe=sk_live_admin_detail_query_token',
	        'array_receipt_url' => 'https://payments.example.test/receipt?safe[]=sk_live_admin_detail_array_query_token',
	        'deep_receipt_url' => 'https://payments.example.test/receipt?safe[token]=raw-admin-detail-deep-query-token',
	        'safe_note' => 'admin legacy payment metadata',
	        'operator_note' => 'legacy payment_token=raw-admin-detail-token',
	        'encoded_note' => 'legacy payment_token%3Draw-admin-detail-encoded-token',
	        'nested' => ['drop' => true],
	    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$adminPdo->prepare('UPDATE cms_payments SET remote_id = :remote_id, idempotency_key = :idempotency_key WHERE id = :id')->execute([
    ':id' => $paidPaymentId,
    ':remote_id' => 'payment_token%3Draw-admin-detail-remote-token',
    ':idempotency_key' => 'payment_token%3Draw-admin-detail-idempotency-token',
]);
$adminPdo->prepare('UPDATE cms_payment_authorization_events SET metadata_json = :metadata WHERE id = :id')->execute([
    ':id' => $authorizationEventIdForDisplay,
    ':metadata' => json_encode([
        'payment_token' => 'raw-admin-detail-event-token',
        'encoded_note' => 'payment_token%3Draw-admin-detail-event-encoded-token',
        'safe_note' => 'admin legacy event metadata',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$legacyMetadataDetail = $admin->paymentDetail(new Request('GET', '/admin/payments/' . $paidPaymentId, [], [], []));
core_payment_check(
    $legacyMetadataDetail->status() === 200
    && !str_contains($legacyMetadataDetail->body(), 'admin-detail-legacy@example.test')
	    && !str_contains($legacyMetadataDetail->body(), 'raw-admin-detail-api-key')
	    && !str_contains($legacyMetadataDetail->body(), 'raw-admin-detail-claim')
	    && !str_contains($legacyMetadataDetail->body(), 'sk_live_admin_detail_path_token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token%3Draw-admin-detail-path-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token=raw-admin-detail-path-token')
	    && !str_contains($legacyMetadataDetail->body(), 'sk_live_admin_detail_query_token')
	    && !str_contains($legacyMetadataDetail->body(), 'sk_live_admin_detail_array_query_token')
	    && !str_contains($legacyMetadataDetail->body(), 'raw-admin-detail-deep-query-token')
	    && !str_contains($legacyMetadataDetail->body(), 'raw-admin-detail-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token%3Draw-admin-detail-encoded-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token=raw-admin-detail-encoded-token')
	    && !str_contains($legacyMetadataDetail->body(), 'raw-admin-detail-fragment-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token%3Draw-admin-detail-remote-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token=raw-admin-detail-remote-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token%3Draw-admin-detail-idempotency-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token=raw-admin-detail-idempotency-token')
	    && !str_contains($legacyMetadataDetail->body(), 'raw-admin-detail-event-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token%3Draw-admin-detail-event-encoded-token')
	    && !str_contains($legacyMetadataDetail->body(), 'payment_token=raw-admin-detail-event-encoded-token')
    && !str_contains($legacyMetadataDetail->body(), 'nested')
    && str_contains($legacyMetadataDetail->body(), 'admin legacy payment metadata')
    && str_contains($legacyMetadataDetail->body(), 'admin legacy event metadata')
    && str_contains($legacyMetadataDetail->body(), '[redacted]'),
    'Core admin payment detail redacts unsafe legacy payment metadata before rendering'
);
$adminPdo->prepare('UPDATE cms_payments SET remote_id = :remote_id, idempotency_key = :idempotency_key WHERE id = :id')->execute([
    ':id' => $paidPaymentId,
    ':remote_id' => (string) ($paidPaymentLedgerBeforeDisplayCorruption['remote_id'] ?? ''),
    ':idempotency_key' => (string) ($paidPaymentLedgerBeforeDisplayCorruption['idempotency_key'] ?? ''),
]);
$entitlementRevokeAuditBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn();
$queryCsrfEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/' . $paidPaymentId . '/entitlements/' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke', ['_csrf' => $csrf], [], []));
$entitlementAfterQueryCsrfRevoke = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $queryCsrfEntitlementRevoke->status() === 403
    && (string) ($queryCsrfEntitlementRevoke->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($entitlementAfterQueryCsrfRevoke)
    && (string) ($entitlementAfterQueryCsrfRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === $entitlementRevokeAuditBeforeQueryCsrf,
    'Core admin payment entitlement revoke accepts CSRF only from POST body with no-store responses before invalidating access or audit'
);
$arrayCsrfEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/' . $paidPaymentId . '/entitlements/' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke', [], ['_csrf' => [$csrf]], []));
$entitlementAfterArrayCsrfRevoke = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $arrayCsrfEntitlementRevoke->status() === 403
    && is_array($entitlementAfterArrayCsrfRevoke)
    && (string) ($entitlementAfterArrayCsrfRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === $entitlementRevokeAuditBeforeQueryCsrf,
    'Core admin payment entitlement revoke rejects non-scalar CSRF before invalidating access or audit'
);
$leadingZeroEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/0' . $paidPaymentId . '/entitlements/0' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$entitlementAfterLeadingZeroRevoke = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $leadingZeroEntitlementRevoke->status() === 400
    && (string) ($leadingZeroEntitlementRevoke->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($entitlementAfterLeadingZeroRevoke)
    && (string) ($entitlementAfterLeadingZeroRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === $entitlementRevokeAuditBeforeQueryCsrf,
    'Core admin payment entitlement revoke rejects non-canonical path ids before invalidating access or audit'
);
$misplacedEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/not-a-payment/entitlements/' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$entitlementAfterMisplacedRevoke = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $misplacedEntitlementRevoke->status() === 400
    && is_array($entitlementAfterMisplacedRevoke)
    && (string) ($entitlementAfterMisplacedRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === $entitlementRevokeAuditBeforeQueryCsrf,
    'Core admin payment entitlement revoke rejects misplaced path ids before invalidating access or audit'
);
$trailingEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/' . $paidPaymentId . '/entitlements/' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke/extra', [], ['_csrf' => $csrf], []));
$entitlementAfterTrailingRevoke = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $trailingEntitlementRevoke->status() === 400
    && is_array($entitlementAfterTrailingRevoke)
    && (string) ($entitlementAfterTrailingRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === $entitlementRevokeAuditBeforeQueryCsrf,
    'Core admin payment entitlement revoke rejects non-canonical action paths before invalidating access or audit'
);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET source_payment_id = :source_payment_id WHERE id = :id')->execute([
    ':id' => (int) ($downloadEntitlement['id'] ?? 0),
    ':source_payment_id' => (string) $paidPaymentId . 'abc',
]);
$corruptPaymentIdEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/' . $paidPaymentId . '/entitlements/' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$entitlementAfterCorruptPaymentIdRevoke = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $corruptPaymentIdEntitlementRevoke->status() === 400
    && is_array($entitlementAfterCorruptPaymentIdRevoke)
    && (string) ($entitlementAfterCorruptPaymentIdRevoke['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === $entitlementRevokeAuditBeforeQueryCsrf,
    'Core admin payment entitlement revoke rejects corrupted source payment ids instead of casting restored rows'
);
$adminPdo->prepare('UPDATE cms_payment_entitlements SET source_payment_id = :source_payment_id WHERE id = :id')->execute([
    ':id' => (int) ($downloadEntitlement['id'] ?? 0),
    ':source_payment_id' => $paidPaymentId,
]);
$adminEntitlementAuditFailureDbFile = $tmpRoot . '/payment-admin-entitlement-audit-failure.sqlite';
$adminEntitlementAuditFailurePdo = new PDO('sqlite:' . $adminEntitlementAuditFailureDbFile);
(new MigrationRunner($adminEntitlementAuditFailurePdo, $migrations))->run();
$adminEntitlementAuditFailureRepo = new PaymentRepository($adminEntitlementAuditFailurePdo);
$adminEntitlementAuditFailureSettings = new PaymentProviderSettingsRepository($adminEntitlementAuditFailurePdo, 'core-payment-admin-settings-key');
$adminEntitlementAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_entitlement_audit_failure']);
$adminEntitlementAuditFailureService = new PaymentService($adminEntitlementAuditFailurePdo, $adminEntitlementAuditFailureRepo);
$adminEntitlementAuditFailurePayment = $adminEntitlementAuditFailureService->createProviderPayment('paid_download', 'admin-entitlement-audit-failure', FixturePaymentProvider::PROVIDER_ID, 700, 'USD', 'admin-entitlement-audit-failure-payment');
$adminEntitlementAuditFailureGrant = (new PaymentEntitlementService($adminEntitlementAuditFailurePdo, $adminEntitlementAuditFailureRepo))->grantFromPayment(
    (int) ($adminEntitlementAuditFailurePayment['id'] ?? 0),
    'member',
    'admin-entitlement-audit-failure-member'
);
$adminEntitlementAuditFailurePdo->exec('DROP TABLE cms_audit_logs');
$adminEntitlementAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminEntitlementAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminEntitlementAuditFailureResponse = $adminEntitlementAuditFailureController->paymentEntitlementRevoke(new Request('POST', '/admin/payments/' . (int) ($adminEntitlementAuditFailurePayment['id'] ?? 0) . '/entitlements/' . (int) ($adminEntitlementAuditFailureGrant['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$adminEntitlementAuditFailureAfter = $adminEntitlementAuditFailureRepo->entitlement((int) ($adminEntitlementAuditFailureGrant['id'] ?? 0));
core_payment_check(
    $adminEntitlementAuditFailureResponse->status() === 500
    && (string) ($adminEntitlementAuditFailureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($adminEntitlementAuditFailureAfter)
    && (string) ($adminEntitlementAuditFailureAfter['status'] ?? '') === 'active'
    && ($adminEntitlementAuditFailureAfter['revoked_at'] ?? null) === null,
    'Core admin payment entitlement revoke rolls back entitlement state when audit persistence fails'
);
$adminEntitlementRevoke = $admin->paymentEntitlementRevoke(new Request('POST', '/admin/payments/' . $paidPaymentId . '/entitlements/' . (int) ($downloadEntitlement['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$adminRevokedEntitlement = $adminRepo->entitlement((int) ($downloadEntitlement['id'] ?? 0));
core_payment_check(
    $adminEntitlementRevoke->status() === 302
    && (string) ($adminEntitlementRevoke->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($adminRevokedEntitlement)
    && (string) ($adminRevokedEntitlement['status'] ?? '') === 'revoked'
    && (string) ($adminRevokedEntitlement['revoked_at'] ?? '') !== ''
    && !$paidDownloads->isEntitled($paidContentId, $paidMediaId, 'member', 'download-member')
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.entitlement.revoked'")->fetchColumn() === 1,
    'Core admin payment entitlement revoke invalidates tokenless access, writes audit and redirects no-store'
);

$revokeCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-revoke-' . $paidContentId . '-' . $paidMediaId);
$revokeAuthorization = is_array($revokeCheckout['authorization'] ?? null) ? $revokeCheckout['authorization'] : [];
$revokeParts = parse_url((string) $revokeCheckout['download_url']);
parse_str((string) ($revokeParts['query'] ?? ''), $revokeQuery);
$authorizationRevokeAuditBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn();
$queryCsrfAuthorizationRevoke = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/' . (int) ($revokeCheckout['payment']['id'] ?? 0) . '/authorizations/' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke', ['_csrf' => $csrf], [], []));
$authorizationAfterQueryCsrfRevoke = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $queryCsrfAuthorizationRevoke->status() === 403
    && (string) ($queryCsrfAuthorizationRevoke->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($authorizationAfterQueryCsrfRevoke)
    && (string) ($authorizationAfterQueryCsrfRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($revokeQuery['payment_token'] ?? ''))
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === $authorizationRevokeAuditBeforeQueryCsrf,
    'Core admin payment authorization revoke accepts CSRF only from POST body with no-store responses before invalidating tokens or audit'
);
$arrayCsrfAuthorizationRevoke = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/' . (int) ($revokeCheckout['payment']['id'] ?? 0) . '/authorizations/' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke', [], ['_csrf' => [$csrf]], []));
$authorizationAfterArrayCsrfRevoke = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $arrayCsrfAuthorizationRevoke->status() === 403
    && is_array($authorizationAfterArrayCsrfRevoke)
    && (string) ($authorizationAfterArrayCsrfRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($revokeQuery['payment_token'] ?? ''))
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === $authorizationRevokeAuditBeforeQueryCsrf,
    'Core admin payment authorization revoke rejects non-scalar CSRF before invalidating tokens or audit'
);
$leadingZeroAuthorizationRevoke = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/0' . (int) ($revokeCheckout['payment']['id'] ?? 0) . '/authorizations/0' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$authorizationAfterLeadingZeroRevoke = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $leadingZeroAuthorizationRevoke->status() === 400
    && (string) ($leadingZeroAuthorizationRevoke->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($authorizationAfterLeadingZeroRevoke)
    && (string) ($authorizationAfterLeadingZeroRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($revokeQuery['payment_token'] ?? ''))
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === $authorizationRevokeAuditBeforeQueryCsrf,
    'Core admin payment authorization revoke rejects non-canonical path ids before invalidating tokens or audit'
);
$misplacedAuthorizationRevoke = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/not-a-payment/authorizations/' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$authorizationAfterMisplacedRevoke = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $misplacedAuthorizationRevoke->status() === 400
    && is_array($authorizationAfterMisplacedRevoke)
    && (string) ($authorizationAfterMisplacedRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($revokeQuery['payment_token'] ?? ''))
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === $authorizationRevokeAuditBeforeQueryCsrf,
    'Core admin payment authorization revoke rejects misplaced path ids before invalidating tokens or audit'
);
$trailingAuthorizationRevoke = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/' . (int) ($revokeCheckout['payment']['id'] ?? 0) . '/authorizations/' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke/extra', [], ['_csrf' => $csrf], []));
$authorizationAfterTrailingRevoke = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $trailingAuthorizationRevoke->status() === 400
    && is_array($authorizationAfterTrailingRevoke)
    && (string) ($authorizationAfterTrailingRevoke['status'] ?? '') === 'active'
    && $paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($revokeQuery['payment_token'] ?? ''))
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === $authorizationRevokeAuditBeforeQueryCsrf,
    'Core admin payment authorization revoke rejects non-canonical action paths before invalidating tokens or audit'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($revokeAuthorization['id'] ?? 0),
    ':payment_id' => (string) ((int) ($revokeAuthorization['payment_id'] ?? 0)) . 'abc',
]);
$corruptPaymentIdAuthorizationRevoke = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/' . (int) ($revokeCheckout['payment']['id'] ?? 0) . '/authorizations/' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$authorizationAfterCorruptPaymentIdRevoke = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $corruptPaymentIdAuthorizationRevoke->status() === 400
    && is_array($authorizationAfterCorruptPaymentIdRevoke)
    && (string) ($authorizationAfterCorruptPaymentIdRevoke['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === $authorizationRevokeAuditBeforeQueryCsrf,
    'Core admin payment authorization revoke rejects corrupted authorization payment ids instead of casting restored rows'
);
$adminPdo->prepare('UPDATE cms_payment_authorizations SET payment_id = :payment_id WHERE id = :id')->execute([
    ':id' => (int) ($revokeAuthorization['id'] ?? 0),
    ':payment_id' => (int) ($revokeAuthorization['payment_id'] ?? 0),
]);
$adminAuthorizationAuditFailureDbFile = $tmpRoot . '/payment-admin-authorization-audit-failure.sqlite';
$adminAuthorizationAuditFailurePdo = new PDO('sqlite:' . $adminAuthorizationAuditFailureDbFile);
(new MigrationRunner($adminAuthorizationAuditFailurePdo, $migrations))->run();
$adminAuthorizationAuditFailureRepo = new PaymentRepository($adminAuthorizationAuditFailurePdo);
$adminAuthorizationAuditFailureSettings = new PaymentProviderSettingsRepository($adminAuthorizationAuditFailurePdo, 'core-payment-admin-settings-key');
$adminAuthorizationAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_authorization_audit_failure']);
$adminAuthorizationAuditFailureService = new PaymentService($adminAuthorizationAuditFailurePdo, $adminAuthorizationAuditFailureRepo);
$adminAuthorizationAuditFailurePayment = $adminAuthorizationAuditFailureService->createProviderPayment('paid_download', 'admin-authorization-audit-failure', FixturePaymentProvider::PROVIDER_ID, 800, 'USD', 'admin-authorization-audit-failure-payment');
$adminAuthorizationAuditFailureId = $adminAuthorizationAuditFailureRepo->insertAuthorization([
    'payment_id' => (int) ($adminAuthorizationAuditFailurePayment['id'] ?? 0),
    'subject_type' => 'paid_download',
    'subject_id' => 'admin-authorization-audit-failure',
    'token_hash' => hash('sha256', 'admin-authorization-audit-failure-token'),
    'status' => 'active',
    'max_uses' => 1,
    'used_count' => 0,
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => [],
]);
$adminAuthorizationAuditFailurePdo->exec('DROP TABLE cms_audit_logs');
$adminAuthorizationAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminAuthorizationAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminAuthorizationAuditFailureResponse = $adminAuthorizationAuditFailureController->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/' . (int) ($adminAuthorizationAuditFailurePayment['id'] ?? 0) . '/authorizations/' . $adminAuthorizationAuditFailureId . '/revoke', [], ['_csrf' => $csrf], []));
$adminAuthorizationAuditFailureAfter = $adminAuthorizationAuditFailureRepo->authorization($adminAuthorizationAuditFailureId);
core_payment_check(
    $adminAuthorizationAuditFailureResponse->status() === 500
    && (string) ($adminAuthorizationAuditFailureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($adminAuthorizationAuditFailureAfter)
    && (string) ($adminAuthorizationAuditFailureAfter['status'] ?? '') === 'active'
    && ($adminAuthorizationAuditFailureAfter['revoked_at'] ?? null) === null
    && (int) $adminAuthorizationAuditFailurePdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $adminAuthorizationAuditFailureId . " AND event_type = 'revoked'")->fetchColumn() === 0,
    'Core admin payment authorization revoke rolls back authorization state and events when audit persistence fails'
);
$revokeResponse = $admin->paymentAuthorizationRevoke(new Request('POST', '/admin/payments/' . (int) ($revokeCheckout['payment']['id'] ?? 0) . '/authorizations/' . (int) ($revokeAuthorization['id'] ?? 0) . '/revoke', [], ['_csrf' => $csrf], []));
$revokedAuthorization = $adminRepo->authorization((int) ($revokeAuthorization['id'] ?? 0));
core_payment_check(
    $revokeResponse->status() === 302
    && (string) ($revokeResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($revokedAuthorization)
    && (string) $revokedAuthorization['status'] === 'revoked'
    && (string) ($revokedAuthorization['revoked_at'] ?? '') !== ''
    && !$paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($revokeQuery['payment_token'] ?? ''))
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.revoked'")->fetchColumn() === 1,
    'Core admin payment authorization revoke invalidates token, writes audit and redirects no-store'
);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . (int) ($revokeAuthorization['id'] ?? 0) . " AND event_type = 'revoked'")->fetchColumn() === 1,
    'Core admin payment authorization revoke records authorization event'
);

$freeDownload = $mediaController->show(new Request('GET', '/media/' . $freeMediaId, ['download' => '1'], [], []));
core_payment_check(
    $freeDownload->status() === 200
    && $freeDownload->body() === 'free download body'
    && (string) ($freeDownload->headers()['Cache-Control'] ?? '') === 'private, max-age=3600',
    'Core media controller keeps free attachment downloads public with existing cache policy'
);

$limitedSettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    'payment' => ['paid_download_token_ttl_seconds' => 3600, 'paid_download_token_max_uses' => 1],
]);
$limitedPaidDownloads = new PaidDownloadService($adminPdo, $limitedSettings);
$limitedCheckout = $limitedPaidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-limited-' . $paidContentId . '-' . $paidMediaId);
$limitedParts = parse_url((string) $limitedCheckout['download_url']);
parse_str((string) ($limitedParts['query'] ?? ''), $limitedQuery);
$limitedMediaController = new MediaController($tmpRoot, $limitedSettings);
$limitedHeadBefore = $adminRepo->authorization((int) ($limitedCheckout['authorization']['id'] ?? 0));
$limitedHeadDownload = $limitedMediaController->show(new Request('HEAD', '/media/' . $paidMediaId, $limitedQuery, [], []));
$limitedHeadAfter = $adminRepo->authorization((int) ($limitedCheckout['authorization']['id'] ?? 0));
$limitedPostDownload = $limitedMediaController->show(new Request('POST', '/media/' . $paidMediaId, $limitedQuery, [], []));
$limitedPostAfter = $adminRepo->authorization((int) ($limitedCheckout['authorization']['id'] ?? 0));
$limitedInvalidRangeDownload = $limitedMediaController->show(new Request('GET', '/media/' . $paidMediaId, $limitedQuery, [], ['HTTP_RANGE' => 'bytes=999999-']));
$limitedInvalidRangeAfter = $adminRepo->authorization((int) ($limitedCheckout['authorization']['id'] ?? 0));
$firstLimitedDownload = $limitedMediaController->show(new Request('GET', '/media/' . $paidMediaId, $limitedQuery, [], []));
$secondLimitedDownload = $limitedMediaController->show(new Request('GET', '/media/' . $paidMediaId, $limitedQuery, [], []));
core_payment_check(
    is_array($limitedHeadBefore)
    && is_array($limitedHeadAfter)
    && is_array($limitedPostAfter)
    && is_array($limitedInvalidRangeAfter)
    && $limitedHeadDownload->status() === 200
    && $limitedHeadDownload->body() === ''
    && (string) ($limitedHeadDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (int) ($limitedHeadBefore['used_count'] ?? -1) === 0
    && (int) ($limitedHeadAfter['used_count'] ?? -1) === 0
    && $limitedPostDownload->status() === 405
    && (string) ($limitedPostDownload->headers()['Allow'] ?? '') === 'GET, HEAD'
    && (int) ($limitedPostAfter['used_count'] ?? -1) === 0
    && str_contains($limitedPostDownload->body(), '媒体文件只能通过 GET 或 HEAD 访问')
    && !str_contains($limitedPostDownload->body(), 'Method Not Allowed')
    && $limitedInvalidRangeDownload->status() === 416
    && (int) ($limitedInvalidRangeAfter['used_count'] ?? -1) === 0
    && $firstLimitedDownload->status() === 200
    && (string) ($firstLimitedDownload->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && $secondLimitedDownload->status() === 402
    && str_contains($secondLimitedDownload->body(), '该文件需要完成支付后才能下载')
    && !str_contains($secondLimitedDownload->body(), 'Payment Required'),
    'Core paid download authorization enforces configured use limits without consuming access on HEAD, non-GET methods or invalid ranges while paid media responses stay no-store and localized'
);

$spacedDownloadExpiryCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-spaced-expiry-' . $paidContentId . '-' . $paidMediaId);
$spacedDownloadExpiryAuthorization = is_array($spacedDownloadExpiryCheckout['authorization'] ?? null) ? $spacedDownloadExpiryCheckout['authorization'] : [];
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($spacedDownloadExpiryAuthorization['id'] ?? 0),
    ':expires_at' => ' ' . gmdate('c', time() + 3600) . ' ',
]);
$spacedDownloadExpiryParts = parse_url((string) $spacedDownloadExpiryCheckout['download_url']);
parse_str((string) ($spacedDownloadExpiryParts['query'] ?? ''), $spacedDownloadExpiryQuery);
core_payment_check(
    !$paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($spacedDownloadExpiryQuery['payment_token'] ?? '')),
    'Core paid download authorization rejects non-canonical restored authorization expiries without trimming rows'
);
$badCounterCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-bad-counter-' . $paidContentId . '-' . $paidMediaId);
$badCounterAuthorization = is_array($badCounterCheckout['authorization'] ?? null) ? $badCounterCheckout['authorization'] : [];
$adminPdo->prepare('UPDATE cms_payment_authorizations SET max_uses = :max_uses, used_count = :used_count WHERE id = :id')->execute([
    ':id' => (int) ($badCounterAuthorization['id'] ?? 0),
    ':max_uses' => -1,
    ':used_count' => ' 0 ',
]);
$badCounterParts = parse_url((string) $badCounterCheckout['download_url']);
parse_str((string) ($badCounterParts['query'] ?? ''), $badCounterQuery);
core_payment_check(
    !$paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($badCounterQuery['payment_token'] ?? '')),
    'Core paid download authorization rejects non-canonical restored use counters without clamping rows'
);

$expiredCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-expired-' . $paidContentId . '-' . $paidMediaId);
$expiredAuthorization = is_array($expiredCheckout['authorization'] ?? null) ? $expiredCheckout['authorization'] : [];
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($expiredAuthorization['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 60),
]);
$expiredParts = parse_url((string) $expiredCheckout['download_url']);
parse_str((string) ($expiredParts['query'] ?? ''), $expiredQuery);
core_payment_check(!$paidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($expiredQuery['payment_token'] ?? '')), 'Core paid download authorization rejects expired tokens');
$authorizationExpireAuditBeforeQueryCsrf = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked'")->fetchColumn();
$getAuthorizationExpireResponse = $admin->paymentAuthorizationsExpire(new Request('GET', '/admin/payments/authorizations/expire', [], ['_csrf' => $csrf], []));
$expiredAuthorizationAfterGet = $adminRepo->authorization((int) ($expiredAuthorization['id'] ?? 0));
core_payment_check(
    $getAuthorizationExpireResponse->status() === 405
    && (string) ($getAuthorizationExpireResponse->headers()['Allow'] ?? '') === 'POST'
    && (string) ($getAuthorizationExpireResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($getAuthorizationExpireResponse->body(), '必须通过 POST')
    && is_array($expiredAuthorizationAfterGet)
    && (string) ($expiredAuthorizationAfterGet['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked'")->fetchColumn() === $authorizationExpireAuditBeforeQueryCsrf,
    'Core admin payment authorization expiry rejects non-POST methods before marking grants or audit'
);
$queryCsrfAuthorizationExpireResponse = $admin->paymentAuthorizationsExpire(new Request('POST', '/admin/payments/authorizations/expire', ['_csrf' => $csrf], [], []));
$expiredAuthorizationAfterQueryCsrf = $adminRepo->authorization((int) ($expiredAuthorization['id'] ?? 0));
core_payment_check(
    $queryCsrfAuthorizationExpireResponse->status() === 403
    && (string) ($queryCsrfAuthorizationExpireResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($expiredAuthorizationAfterQueryCsrf)
    && (string) ($expiredAuthorizationAfterQueryCsrf['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked'")->fetchColumn() === $authorizationExpireAuditBeforeQueryCsrf,
    'Core admin payment authorization expiry accepts CSRF only from POST body with no-store responses before marking grants or audit'
);
$arrayCsrfAuthorizationExpireResponse = $admin->paymentAuthorizationsExpire(new Request('POST', '/admin/payments/authorizations/expire', [], ['_csrf' => [$csrf]], []));
$expiredAuthorizationAfterArrayCsrf = $adminRepo->authorization((int) ($expiredAuthorization['id'] ?? 0));
core_payment_check(
    $arrayCsrfAuthorizationExpireResponse->status() === 403
    && is_array($expiredAuthorizationAfterArrayCsrf)
    && (string) ($expiredAuthorizationAfterArrayCsrf['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked'")->fetchColumn() === $authorizationExpireAuditBeforeQueryCsrf,
    'Core admin payment authorization expiry rejects non-scalar CSRF before marking grants or audit'
);
$adminAuthorizationExpiryAuditFailureDbFile = $tmpRoot . '/payment-admin-authorization-expiry-audit-failure.sqlite';
$adminAuthorizationExpiryAuditFailurePdo = new PDO('sqlite:' . $adminAuthorizationExpiryAuditFailureDbFile);
(new MigrationRunner($adminAuthorizationExpiryAuditFailurePdo, $migrations))->run();
$adminAuthorizationExpiryAuditFailureRepo = new PaymentRepository($adminAuthorizationExpiryAuditFailurePdo);
$adminAuthorizationExpiryAuditFailureSettings = new PaymentProviderSettingsRepository($adminAuthorizationExpiryAuditFailurePdo, 'core-payment-admin-settings-key');
$adminAuthorizationExpiryAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Audit Failure Fixture', 'enabled', ['mode' => 'audit-failure'], ['api_secret' => 'sk_admin_authorization_expiry_audit_failure']);
$adminAuthorizationExpiryAuditFailureService = new PaymentService($adminAuthorizationExpiryAuditFailurePdo, $adminAuthorizationExpiryAuditFailureRepo);
$adminAuthorizationExpiryAuditFailurePayment = $adminAuthorizationExpiryAuditFailureService->createProviderPayment('paid_download', 'admin-authorization-expiry-audit-failure', FixturePaymentProvider::PROVIDER_ID, 810, 'USD', 'admin-authorization-expiry-audit-failure-payment');
$adminAuthorizationExpiryAuditFailureId = $adminAuthorizationExpiryAuditFailureRepo->insertAuthorization([
    'payment_id' => (int) ($adminAuthorizationExpiryAuditFailurePayment['id'] ?? 0),
    'subject_type' => 'paid_download',
    'subject_id' => 'admin-authorization-expiry-audit-failure',
    'token_hash' => hash('sha256', 'admin-authorization-expiry-audit-failure-token'),
    'status' => 'active',
    'max_uses' => 1,
    'used_count' => 0,
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => [],
]);
$adminAuthorizationExpiryAuditFailurePdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $adminAuthorizationExpiryAuditFailureId,
    ':expires_at' => gmdate('c', time() - 60),
]);
$adminAuthorizationExpiryAuditFailurePdo->exec('DROP TABLE cms_audit_logs');
$adminAuthorizationExpiryAuditFailureController = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $adminAuthorizationExpiryAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
]), new FileLogger($tmpRoot . '/storage/logs/app.log'), $tmpRoot);
$adminAuthorizationExpiryAuditFailureResponse = $adminAuthorizationExpiryAuditFailureController->paymentAuthorizationsExpire(new Request('POST', '/admin/payments/authorizations/expire', [], ['_csrf' => $csrf], []));
$adminAuthorizationExpiryAuditFailureAfter = $adminAuthorizationExpiryAuditFailureRepo->authorization($adminAuthorizationExpiryAuditFailureId);
core_payment_check(
    $adminAuthorizationExpiryAuditFailureResponse->status() === 500
    && (string) ($adminAuthorizationExpiryAuditFailureResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($adminAuthorizationExpiryAuditFailureAfter)
    && (string) ($adminAuthorizationExpiryAuditFailureAfter['status'] ?? '') === 'active'
    && (int) $adminAuthorizationExpiryAuditFailurePdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $adminAuthorizationExpiryAuditFailureId . " AND event_type = 'expired'")->fetchColumn() === 0,
    'Core admin payment authorization expiry rolls back authorization state and events when audit persistence fails'
);
$expireResponse = $admin->paymentAuthorizationsExpire(new Request('POST', '/admin/payments/authorizations/expire', [], ['_csrf' => $csrf], []));
$expiredAuthorizationAfterSweep = $adminRepo->authorization((int) ($expiredAuthorization['id'] ?? 0));
core_payment_check(
    $expireResponse->status() === 302
    && (string) ($expireResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && is_array($expiredAuthorizationAfterSweep)
    && (string) ($expiredAuthorizationAfterSweep['status'] ?? '') === 'expired'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . (int) ($expiredAuthorization['id'] ?? 0) . " AND event_type = 'expired'")->fetchColumn() === 1,
    'Core admin payment authorization expiry marks stale active grants, records event and redirects no-store'
);
core_payment_check(
    (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked'")->fetchColumn() === 1,
    'Core admin payment authorization expiry writes audit'
);
$cliExpiredCheckout = $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-cli-expired-' . $paidContentId . '-' . $paidMediaId);
$cliExpiredAuthorization = is_array($cliExpiredCheckout['authorization'] ?? null) ? $cliExpiredCheckout['authorization'] : [];
$adminPdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => (int) ($cliExpiredAuthorization['id'] ?? 0),
    ':expires_at' => gmdate('c', time() - 120),
]);
$invalidCliAuthorizationExpiryAuditBefore = (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked' AND actor_type = 'cli'")->fetchColumn();
$invalidCliAuthorizationExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-authorizations', ['0']);
$invalidCliAuthorizationExpiryJson = json_decode((string) ($invalidCliAuthorizationExpiry['stdout'] ?? ''), true) ?: [];
$nonCanonicalCliAuthorizationExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-authorizations', [' 10 ']);
$nonCanonicalCliAuthorizationExpiryJson = json_decode((string) ($nonCanonicalCliAuthorizationExpiry['stdout'] ?? ''), true) ?: [];
$leadingZeroCliAuthorizationExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-authorizations', ['010']);
$leadingZeroCliAuthorizationExpiryJson = json_decode((string) ($leadingZeroCliAuthorizationExpiry['stdout'] ?? ''), true) ?: [];
$oversizedCliAuthorizationExpiry = core_payment_run_cli_raw($tmpRoot, 'expire-payment-authorizations', ['1001']);
$oversizedCliAuthorizationExpiryJson = json_decode((string) ($oversizedCliAuthorizationExpiry['stdout'] ?? ''), true) ?: [];
$authorizationExpiryInvalidLimitNoConfigRoot = $tmpRoot . '/payment-cli-authorization-expiry-invalid-limit-no-config';
mkdir($authorizationExpiryInvalidLimitNoConfigRoot, 0775, true);
$authorizationExpiryInvalidLimitNoConfig = core_payment_run_cli_raw($authorizationExpiryInvalidLimitNoConfigRoot, 'expire-payment-authorizations', [' 10 ']);
$authorizationExpiryInvalidLimitNoConfigJson = json_decode((string) ($authorizationExpiryInvalidLimitNoConfig['stdout'] ?? ''), true) ?: [];
$cliExpiredAuthorizationAfterInvalidSweep = $adminRepo->authorization((int) ($cliExpiredAuthorization['id'] ?? 0));
core_payment_check(
    (int) ($invalidCliAuthorizationExpiry['code'] ?? 0) === 1
    && (string) ($invalidCliAuthorizationExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($invalidCliAuthorizationExpiryJson['error'] ?? ''), 'positive integer')
    && (int) ($nonCanonicalCliAuthorizationExpiry['code'] ?? 0) === 1
    && (string) ($nonCanonicalCliAuthorizationExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($nonCanonicalCliAuthorizationExpiryJson['error'] ?? ''), 'positive integer')
    && (int) ($leadingZeroCliAuthorizationExpiry['code'] ?? 0) === 1
    && (string) ($leadingZeroCliAuthorizationExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($leadingZeroCliAuthorizationExpiryJson['error'] ?? ''), 'positive integer')
    && (int) ($oversizedCliAuthorizationExpiry['code'] ?? 0) === 1
    && (string) ($oversizedCliAuthorizationExpiryJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($oversizedCliAuthorizationExpiryJson['error'] ?? ''), 'Core bounds')
    && is_array($cliExpiredAuthorizationAfterInvalidSweep)
    && (string) ($cliExpiredAuthorizationAfterInvalidSweep['status'] ?? '') === 'active'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked' AND actor_type = 'cli'")->fetchColumn() === $invalidCliAuthorizationExpiryAuditBefore,
    'Core CLI payment authorization expiry rejects invalid or out-of-bounds limits before marking grants or audit'
);
core_payment_check(
    (int) ($authorizationExpiryInvalidLimitNoConfig['code'] ?? 0) === 1
    && (string) ($authorizationExpiryInvalidLimitNoConfigJson['status'] ?? '') === 'Failed'
    && str_contains((string) ($authorizationExpiryInvalidLimitNoConfigJson['error'] ?? ''), 'positive integer')
    && (string) ($authorizationExpiryInvalidLimitNoConfig['stderr'] ?? '') === ''
    && !str_contains((string) ($authorizationExpiryInvalidLimitNoConfig['stdout'] ?? ''), 'config/app.php'),
    'Core CLI payment authorization expiry rejects invalid limits before loading site configuration'
);
$cliAuthorizationExpiryAuditFailureRoot = $tmpRoot . '/payment-cli-authorization-expiry-audit-failure';
mkdir($cliAuthorizationExpiryAuditFailureRoot . '/config', 0775, true);
mkdir($cliAuthorizationExpiryAuditFailureRoot . '/storage/logs', 0775, true);
$cliAuthorizationExpiryAuditFailureDbFile = $cliAuthorizationExpiryAuditFailureRoot . '/payment-cli-authorization-expiry-audit-failure.sqlite';
$cliAuthorizationExpiryAuditFailurePdo = new PDO('sqlite:' . $cliAuthorizationExpiryAuditFailureDbFile);
$cliAuthorizationExpiryAuditFailurePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new MigrationRunner($cliAuthorizationExpiryAuditFailurePdo, $migrations))->run();
$cliAuthorizationExpiryAuditFailureRepo = new PaymentRepository($cliAuthorizationExpiryAuditFailurePdo);
$cliAuthorizationExpiryAuditFailureSettings = new PaymentProviderSettingsRepository($cliAuthorizationExpiryAuditFailurePdo, 'core-payment-admin-settings-key');
$cliAuthorizationExpiryAuditFailureSettings->save(FixturePaymentProvider::PROVIDER_ID, 'CLI Audit Failure Fixture', 'enabled', ['mode' => 'cli-audit-failure'], ['api_secret' => 'sk_cli_authorization_expiry_audit_failure']);
$cliAuthorizationExpiryAuditFailureService = new PaymentService($cliAuthorizationExpiryAuditFailurePdo, $cliAuthorizationExpiryAuditFailureRepo);
$cliAuthorizationExpiryAuditFailurePayment = $cliAuthorizationExpiryAuditFailureService->createProviderPayment('paid_download', 'cli-authorization-expiry-audit-failure', FixturePaymentProvider::PROVIDER_ID, 810, 'USD', 'cli-authorization-expiry-audit-failure-payment');
$cliAuthorizationExpiryAuditFailureId = $cliAuthorizationExpiryAuditFailureRepo->insertAuthorization([
    'payment_id' => (int) ($cliAuthorizationExpiryAuditFailurePayment['id'] ?? 0),
    'subject_type' => 'paid_download',
    'subject_id' => 'cli-authorization-expiry-audit-failure',
    'token_hash' => hash('sha256', 'cli-authorization-expiry-audit-failure-token'),
    'status' => 'active',
    'max_uses' => 1,
    'used_count' => 0,
    'expires_at' => gmdate('c', time() + 3600),
    'metadata' => [],
]);
$cliAuthorizationExpiryAuditFailurePdo->prepare('UPDATE cms_payment_authorizations SET expires_at = :expires_at WHERE id = :id')->execute([
    ':id' => $cliAuthorizationExpiryAuditFailureId,
    ':expires_at' => gmdate('c', time() - 120),
]);
$cliAuthorizationExpiryAuditFailurePdo->exec("CREATE TRIGGER core_payment_fail_cli_authorization_expiry_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action = 'payment.authorization.expired_marked' BEGIN SELECT RAISE(ABORT, 'cli authorization expiry audit failure secret=raw-leak'); END");
file_put_contents($cliAuthorizationExpiryAuditFailureRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
    'database' => ['dsn' => 'sqlite:' . $cliAuthorizationExpiryAuditFailureDbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
], true) . ";\n");
$cliAuthorizationExpiryAuditFailure = core_payment_run_cli_raw($cliAuthorizationExpiryAuditFailureRoot, 'expire-payment-authorizations', ['10']);
$cliAuthorizationExpiryAuditFailureJson = json_decode((string) ($cliAuthorizationExpiryAuditFailure['stdout'] ?? ''), true) ?: [];
$cliAuthorizationExpiryAuditFailureAfter = $cliAuthorizationExpiryAuditFailureRepo->authorization($cliAuthorizationExpiryAuditFailureId);
core_payment_check(
    (int) ($cliAuthorizationExpiryAuditFailure['code'] ?? 0) === 1
    && (string) ($cliAuthorizationExpiryAuditFailureJson['status'] ?? '') === 'Failed'
    && (string) ($cliAuthorizationExpiryAuditFailureJson['error'] ?? '') === '[invalid]'
    && !str_contains((string) ($cliAuthorizationExpiryAuditFailure['stdout'] ?? ''), 'payment_token=raw-leak')
    && !str_contains((string) ($cliAuthorizationExpiryAuditFailure['stdout'] ?? ''), 'secret=raw-leak')
    && is_array($cliAuthorizationExpiryAuditFailureAfter)
    && (string) ($cliAuthorizationExpiryAuditFailureAfter['status'] ?? '') === 'active'
    && (int) $cliAuthorizationExpiryAuditFailurePdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . $cliAuthorizationExpiryAuditFailureId . " AND event_type = 'expired'")->fetchColumn() === 0,
    'Core CLI payment authorization expiry rolls back authorization state, events and sanitizes failure output when audit persistence fails'
);
$cliExpiry = core_payment_run_cli($tmpRoot, 'expire-payment-authorizations', ['10']);
$cliExpiredAuthorizationAfterSweep = $adminRepo->authorization((int) ($cliExpiredAuthorization['id'] ?? 0));
core_payment_check(
    ($cliExpiry['status'] ?? '') === 'Completed'
    && (int) ($cliExpiry['expired'] ?? 0) === 1
    && (int) ($cliExpiry['limit'] ?? 0) === 10
    && is_array($cliExpiredAuthorizationAfterSweep)
    && (string) ($cliExpiredAuthorizationAfterSweep['status'] ?? '') === 'expired'
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_payment_authorization_events WHERE authorization_id = " . (int) ($cliExpiredAuthorization['id'] ?? 0) . " AND event_type = 'expired'")->fetchColumn() === 1
    && (int) $adminPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'payment.authorization.expired_marked' AND actor_type = 'cli' AND context_json LIKE '%\"count\":1%' AND context_json LIKE '%\"limit\":10%'")->fetchColumn() === 1,
    'Core CLI payment authorization expiry marks stale grants and writes audit for scheduled maintenance'
);
$badTokenConfigPaymentCountBefore = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
$badPaidContentTtl = new PaidContentService($adminPdo, Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    'payment' => ['paid_content_token_ttl_seconds' => ' 3600 '],
]));
$badPaidDownloadTtl = new PaidDownloadService($adminPdo, Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    'payment' => ['paid_download_token_ttl_seconds' => ' 3600 ', 'paid_download_token_max_uses' => 1],
]));
$badPaidDownloadMaxUses = new PaidDownloadService($adminPdo, Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => 'core-payment-admin-settings-key'],
    'payment' => ['paid_download_token_ttl_seconds' => 3600, 'paid_download_token_max_uses' => ' 1 '],
]));
core_payment_throws(
    static fn () => $badPaidContentTtl->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-bad-token-ttl-' . $paidArticleId),
    'Core paid content checkout rejects non-canonical token TTL before payment creation'
);
core_payment_throws(
    static fn () => $badPaidDownloadTtl->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-bad-token-ttl-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download checkout rejects non-canonical token TTL before payment creation'
);
core_payment_throws(
    static fn () => $badPaidDownloadMaxUses->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-bad-token-max-uses-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download checkout rejects non-canonical token max uses before payment creation'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $badTokenConfigPaymentCountBefore,
    'Core paid checkout token configuration failures do not create payment records'
);
$noKeySettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => ''],
]);
$noKeyPaidContent = new PaidContentService($adminPdo, $noKeySettings);
$noKeyPaidDownloads = new PaidDownloadService($adminPdo, $noKeySettings);
$paymentCountBeforeNoKey = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
core_payment_throws(
    static fn () => $noKeyPaidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-no-key-' . $paidArticleId),
    'Core paid content checkout fails closed before payment creation when token signing key is missing'
);
core_payment_throws(
    static fn () => $noKeyPaidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-no-key-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download checkout fails closed before payment creation when token signing key is missing'
);
core_payment_check((int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeNoKey, 'Core payment token signing key failure does not create trusted payment records');
core_payment_check(
    !$noKeyPaidContent->isAuthorized($paidArticleId, (string) ($paidContentQuery['payment_token'] ?? ''))
    && !$noKeyPaidDownloads->isAuthorized($paidContentId, $paidMediaId, (string) ($downloadQuery['payment_token'] ?? '')),
    'Core payment token authorization fails closed when signing key is missing'
);
$nonCanonicalKeySettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => ' core-payment-admin-settings-key '],
]);
$nonStringKeySettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $dbFile, 'username' => '', 'password' => '', 'options' => []],
    'security' => ['encryption_key' => true],
]);
$badKeyPaidContent = new PaidContentService($adminPdo, $nonCanonicalKeySettings);
$badKeyPaidDownloads = new PaidDownloadService($adminPdo, $nonStringKeySettings);
$paymentCountBeforeBadKey = (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn();
core_payment_throws(
    static fn () => $badKeyPaidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-bad-signing-key-' . $paidArticleId),
    'Core paid content checkout rejects non-canonical token signing keys before payment creation'
);
core_payment_throws(
    static fn () => $badKeyPaidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-bad-signing-key-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download checkout rejects non-string token signing keys before payment creation'
);
core_payment_check(
    (int) $adminPdo->query('SELECT COUNT(*) FROM cms_payments')->fetchColumn() === $paymentCountBeforeBadKey,
    'Core payment invalid token signing keys do not create trusted payment records'
);
$adminProviderSettings->save(FixturePaymentProvider::PROVIDER_ID, 'Admin Fixture', 'disabled', ['mode' => 'admin-test'], []);
core_payment_throws(
    static fn () => $adminService->createProviderPayment('paid_content', 'disabled-service', FixturePaymentProvider::PROVIDER_ID, 100, 'USD', 'service-disabled-provider'),
    'Core PaymentService fails closed when provider is disabled'
);
core_payment_throws(
    static fn () => $paidContent->checkout($paidArticleId, FixturePaymentProvider::PROVIDER_ID, 'paid-content-disabled-' . $paidArticleId),
    'Core paid content checkout fails closed when provider is disabled'
);
core_payment_throws(
    static fn () => $paidDownloads->checkout($paidContentId, $paidMediaId, FixturePaymentProvider::PROVIDER_ID, 'paid-download-disabled-' . $paidContentId . '-' . $paidMediaId),
    'Core paid download checkout fails closed when provider is disabled'
);
unset($_SESSION['admin_user']);

if ($failures > 0) {
    echo '[RESULT] core_payment_foundation failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] core_payment_foundation passed.' . PHP_EOL;
