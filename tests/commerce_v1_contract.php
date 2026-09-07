<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceContracts.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceRepository.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceAiModuleManager.php';
require __DIR__ . '/../content/plugins/official.commerce/src/GenericUrlVerificationProvider.php';
require __DIR__ . '/../content/plugins/official.commerce/src/AmazonVerificationProvider.php';
require __DIR__ . '/../content/plugins/official.commerce/src/TaobaoVerificationProvider.php';
require __DIR__ . '/../content/plugins/official.commerce/src/CommerceController.php';

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Payment\PaymentRepository;
use Daiying\Commerce\CommerceController;
use Daiying\Commerce\CommerceAiModuleManager;
use Daiying\Commerce\CommerceAiModuleInterface;
use Daiying\Commerce\CommerceDistributionInterface;
use Daiying\Commerce\CommerceVerificationProviderInterface;
use Daiying\Commerce\CommerceLogisticsProviderInterface;
use Daiying\Commerce\CommerceProviderIsolation;
use Daiying\Commerce\CommerceRepository;
use Daiying\Commerce\AmazonVerificationProvider;
use Daiying\Commerce\GenericUrlVerificationProvider;
use Daiying\Commerce\TaobaoVerificationProvider;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/content/plugins/official.commerce/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$official = require $root . '/system/official-plugins.php';
$parsed = PluginManifest::fromArray($manifest);

$assert($parsed->id === 'official.commerce', 'Commerce uses the official.commerce plugin id.');
$assert($parsed->trustLevel === 'trusted_php', 'Commerce is a trusted official plugin because it owns order tables.');
$assert(($official['official.commerce']['table_prefixes'] ?? []) === ['commerce_'], 'Official registry grants only the commerce_ table prefix.');
$assert(!in_array('payment.create', $parsed->capabilities, true), 'Commerce uses Core PaymentService without claiming a foreign payment capability namespace.');
$assert(in_array('network.external', $parsed->capabilities, true), 'Commerce declares external network access for the generic URL verification provider.');
$assert(in_array('commerce.verify.write', $parsed->capabilities, true), 'Commerce declares a dedicated verification write capability for future permission splits.');
$assert(in_array('commerce.logistics.write', $parsed->capabilities, true), 'Commerce declares a dedicated logistics write capability for future provider integrations.');
$assert(in_array('commerce.ai.manage', $parsed->capabilities, true), 'Commerce declares a dedicated AI module management capability.');
$assert(interface_exists(CommerceAiModuleInterface::class), 'Commerce exposes an optional AI module interface without making AI a hard dependency.');
$assert(interface_exists(CommerceDistributionInterface::class), 'Commerce exposes a distribution provider interface for future channels.');
$assert(interface_exists(CommerceLogisticsProviderInterface::class), 'Commerce exposes a logistics provider interface for future carrier plugins.');
$assert(interface_exists(CommerceVerificationProviderInterface::class), 'Commerce exposes a source verification provider interface.');
$isolated = CommerceProviderIsolation::capture('fixture', 'explode', static function (): void {
    throw new RuntimeException('provider unavailable');
});
$assert(($isolated['ok'] ?? true) === false && ($isolated['provider'] ?? '') === 'fixture', 'Provider failures are captured instead of escaping into the commerce flow.');

