<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Market\ExtensionSource;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderRedirectPolicyInterface;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentProviderSettingsSchemaInterface;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialExtensionTrustGrant;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
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
payment_provider_decoupling_market_regression($check);

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

function payment_provider_decoupling_market_regression(callable $check): void
{
    $fixture = payment_provider_decoupling_fixture_root();
    $root = $fixture['root'];
    $pdo = $fixture['pdo'];
    $providerId = 'official.decoupled-pay';
    $encryptionKey = 'decoupled-payment-secret-key';

    try {
        $registrySource = (string) file_get_contents(CMS_ROOT . '/system/core/Plugin/OfficialPluginRegistry.php');
        $check(!str_contains($registrySource, $providerId), 'unknown official payment provider id is not hard-coded in Core official registry.');

        [$zip, $authorization] = payment_provider_decoupling_package($providerId, '0.1.0', payment_provider_decoupling_trust_grant($providerId));
        $result = (new MarketPackageInstaller($root))->install($zip, $authorization, $pdo);
        $check(($result['status'] ?? '') === 'Installed' && ($result['type'] ?? '') === 'payment_provider', 'unknown payment provider installs through official market as payment_provider.');
        $check(payment_provider_decoupling_table_exists($pdo, 'decoupled_pay_events'), 'unknown payment provider market install runs its plugin migration.');
        $migrationStatus = $pdo->query("SELECT status FROM cms_plugin_migrations WHERE plugin_id = 'official.decoupled-pay' AND migration_id = 'decoupled_pay_001_create'")->fetchColumn();
        $check($migrationStatus === 'applied', 'unknown payment provider migration checksum is recorded as applied.');
        $source = $pdo->query("SELECT extension_type FROM cms_extension_sources WHERE extension_id = 'official.decoupled-pay'")->fetchColumn();
        $check($source === 'payment_provider', 'market source records payment_provider extension type.');
        $grantPrefixes = (new OfficialPluginRegistry($root, $pdo))->tablePrefixes($providerId);
        $check($grantPrefixes === ['decoupled_pay_'], 'Trust Grant supplies table prefixes without a Core static registry entry.');

        (new LocalPluginPackageInstaller($root, $pdo))->enable($providerId, 1);
        $status = $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.decoupled-pay'")->fetchColumn();
        $check($status === PluginLifecycle::ENABLED, 'unknown payment provider can be enabled after Trust Grant market install.');

        $manager = new PluginManager(
            $root . '/content/plugins',
            $pdo,
            new FileLogger($root . '/storage/logs/app.log'),
            new EventDispatcher(),
            new BlockRegistry(),
            null,
            new OfficialPluginRegistry($root, $pdo),
            null,
            Settings::load($root),
        );
        $check($manager->bootEnabled() === 1 && PaymentProviderRegistry::get($providerId) !== null, 'enabled unknown payment provider registers itself through PluginContext.');

        $controller = new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root);
        $schemaMethod = new ReflectionMethod($controller, 'paymentProviderSchemaFields');
        $fields = (string) $schemaMethod->invoke($controller, $providerId, ['merchant_id' => 'mid_unknown', 'currency' => 'USD'], ['api_secret']);
        $check(str_contains($fields, 'Decoupled Merchant') && str_contains($fields, 'name="decoupled_pay_secret"'), 'admin settings form is generated from unknown provider schema.');

        $publicMethod = new ReflectionMethod($controller, 'paymentProviderPublicConfigFromRequest');
        $secretMethod = new ReflectionMethod($controller, 'paymentProviderSecretsFromRequest');
        $request = new Request('POST', '/admin/payments/providers/save', [], [
            'public_config_json' => '{}',
            'decoupled_pay_merchant' => 'mid_unknown',
            'decoupled_pay_mode' => 'test',
            'decoupled_pay_currency' => ' usd ',
            'decoupled_pay_secret' => ' dp_secret_123456 ',
        ]);
        $publicConfig = $publicMethod->invoke($controller, $request, $providerId);
        $secretConfig = $secretMethod->invoke($controller, $request, $providerId);
        $settings = new PaymentProviderSettingsRepository($pdo, $encryptionKey);
        $settings->save($providerId, 'Decoupled Pay', 'enabled', is_array($publicConfig) ? $publicConfig : [], is_array($secretConfig) ? $secretConfig : []);
        $row = $settings->setting($providerId) ?? [];
        $ciphertext = (string) ($row['secret_config_ciphertext'] ?? '');
        $check(($settings->secrets($providerId)['api_secret'] ?? '') === 'dp_secret_123456', 'unknown provider secret decrypts server-side through Core settings repository.');
        $check($ciphertext !== '' && !str_contains($ciphertext, 'dp_secret_123456'), 'unknown provider secret is encrypted at rest.');

        $service = new PaymentService($pdo, new PaymentRepository($pdo), $encryptionKey);
        $payment = $service->createProviderPayment('commerce_order', 'order:100', $providerId, 390, 'USD', 'unknown-provider-ok');
        $check(($payment['provider_id'] ?? '') === $providerId && ($payment['status'] ?? '') === 'pending', 'unknown provider can create a payment through Core PaymentService.');
        $check(str_starts_with((string) ($payment['_provider_checkout_url'] ?? ''), 'https://checkout.decoupled-pay.example/pay/session-'), 'unknown provider redirect URL is accepted by its own redirect policy.');

        try {
            $service->createProviderPayment('commerce_order', 'order:101', $providerId, 390, 'USD', 'unknown-provider-unsafe', 'success', ['redirect_variant' => 'unsafe']);
            $check(false, 'unknown provider redirect policy rejects unsafe provider checkout URL.');
        } catch (PaymentException) {
            $check(true, 'unknown provider redirect policy rejects unsafe provider checkout URL.');
        }
    } finally {
        foreach (glob(sys_get_temp_dir() . '/daiying-decoupled-pay-*.zip') ?: [] as $zip) {
            @unlink($zip);
        }
        market_plugin_decoupling_remove_tree($root);
    }
}

