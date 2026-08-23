<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\CjDropshipping\Api\CjCircuitBreaker;
use Official\CjDropshipping\Api\CjEndpointRegistry;
use Official\CjDropshipping\Api\CjErrorMapper;
use Official\CjDropshipping\Api\CjFixtureTransport;
use Official\CjDropshipping\Api\CjHttpClient;
use Official\CjDropshipping\Api\CjPointsBudget;
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjRateLimiter;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Catalog\CjCatalogInput;
use Official\CjDropshipping\Catalog\CjCatalogPreviewService;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;

function j2_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function j2_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j2_check(false, $message);
    } catch (Throwable) {
        j2_check(true, $message);
    }
}

function j2_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function j2_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function j2_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function j2_table_exists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);
    return $stmt->fetchColumn() !== false;
}

function j2_count(PDO $pdo, string $table): int
{
    return j2_table_exists($pdo, $table) ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : 0;
}

function j2_make_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j2_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/cj.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j2-static-test-key';
    j2_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j2_core_migrations($pdo);
    return [$root, $pdo];
}

function j2_install(PDO $pdo): void
{
    $installer = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo);
    $installer->installBundled('official.commerce', 1, true);
    $installer->installBundled('official.cj-dropshipping', 1, true);
}

function j2_client(PDO $pdo, array $fixtures = []): CjHttpClient
{
    $repo = new CjRepository($pdo);
    $registry = new CjEndpointRegistry();
    $transport = new CjFixtureTransport();
    $defaults = [
        'category.list' => [200, ['requestId' => 'j2-categories'], '{"code":"200","data":[{"categoryId":"cat-1","categoryName":"Home Decor"},{"categoryId":"cat-2","categoryName":"Accessories"}],"pointsInfo":{"usedToday":1,"remaining":99,"total":100}}'],
        'product.listV2' => [200, ['requestId' => 'j2-list'], '{"code":"200","data":{"total":2,"list":[{"pid":"CJ100001","productSku":"SKU-LAMP","productName":"Fixture Lamp","sellPrice":"12.34","currency":"USD","weight":"420"},{"pid":"CJ100002","productSku":"SKU-BAG","productName":"Fixture Bag","sellPrice":"18.90","currency":"USD","weight":"280"}],"pointsInfo":{"usedToday":5,"remaining":95,"total":100}}}'],
        'product.query' => [200, ['requestId' => 'j2-product'], '{"code":"200","data":{"pid":"CJ100001","productSku":"SKU-LAMP","productName":"Fixture <b>Lamp</b>","description":"<p>Hello</p><script>alert(1)</script><p onclick=\"bad()\">World</p>","sellPrice":"12.34","maxPrice":"15.00","currency":"USD","weight":"420"},"pointsInfo":{"usedToday":6,"remaining":94,"total":100}}'],
        'variant.query' => [200, ['requestId' => 'j2-variants'], '{"code":"200","data":[{"pid":"CJ100001","productSku":"SKU-LAMP","vid":"VID100001","variantSku":"VID-LAMP-BLACK","variantNameEn":"Black","sellPrice":"12.34","currency":"USD","weight":"420"},{"pid":"CJ100001","productSku":"SKU-LAMP","vid":"VID100002","variantSku":"VID-LAMP-WHITE","variantNameEn":"White","sellPrice":"13.00","currency":"USD","weight":"430"}],"pointsInfo":{"usedToday":7,"remaining":93,"total":100}}'],
        'variant.queryByVid' => [200, ['requestId' => 'j2-variant-by-vid'], '{"code":"200","data":{"pid":"CJ100001","productSku":"SKU-LAMP","vid":"VID100001","variantSku":"VID-LAMP-BLACK","variantNameEn":"Black","sellPrice":"12.34","currency":"USD","weight":"420"},"pointsInfo":{"usedToday":8,"remaining":92,"total":100}}'],
        'stock.queryByVid' => [200, ['requestId' => 'j2-stock'], '{"code":"200","data":[{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":17}],"pointsInfo":{"usedToday":9,"remaining":91,"total":100}}'],
        'warehouse.detail' => [200, ['requestId' => 'j2-warehouse'], '{"code":"200","data":{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US"},"pointsInfo":{"usedToday":10,"remaining":90,"total":100}}'],
    ];
    foreach ($fixtures + $defaults as $operation => $fixture) {
        $endpoint = $registry->resolve((string) $operation);
        $transport->add($endpoint['method'], $endpoint['url'], (int) $fixture[0], $fixture[1], (string) $fixture[2]);
    }
    return new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 1), new CjCircuitBreaker($repo));
}