$coreMigration = require $root . '/content/plugins/official.commerce/migrations/001_commerce_core.php';
$assert(in_array('table:commerce_orders', $coreMigration['affected_objects'] ?? [], true), 'Migration declares the commerce order table.');
$logisticsMigration = require $root . '/content/plugins/official.commerce/migrations/002_logistics_events.php';
$assert(in_array('table:commerce_logistics_events', $logisticsMigration['affected_objects'] ?? [], true), 'Logistics migration declares the logistics fact table.');
$pricingMigration = require $root . '/content/plugins/official.commerce/migrations/003_price_transparency.php';
$assert(in_array('table:commerce_products', $pricingMigration['affected_objects'] ?? [], true), 'Price transparency migration declares the commerce product table.');
$governanceMigration = require $root . '/content/plugins/official.commerce/migrations/004_source_verification_governance.php';
$assert(in_array('table:commerce_verification_records', $governanceMigration['affected_objects'] ?? [], true), 'Source verification governance migration declares verification records.');
$aiMigration = require $root . '/content/plugins/official.commerce/migrations/005_ai_modules.php';
$assert(in_array('table:commerce_ai_modules', $aiMigration['affected_objects'] ?? [], true), 'AI module migration declares the Commerce AI module table.');
$assert(in_array('table:commerce_ai_invocations', $aiMigration['affected_objects'] ?? [], true), 'AI module migration declares the Commerce AI invocation log table.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
($coreMigration['up'])($pdo);
($logisticsMigration['up'])($pdo);
($pricingMigration['up'])($pdo);
($governanceMigration['up'])($pdo);
($aiMigration['up'])($pdo);
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
$pdo->exec('CREATE TABLE cms_payment_refunds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id INTEGER NOT NULL,
    provider_id VARCHAR(96) NOT NULL,
    remote_id VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    amount_minor INTEGER NOT NULL,
    currency VARCHAR(3) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    metadata_json TEXT NOT NULL,
    completed_at VARCHAR(64),
    failed_at VARCHAR(64),
    cancelled_at VARCHAR(64),
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL
)');

$repo = new CommerceRepository($pdo);
$productId = $repo->saveProduct([
    'name' => '测试商品',
    'sku' => 'TEST-001',
    'status' => 'active',
    'price_minor' => 36000,
    'currency' => 'CNY',
    'stock_quantity' => 5,
    'transaction_region' => 'cross_border',
    'shipping_fee_minor' => 1200,
    'tax_fee_minor' => 800,
    'service_fee_minor' => 300,
    'discount_minor' => 100,
    'price_note' => '跨境订单费用以结算页快照为准',
    'source_url' => 'https://example.com/item/1',
    'source_claim_text' => '官方授权渠道采购',
    'brand' => 'Daiying',
    'model' => 'V1',
    'specs' => "颜色: 黑色\n容量: 128GB",
]);
$repo->saveAction(['product_id' => $productId, 'action_type' => 'site_checkout', 'label' => '立即购买']);
$product = $repo->product($productId);

$assert(is_array($product), 'Product can be created.');
$assert((int) ($product['available_quantity'] ?? 0) === 5, 'New product starts with full available stock.');
$assert(($product['specs']['颜色'] ?? '') === '黑色', 'Product specs are stored as structured facts.');
$assert(($product['verification_status'] ?? '') === 'pending', 'Product with a source URL starts as pending verification.');
$assert(($product['transaction_region'] ?? '') === 'cross_border', 'Product keeps a transaction-region fact separate from currency.');
$sourceRecords = $repo->verificationRecords($productId);
$assert(($sourceRecords[0]['record_type'] ?? '') === 'source_declaration', 'Saving a product source creates an append-only source declaration record.');
$assert(($sourceRecords[0]['checked_facts']['source_claim'] ?? '') === '官方授权渠道采购', 'Source declarations keep seller-provided source claims as facts.');

$aiKey = 'commerce-test-secret-key';
$freeFailId = $repo->saveAiModule([
    'name' => 'Free Broken AI',
    'provider_type' => 'domestic',
    'protocol' => 'openai_compatible',
    'endpoint' => 'https://ai-free-broken.example/v1',
    'model' => 'free-broken',
    'api_key' => 'free-broken-key',
    'status' => 'enabled',
    'billing_type' => 'free',
    'sort_order' => 10,
    'capabilities' => ['product_copy', 'verification_explanation'],
], $aiKey);
$freeOkId = $repo->saveAiModule([
    'name' => 'Free Working AI',
    'provider_type' => 'local',
    'protocol' => 'openai_compatible',
    'endpoint' => 'http://127.0.0.1:11434/v1',
    'model' => 'local-free',
    'api_key' => 'free-working-key',
    'status' => 'enabled',
    'billing_type' => 'free',
    'sort_order' => 20,
    'capabilities' => ['product_copy', 'share_copy', 'content_match', 'sales_insight', 'verification_explanation'],
], $aiKey);
$paidId = $repo->saveAiModule([
    'name' => 'Paid Overseas AI',
    'provider_type' => 'overseas',
    'protocol' => 'openai_compatible',
    'endpoint' => 'https://ai-paid.example/v1',
    'model' => 'paid-model',
    'api_key' => 'paid-secret-key',
    'status' => 'enabled',
    'billing_type' => 'paid',
    'sort_order' => 30,
    'capabilities' => ['product_copy'],
], $aiKey);
$aiModules = $repo->aiModules();
$assert(count($aiModules) === 3, 'Commerce can manage multiple AI modules.');
$assert(($aiModules[0]['credential_masked'] ?? '') === '********', 'AI credentials are masked when listed for the admin UI.');
$assert(!array_key_exists('credential', $aiModules[0]), 'AI credentials are not exposed in module list data.');
$assert($repo->aiModuleCredential($freeOkId, $aiKey) === 'free-working-key', 'AI credentials can be decrypted only on the server with the configured encryption key.');
$controllerWithAi = new CommerceController($repo, $pdo, Settings::fromArray(['security' => ['encryption_key' => $aiKey]]));
$aiPage = $controllerWithAi->adminAiModules(new Request('GET', '/admin/commerce/ai'))->body();
$assert(str_contains($aiPage, 'Commerce AI 模块'), 'Admin exposes a unified Commerce AI module management entry.');
$assert(str_contains($aiPage, 'Free Working AI'), 'Admin AI module page lists configured modules.');
$assert(!str_contains($aiPage, 'free-working-key') && !str_contains($aiPage, 'paid-secret-key'), 'Admin AI page never renders API keys.');

$manager = new CommerceAiModuleManager($repo, $aiKey, static function (array $module, string $prompt): array {
    if ((string) $module['name'] === 'Free Broken AI') {
        throw new RuntimeException('free quota exhausted: api_key=SHOULD_NOT_LEAK');
    }
    return ['text' => (string) $module['name'] . ' handled ' . (str_contains($prompt, '正品认证') ? 'guarded' : 'copy')];
});
$testResult = $manager->testModule($freeOkId);
$assert(($testResult['ok'] ?? false) === true, 'AI modules support explicit connection testing.');
$testedModule = $repo->aiModule($freeOkId);
$assert(($testedModule['last_test_status'] ?? '') === 'success', 'AI connection test status and time are recorded.');
$aiResult = $manager->runProductTask('product_copy', $product);
$assert(($aiResult['ok'] ?? false) === true && ($aiResult['module_id'] ?? 0) === $freeOkId, 'Free AI failures fall back to the next free module by sort order.');
$assert(($aiResult['billing_type'] ?? '') === 'free', 'Free AI fallback remains free by default.');
$invocations = $repo->aiInvocations(10);
$assert(($invocations[0]['status'] ?? '') === 'success', 'AI invocations are logged without blocking Commerce.');
$assert(!str_contains(json_encode($invocations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 'SHOULD_NOT_LEAK'), 'AI invocation logs redact secret-looking error text.');

$repo->saveAiModule([
    'id' => $freeOkId,
    'name' => 'Free Working AI',
    'provider_type' => 'local',
    'protocol' => 'openai_compatible',
    'endpoint' => 'http://127.0.0.1:11434/v1',
    'model' => 'local-free',
    'status' => 'disabled',
    'billing_type' => 'free',
    'sort_order' => 20,
    'capabilities' => ['product_copy'],
], $aiKey);
$paidBlocked = $manager->runProductTask('product_copy', $product);
$assert(($paidBlocked['ok'] ?? true) === false, 'Paid AI is not used when the caller has not explicitly allowed paid modules.');
$paidAllowed = $manager->runProductTask('product_copy', $product, ['allow_paid' => true]);
$assert(($paidAllowed['ok'] ?? false) === true && ($paidAllowed['module_id'] ?? 0) === $paidId && ($paidAllowed['billing_type'] ?? '') === 'paid', 'Paid AI is used only after explicit allow_paid consent.');
$assert(($repo->product($productId)['verification_status'] ?? '') === 'pending', 'AI calls do not modify Verification source facts or product verification status.');

$urlProvider = new GenericUrlVerificationProvider(static function (string $url): array {
    return [
        'requested_url' => $url,
        'final_url' => 'https://store.example/items/1',
        'http_status' => 200,
        'content_type' => 'text/html; charset=utf-8',
        'redirect_count' => 1,
        'body' => '<!doctype html><html><head><title>测试商品 - 店铺</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"测试商品","brand":{"@type":"Brand","name":"Daiying"},"model":"V1","offers":{"@type":"Offer","price":"360.00","priceCurrency":"CNY"},"additionalProperty":[{"@type":"PropertyValue","name":"颜色","value":"黑色"}]}</script></head><body>商品页面</body></html>',
    ];
});
$urlResult = $urlProvider->verifySource($product);
$assert(($urlResult['status'] ?? '') === 'pending', 'Generic URL verification records accessible page facts without claiming authenticity.');
$assert(($urlResult['checked_facts']['URL可访问性'] ?? '') === '可访问', 'Generic URL provider records URL accessibility.');
$assert(($urlResult['checked_facts']['最终跳转域名'] ?? '') === 'store.example', 'Generic URL provider records the final redirected host.');
$assert(($urlResult['checked_facts']['品牌'] ?? '') === 'Daiying', 'Generic URL provider extracts reliable JSON-LD brand facts.');
$assert(($urlResult['checked_facts']['型号'] ?? '') === 'V1', 'Generic URL provider extracts reliable JSON-LD model facts.');
$assert(($urlResult['checked_facts']['价格'] ?? '') === '360.00', 'Generic URL provider extracts reliable JSON-LD price facts.');
$urlRecordId = $repo->appendVerificationRecord($urlResult + ['product_id' => $productId], 99);
$urlRecords = $repo->verificationRecords($productId);
$assert($urlRecordId > 0 && ($urlRecords[0]['provider'] ?? '') === 'official.commerce.verifier.url', 'Generic URL provider appends immutable verification records.');
$assert(($repo->product($productId)['verification_status'] ?? '') === 'pending', 'Generic URL provider does not turn URL existence into verified authenticity.');

$missingFactsProvider = new GenericUrlVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => $url,
    'http_status' => 200,
    'content_type' => 'text/html',
    'redirect_count' => 0,
    'body' => '<html><head><title>只有标题</title></head><body>没有结构化商品事实</body></html>',
]);
$missingFacts = $missingFactsProvider->verifySource($product);
$assert(($missingFacts['checked_facts']['品牌'] ?? '') === '未核验', 'Missing source facts remain explicitly unverified.');
$assert(($missingFacts['checked_facts']['型号'] ?? '') === '未核验', 'Generic URL provider does not guess unavailable model facts.');