/** @return array{root:string,pdo:PDO} */
function payment_provider_decoupling_fixture_root(): array
{
    $keys = payment_provider_decoupling_test_keys();
    $root = sys_get_temp_dir() . '/daiying-payment-provider-decoupling-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    mkdir($root . '/content/plugins', 0777, true);
    mkdir($root . '/content/themes', 0777, true);
    mkdir($root . '/storage/market/tmp', 0777, true);
    mkdir($root . '/storage/logs', 0777, true);
    file_put_contents($root . '/config/app.php', "<?php\nreturn ['app' => ['version' => '1.2.49'], 'updates' => ['public_key' => '" . $keys['public'] . "', 'key_id' => 'test-key'], 'security' => ['encryption_key' => 'decoupled-payment-secret-key']];\n");

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE cms_plugins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plugin_id TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        version TEXT NOT NULL,
        author TEXT NOT NULL,
        status TEXT NOT NULL,
        trust_level TEXT NOT NULL,
        capabilities_json TEXT NOT NULL,
        installed_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        source TEXT,
        review_status TEXT,
        dependencies_json TEXT,
        optional_dependencies_json TEXT,
        data_policy_json TEXT,
        data_schema_version TEXT,
        dormant_data_json TEXT,
        removed_at TEXT,
        last_error TEXT,
        table_prefixes_json TEXT
    )');
    $pdo->exec('CREATE TABLE cms_extension_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        extension_id TEXT NOT NULL,
        extension_type TEXT NOT NULL,
        source TEXT NOT NULL,
        market_id TEXT,
        version TEXT NOT NULL,
        installed_at TEXT NOT NULL,
        metadata_json TEXT
    )');
    $pdo->exec('CREATE TABLE cms_market_install_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        market_id TEXT NOT NULL,
        extension_id TEXT NOT NULL,
        extension_type TEXT NOT NULL,
        status TEXT NOT NULL,
        plan_json TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE cms_plugin_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plugin_id TEXT NOT NULL,
        plugin_version TEXT NOT NULL,
        migration_id TEXT NOT NULL,
        checksum TEXT NOT NULL,
        status TEXT NOT NULL,
        affected_objects_json TEXT,
        started_at TEXT,
        completed_at TEXT,
        rollback_at TEXT,
        error_code TEXT,
        error_summary TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE cms_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_type TEXT NOT NULL,
        actor_id INTEGER,
        action TEXT NOT NULL,
        context_json TEXT,
        created_at TEXT NOT NULL
    )');
    $trustGrantMigration = require CMS_ROOT . '/system/migrations/2026_09_10_000001_extension_trust_grants.php';
    $trustGrantMigration['up']($pdo);
    $paymentMigration = require CMS_ROOT . '/system/migrations/2026_08_20_000001_core_payment_schema.php';
    $paymentMigration->up($pdo);

    return ['root' => $root, 'pdo' => $pdo];
}

