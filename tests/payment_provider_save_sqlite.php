<?php
declare(strict_types=1);
define('TEST_SOURCE_ROOT', dirname(__DIR__));
define('CMS_ROOT', sys_get_temp_dir() . '/cms-provider-save-' . bin2hex(random_bytes(6)));
mkdir(CMS_ROOT, 0700);
mkdir(CMS_ROOT . '/sessions', 0700);
session_save_path(CMS_ROOT . '/sessions');
require TEST_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Payment\PaymentProviderConfigurationInterface;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentProviderSettingsSchemaInterface;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentTransaction;

final class ConfiguredSaveFixture implements PaymentProviderInterface, PaymentProviderConfigurationInterface, PaymentProviderSettingsSchemaInterface
{
    public function providerId(): string { return 'fixture.configured-payment'; }
    public function displayName(): string { return 'TEST ONLY configured provider'; }
    public function capabilities(): array { return ['payment.create']; }
    public function help(): array { return []; }
    public function webhookMetadata(): array { return []; }
    public function createPayment(object $command): PaymentResult { throw new RuntimeException('No payment calls allowed'); }
    public function capturePayment(object $command): PaymentResult { throw new RuntimeException('No payment calls allowed'); }
    public function cancelPayment(object $command): PaymentResult { throw new RuntimeException('No payment calls allowed'); }
    public function refundPayment(object $command): PaymentResult { throw new RuntimeException('No payment calls allowed'); }
    public function getPaymentStatus(object $command): PaymentResult { throw new RuntimeException('No payment calls allowed'); }
    public function settingsSchema(): array
    {
        return [
            ['name'=>'merchant_id','input_name'=>'fixture_merchant','label'=>'Fixture merchant','type'=>'text'],
            ['name'=>'merchant_key','input_name'=>'fixture_secret','label'=>'Fixture secret','type'=>'password','secret'=>true,'clearable'=>true],
        ];
    }
    public function isConfigured(array $publicConfig, array $maskedSecrets): bool { return $this->diagnostics($publicConfig, $maskedSecrets) === []; }
    public function diagnostics(array $publicConfig, array $maskedSecrets): array
    {
        return ($publicConfig['merchant_id']??'')==='fixture' && !empty($maskedSecrets['merchant_key']) ? [] : ['Missing fixture merchant or key'];
    }
}