$unsafeProvider = new GenericUrlVerificationProvider(static fn (string $url): array => ['final_url' => $url, 'http_status' => 200, 'body' => '']);
$unsafeResult = $unsafeProvider->verifySource(array_replace($product, ['source_url' => 'http://127.0.0.1/admin']));
$assert(($unsafeResult['status'] ?? '') === 'failed', 'Generic URL provider blocks private-address source URLs.');
$assert(($unsafeResult['checked_facts']['品牌'] ?? '') === '未核验', 'Blocked URLs do not produce guessed brand facts.');

$amazonProduct = array_replace($product, ['source_url' => 'https://www.amazon.com/dp/B0TEST1234']);
$amazonProvider = new AmazonVerificationProvider(static function (string $url): array {
    return [
        'requested_url' => $url,
        'final_url' => 'https://www.amazon.com/Daiying-Widget/dp/B0TEST1234/ref=fixture',
        'http_status' => 200,
        'content_type' => 'text/html; charset=utf-8',
        'redirect_count' => 1,
        'body' => '<!doctype html><html><head><title>Amazon.com: Daiying Widget</title><meta property="og:image" content="https://m.media-amazon.com/images/I/test.jpg"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Daiying Widget","brand":{"@type":"Brand","name":"Daiying"},"model":"V1-Pro","offers":{"@type":"Offer","price":"59.99","priceCurrency":"USD"},"additionalProperty":[{"@type":"PropertyValue","name":"Color","value":"Black"}]}</script></head><body><span id="productTitle">Daiying Widget</span><span class="a-offscreen">$59.99</span></body></html>',
    ];
});
$amazonResult = $amazonProvider->verifySource($amazonProduct);
$assert(($amazonResult['status'] ?? '') === 'pending', 'Amazon provider records source facts without claiming authenticity.');
$assert(($amazonResult['checked_facts']['Amazon域名是否合法'] ?? '') === '合法', 'Amazon provider allowlists official Amazon hosts.');
$assert(($amazonResult['checked_facts']['ASIN'] ?? '') === 'B0TEST1234', 'Amazon provider extracts ASIN from the source or final URL.');
$assert(($amazonResult['checked_facts']['最终跳转域名'] ?? '') === 'www.amazon.com', 'Amazon provider records final Amazon host.');
$assert(($amazonResult['checked_facts']['当前页面状态'] ?? '') === '正常商品页面', 'Amazon provider records the current page state.');
$assert(($amazonResult['checked_facts']['商品标题'] ?? '') === 'Daiying Widget', 'Amazon provider extracts product title.');
$assert(($amazonResult['checked_facts']['品牌'] ?? '') === 'Daiying', 'Amazon provider extracts reliable brand facts.');
$assert(($amazonResult['checked_facts']['型号'] ?? '') === 'V1-Pro', 'Amazon provider extracts reliable model facts.');
$assert(($amazonResult['checked_facts']['价格'] ?? '') === '$59.99', 'Amazon provider records the visible Amazon price when reliable.');
$assert(($amazonResult['checked_facts']['币种'] ?? '') === 'USD', 'Amazon provider records currency when reliable.');
$assert(($amazonResult['checked_facts']['图片信息'] ?? '') === 'https://m.media-amazon.com/images/I/test.jpg', 'Amazon provider records reliable image metadata.');
$assert(($amazonResult['checked_facts']['商品标题对比'] ?? '') === '存在差异', 'Amazon provider compares source title with Daiying product name.');
$assert(($amazonResult['checked_facts']['品牌对比'] ?? '') === '一致', 'Amazon provider compares source brand with Daiying product brand.');
$assert(($amazonResult['checked_facts']['型号对比'] ?? '') === '存在差异', 'Amazon provider compares source model with Daiying product model.');
$assert(($amazonResult['checked_facts']['价格对比'] ?? '') === '存在差异', 'Amazon provider compares source price with Daiying product price and currency.');
$assert(($amazonResult['checked_facts']['图片对比'] ?? '') === '未核验', 'Amazon provider leaves image comparison unverified without a reliable local image URL.');
$amazonRecordId = $repo->appendVerificationRecord($amazonResult + ['product_id' => $productId], 99);
$amazonRecords = $repo->verificationRecords($productId);
$assert($amazonRecordId > 0 && ($amazonRecords[0]['provider'] ?? '') === 'official.commerce.verifier.amazon', 'Amazon provider appends immutable verification facts.');
$assert(($repo->product($productId)['verification_status'] ?? '') === 'pending', 'Amazon provider keeps product pending until a stronger trusted provider verifies authenticity.');
$amazonProductPage = (new CommerceController($repo, $pdo, Settings::fromArray(['security' => ['encryption_key' => 'commerce-test-secret']])))->productPage(new Request('GET', '/commerce/product', ['id' => $productId]))->body();
$assert(str_contains($amazonProductPage, 'Daiying Widget'), 'Consumer transparency profile can display Amazon product facts.');
$assert(str_contains($amazonProductPage, '商品标题') && str_contains($amazonProductPage, '存在差异'), 'Consumer transparency profile can display Amazon comparison results.');

