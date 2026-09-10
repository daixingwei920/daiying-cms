<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderRedirectPolicyInterface;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentProviderSettingsSchemaInterface;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Plugin\PluginMenuItem;
use Cms\Core\Support\View;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

final class DecouplingPaymentProvider implements PaymentProviderInterface, PaymentProviderRedirectPolicyInterface, PaymentProviderSettingsSchemaInterface
{
    public function providerId(): string
    {
        return 'vendor.decoupled-pay';
    }

    public function displayName(): string
    {
        return 'Decoupled Pay';
    }

    public function capabilities(): array
    {
        return ['payment.create'];
    }

    public function createPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'created', 'ok');
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

    public function isSafeRedirectUrl(string $url): bool
    {
        return $url === 'https://checkout.example.test/session/ok#provider-fragment';
    }

    public function settingsSchema(): array
    {
        return [
            [
                'name' => 'merchant_id',
                'input_name' => 'decoupled_merchant_id',
                'key' => 'merchant_id',
                'label' => 'Merchant ID',
                'type' => 'text',
                'required' => true,
                'max_length' => 64,
            ],
            [
                'name' => 'api_secret',
                'input_name' => 'decoupled_api_secret',
                'key' => 'api_secret',
                'label' => 'API Secret',
                'type' => 'password',
                'secret' => true,
                'clearable' => true,
                'compact' => true,
                'min_length' => 8,
            ],
            [
                'name' => 'mode',
                'input_name' => 'decoupled_mode',
                'key' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'options' => [
                    ['value' => 'test', 'label' => 'Test'],
                    ['value' => 'live', 'label' => 'Live'],
                ],
                'default' => 'test',
            ],
            [
                'name' => 'settlement_currency',
                'input_name' => 'decoupled_currency',
                'key' => 'currency',
                'label' => 'Currency',
                'type' => 'text',
                'uppercase' => true,
                'allowed_values' => ['USD', 'CNY'],
            ],
        ];
    }

    public function help(): array
    {
        return ['summary' => 'Schema-driven provider fields.'];
    }

    public function webhookMetadata(): array
    {
        return ['path' => '/payment/webhooks/vendor.decoupled-pay'];
    }
}

$_SERVER['REQUEST_URI'] = '/admin/mail';
View::setAdminPluginMenus([
    new PluginMenuItem('official.mail', '邮件中心', '/admin/mail', 'mail.read', '通信', 'bell', 5, '通信', '2'),
]);
$html = View::page('邮件中心', '<h1>邮件中心</h1>');
$check(str_contains($html, '通信'), 'plugin admin menu can declare its own sidebar section metadata.');
$check(str_contains($html, '邮件中心 2'), 'plugin admin menu can render a metadata badge without hard-coded plugin ids.');
$check(str_contains($html, '<span>通信</span>'), 'plugin admin breadcrumb can use plugin metadata.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id TEXT,
    subject_type TEXT,
    subject_id TEXT,
    provider_id TEXT,
    status TEXT,
    amount_minor INTEGER,
    currency TEXT,
    provider_payment_id TEXT,
    idempotency_key TEXT,
    metadata_json TEXT,
    created_at TEXT,
    updated_at TEXT
)');
$provider = new DecouplingPaymentProvider();
PaymentProviderRegistry::register($provider->providerId(), $provider);
$service = new PaymentService($pdo, new PaymentRepository($pdo), '');
$redirectMethod = new ReflectionMethod($service, 'isSafeProviderRedirectUrlForProvider');
$check($redirectMethod->invoke($service, 'vendor.decoupled-pay', 'https://checkout.example.test/session/ok#provider-fragment') === true, 'payment provider redirect policy interface is used before Core generic URL policy.');
$check($redirectMethod->invoke($service, 'vendor.decoupled-pay', 'https://checkout.example.test/session/nope#provider-fragment') === false, 'payment provider redirect policy still rejects URLs outside its own policy.');
$check($redirectMethod->invoke($service, 'vendor.decoupled-pay', 'https://checkout.example.test/session/nope') === false, 'payment provider redirect policy is authoritative even when Core generic URL policy would accept the URL.');

