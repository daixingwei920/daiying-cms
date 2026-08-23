<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginSecretStore;
use Official\CjDropshipping\Api\CjApiException;
use Official\CjDropshipping\Api\CjEndpointRegistry;
use Official\CjDropshipping\Api\CjErrorMapper;
use Official\CjDropshipping\Api\CjHttpClient;
use Official\CjDropshipping\Api\CjPointsBudget;
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjRealHttpTransport;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Auth\CjTokenManager;
use Official\CjDropshipping\Production\CjProductionReadOnlyProbe;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;

function cjro_check(bool $condition, string $message): void
{
    global $failures;
    echo '[' . ($condition ? 'PASS' : 'FAIL') . '] ' . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function cjro_remove(string $path): void
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

function cjro_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function cjro_make_pdo(): array
{
    $root = sys_get_temp_dir() . '/cms-cj-readonly-' . bin2hex(random_bytes(4));
    cjro_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/readonly.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-readonly-static-key';
    cjro_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}

function cjro_env(bool $enabled): void
{
    foreach (['APP_ENV', 'CJ_REAL_TRANSPORT_ENABLED', 'CJ_LIVE_TESTS', 'CJ_LIVE_READONLY_TESTS', 'CJ_LIVE_MUTATING_TESTS', 'CJ_LIVE_ORDER_TESTS', 'CJ_LIVE_PAYMENT_TESTS', 'CJ_LIVE_WEBHOOK_TESTS', 'CJ_REAL_REMOTE_WRITES_ENABLED', 'CJ_REAL_ORDER_SUBMIT_ENABLED', 'CJ_REAL_SUPPLIER_PAYMENT_ENABLED', 'CJ_REAL_WEBHOOK_REGISTRATION_ENABLED'] as $name) {
        putenv($name);
    }
    if ($enabled) {
        putenv('APP_ENV=production');
        putenv('CJ_REAL_TRANSPORT_ENABLED=1');
        putenv('CJ_LIVE_TESTS=1');
        putenv('CJ_LIVE_READONLY_TESTS=1');
    }
}

[$root, $pdo] = cjro_make_pdo();
$repo = new CjRepository($pdo);
$tokens = new CjTokenManager(new PluginSecretStore($pdo, 'cj-readonly-static-key'), $repo, new CjRedactor());
$tokens->saveApiKey('CJ-READONLY-API-KEY-SECRET', 'Read Only Account');
$registry = new CjEndpointRegistry();
$calls = [];
$transport = new CjRealHttpTransport(
    $registry->allowedHosts(),
    1,
    static fn (): array => ['8.8.8.8'],
    static function (string $method, string $url, array $headers, string $body) use (&$calls, $registry): array {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $calls[] = [$method, $path, $body, $headers, $url];
        $responses = [
            '/api2.0/v1/authentication/getAccessToken' => '{"code":200,"success":true,"data":{"openId":"readonly-open-id","accessToken":"readonly-access-token","refreshToken":"readonly-refresh-token","accessTokenExpiryDate":"2026-09-01T00:00:00+00:00","refreshTokenExpiryDate":"2027-02-01T00:00:00+00:00"}}',
            '/api2.0/v1/setting/get' => '{"code":200,"success":true,"data":{"openName":"readonly","setting":{"qpsLimit":100}}}',
            '/api2.0/v1/shopping/pointInfo' => '{"code":200,"success":true,"data":{"pointsInfo":{"remaining":88,"total":100}}}',
            '/api2.0/v1/product/getCategory' => '{"code":200,"success":true,"data":[{"categoryId":"cat-1","categoryName":"Shoes"}],"pointsInfo":{"remaining":88,"total":100}}',
            '/api2.0/v1/product/listV2' => '{"code":200,"success":true,"data":{"total":1,"list":[{"pid":"CJ-PID-1","productSku":"CJ-SKU-1","productName":"Real Readonly Shoe","sellPrice":"12.34","currency":"USD","weight":"420"}]}}',
            '/api2.0/v1/product/query' => '{"code":200,"success":true,"data":{"pid":"CJ-PID-1","productName":"Real Readonly Shoe Detail","sellPrice":"12.34","currency":"USD","weight":"420"}}',
            '/api2.0/v1/product/variant/query' => '{"code":200,"success":true,"data":[{"vid":"CJ-VID-1","variantSku":"CJ-VSKU-1","sellPrice":"12.34","currency":"USD","weight":"420"}]}',
            '/api2.0/v1/product/variant/queryByVid' => '{"code":200,"success":true,"data":{"vid":"CJ-VID-1","variantSku":"CJ-VSKU-1","sellPrice":"12.34","currency":"USD","weight":"420"}}',
            '/api2.0/v1/product/stock/queryByVid' => '{"code":200,"success":true,"data":[{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US","totalInventoryNum":12}]}',
            '/api2.0/v1/warehouse/detail' => '{"code":200,"success":true,"data":{"warehouseId":"WH-US","warehouseName":"US Warehouse","countryCode":"US"}}',
            '/api2.0/v1/logistic/freightCalculate' => '{"code":200,"success":true,"data":[{"logisticName":"CJ Standard","logisticPrice":"6.50","currency":"USD","agingMin":7,"agingMax":14,"warehouse":"WH-US"}]}',
        ];
        return ['status' => 200, 'headers' => ['requestId' => 'readonly-' . md5($path)], 'body' => $responses[$path] ?? '{"code":404,"success":false}', 'url' => $url];
    }
);
$client = new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, null, null, null, static fn (string $operation): ?string => str_starts_with($operation, 'auth.') ? null : $tokens->accessToken());
$probe = new CjProductionReadOnlyProbe($client, $tokens);

