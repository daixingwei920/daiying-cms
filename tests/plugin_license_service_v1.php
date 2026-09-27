<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Market\CommercialLicenseStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLicenseService;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Support\PublicApiRegistry;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}\n");
        return;
    }
    echo "[PASS] {$message}\n";
};

$keyOne = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$keyTwo = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($keyOne, $privateOne);
openssl_pkey_export($keyTwo, $privateTwo);
$publicOne = openssl_pkey_get_details($keyOne)['key'] ?? '';
$publicTwo = openssl_pkey_get_details($keyTwo)['key'] ?? '';

$settings = Settings::fromArray([
    'site' => ['url' => 'https://www.example.test/path'],
    'market' => [
        'license_public_keys' => [
            'current' => $publicOne,
            'next' => $publicTwo,
        ],
    ],
]);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$migration = require __DIR__ . '/../system/migrations/2026_08_30_000001_cms_site_license_client_schema.php';
$migration->up($pdo);

$manifest = new PluginManifest('official.wechat', 'Wechat', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'trusted_php', [], [], [], 'plugin', true, [], [], '');
$otherManifest = new PluginManifest('official.other', 'Other', '1.0.0', 'Unit', '1.2.70', '8.3.0', 'plugin.php', 'trusted_php', [], [], [], 'plugin', true, [], [], '');
$service = new PluginLicenseService($manifest, new CommercialLicenseStore($pdo), $settings);
$otherService = new PluginLicenseService($otherManifest, new CommercialLicenseStore($pdo), $settings);

$b64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
$code = static function (array $payload, string $privateKey) use ($b64): string {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    openssl_sign((string) $json, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return 'dylic_v1.' . $b64((string) $json) . '.' . $b64($signature);
};

$activePayload = [
    'license_id' => 'lic-pro',
    'plugin_id' => 'official.wechat',
    'domain' => 'example.test',
    'tier' => 'pro',
    'features' => ['article_sync', 'template_messages'],
    'issued_at' => gmdate('c', time() - 3600),
    'expires_at' => gmdate('c', time() + 86400),
    'key_id' => 'current',
];
$proCode = $code($activePayload, $privateOne);
$state = $service->activate($proCode);
$check($state['status'] === 'active' && $state['tier'] === 'pro', 'Valid Pro license activates.');
$check($service->hasFeature('article_sync') && !$service->hasFeature('unknown_feature'), 'Feature gates use signed feature list.');
$requiredOk = true;
try {
    $service->requireFeature('template_messages');
} catch (Throwable) {
    $requiredOk = false;
}
$check($requiredOk, 'requireFeature allows signed features.');

$litePayload = $activePayload + [];
$litePayload['license_id'] = 'lic-lite';
$litePayload['tier'] = 'lite';
$litePayload['features'] = ['qr_login'];
$liteCode = $code($litePayload, $privateOne);
$service->activate($liteCode);
$check($service->current()['tier'] === 'lite' && $service->hasFeature('qr_login') && !$service->hasFeature('article_sync'), 'Lite license works without Pro features.');

$blocked = false;
try {
    $service->requireFeature('article_sync');
} catch (PluginException) {
    $blocked = true;
}
$check($blocked, 'requireFeature fails closed for absent features.');

$rotatedPayload = $activePayload;
$rotatedPayload['license_id'] = 'lic-rotated';
$rotatedPayload['key_id'] = 'next';
$service->activate($code($rotatedPayload, $privateTwo));
$check($service->current()['status'] === 'active', 'Key rotation accepts configured next public key.');

$badCode = substr($proCode, 0, -2) . 'xx';
$badSig = false;
try {
    $service->activate($badCode);
} catch (PluginException) {
    $badSig = true;
}
$check($badSig, 'Bad signature is rejected.');

$wrongPlugin = $activePayload;
$wrongPlugin['plugin_id'] = 'official.other';
$wrongPluginRejected = false;
try {
    $service->activate($code($wrongPlugin, $privateOne));
} catch (PluginException) {
    $wrongPluginRejected = true;
}
$check($wrongPluginRejected, 'Wrong plugin license is rejected.');

$wrongSite = $activePayload;
$wrongSite['domain'] = 'elsewhere.test';
$wrongSiteRejected = false;
try {
    $service->activate($code($wrongSite, $privateOne));
} catch (PluginException) {
    $wrongSiteRejected = true;
}
$check($wrongSiteRejected, 'Wrong site/domain license is rejected.');

$expired = $activePayload;
$expired['expires_at'] = gmdate('c', time() - 60);
$expiredRejected = false;
try {
    $service->activate($code($expired, $privateOne));
} catch (PluginException) {
    $expiredRejected = true;
}
$check($expiredRejected, 'Expired license is rejected.');

$service->activate($proCode);
$pdo->exec("UPDATE cms_site_licenses SET status = 'EXPIRED' WHERE product_id = 'official.wechat'");
$check($service->current()['status'] === 'expired' && !$service->hasFeature('article_sync'), 'Market expired status disables signed features.');

$service->activate($proCode);
$tampered = json_decode((string) $pdo->query("SELECT activation_payload_json FROM cms_site_licenses WHERE product_id = 'official.wechat'")->fetchColumn(), true);
$parts = explode('.', (string) $tampered['license_code']);
$payloadJson = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4));
$payload = json_decode((string) $payloadJson, true);
$payload['features'][] = 'forged_feature';
$parts[1] = $b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$tampered['license_code'] = implode('.', $parts);
$pdo->prepare("UPDATE cms_site_licenses SET activation_payload_json = :payload WHERE product_id = 'official.wechat'")
    ->execute([':payload' => json_encode($tampered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
$check($service->current()['status'] === 'invalid' && !$service->hasFeature('forged_feature'), 'Cache tampering fails closed.');

$service->activate($proCode);
$payload = json_decode((string) $pdo->query("SELECT activation_payload_json FROM cms_site_licenses WHERE product_id = 'official.wechat'")->fetchColumn(), true);
$payload['offline_grace'] = true;
$payload['grace_until'] = gmdate('c', time() + 3600);
$pdo->prepare("UPDATE cms_site_licenses SET status = 'OFFLINE_GRACE', activation_payload_json = :payload WHERE product_id = 'official.wechat'")
    ->execute([':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
$check($service->current()['status'] === 'offline_grace' && $service->hasFeature('article_sync'), 'Fresh signed cache can enter offline grace.');

$payload['grace_until'] = gmdate('c', time() - 3600);
$pdo->prepare("UPDATE cms_site_licenses SET activation_payload_json = :payload WHERE product_id = 'official.wechat'")
    ->execute([':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
$check($service->current()['status'] === 'invalid' && !$service->hasFeature('article_sync'), 'Expired offline grace fails closed.');

$service->activate($proCode);
$check($otherService->current()['status'] === 'missing', 'Plugin A cannot read plugin B license through context-scoped service.');
$check(!str_contains($publicOne, 'PRIVATE KEY') && !str_contains(json_encode($settings->all()), 'PRIVATE KEY'), 'Core configuration contains public verification material only.');

$service->clear();
$check($service->current()['status'] === 'missing', 'No-license plugin state remains missing/free and non-fatal.');
$check(PublicApiRegistry::contract('plugin.license')['class'] === 'Cms\\Core\\Plugin\\PluginLicenseService', 'Plugin license service is registered as public API.');

if ($failures > 0) {
    fwrite(STDERR, "Plugin license service tests FAILED: {$failures}\n");
    exit(1);
}

echo "Plugin license service tests PASS\n";