$amazonCaptchaProvider = new AmazonVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => $url,
    'http_status' => 200,
    'content_type' => 'text/html',
    'redirect_count' => 0,
    'body' => '<html><head><title>Robot Check</title></head><body>Enter the characters you see below</body></html>',
]);
$amazonCaptcha = $amazonCaptchaProvider->verifySource($amazonProduct);
$assert(($amazonCaptcha['checked_facts']['当前页面状态'] ?? '') === '验证码/反爬页面', 'Amazon provider marks robot checks instead of fighting them.');
$assert(($amazonCaptcha['checked_facts']['品牌'] ?? '') === '未核验', 'Amazon provider leaves facts unverified behind robot checks.');

$amazonContinueProvider = new AmazonVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => $url,
    'http_status' => 200,
    'content_type' => 'text/html',
    'redirect_count' => 0,
    'body' => '<html><head><title>Amazon.com</title></head><body>Click the button below to continue shopping</body></html>',
]);
$amazonContinue = $amazonContinueProvider->verifySource($amazonProduct);
$assert(($amazonContinue['checked_facts']['当前页面状态'] ?? '') === '验证码/反爬页面', 'Amazon provider marks continue-shopping interstitials as access limits.');
$assert(($amazonContinue['checked_facts']['商品标题'] ?? '') === '未核验', 'Amazon interstitial pages do not produce product facts.');

