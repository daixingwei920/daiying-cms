<?php
declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\{PaymentProviderConfiguredRedirectPolicyInterface, PaymentProviderInterface, PaymentProviderRegistry, PaymentProviderRedirectPolicyResolver, PaymentProviderSettingsRepository, PaymentResult};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$migrations = array_map(static fn ($file) => require $file, glob(dirname(__DIR__) . '/system/migrations/*.php'));
(new Cms\Core\Migration\MigrationRunner($pdo, $migrations))->run();
$settings = new PaymentProviderSettingsRepository($pdo, 'fixture-only-symmetric-encryption');
$provider = new class implements PaymentProviderInterface, PaymentProviderConfiguredRedirectPolicyInterface {
    public function providerId(): string { return 'test.configured-redirect'; }
    public function displayName(): string { return 'Fixture'; }
    public function capabilities(): array { return ['payment.create']; }
    public function isSafeRedirectUrl(string $url): bool { throw new RuntimeException('Legacy policy must not be called.'); }
    public function isSafeRedirectUrlForConfiguration(string $url, array $publicConfig): bool {
        if (isset($publicConfig['throw'])) { throw new RuntimeException('Invalid provider configuration'); }
        if (isset($publicConfig['merchant_key'])) { throw new RuntimeException('Secrets must not reach redirect policy.'); }
        return $url === ($publicConfig['gateway_url'] ?? '');
    }
    public function createPayment(object $command): PaymentResult { throw new RuntimeException('No network'); }
    public function capturePayment(object $command): PaymentResult { throw new RuntimeException('No network'); }
    public function cancelPayment(object $command): PaymentResult { throw new RuntimeException('No network'); }
    public function refundPayment(object $command): PaymentResult { throw new RuntimeException('No network'); }
    public function getPaymentStatus(object $command): PaymentResult { throw new RuntimeException('No network'); }
};
PaymentProviderRegistry::register($provider->providerId(), $provider);
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++; echo "PASS $message\n";
}
$fallbackCalls = 0;
$fallback = static function (string $url) use (&$fallbackCalls): bool { $fallbackCalls++; return true; };
$id = $provider->providerId();
$url = 'https://approved.example.com/submit.php';
$safe = fn (string $candidate): bool => PaymentProviderRedirectPolicyResolver::isSafe($id, $candidate, $fallback, $settings);
check(!PaymentProviderRedirectPolicyResolver::isSafe($id, $url, $fallback), 'Missing repository fails closed');
check(!$safe($url), 'Missing persisted provider fails closed');
$settings->save($id, 'Fixture', 'enabled', ['gateway_url'=>$url], ['merchant_key'=>'fixture-only-secret']);
check($safe($url), 'Persisted public endpoint accepted without exposing decrypted secret');
check(!$safe('https://other.example.com/submit.php'), 'Different host rejected');
check(!$safe($url . '?redirect=https://other.example.com'), 'Unexpected query rejected by provider');
$settings->save($id, 'Fixture', 'enabled', ['gateway_url'=>'https://new.example.com/submit.php']);
check(!$safe($url) && $safe('https://new.example.com/submit.php'), 'Updated persisted endpoint replaces old endpoint without stale cache');
$settings->save($id, 'Fixture', 'disabled', ['gateway_url'=>$url]);
check(!$safe($url), 'Disabled provider fails closed');
$settings->save($id, 'Fixture', 'enabled', ['gateway_url'=>$url]);
$pdo->exec("UPDATE cms_payment_provider_settings SET public_config_json = 'invalid-json'");
check(!$safe($url), 'Malformed persisted JSON fails closed');
$pdo->exec("UPDATE cms_payment_provider_settings SET public_config_json = 'null'");
check(!$safe($url), 'Nonobject persisted config fails closed');
$settings->save($id, 'Fixture', 'enabled', ['throw'=>true]);
check(!$safe($url), 'Provider exception fails closed');
check($fallbackCalls === 0, 'No configured-policy failure reaches broad fallback');
check(PaymentProviderRedirectPolicyResolver::isSafe('test.legacy-missing', $url, $fallback), 'Older provider fallback contract preserved');
echo "Checks: $checks; network requests: 0; signing keys: 0\n";