register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMS_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir(CMS_ROOT);
});
try {
    SessionManager::start(false);
    $settings = Settings::fromArray([
        'app' => ['version' => '1.2.91'],
        'database' => ['dsn' => 'sqlite:' . CMS_ROOT . '/fixture.sqlite', 'options' => [PDO::ATTR_TIMEOUT => 1]],
        'security' => ['encryption_key' => 'isolated-provider-save-test-only'],
        'site' => ['url' => 'https://isolated.example', 'currency' => 'CNY'],
    ]);
    $pdo = ConnectionFactory::make($settings);
    (new MigrationRunner($pdo, array_map(static fn (string $f) => require $f, glob(TEST_SOURCE_ROOT . '/system/migrations/*.php'))))->run();
    $auth = new AdminAuthenticator($pdo);
    $auth->createAdmin('owner@example.invalid', 'Synthetic-provider-save-only!', 'Fixture Owner');
    if (!$auth->attempt('owner@example.invalid', 'Synthetic-provider-save-only!', '127.0.0.1')) {
        throw new RuntimeException('Native fixture login failed');
    }
    PaymentProviderRegistry::clear();
    PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
    $logger = new FileLogger(CMS_ROOT . '/storage/logs/app.log');
    mkdir(CMS_ROOT . '/config', 0700);
    foreach (['system/core', 'system/migrations'] as $dir) {
        mkdir(CMS_ROOT . '/' . $dir, 0700, true);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TEST_SOURCE_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            $target = CMS_ROOT . '/' . $dir . '/' . $files->getSubPathName();
            $file->isDir() ? mkdir($target, 0700) : copy($file->getPathname(), $target);
        }
    }
    copy(TEST_SOURCE_ROOT . '/system/core-manifest.json', CMS_ROOT . '/system/core-manifest.json');
    file_put_contents(CMS_ROOT . '/config/app.php', '<?php return ' . var_export($settings->all(), true) . ';');
    file_put_contents(CMS_ROOT . '/storage/installed.lock', 'isolated');
    $application = Application::boot(CMS_ROOT);
    $save = static fn (Request $request) => $application->handle($request);
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], [
        '_csrf' => CsrfToken::get(), 'provider_id' => ManualPaymentProvider::PROVIDER_ID,
        'display_name' => 'TEST ONLY manual', 'status' => 'disabled',
        'public_config_json' => '{}', 'secrets_text' => '',
    ]));
    if ($response->status() !== 302 || !str_contains($response->headers()['Location'] ?? '', '/admin/payments/providers?provider_id=')) {
        throw new RuntimeException('Expected native save redirect, got ' . $response->status() . '; log: ' . file_get_contents(CMS_ROOT . '/storage/logs/app.log'));
    }
    echo "[PASS] Native administrator saves a provider on file-backed SQLite\n";
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], [
        '_csrf' => CsrfToken::get(), 'provider_id' => ManualPaymentProvider::PROVIDER_ID,
        'display_name' => 'TEST ONLY manual', 'status' => 'enabled', 'default_provider' => '1',
        'public_config_json' => '{"instructions":"TEST ONLY no real collection"}', 'secrets_text' => '',
    ]));
    if ($response->status() !== 302 || !str_contains($response->headers()['Location'] ?? '', '/admin/payments/providers?provider_id=')) {
        throw new RuntimeException('Expected enabled default save redirect, got ' . $response->status() . '; log: ' . file_get_contents(CMS_ROOT . '/storage/logs/app.log'));
    }
    echo "[PASS] Native administrator enables and selects a default provider on file-backed SQLite\n";
    $provider = new ConfiguredSaveFixture();
    PaymentProviderRegistry::register($provider->providerId(), $provider);
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], [
        '_csrf' => CsrfToken::get(), 'provider_id' => $provider->providerId(),
        'display_name' => 'TEST ONLY configured', 'status' => 'enabled', 'default_provider' => '1',
        'public_config_json' => '{}', 'secrets_text' => '',
        'fixture_merchant' => 'fixture', 'fixture_secret' => 'synthetic-secret-only',
    ]));
    if ($response->status() !== 302 || !str_contains($response->headers()['Location'] ?? '', '/admin/payments/providers?provider_id=')) {
        throw new RuntimeException('Expected configured default save redirect, got ' . $response->status() . '; log: ' . file_get_contents(CMS_ROOT . '/storage/logs/app.log'));
    }
    echo "[PASS] Native administrator saves a schema-declared secret and configured default provider\n";
    $repo = new PaymentProviderSettingsRepository($pdo, 'isolated-provider-save-test-only');
    if ($repo->secrets($provider->providerId())['merchant_key'] !== 'synthetic-secret-only') {
        throw new RuntimeException('Schema secret was not encrypted and persisted');
    }
    $saveBody = [
        '_csrf' => CsrfToken::get(), 'provider_id' => $provider->providerId(),
        'display_name' => 'TEST ONLY after lock', 'status' => 'enabled', 'default_provider' => '1',
        'public_config_json' => '{}', 'secrets_text' => '',
        'fixture_merchant' => 'fixture', 'fixture_secret' => '',
    ];
    $rowsBefore = $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY id')->fetchAll();
    $writer = ConnectionFactory::make($settings);
    PaymentTransaction::beginImmediate($writer);
    try {
        $response = $save(new Request('POST', '/admin/payments/providers/save', [], $saveBody));
        if ($response->status() !== 503 || ($response->headers()['Retry-After'] ?? '') !== '3') {
            throw new RuntimeException('External SQLite writer must produce retryable503, got ' . $response->status());
        }
        if ($pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY id')->fetchAll() !== $rowsBefore) {
            throw new RuntimeException('A blocked save changed existing provider settings');
        }
    } finally {
        PaymentTransaction::rollback($writer);
    }
    echo "[PASS] External writer returns retryable503 without modifying settings\n";
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], $saveBody));
    if ($response->status() !== 302 || !str_contains($response->headers()['Location'] ?? '', 'saved=1')) {
        throw new RuntimeException('Retry after writer release must persist and redirect');
    }
    $repo = new PaymentProviderSettingsRepository($pdo, 'isolated-provider-save-test-only');
    if ($repo->secrets($provider->providerId())['merchant_key'] !== 'synthetic-secret-only') {
        throw new RuntimeException('Blank secret on retry must preserve the original encrypted key');
    }
    echo "[PASS] Retry after lock release succeeds and preserves the existing secret\n";
    $rowsBefore = $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY id')->fetchAll();
    $pdo->exec("CREATE TRIGGER reject_save_audit BEFORE INSERT ON cms_audit_logs WHEN NEW.action='payment.provider_settings.saved' BEGIN SELECT RAISE(ABORT,'fixture audit failure'); END");
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], array_replace($saveBody, ['display_name'=>'must roll back', 'fixture_secret'=>'rejected-rotation'])));
    if ($response->status() !== 500 || $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY id')->fetchAll() !== $rowsBefore) {
        throw new RuntimeException('Audit failure must roll back the entire settings/default/secret change');
    }
    $pdo->exec('DROP TRIGGER reject_save_audit');
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], $saveBody));
    if ($response->status() !== 302 || !str_contains($response->headers()['Location'] ?? '', 'saved=1')) {
        throw new RuntimeException('Rollback must not leave a writer lock behind');
    }
    echo "[PASS] Audit failure rolls back atomically and leaves no writer lock\n";
    $rowsBefore = $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY id')->fetchAll();
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], array_replace($saveBody, ['_csrf'=>'invalid'])));
    if ($response->status() !== 403) {
        throw new RuntimeException('CSRF protection must remain intact');
    }
    $response = $save(new Request('POST', '/admin/payments/providers/save', [], array_replace($saveBody, ['fixture_merchant'=>'invalid'])));
    if ($response->status() !== 400 || $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY id')->fetchAll() !== $rowsBefore) {
        throw new RuntimeException('Invalid provider configuration must remain rejected without writes');
    }
    echo "[PASS] CSRF and provider validation remain fail closed\n";
    echo "PROVIDER_SAVE_TEST_COMPLETE\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception . PHP_EOL);
    exit(1);
}