$amazonInvalidProvider = new AmazonVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => $url,
    'http_status' => 404,
    'content_type' => 'text/html',
    'redirect_count' => 0,
    'body' => '<html><head><title>Page Not Found</title></head><body>Page Not Found</body></html>',
]);
$amazonInvalid = $amazonInvalidProvider->verifySource($amazonProduct);
$assert(($amazonInvalid['status'] ?? '') === 'failed', 'Amazon provider marks invalid or unavailable product pages as failed.');
$assert(($amazonInvalid['checked_facts']['商品标题'] ?? '') === '未核验', 'Invalid Amazon pages do not produce guessed product titles.');

$fakeAmazonProvider = new AmazonVerificationProvider(static fn (string $url): array => ['requested_url' => $url, 'final_url' => $url, 'http_status' => 200, 'body' => '']);
$fakeAmazon = $fakeAmazonProvider->verifySource(array_replace($product, ['source_url' => 'https://amazon.com.evil.example/dp/B0TEST1234']));
$assert(($fakeAmazon['status'] ?? '') === 'failed', 'Amazon provider rejects lookalike Amazon domains.');
$assert(($fakeAmazon['checked_facts']['Amazon域名是否合法'] ?? '') === '不合法或未核验', 'Rejected Amazon domains are not recorded as valid platform facts.');

$taobaoProduct = array_replace($product, ['source_url' => 'https://item.taobao.com/item.htm?id=812345678901']);
$taobaoProvider = new TaobaoVerificationProvider(static function (string $url): array {
    return [
        'requested_url' => $url,
        'final_url' => 'https://detail.tmall.com/item.htm?id=812345678901&skuId=1',
        'http_status' => 200,
        'content_type' => 'text/html; charset=utf-8',
        'redirect_count' => 1,
        'body' => '<!doctype html><html><head><title>黛影夹克-天猫</title><meta property="og:title" content="Daiying Jacket"><meta property="og:image" content="//img.alicdn.com/example.jpg"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Daiying Jacket","brand":{"@type":"Brand","name":"Daiying"},"model":"V2","offers":{"@type":"Offer","price":"360.00","priceCurrency":"CNY"},"additionalProperty":[{"@type":"PropertyValue","name":"颜色","value":"黑色"}]}</script></head><body><ul><li>品牌: Daiying</li><li>型号: V2</li></ul></body></html>',
    ];
});
$taobaoResult = $taobaoProvider->verifySource($taobaoProduct);
$assert(($taobaoResult['status'] ?? '') === 'pending', 'Taobao provider records source facts without claiming authenticity.');
$assert(($taobaoResult['checked_facts']['淘宝域名是否合法'] ?? '') === '合法', 'Taobao provider allowlists official Taobao/Tmall hosts.');
$assert(($taobaoResult['checked_facts']['商品ID'] ?? '') === '812345678901', 'Taobao provider extracts item id from source or final URL.');
$assert(($taobaoResult['checked_facts']['最终跳转域名'] ?? '') === 'detail.tmall.com', 'Taobao provider records final Taobao/Tmall host.');
$assert(($taobaoResult['checked_facts']['当前页面状态'] ?? '') === '正常商品页面', 'Taobao provider records the current page state.');
$assert(($taobaoResult['checked_facts']['商品标题'] ?? '') === 'Daiying Jacket', 'Taobao provider extracts product title.');
$assert(($taobaoResult['checked_facts']['品牌'] ?? '') === 'Daiying', 'Taobao provider extracts reliable brand facts.');
$assert(($taobaoResult['checked_facts']['型号'] ?? '') === 'V2', 'Taobao provider extracts reliable model facts.');
$assert(($taobaoResult['checked_facts']['价格'] ?? '') === '360.00', 'Taobao provider records reliable price facts.');
$assert(($taobaoResult['checked_facts']['币种'] ?? '') === 'CNY', 'Taobao provider records currency when reliable.');
$assert(($taobaoResult['checked_facts']['图片信息'] ?? '') === 'https://img.alicdn.com/example.jpg', 'Taobao provider records reliable image metadata.');
$assert(($taobaoResult['checked_facts']['商品标题对比'] ?? '') === '存在差异', 'Taobao provider compares source title with Daiying product name.');
$assert(($taobaoResult['checked_facts']['品牌对比'] ?? '') === '一致', 'Taobao provider compares source brand with Daiying product brand.');
$assert(($taobaoResult['checked_facts']['型号对比'] ?? '') === '存在差异', 'Taobao provider compares source model with Daiying product model.');
$assert(($taobaoResult['checked_facts']['价格对比'] ?? '') === '一致', 'Taobao provider compares source price with Daiying product price.');
$taobaoRecordId = $repo->appendVerificationRecord($taobaoResult + ['product_id' => $productId], 99);
$taobaoRecords = $repo->verificationRecords($productId);
$assert($taobaoRecordId > 0 && ($taobaoRecords[0]['provider'] ?? '') === 'official.commerce.verifier.taobao', 'Taobao provider appends immutable verification facts.');
$assert(($repo->product($productId)['verification_status'] ?? '') === 'pending', 'Taobao provider keeps product pending until a stronger trusted provider verifies authenticity.');
$taobaoProductPage = (new CommerceController($repo, $pdo, Settings::fromArray(['security' => ['encryption_key' => 'commerce-test-secret']])))->productPage(new Request('GET', '/commerce/product', ['id' => $productId]))->body();
$assert(str_contains($taobaoProductPage, 'Daiying Jacket'), 'Consumer transparency profile can display Taobao product facts.');
$assert(str_contains($taobaoProductPage, '商品标题') && str_contains($taobaoProductPage, '存在差异'), 'Consumer transparency profile can display Taobao comparison results.');