function j2_commerce_counts(PDO $pdo): array
{
    return [
        'products' => j2_count($pdo, 'cms_commerce_products'),
        'variants' => j2_count($pdo, 'cms_commerce_product_variants'),
        'media' => j2_count($pdo, 'cms_commerce_product_media'),
        'inventory' => j2_count($pdo, 'cms_commerce_inventory_items'),
        'orders' => j2_count($pdo, 'cms_commerce_orders'),
    ];
}

[$root, $pdo] = j2_make_root('cms-cj-j2');
j2_install($pdo);
$repo = new CjRepository($pdo);
$service = new CjCatalogPreviewService(j2_client($pdo), $repo, new CjCatalogInput(), 300);
$input = new CjCatalogInput();

j2_check(j2_table_exists($pdo, 'cms_cj_catalog_cache'), '003 migration creates CJ-owned catalog cache table');
j2_check(json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true)['version'] === '1.0.0-rc1', 'CJ plugin version is 1.0.0-rc1');

$parsed = $input->parseSearch('lamp', 1, 20);
j2_check($parsed['type'] === 'keyword' && $parsed['page_size'] === 20, 'parses keyword search with controlled pagination');
$zhParsed = $input->parseSearch('台灯', 1, 20);
j2_check($zhParsed['type'] === 'keyword' && $zhParsed['value'] === '台灯', 'parses Chinese keyword search without treating it as an ASCII identifier');
$vidParsed = $input->parseSearch('VID100001', 1, 20);
j2_check($vidParsed['type'] === 'vid', 'parses VID input for variant.queryByVid');
$urlParsed = $input->parseSearch('https://www.cjdropshipping.com/product/detail?pid=CJ100001', 1, 20);
j2_check($urlParsed['type'] === 'pid' && $urlParsed['value'] === 'CJ100001', 'parses HTTPS official CJ product URL locally');
foreach (['http://www.cjdropshipping.com/product/detail?pid=CJ1', 'https://cjdropshipping.com.evil.test/product?pid=CJ1', 'https://example.com/product?pid=CJ1', str_repeat('a', 191), '<script>'] as $bad) {
    j2_throws(static fn () => $input->parseSearch($bad, 999, 999), 'rejects unsafe catalog input: ' . substr($bad, 0, 40));
}