$root = sys_get_temp_dir() . '/daiying-payment-schema-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0777, true);
mkdir($root . '/storage/logs', 0777, true);
file_put_contents($root . '/config/app.php', "<?php\nreturn ['app' => ['version' => '1.2.48'], 'security' => ['encryption_key' => 'test']];\n");
$controller = new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root);
$schemaMethod = new ReflectionMethod($controller, 'paymentProviderSchemaFields');
$fields = (string) $schemaMethod->invoke($controller, 'vendor.decoupled-pay', ['merchant_id' => 'mid_123', 'currency' => 'USD'], ['api_secret']);
$check(str_contains($fields, 'Merchant ID') && str_contains($fields, 'mid_123'), 'payment settings UI can render provider-declared public schema fields.');
$check(str_contains($fields, 'API Secret') && str_contains($fields, '已配置，留空则保留'), 'payment settings UI can render provider-declared secret schema fields as masked placeholders.');
$check(str_contains($fields, 'name="decoupled_merchant_id"') && str_contains($fields, 'name="decoupled_api_secret"'), 'payment settings schema can decouple HTML input names from stored config keys.');
$check(str_contains($fields, 'provider_schema_clear_api_secret'), 'payment settings schema can expose provider-declared secret clearing controls.');

$advancedMethod = new ReflectionMethod($controller, 'paymentProviderAdvancedJson');
$advanced = (string) $advancedMethod->invoke($controller, ['merchant_id' => 'mid_123', 'mode' => 'live', 'currency' => 'USD', 'custom' => 'kept'], 'vendor.decoupled-pay');
$check($advanced === '{"custom":"kept"}', 'payment settings advanced JSON removes provider-declared visual public fields.');

$publicMethod = new ReflectionMethod($controller, 'paymentProviderPublicConfigFromRequest');
$request = new Request('POST', '/admin/payments/providers/save', [], [
    'public_config_json' => '{"custom":"kept","merchant_id":"old"}',
    'decoupled_merchant_id' => 'mid_456',
    'decoupled_mode' => 'live',
    'decoupled_currency' => ' cny ',
]);
$public = $publicMethod->invoke($controller, $request, 'vendor.decoupled-pay');
$check(is_array($public) && ($public['merchant_id'] ?? '') === 'mid_456' && ($public['mode'] ?? '') === 'live' && ($public['currency'] ?? '') === 'CNY' && ($public['custom'] ?? '') === 'kept', 'payment settings save reads provider schema input names and stores provider schema config keys.');

$badRequest = new Request('POST', '/admin/payments/providers/save', [], [
    'public_config_json' => '{}',
    'decoupled_merchant_id' => 'mid_456',
    'decoupled_mode' => 'invalid',
]);
try {
    $publicMethod->invoke($controller, $badRequest, 'vendor.decoupled-pay');
    $check(false, 'payment settings save rejects provider schema values outside declared options.');
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $check($exception->getPrevious() instanceof PaymentException || $exception instanceof PaymentException, 'payment settings save rejects provider schema values outside declared options.');
}

$secretMethod = new ReflectionMethod($controller, 'paymentProviderSecretsFromRequest');
$secretRequest = new Request('POST', '/admin/payments/providers/save', [], [
    'secrets_text' => '',
    'decoupled_api_secret' => ' sk_test_abc123 ',
]);
$secrets = $secretMethod->invoke($controller, $secretRequest, 'vendor.decoupled-pay');
$check(is_array($secrets) && ($secrets['api_secret'] ?? '') === 'sk_test_abc123', 'payment settings save stores provider schema secret fields under provider schema config keys.');

$clearRequest = new Request('POST', '/admin/payments/providers/save', [], [
    'secrets_text' => '',
    'decoupled_api_secret' => '',
    'provider_schema_clear_api_secret' => '1',
]);
$clearSecrets = $secretMethod->invoke($controller, $clearRequest, 'vendor.decoupled-pay');
$check(is_array($clearSecrets) && ($clearSecrets['api_secret'] ?? '') === PaymentProviderSettingsRepository::CLEAR_SECRET_VALUE, 'payment settings save supports provider schema secret clearing without hard-coded provider ids.');

PaymentProviderRegistry::clear();
market_plugin_decoupling_remove_tree($root);

if ($failures > 0) {
    echo 'plugin_decoupling_foundation failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'plugin_decoupling_foundation: PASS' . PHP_EOL;

function market_plugin_decoupling_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