$taobaoLoginProvider = new TaobaoVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => 'https://login.taobao.com/member/login.jhtml',
    'http_status' => 200,
    'content_type' => 'text/html',
    'redirect_count' => 1,
    'body' => '<html><head><title>亲，请登录</title></head><body>亲，请登录</body></html>',
]);
$taobaoLogin = $taobaoLoginProvider->verifySource($taobaoProduct);
$assert(($taobaoLogin['checked_facts']['当前页面状态'] ?? '') === '登录页', 'Taobao provider marks login pages instead of bypassing them.');
$assert(($taobaoLogin['checked_facts']['品牌'] ?? '') === '未核验', 'Taobao login pages do not produce guessed brand facts.');

$taobaoRiskProvider = new TaobaoVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => 'https://sec.taobao.com/query.htm',
    'http_status' => 200,
    'content_type' => 'text/html',
    'redirect_count' => 1,
    'body' => '<html><head><title>安全验证</title></head><body>验证码 滑块 风控</body></html>',
]);
$taobaoRisk = $taobaoRiskProvider->verifySource($taobaoProduct);
$assert(($taobaoRisk['checked_facts']['当前页面状态'] ?? '') === '验证码/风控页面', 'Taobao provider marks risk-control pages as unverified.');
$assert(($taobaoRisk['checked_facts']['商品标题'] ?? '') === '未核验', 'Taobao risk pages do not produce product facts.');

$taobaoInvalidProvider = new TaobaoVerificationProvider(static fn (string $url): array => [
    'requested_url' => $url,
    'final_url' => $url,
    'http_status' => 404,
    'content_type' => 'text/html',
    'redirect_count' => 0,
    'body' => '<html><head><title>页面不存在</title></head><body>商品不存在</body></html>',
]);
$taobaoInvalid = $taobaoInvalidProvider->verifySource($taobaoProduct);
$assert(($taobaoInvalid['status'] ?? '') === 'failed', 'Taobao provider marks invalid or unavailable product pages as failed.');
$assert(($taobaoInvalid['checked_facts']['商品标题'] ?? '') === '未核验', 'Invalid Taobao pages do not produce guessed product titles.');

$fakeTaobaoProvider = new TaobaoVerificationProvider(static fn (string $url): array => ['requested_url' => $url, 'final_url' => $url, 'http_status' => 200, 'body' => '']);
$fakeTaobao = $fakeTaobaoProvider->verifySource(array_replace($product, ['source_url' => 'https://taobao.com.evil.example/item.htm?id=812345678901']));
$assert(($fakeTaobao['status'] ?? '') === 'failed', 'Taobao provider rejects lookalike Taobao domains.');
$assert(($fakeTaobao['checked_facts']['淘宝域名是否合法'] ?? '') === '不合法或未核验', 'Rejected Taobao domains are not recorded as valid platform facts.');

$sellerCannotVerify = false;
try {
    $repo->appendVerificationRecord([
        'product_id' => $productId,
        'status' => 'verified',
        'source_url' => 'https://example.com/item/1',
    ]);
} catch (RuntimeException) {
    $sellerCannotVerify = true;
}
$assert($sellerCannotVerify, 'Seller/manual flows cannot directly mark a source as verified.');

$repo->appendVerificationRecord([
    'product_id' => $productId,
    'status' => 'verified',
    'provider' => 'official.verifier.fixture',
    'source_url' => 'https://example.com/item/1',
    'checked_facts' => "品牌: Daiying\n型号: V1",
    'raw_evidence' => "页面标题: 测试商品\n抓取方式: manual",
]);
$verifiedProduct = $repo->product($productId);
$verifiedRecords = $repo->verificationRecords($productId);
$assert(($verifiedProduct['verification_status'] ?? '') === 'verified', 'Appending a verified record updates the product verification status.');
$assert(($verifiedRecords[0]['record_type'] ?? '') === 'provider_result', 'Trusted provider verification is recorded as a provider result.');
$assert(($verifiedRecords[0]['checked_facts']['品牌'] ?? '') === 'Daiying', 'Verification facts are stored as append-only structured records.');
$requestId = $repo->requestVerificationReview($productId, '来源页面已更新，请重新核验。', 99);
$requestedRecords = $repo->verificationRecords($productId);
$assert($requestId > 0 && ($requestedRecords[0]['record_type'] ?? '') === 'seller_request', 'Seller can request re-verification without editing the result.');
$assert(($repo->product($productId)['verification_status'] ?? '') === 'pending', 'Seller re-verification requests move the product back to pending.');
$repo->appendVerificationRecord([
    'product_id' => $productId,
    'status' => 'verified',
    'provider' => 'official.verifier.fixture',
    'source_url' => 'https://example.com/item/1',
    'checked_facts' => "品牌: Daiying\n型号: V1",
    'raw_evidence' => "页面标题: 测试商品\n抓取方式: fixture",
]);
$repo->saveProduct([
    'id' => $productId,
    'name' => '测试商品',
    'sku' => 'TEST-001',
    'status' => 'active',
    'price_minor' => 36000,
    'currency' => 'CNY',
    'stock_quantity' => 5,
    'transaction_region' => 'cross_border',
    'shipping_fee_minor' => 1200,
    'tax_fee_minor' => 800,
    'service_fee_minor' => 300,
    'discount_minor' => 100,
    'price_note' => '跨境订单费用以结算页快照为准',
    'source_url' => 'https://example.com/item/1',
    'source_claim_text' => '官方授权渠道采购',
    'brand' => 'Daiying Updated',
    'model' => 'V1',
    'specs' => "颜色: 黑色\n容量: 128GB",
]);
$pendingProduct = $repo->product($productId);
$verificationAfterChange = $repo->verificationRecords($productId);
$assert(($pendingProduct['verification_status'] ?? '') === 'pending', 'Changing key product facts invalidates verified source status.');
$assert(($verificationAfterChange[0]['provider'] ?? '') === 'system', 'Verification invalidation is recorded by the system as a separate fact.');
$assert(($verificationAfterChange[0]['record_type'] ?? '') === 'system_invalidation', 'System invalidation is distinguishable from seller requests and provider results.');