$before = j2_commerce_counts($pdo);
$result = $service->search('lamp', 1, 2, 'j2-search-1');
j2_check(count($result['items']) === 2 && $result['items'][0]['cost_minor'] === 1234 && $result['points']['remaining'] === 95, 'maps keyword paginated search with minor-unit money and points');
$v2ShapeService = new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, ['requestId' => 'j2-v2-shape'], '{"code":"200","data":{"totalRecords":1,"totalPages":1,"content":{"productList":[{"id":"CJREAL1","nameEn":"Real CJ Shoe","sku":"CJREALSKU","spu":"CJREALSPU","productWeight":"420.00"}],"relatedCategoryList":[{"categoryId":"cat-real","categoryName":"Shoes"}],"keyWord":"shoes"}},"pointsInfo":{"remaining":88,"total":100}}']]), $repo, new CjCatalogInput(), 1);
$v2Shape = $v2ShapeService->search('real-shape', 1, 20, 'j2-v2-shape');
j2_check($v2Shape['total'] === 1 && $v2Shape['items'][0]['pid'] === 'CJREAL1' && $v2Shape['items'][0]['product_sku'] === 'CJREALSKU' && $v2Shape['items'][0]['title'] === 'Real CJ Shoe' && $v2Shape['items'][0]['weight_grams'] === 420, 'maps official CJ listV2 content.productList response shape');
$v2NestedContentService = new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, ['requestId' => 'j2-v2-nested-content'], '{"code":"200","data":{"totalRecords":6000,"content":[{"productList":[{"id":"CJNESTED1","nameEn":"Nested CJ Shoe","sku":"NESTED-SKU","productWeight":"388.50"}],"relatedCategoryList":[{"categoryId":"cat-shoe","categoryName":"Shoes"}],"keyWord":"shoes"}]},"pointsInfo":{"remaining":87,"total":100}}']]), $repo, new CjCatalogInput(), 1);
$v2NestedContent = $v2NestedContentService->search('nested-shape', 1, 20, 'j2-v2-nested-content');
j2_check($v2NestedContent['total'] === 6000 && $v2NestedContent['items'][0]['pid'] === 'CJNESTED1' && $v2NestedContent['items'][0]['title'] === 'Nested CJ Shoe', 'maps official CJ listV2 content array wrapping productList');
$realMoneyShapeService = new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, ['requestId' => 'j2-money-shape'], '{"code":"200","data":{"totalRecords":3,"content":[{"productList":[{"id":"CJMONEY1","nameEn":"No Price Yet","sku":"NO-PRICE"},{"id":"CJMONEY2","nameEn":"Range Price","sku":"RANGE-PRICE","sellPrice":"1.234-2.345"},{"id":"CJMONEY3","nameEn":"Text Price","sku":"TEXT-PRICE","listedSellPrice":"USD 3.456"}]}]},"pointsInfo":{"remaining":86,"total":100}}']]), $repo, new CjCatalogInput(), 1);
$realMoneyShape = $realMoneyShapeService->search('money-shape', 1, 20, 'j2-money-shape');
j2_check($realMoneyShape['items'][0]['cost_minor'] === 0 && $realMoneyShape['items'][1]['cost_minor'] === 123 && $realMoneyShape['items'][2]['cost_minor'] === 346, 'maps missing, range and text CJ list prices without float money');
$oldCacheKey = hash('sha256', 'search:' . json_encode(['page' => 1, 'page_size' => 20, 'type' => 'keyword', 'value' => 'stale-shape'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$repo->catalogCachePut($oldCacheKey, 'search', ['page' => 1, 'page_size' => 20, 'type' => 'keyword', 'value' => 'stale-shape'], ['items' => [['pid' => '', 'title' => '']], 'points' => []], 300);
$freshShapeService = new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, ['requestId' => 'j2-cache-version'], '{"code":"200","data":{"totalRecords":1,"content":{"productList":[{"id":"CJFRESH1","nameEn":"Fresh Cached Shape","sku":"FRESH-SKU","productWeight":"321"}]}},"pointsInfo":{"remaining":80,"total":100}}']]), $repo, new CjCatalogInput(), 300);
$freshShape = $freshShapeService->search('stale-shape', 1, 20, 'j2-cache-version');
j2_check(($freshShape['cache_hit'] ?? true) === false && $freshShape['items'][0]['pid'] === 'CJFRESH1', 'catalog cache schema version avoids stale pre-mapping blank results');
$badCurrentCacheKey = hash('sha256', 'catalog-map-v2:search:' . json_encode(['page' => 1, 'page_size' => 20, 'type' => 'keyword', 'value' => 'blank-current-cache'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$repo->catalogCachePut($badCurrentCacheKey, 'search', ['page' => 1, 'page_size' => 20, 'type' => 'keyword', 'value' => 'blank-current-cache'], ['items' => [['pid' => '', 'product_sku' => '', 'title' => '', 'currency' => 'USD', 'cost_minor' => 0, 'weight_grams' => 0]], 'total' => 6000, 'points' => []], 300);
$blankCacheService = new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, ['requestId' => 'j2-blank-cache'], '{"code":"200","data":{"totalRecords":1,"content":{"productList":[{"id":"CJBLANKFIX","nameEn":"Blank Cache Fixed","sku":"BLANK-FIX","productWeight":"222"}]}},"pointsInfo":{"remaining":79,"total":100}}']]), $repo, new CjCatalogInput(), 300);
$blankCache = $blankCacheService->search('blank-current-cache', 1, 20, 'j2-blank-cache');
j2_check(($blankCache['cache_hit'] ?? true) === false && $blankCache['items'][0]['pid'] === 'CJBLANKFIX', 'blank current-schema catalog cache is rejected and refetched');
$zhResult = $service->search('台灯', 1, 2, 'j2-search-zh');
j2_check(count($zhResult['items']) === 2 && ($zhResult['input']['value'] ?? '') === '台灯', 'maps Chinese keyword search through the same read-only Fixture catalog flow');
$categories = $service->categories('j2-categories');
j2_check(count($categories['items']) === 2 && $categories['items'][0]['name'] === 'Home Decor', 'maps CJ category fixture query through catalog service');
$logsAfterSearch = (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'product.listV2'")->fetchColumn();
$cached = $service->search('lamp', 1, 2, 'j2-search-cache');
j2_check(($cached['cache_hit'] ?? false) === true && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'product.listV2'")->fetchColumn() === $logsAfterSearch, 'cache hit returns stored snapshot without repeating API call or Points deduction');
$pdo->exec("UPDATE cms_cj_catalog_cache SET expires_at = '2000-01-01T00:00:00+00:00'");
$service->search('lamp', 1, 2, 'j2-search-expired');
j2_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'product.listV2'")->fetchColumn() === $logsAfterSearch + 1, 'expired cache refetches fixture data');

$pidSearch = $service->search('CJ100001', 1, 20, 'j2-pid');
$skuSearch = $service->search('SKU-LAMP', 1, 20, 'j2-product-sku');
$variantSearch = $service->search('VID100001', 1, 20, 'j2-variant-sku');
j2_check($pidSearch['items'][0]['pid'] === 'CJ100001' && $skuSearch['items'][0]['pid'] !== '' && $variantSearch['items'][0]['pid'] !== '', 'supports PID, Product SKU and Variant SKU lookup inputs');

$preview = $service->preview('CJ100001', 'j2-preview');
j2_check($preview->pid === 'CJ100001' && count($preview->variants) === 2 && count($preview->warehouses) >= 2, 'maps product detail, variants, stock and warehouse DTO');
j2_check(!str_contains($preview->descriptionHtml, '<script') && !str_contains($preview->descriptionHtml, 'onclick') && $preview->freshness === 'fresh', 'sanitizes product description and marks fresh inventory snapshots');
$rateLimitedPreviewService = new CjCatalogPreviewService(j2_client($pdo, [
    'product.query' => [200, ['requestId' => 'j2-rate-product'], '{"code":"1600200","message":"too frequent"}'],
    'product.listV2' => [200, ['requestId' => 'j2-rate-list'], '{"code":"200","data":{"totalRecords":1,"content":{"productList":[{"id":"CJ-RATE-LIMIT","nameEn":"Rate Limited Fallback Shoe","sku":"RATE-SKU","sellPrice":"3.41","productWeight":"333"}]}},"pointsInfo":{"remaining":77,"total":100}}'],
]), $repo, new CjCatalogInput(), 300);
$rateLimitedPreview = $rateLimitedPreviewService->preview('CJ-RATE-LIMIT', 'j2-rate-preview');
j2_check($rateLimitedPreview->pid === 'CJ-RATE-LIMIT' && $rateLimitedPreview->title === 'Rate Limited Fallback Shoe' && $rateLimitedPreview->freshness === 'stale' && $rateLimitedPreview->variants === [], 'preview falls back to list data when CJ detail endpoint returns retryable 1600200');
$variantLimitedPreviewService = new CjCatalogPreviewService(j2_client($pdo, [
    'product.query' => [200, ['requestId' => 'j2-variant-rate-product'], '{"code":"200","data":{"pid":"CJ-VARIANT-RATE","productSku":"SKU-VARIANT-RATE","productName":"Variant Rate Product","sellPrice":"9.99","currency":"USD","weight":"111"},"pointsInfo":{"remaining":76,"total":100}}'],
    'variant.query' => [200, ['requestId' => 'j2-variant-rate'], '{"code":"1600200","message":"too frequent"}'],
]), $repo, new CjCatalogInput(), 300);
$variantLimitedPreview = $variantLimitedPreviewService->preview('CJ-VARIANT-RATE', 'j2-variant-rate-preview');
j2_check($variantLimitedPreview->pid === 'CJ-VARIANT-RATE' && $variantLimitedPreview->variants === [] && $variantLimitedPreview->freshness === 'stale', 'preview keeps product detail when variant endpoint is temporarily rate limited');
j2_check($before === j2_commerce_counts($pdo), 'J2 read-only search and preview leave Commerce products, variants, media, inventory and orders unchanged');

$emptyService = new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, ['requestId' => 'empty'], '{"code":"200","data":{"total":0,"list":[]},"pointsInfo":{"remaining":88,"total":100}}']]), $repo, new CjCatalogInput(), 1);
j2_check($emptyService->search('empty', 1, 20, 'j2-empty')['items'] === [], 'empty CJ results map to an empty list');
j2_throws(static fn () => (new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, [], '{"code":"500","message":"failed"}']]), $repo))->search('broken', 1, 20), 'HTTP 200 with failed CJ code is rejected');
j2_throws(static fn () => (new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, [], '{"code":"200","result":false}']]), $repo))->search('result-false', 1, 20), 'HTTP 200 with result=false is rejected');
foreach ([401, 403, 429, 500] as $status) {
    j2_throws(static fn () => (new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [$status, [], '{"code":"' . $status . '","message":"error"}']]), $repo))->search('http-error-' . $status, 1, 20), 'HTTP ' . $status . ' maps to safe failure');
}
$repo->closeCircuit('auth');
$repo->openCircuit('service', 300, ['reason' => 'j2']);
j2_throws(static fn () => $service->search('circuit', 1, 20), 'service circuit safely blocks J2 catalog search');
$repo->closeCircuit('service');
$repo->recordPoints(['used_today' => 0, 'remaining' => 0, 'total' => 100]);
j2_throws(static fn () => j2_client($pdo, ['product.listV2' => [200, [], '{"code":"200","data":{"list":[]}}']])->call('product.listV2', ['keyWord' => 'points-low'], [], 'P2'), 'low-priority Points budget exhaustion safely degrades without fake success data');
j2_check($service->search('points-low', 1, 20)['cache_hit'] === false, 'admin P1 read-only catalog search may probe CJ when a local Points snapshot is stale');
$repo->recordPoints(['used_today' => 0, 'remaining' => 100, 'total' => 100]);
j2_throws(static fn () => (new CjCatalogPreviewService(j2_client($pdo, ['product.listV2' => [200, [], '{"code":"200","data":{"list":[{"pid":"CJX","productName":"Bad","sellPrice":1.2,"currency":"USD"}]}}']]), $repo))->search('float-price', 1, 20), 'rejects float money values in CJ catalog response');