/** @return array{public:string,secret:string} */
function payment_provider_decoupling_test_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    if (!function_exists('sodium_crypto_sign_seed_keypair')) {
        throw new RuntimeException('Sodium is required for signed trust grant tests.');
    }
    $keypair = sodium_crypto_sign_seed_keypair(str_repeat("\x51", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $keys = [
        'public' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        'secret' => sodium_crypto_sign_secretkey($keypair),
    ];

    return $keys;
}

function payment_provider_decoupling_trust_grant(string $extensionId): array
{
    $payload = [
        'schema_version' => 1,
        'extension_id' => $extensionId,
        'extension_type' => 'payment_provider',
        'publisher' => 'official',
        'source' => 'official_market',
        'trust_level' => 'trusted_php',
        'capability_namespaces' => ['decoupled_pay'],
        'table_prefixes' => ['decoupled_pay_'],
        'route_prefixes' => ['/payment/webhooks/' . $extensionId],
        'admin_menu' => ['section' => '商业', 'label' => 'Decoupled Pay'],
        'provider_capabilities' => ['payment' => ['create']],
        'status' => 'active',
        'issued_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + 2592000),
    ];
    $canonical = OfficialExtensionTrustGrant::canonicalPayload($payload);
    $signature = sodium_crypto_sign_detached($canonical, payment_provider_decoupling_test_keys()['secret']);

    return [
        'payload' => $payload,
        'signature' => base64_encode($signature),
        'grant_fingerprint' => OfficialExtensionTrustGrant::fingerprint($payload),
        'key_id' => 'test-key',
    ];
}

function payment_provider_decoupling_package(string $providerId, string $version, array $trustGrant): array
{
    $files = [
        'content/plugins/' . $providerId . '/plugin.json' => payment_provider_decoupling_plugin_manifest($providerId, $version),
        'content/plugins/' . $providerId . '/plugin.php' => payment_provider_decoupling_plugin_entry(),
        'content/plugins/' . $providerId . '/src/DecoupledPayProvider.php' => payment_provider_decoupling_provider_source($providerId),
        'content/plugins/' . $providerId . '/migrations/001_create.php' => payment_provider_decoupling_migration_source(),
    ];
    $manifestFiles = [];
    foreach ($files as $path => $content) {
        $manifestFiles[$path] = hash('sha256', $content);
    }
    $marketManifest = [
        'extension_id' => $providerId,
        'type' => 'payment_provider',
        'version' => $version,
        'source' => ExtensionSource::OFFICIAL_MARKET,
        'review_status' => 'published',
        'core' => '>=1.2.0',
        'php' => '>=8.3.0',
        'dependencies' => [],
        'files' => $manifestFiles,
    ];

    $zipPath = sys_get_temp_dir() . '/daiying-decoupled-pay-' . bin2hex(random_bytes(5)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create decoupled payment provider test package.');
    }
    $zip->addFromString('market-package.json', json_encode($marketManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();

    $sha = hash_file('sha256', $zipPath);

    return [$zipPath, new InstallAuthorization('test-token', 'https://updates.daiyingcms.com/decoupled-pay.zip', gmdate('c', time() + 3600), (string) $sha, 'test-market', $trustGrant)];
}

function payment_provider_decoupling_plugin_manifest(string $providerId, string $version): string
{
    return json_encode([
        'plugin_id' => $providerId,
        'name' => 'Decoupled Payment Provider',
        'version' => $version,
        'author' => 'Daiying CMS',
        'package_type' => 'payment_provider',
        'type' => 'payment_provider',
        'core' => ['min' => '1.2.0'],
        'php' => '>=8.3.0',
        'entry' => 'plugin.php',
        'trust_level' => 'trusted_php',
        'capabilities' => ['payment.provider'],
        'capability_namespaces' => ['decoupled_pay'],
        'table_prefixes' => ['decoupled_pay_'],
        'migrations' => ['migrations/001_create.php'],
        'data_policy' => ['uninstall' => 'retain'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function payment_provider_decoupling_plugin_entry(): string
{
    return <<<'PHP'
<?php

use Cms\Core\Plugin\PluginContext;
use OfficialDecoupledPay\DecoupledPayProvider;

require_once __DIR__ . '/src/DecoupledPayProvider.php';

return static function (PluginContext $context): void {
    $context->registerPaymentProvider(new DecoupledPayProvider());
};
PHP;
}

function payment_provider_decoupling_provider_source(string $providerId): string
{
    return <<<PHP
<?php

declare(strict_types=1);

namespace OfficialDecoupledPay;

use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderRedirectPolicyInterface;
use Cms\Core\Payment\PaymentProviderSettingsSchemaInterface;
use Cms\Core\Payment\PaymentResult;

final class DecoupledPayProvider implements PaymentProviderInterface, PaymentProviderSettingsSchemaInterface, PaymentProviderRedirectPolicyInterface
{
    public function providerId(): string
    {
        return '{$providerId}';
    }

    public function displayName(): string
    {
        return 'Decoupled Pay';
    }

    public function capabilities(): array
    {
        return ['payment.create'];
    }

    public function createPayment(object \$command): PaymentResult
    {
        \$public = is_array(\$command->provider_public_config ?? null) ? \$command->provider_public_config : [];
        \$secrets = is_array(\$command->provider_secret_config ?? null) ? \$command->provider_secret_config : [];
        if ((string) (\$public['mode'] ?? '') !== 'test' || (string) (\$secrets['api_secret'] ?? '') !== 'dp_secret_123456') {
            return new PaymentResult(false, 'decoupled_not_configured', 'Provider settings were not available.', false, 'decoupled-failed', ['status' => 'failed']);
        }
        \$metadata = is_array(\$command->metadata ?? null) ? \$command->metadata : [];
        \$url = (\$metadata['redirect_variant'] ?? '') === 'unsafe'
            ? 'https://generic.example.test/checkout'
            : 'https://checkout.decoupled-pay.example/pay/session-' . substr(hash('sha256', (string) (\$command->idempotency_key ?? '')), 0, 12);

        return new PaymentResult(true, 'decoupled_created', 'Decoupled payment created.', false, 'decoupled-' . substr(hash('sha256', (string) (\$command->idempotency_key ?? '')), 0, 16), [
            'status' => 'pending',
            'provider_payment_id' => 'dp_' . substr(hash('sha256', \$url), 0, 16),
            'checkout_url' => \$url,
            'requires_buyer_action' => true,
        ]);
    }

    public function capturePayment(object \$command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function cancelPayment(object \$command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function refundPayment(object \$command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function getPaymentStatus(object \$command): PaymentResult
    {
        return new PaymentResult(false, 'unsupported', 'unsupported');
    }

    public function settingsSchema(): array
    {
        return [
            ['name' => 'merchant_id', 'input_name' => 'decoupled_pay_merchant', 'key' => 'merchant_id', 'label' => 'Decoupled Merchant', 'type' => 'text', 'required' => true, 'max_length' => 64],
            ['name' => 'api_secret', 'input_name' => 'decoupled_pay_secret', 'key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'secret' => true, 'clearable' => true, 'min_length' => 8],
            ['name' => 'mode', 'input_name' => 'decoupled_pay_mode', 'key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => [['value' => 'test', 'label' => 'Test'], ['value' => 'live', 'label' => 'Live']], 'default' => 'test'],
            ['name' => 'currency', 'input_name' => 'decoupled_pay_currency', 'key' => 'currency', 'label' => 'Currency', 'type' => 'text', 'uppercase' => true, 'allowed_values' => ['USD', 'CNY']],
        ];
    }

    public function help(): array
    {
        return ['summary' => 'Decoupled test provider rendered entirely from schema.'];
    }

    public function webhookMetadata(): array
    {
        return ['path' => '/payment/webhooks/{$providerId}'];
    }

    public function isSafeRedirectUrl(string \$url): bool
    {
        \$parts = parse_url(\$url);
        return is_array(\$parts)
            && strtolower((string) (\$parts['scheme'] ?? '')) === 'https'
            && strtolower((string) (\$parts['host'] ?? '')) === 'checkout.decoupled-pay.example'
            && !isset(\$parts['user'], \$parts['pass'], \$parts['port'], \$parts['query'], \$parts['fragment'])
            && preg_match('#^/pay/session-[a-f0-9]{12}$#', (string) (\$parts['path'] ?? '')) === 1;
    }
}
PHP;
}

function payment_provider_decoupling_migration_source(): string
{
    return <<<'PHP'
<?php
return [
    'id' => 'decoupled_pay_001_create',
    'affected_objects' => ['table:decoupled_pay_events'],
    'up' => static function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE decoupled_pay_events (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');
    },
    'down' => static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS decoupled_pay_events');
    },
];
PHP;
}

function payment_provider_decoupling_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);

    return $stmt->fetchColumn() === $table;
}