$controller = new CommerceController($repo, $pdo, Settings::fromArray(['security' => ['encryption_key' => 'commerce-test-secret']]));
$productPage = $controller->productPage(new Request('GET', '/commerce/product', ['id' => $productId]))->body();
$assert(str_contains($productPage, '商品透明档案'), 'Public product page exposes a consumer transparency profile.');
$assert(str_contains($productPage, '来源声明'), 'Consumer transparency profile includes the seller source declaration.');
$assert(str_contains($productPage, '最近核验'), 'Consumer transparency profile includes the latest provider verification time.');
$assert(str_contains($productPage, '存在差异'), 'Consumer transparency profile shows mismatches after key product changes.');
$assert(str_contains($productPage, '商品关键修改历史'), 'Consumer transparency profile includes public key change history.');
$assert(str_contains($productPage, '价格透明'), 'Consumer transparency profile includes transparent price and fee facts.');
$assert(str_contains($productPage, '支付处理方'), 'Consumer transparency profile includes payment processor context.');
$assert(str_contains($productPage, '核验历史'), 'Consumer transparency profile includes verification history.');
$assert(!str_contains($productPage, '正品认证'), 'Consumer transparency wording avoids unsupported authenticity claims.');

$variantId = $repo->saveVariant([
    'product_id' => $productId,
    'title' => '黑色 128GB',
    'sku' => 'TEST-001-BLK-128',
    'stock_quantity' => 2,
    'price_delta_minor' => 100,
    'status' => 'active',
]);
$variantOrder = $repo->createPendingOrder($productId, $variantId, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-variant', hash('sha256', 'variant'));
$variantAfterReserve = $repo->variant($variantId);
$assert((int) ($variantAfterReserve['reserved_quantity'] ?? 0) === 1, 'Variant checkout reserves variant inventory.');
$repo->markOrderPaymentFailed((int) $variantOrder['id'], 'variant provider rejected');
$variantAfterFailure = $repo->variant($variantId);
$assert((int) ($variantAfterFailure['reserved_quantity'] ?? 0) === 0, 'Failed variant payment releases variant inventory.');

$failed = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 2, 'fixture', 'commerce-test-failed', hash('sha256', 'failed'));
$productAfterReserve = $repo->product($productId);
$assert((int) ($productAfterReserve['reserved_quantity'] ?? 0) === 2, 'Checkout reserves product inventory.');
$repo->markOrderPaymentFailed((int) $failed['id'], 'provider rejected');
$productAfterFailure = $repo->product($productId);
$assert((int) ($productAfterFailure['reserved_quantity'] ?? 0) === 0, 'Failed payment releases reserved inventory.');

$cancelled = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-cancelled', hash('sha256', 'cancelled'));
$repo->cancelPendingOrder((int) $cancelled['id'], 'admin cancelled');
$cancelledOrder = $repo->order((int) $cancelled['id']);
$productAfterCancel = $repo->product($productId);
$assert(($cancelledOrder['status'] ?? '') === 'cancelled', 'Pending order can be cancelled by admin.');
$assert((int) ($productAfterCancel['reserved_quantity'] ?? 0) === 0, 'Cancelled pending order releases reserved inventory.');

$paid = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-paid', hash('sha256', 'paid'));
$repo->attachPayment((int) $paid['id'], 123);
$repo->markOrderPaid((int) $paid['id']);
$paidOrder = $repo->order((int) $paid['id']);
$productAfterPaid = $repo->product($productId);

$assert(($paidOrder['status'] ?? '') === 'paid', 'Paid order status is persisted.');
$assert((int) ($paidOrder['amount_minor'] ?? 0) === 38200, 'Order total includes transparent shipping, tax, service, and discount components.');
$assert((int) ($productAfterPaid['sold_quantity'] ?? 0) === 1, 'Paid order increments sold quantity.');
$assert((int) ($productAfterPaid['available_quantity'] ?? 0) === 4, 'Paid order reduces available stock.');
$assert(($paidOrder['snapshot']['product']['name'] ?? '') === '测试商品', 'Order keeps product snapshot.');
$assert((int) ($paidOrder['snapshot']['pricing']['shipping_fee_minor'] ?? 0) === 1200, 'Order snapshot freezes shipping fee at checkout time.');
$assert(($paidOrder['snapshot']['pricing']['transaction_region'] ?? '') === 'cross_border', 'Order snapshot freezes transaction-region at checkout time.');
$repo->markOrderFulfilled((int) $paid['id']);
$fulfilledOrder = $repo->order((int) $paid['id']);
$assert(($fulfilledOrder['status'] ?? '') === 'fulfilled', 'Paid order can be marked fulfilled.');
$assert(($fulfilledOrder['fulfillment_status'] ?? '') === 'fulfilled', 'Fulfilled order updates fulfillment status.');