$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j2-static-test-key')))->bootEnabled();
$routeMap = [];
foreach ($runtime->routes() as $route) {
    $routeMap[$route->method . ' ' . $route->path] = $route;
}
j2_check(isset($routeMap['GET /admin/cj/catalog']) && $routeMap['GET /admin/cj/catalog']->capability === 'cj.catalog.search', 'catalog search route requires cj.catalog.search');
j2_check(isset($routeMap['GET /admin/cj/catalog/preview']) && $routeMap['GET /admin/cj/catalog/preview']->capability === 'cj.catalog.search', 'catalog preview route requires cj.catalog.search');
$searchHtml = $routeMap['GET /admin/cj/catalog']->handler->__invoke(new Cms\Core\Http\Request('GET', '/admin/cj/catalog', ['q' => '台灯']))->body();
j2_check(str_contains($searchHtml, 'Fixture') && !str_contains($searchHtml, 'api-key') && !str_contains($searchHtml, 'open-id') && !str_contains($searchHtml, '<script'), 'admin catalog page renders sanitized Chinese keyword preview without secret leakage');
$previewHtml = $routeMap['GET /admin/cj/catalog/preview']->handler->__invoke(new Cms\Core\Http\Request('GET', '/admin/cj/catalog/preview', ['q' => 'CJ100001']))->body();
j2_check(str_contains($previewHtml, '创建 Commerce 草稿') && !str_contains($previewHtml, '<script') && !str_contains($previewHtml, 'onclick'), 'admin preview page remains sanitized after J3 draft-import form is enabled');
$fallbackHtml = $routeMap['GET /admin/cj/catalog/preview']->handler->__invoke(new Cms\Core\Http\Request('GET', '/admin/cj/catalog/preview', ['q' => 'CJ-VARIANT-RATE']))->body();
j2_check(str_contains($fallbackHtml, '详情接口暂时受限') && str_contains($fallbackHtml, '暂无可导入变体') && !str_contains($fallbackHtml, '<form id="cj-import-form"'), 'admin preview shows safe Chinese fallback and does not allow import when variants are unavailable');

