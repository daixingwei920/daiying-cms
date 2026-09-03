<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Payment\PaymentService;

final class ConfigCurrencyFilterProvider implements PaymentProviderInterface
{
    public function __construct(private readonly string $id, private readonly string $label)
    {
    }

    public function providerId(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->label;
    }

    public function capabilities(): array
    {
        return ['payment.create'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'created', 'created', false, $this->id . '-remote', ['status' => 'pending']);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_payment_provider_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id VARCHAR(96) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    public_config_json TEXT NOT NULL,
    secret_config_ciphertext TEXT,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL
)');
$pdo->exec('CREATE TABLE cms_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_type VARCHAR(96) NOT NULL,
    subject_id VARCHAR(191) NOT NULL,
    provider_id VARCHAR(96) NOT NULL,
    remote_id VARCHAR(191) NOT NULL,
    reference VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    amount_minor INTEGER NOT NULL,
    currency VARCHAR(3) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    metadata_json TEXT NOT NULL,
    authorized_at VARCHAR(64),
    paid_at VARCHAR(64),
    failed_at VARCHAR(64),
    cancelled_at VARCHAR(64),
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL
)');

PaymentProviderRegistry::clear();
PaymentProviderRegistry::register('test.usd-provider', new ConfigCurrencyFilterProvider('test.usd-provider', 'USD Provider'));
PaymentProviderRegistry::register('test.cny-provider', new ConfigCurrencyFilterProvider('test.cny-provider', 'CNY Provider'));

$now = gmdate('c');
$insert = $pdo->prepare('INSERT INTO cms_payment_provider_settings
    (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at)
    VALUES (:provider_id, :display_name, :status, :public_config_json, :secret_config_ciphertext, :created_at, :updated_at)');
$insert->execute([
    ':provider_id' => 'test.usd-provider',
    ':display_name' => 'USD Provider',
    ':status' => 'enabled',
    ':public_config_json' => json_encode(['currency' => 'USD', 'default_provider' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':secret_config_ciphertext' => '',
    ':created_at' => $now,
    ':updated_at' => $now,
]);
$insert->execute([
    ':provider_id' => 'test.cny-provider',
    ':display_name' => 'CNY Provider',
    ':status' => 'enabled',
    ':public_config_json' => json_encode(['currency' => 'CNY'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':secret_config_ciphertext' => '',
    ':created_at' => $now,
    ':updated_at' => $now,
]);

$settings = Settings::fromArray(['security' => ['encryption_key' => 'test-key']]);
$selector = new PaymentProviderSelector($pdo, $settings);
$service = new PaymentService($pdo, new PaymentRepository($pdo), 'test-key');
$failures = 0;

function currency_filter_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

currency_filter_check($selector->defaultProviderId('CNY') === 'test.cny-provider', 'CNY checkout skips the USD default provider');
currency_filter_check($selector->defaultProviderId('USD') === 'test.usd-provider', 'USD checkout keeps the USD default provider');
currency_filter_check(array_column($selector->enabledProviders('CNY'), 'id') === ['test.cny-provider'], 'Selector CNY provider list excludes USD-configured providers');
currency_filter_check(array_column($service->enabledProviders('CNY'), 'id') === ['test.cny-provider'], 'PaymentService CNY provider list excludes USD-configured providers');

try {
    $selector->requireEnabled('test.usd-provider', 'CNY');
    currency_filter_check(false, 'Explicit USD provider selection is rejected for CNY checkout');
} catch (Cms\Core\Payment\PaymentException) {
    currency_filter_check(true, 'Explicit USD provider selection is rejected for CNY checkout');
}

try {
    $service->createProviderPayment('test_subject', 'subject:1', 'test.usd-provider', 100, 'CNY', 'currency-filter-explicit-mismatch');
    currency_filter_check(false, 'PaymentService rejects explicit provider/config currency mismatch before provider create');
} catch (Cms\Core\Payment\PaymentException) {
    currency_filter_check(true, 'PaymentService rejects explicit provider/config currency mismatch before provider create');
}

if ($failures > 0) {
    echo 'Payment provider config currency filter tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Payment provider config currency filter tests passed.' . PHP_EOL;
