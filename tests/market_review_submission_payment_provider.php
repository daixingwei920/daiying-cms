<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function review_payment_provider_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/cms-review-payment-provider-' . bin2hex(random_bytes(4));
mkdir($root . '/storage/logs', 0755, true);
$zipPath = $root . '/official-payment-wechatpay-1.2.1-stable.zip';

$zip = new ZipArchive();
review_payment_provider_check($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'created payment provider submission fixture');
$zip->addFromString('manifest.json', json_encode([
    'product_id' => 'official.payment.wechatpay',
    'package_type' => 'payment_provider',
    'version' => '1.2.1',
    'slug' => 'official-payment-wechatpay',
    'name' => '微信支付官方插件',
    'vendor' => 'Daiying',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$zip->addFromString('content/plugins/official.payment.wechatpay/plugin.json', json_encode([
    'plugin_id' => 'official.payment.wechatpay',
    'name' => '微信支付官方插件',
    'version' => '1.2.1',
    'author' => 'Daiying CMS',
    'type' => 'payment_provider',
    'package_type' => 'payment_provider',
    'entry' => 'plugin.php',
    'core' => ['min' => '1.2.0'],
    'php' => '>=8.3.0',
    'capabilities' => [],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$zip->close();

$controller = new AdminController(
    Settings::fromArray([
        'database' => ['dsn' => 'sqlite:' . $root . '/cms.sqlite', 'username' => '', 'password' => '', 'options' => []],
    ]),
    new FileLogger($root . '/storage/logs/cms.log'),
    $root,
);

$hintsMethod = new ReflectionMethod(AdminController::class, 'developerSubmissionManifestHints');
$hints = $hintsMethod->invoke($controller, $zipPath);
review_payment_provider_check(($hints['product_id'] ?? '') === 'official.payment.wechatpay', 'review submission hints use canonical payment Provider product_id');
review_payment_provider_check(($hints['package_type'] ?? '') === 'payment_provider', 'review submission hints preserve payment_provider package type');

$applyMethod = new ReflectionMethod(AdminController::class, 'applyDeveloperSubmissionManifestHints');
$data = $applyMethod->invoke($controller, ['package_type' => 'plugin', 'product_id' => 'official.payment.wechatpay'], ['tmp_name' => $zipPath]);
review_payment_provider_check(($data['package_type'] ?? '') === 'payment_provider', 'review submission metadata upgrades legacy plugin type to payment_provider when manifest matches');

$formMethod = new ReflectionMethod(AdminController::class, 'developerSubmissionForm');
$form = (string) $formMethod->invoke($controller, '', ['package_type' => 'payment_provider'], '');
review_payment_provider_check(str_contains($form, 'value="payment_provider" selected') && str_contains($form, '支付插件'), 'developer submission form exposes payment_provider as a supported product type');

unlink($zipPath);
@rmdir($root . '/storage/logs');
@rmdir($root . '/storage');
@rmdir($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " payment provider review submission checks failed.\n");
    exit(1);
}

echo "Payment provider review submission tests passed.\n";