$shippingProductId = $repo->saveProduct([
    'name' => '实体商品',
    'sku' => 'SHIP-001',
    'status' => 'active',
    'price_minor' => 9900,
    'currency' => 'CNY',
    'stock_quantity' => 3,
    'requires_shipping' => '1',
]);
$repo->saveAction(['product_id' => $shippingProductId, 'action_type' => 'site_checkout', 'label' => '购买实体商品', 'fulfillment_mode' => 'shipping']);
$shippingOrder = $repo->createPendingOrder($shippingProductId, null, (int) $repo->activeActions($shippingProductId)[0]['id'], 1, 'fixture', 'commerce-test-shipping', hash('sha256', 'shipping'));
$repo->markOrderPaid((int) $shippingOrder['id']);
$repo->appendLogisticsEvent([
    'order_id' => (int) $shippingOrder['id'],
    'status' => 'in_transit',
    'carrier' => 'SF Express',
    'tracking_number' => 'SF123456',
    'provider' => 'manual',
    'raw_status' => 'transit',
    'raw_payload' => "node: Shanghai\nsource: operator",
    'message' => '包裹运输中',
    'occurred_at' => '2026-09-06 12:00:00',
]);
$logisticsEvents = $repo->logisticsEvents((int) $shippingOrder['id']);
$shippingInTransit = $repo->order((int) $shippingOrder['id']);
$assert(($shippingInTransit['fulfillment_status'] ?? '') === 'in_transit', 'Shipping order records the latest logistics status.');
$assert(($logisticsEvents[0]['raw_payload']['node'] ?? '') === 'Shanghai', 'Logistics raw provider facts are stored as structured append-only data.');
$repo->appendLogisticsEvent([
    'order_id' => (int) $shippingOrder['id'],
    'status' => 'delivered',
    'carrier' => 'SF Express',
    'tracking_number' => 'SF123456',
    'message' => '已签收',
    'occurred_at' => '2026-09-07 09:30:00',
]);
$shippingDelivered = $repo->order((int) $shippingOrder['id']);
$assert(($shippingDelivered['status'] ?? '') === 'fulfilled', 'Delivered logistics status fulfills the shipping order.');
$assert(($shippingDelivered['fulfillment_status'] ?? '') === 'delivered', 'Delivered logistics status is kept as an explicit fulfillment fact.');

$blockedNonShipping = false;
try {
    $repo->appendLogisticsEvent(['order_id' => (int) $paid['id'], 'status' => 'picked_up']);
} catch (RuntimeException) {
    $blockedNonShipping = true;
}
$assert($blockedNonShipping, 'Non-shipping orders reject logistics facts.');

$synced = $repo->createPendingOrder($productId, null, (int) $repo->activeActions($productId)[0]['id'], 1, 'fixture', 'commerce-test-sync', hash('sha256', 'sync'));
$paymentRepo = new PaymentRepository($pdo);
$paymentId = $paymentRepo->insertPayment([
    'subject_type' => 'commerce_order',
    'subject_id' => 'order:' . (int) $synced['id'],
    'provider_id' => 'fixture',
    'remote_id' => 'remote-sync',
    'reference' => 'reference-sync',
    'status' => 'paid',
    'amount_minor' => (int) $synced['amount_minor'],
    'currency' => (string) $synced['currency'],
    'idempotency_key' => 'payment-sync',
    'request_hash' => hash('sha256', 'payment-sync'),
    'metadata' => [],
]);
$repo->attachPayment((int) $synced['id'], $paymentId);
$syncResult = $repo->markTrustedPaidOrders($paymentRepo);
$syncedOrder = $repo->order((int) $synced['id']);
$assert((int) ($syncResult['marked'] ?? 0) === 1, 'Trusted paid CMS payment can sync a pending commerce order.');
$assert(($syncedOrder['status'] ?? '') === 'paid', 'Synced commerce order is marked paid.');

$repo->saveProduct([
    'id' => $productId,
    'name' => '测试商品改名',
    'sku' => 'TEST-001',
    'status' => 'active',
    'price_minor' => 36100,
    'currency' => 'CNY',
    'stock_quantity' => 5,
    'transaction_region' => 'cross_border',
    'shipping_fee_minor' => 1200,
    'tax_fee_minor' => 800,
    'service_fee_minor' => 300,
    'discount_minor' => 100,
    'price_note' => '跨境订单费用以结算页快照为准',
    'source_url' => 'https://example.com/item/1',
    'source_claim_text' => '官方授权渠道采购',
]);
$changes = $repo->productChanges($productId);
$fields = array_values(array_map(static fn (array $row): string => (string) $row['field_name'], $changes));
$assert(in_array('name', $fields, true), 'Key product name changes are appended to immutable change history.');
$assert(in_array('price_minor', $fields, true), 'Key product price changes are appended to immutable change history.');

if ($failures > 0) {
    exit(1);
}

echo "Commerce V1 contract OK\n";