cjro_env(false);
try {
    $probe->run(['query' => 'shoes']);
    cjro_check(false, 'read-only probe refuses to run without live read-only gate');
} catch (CjApiException $exception) {
    cjro_check($exception->codeValue === 'live_readonly_disabled', 'read-only probe refuses to run without live read-only gate');
}
cjro_check($calls === [], 'blocked read-only probe performs zero CJ transport calls');

cjro_env(true);
$repo->recordPoints(['used_today' => 0, 'remaining' => 0, 'total' => 100]);
$budgetedClient = new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, null, new CjPointsBudget($repo), null, static fn (string $operation): ?string => str_starts_with($operation, 'auth.') ? null : $tokens->accessToken());
try {
    $budgetedClient->call('product.listV2', ['keyWord' => 'shoes', 'page' => 1, 'size' => 5], [], 'P1', 'budget-blocked-search');
    cjro_check(true, 'P1 read-only product search may probe CJ when local Points snapshot is stale or exhausted');
} catch (CjApiException $exception) {
    cjro_check(false, 'P1 read-only product search may probe CJ when local Points snapshot is stale or exhausted: ' . $exception->codeValue);
}
$budgetedClient->call('category.list', [], [], 'P0', 'budget-refresh-points');
cjro_check($repo->latestPointsRemaining() === 88, 'a lightweight read-only official endpoint can refresh real CJ Points even when local budget is exhausted');
$result = $probe->run(['query' => 'shoes', 'country' => 'US', 'state' => 'CA', 'postal' => '90001', 'quantity' => 1]);
cjro_check($result['authenticated'] === true && (string) ($result['product']['pid'] ?? '') === 'CJ-PID-1', 'read-only probe authenticates and resolves product detail through CJ client');
cjro_check((int) ($result['inventory']['quantity'] ?? 0) === 12 && (string) ($result['shipping']['service'] ?? '') === 'CJ Standard', 'read-only probe maps inventory and freight summary');
cjro_check((int) ($result['writes_performed'] ?? 1) === 0, 'read-only probe reports zero remote writes');
$paths = array_map(static fn (array $call): string => (string) $call[1], $calls);
cjro_check(!in_array('/api2.0/v1/shopping/order/createOrderV2', $paths, true) && !in_array('/api2.0/v1/webhook/set', $paths, true), 'read-only probe never calls order.create or webhook.set');
$listCall = array_values(array_filter($calls, static fn (array $call): bool => (string) $call[1] === '/api2.0/v1/product/listV2'))[0] ?? null;
cjro_check(is_array($listCall) && (string) $listCall[2] === '' && str_contains((string) $listCall[4], 'keyWord=shoes') && str_contains((string) $listCall[4], 'size=5'), 'real GET product search uses CJ query parameters and an empty body');
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
cjro_check(is_string($encoded) && !str_contains($encoded, 'CJ-READONLY-API-KEY-SECRET') && !str_contains($encoded, 'readonly-access-token') && !str_contains($encoded, 'readonly-refresh-token') && !str_contains($encoded, 'readonly-open-id'), 'read-only probe result does not leak secrets or tokens');

cjro_remove($root);
cjro_env(false);

if ($failures > 0) {
    echo '[RESULT] FAILURES=' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ Production V1 read-only probe checks passed.' . PHP_EOL;