$manifest = json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true);
j2_check(in_array('cj.catalog.search', $manifest['capabilities'] ?? [], true) && in_array('cj.data.purge', $manifest['capabilities'] ?? [], true), 'manifest declares cj.catalog.search and keeps independent cj.data.purge');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j2_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j2_core_migrations($mysqlPdo);
            j2_install($mysqlPdo);
            $mysqlRepo = new CjRepository($mysqlPdo);
            $mysqlService = new CjCatalogPreviewService(j2_client($mysqlPdo), $mysqlRepo, new CjCatalogInput(), 300);
            $mysqlBefore = j2_commerce_counts($mysqlPdo);
            $mysqlService->search('lamp', 1, 20, 'j2-mysql-search');
            $mysqlService->preview('CJ100001', 'j2-mysql-preview');
            j2_check(j2_table_exists($mysqlPdo, 'cms_cj_catalog_cache') && $mysqlRepo->catalogCacheCount() >= 2 && $mysqlBefore === j2_commerce_counts($mysqlPdo), 'MySQL installs J2 schema, caches fixture preview and leaves Commerce counts unchanged');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        $mysqlStatus = 'failed';
        j2_check(false, 'MySQL J2 catalog preview validation failed: ' . $exception->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j2_check(false, 'pdo_mysql extension is required for CJ J2 MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;

j2_remove($root);

if ($failures > 0) {
    echo '[RESULT] CJ J2 catalog preview checks failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ J2 catalog preview checks passed.' . PHP_EOL;
