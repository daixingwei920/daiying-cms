<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceContracts.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceRepository.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceOpenAiCompatibleProvider.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceAiModuleManager.php';

use Daiying\Commerce\CommerceAiModuleManager;
use Daiying\Commerce\CommerceRepository;

$apiKey = trim((string) getenv('DEEPSEEK_API_KEY'));
if ($apiKey === '') {
    echo "commerce_ai_deepseek_live: SKIP (DEEPSEEK_API_KEY not set)\n";
    exit(0);
}

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$root = dirname(__DIR__);
$coreMigration = require $root . '/content/plugins/official.commerce/migrations/001_commerce_core.php';
$logisticsMigration = require $root . '/content/plugins/official.commerce/migrations/002_logistics_events.php';
$pricingMigration = require $root . '/content/plugins/official.commerce/migrations/003_price_transparency.php';
$governanceMigration = require $root . '/content/plugins/official.commerce/migrations/004_source_verification_governance.php';
$aiMigration = require $root . '/content/plugins/official.commerce/migrations/005_ai_modules.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
($coreMigration['up'])($pdo);
($logisticsMigration['up'])($pdo);
($pricingMigration['up'])($pdo);
($governanceMigration['up'])($pdo);
($aiMigration['up'])($pdo);

$repo = new CommerceRepository($pdo);
$key = 'commerce-deepseek-live-secret';
$defaults = CommerceAiModuleManager::deepSeekDefaults();
$moduleId = $repo->saveAiModule(array_replace($defaults, [
    'api_key' => $apiKey,
    'status' => 'enabled',
    'billing_type' => 'free',
    'sort_order' => 1,
]), $key);
$listed = $repo->aiModule($moduleId);
if (!is_array($listed) || array_key_exists('credential', $listed) || str_contains(json_encode($listed, JSON_UNESCAPED_SLASHES) ?: '', $apiKey)) {
    $fail('DeepSeek credential leaked through module listing.');
}
$manager = new CommerceAiModuleManager($repo, $key);
$test = $manager->testModule($moduleId);
if (($test['ok'] ?? false) !== true) {
    $fail('DeepSeek connection test failed: ' . (string) ($test['message'] ?? 'unknown'));
}
$productId = $repo->saveProduct([
    'name' => 'DeepSeek 测试商品',
    'sku' => 'DEEPSEEK-LIVE-001',
    'status' => 'active',
    'price_minor' => 9900,
    'currency' => 'CNY',
    'stock_quantity' => 1,
    'source_url' => 'https://example.com/deepseek-live',
    'source_claim_text' => '真实 AI 连接测试用商品',
]);
$product = $repo->product($productId);
if (!is_array($product)) {
    $fail('DeepSeek live test product was not created.');
}
$result = $manager->runProductTask('verification_explanation', $product, [
    'verification_facts' => ['URL可访问性' => '未核验'],
]);
if (($result['ok'] ?? false) !== true || trim((string) ($result['result'] ?? '')) === '') {
    $fail('DeepSeek low-risk task failed.');
}
$logs = json_encode($repo->aiInvocations(10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
if (str_contains($logs, $apiKey)) {
    $fail('DeepSeek API key leaked into invocation logs.');
}
if (($repo->product($productId)['verification_status'] ?? '') !== 'pending') {
    $fail('DeepSeek AI call modified product verification status.');
}

echo "commerce_ai_deepseek_live: PASS\n";
