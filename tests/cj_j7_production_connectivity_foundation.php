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
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjProductionGate;
use Official\CjDropshipping\Api\CjRealHttpTransport;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Auth\CjTokenManager;
use Official\CjDropshipping\Fulfillment\CjProductionFulfillmentProvider;
use Official\CjDropshipping\Fulfillment\CjProductionTrialPreflight;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;

function j7_check(bool $condition, string $message): void
{
    global $failures;
    echo '[' . ($condition ? 'PASS' : 'FAIL') . '] ' . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function j7_throws_code(callable $callback, string $code, string $message): void
{
    try {
        $callback();
        j7_check(false, $message);
    } catch (CjApiException $exception) {
        j7_check($exception->codeValue === $code, $message . ' (' . (string) $exception->codeValue . ')');
    } catch (Throwable $exception) {
        j7_check(false, $message . ' unexpected ' . get_class($exception));
    }
}

function j7_remove(string $path): void
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

function j7_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function j7_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function j7_make_root(): array
{
    $root = sys_get_temp_dir() . '/cms-cj-j7-' . bin2hex(random_bytes(4));
    j7_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j7.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j7-static-test-key';
    j7_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j7_core_migrations($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}

function j7_env(?string $appEnv, ?string $realTransport, ?string $realOrder = null, ?string $realWebhook = null): void
{
    putenv($appEnv === null ? 'APP_ENV' : 'APP_ENV=' . $appEnv);
    putenv($realTransport === null ? 'CJ_REAL_TRANSPORT_ENABLED' : 'CJ_REAL_TRANSPORT_ENABLED=' . $realTransport);
    putenv('CJ_REAL_REMOTE_WRITES_ENABLED');
    putenv('CJ_REAL_SUPPLIER_PAYMENT_ENABLED');
    putenv($realOrder === null ? 'CJ_REAL_ORDER_SUBMIT_ENABLED' : 'CJ_REAL_ORDER_SUBMIT_ENABLED=' . $realOrder);
    putenv($realWebhook === null ? 'CJ_REAL_WEBHOOK_REGISTRATION_ENABLED' : 'CJ_REAL_WEBHOOK_REGISTRATION_ENABLED=' . $realWebhook);
}

[$root, $pdo] = j7_make_root();
$repo = new CjRepository($pdo);
$redactor = new CjRedactor();
$registry = new CjEndpointRegistry();

j7_env('production', null);
j7_check(CjProductionGate::isProduction() && !CjProductionGate::realTransportEnabled(), 'production mode keeps real CJ transport disabled by default');
$disabledTransport = new CjRealHttpTransport($registry->allowedHosts(), 1, static fn (): array => ['8.8.8.8'], static fn (): array => throw new RuntimeException('must not call sender'));
j7_throws_code(static fn () => $disabledTransport->request('POST', $registry->resolve('auth.accessToken')['url'], [], '{}', 1, 1024), 'real_transport_disabled', 'real transport refuses requests until explicit env gate is enabled');

j7_env('production', '1');
$senderCalls = 0;
$transport = new CjRealHttpTransport(
    $registry->allowedHosts(),
    1,
    static fn (string $host): array => ['8.8.8.8'],
    static function (string $method, string $url, array $headers, string $body, int $timeout, int $maxBytes) use (&$senderCalls, $registry): array {
        $senderCalls++;
        j7_check($method === 'POST' && $url === $registry->resolve('auth.accessToken')['url'], 'real auth uses official CJ getAccessToken endpoint');
        j7_check(!str_contains($body, 'AccessToken') && str_contains($body, 'apiKey'), 'real auth request body uses apiKey and does not expose stored tokens');
        return [
            'status' => 200,
            'headers' => ['requestId' => 'j7-auth-fixture'],
            'body' => '{"code":200,"result":true,"success":true,"data":{"openId":"123456","accessToken":"real-access-fixture","accessTokenExpiryDate":"2026-09-01T00:00:00+00:00","refreshToken":"real-refresh-fixture","refreshTokenExpiryDate":"2027-02-01T00:00:00+00:00"}}',
            'url' => $url,
        ];
    }
);
$client = new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, $redactor);
$response = $client->call('auth.accessToken', ['apiKey' => 'CJUserNum@api@secret'], ['Content-Type' => 'application/json'], 'P0', 'j7-auth');
j7_check((string) ($response['data']['openId'] ?? '') === '123456' && $senderCalls === 1, 'real auth response can be decoded without a real network call in tests');

$tokens = new CjTokenManager(new PluginSecretStore($pdo, 'cj-j7-static-test-key'), $repo, $redactor);
$tokens->storeTokenResponse($response, 'production');
$connection = $repo->connection() ?? [];
j7_check((string) ($connection['mode'] ?? '') === 'production' && (string) ($connection['status'] ?? '') === 'connected', 'Token Manager records production connection mode after real auth');

$preflight = new CjProductionTrialPreflight($repo, $tokens);
$defaultPreflight = $preflight->evaluate([]);
j7_check($defaultPreflight['allowed'] === false && count(array_filter($defaultPreflight['checks'], static fn (array $check): bool => $check['status'] === 'fail')) >= 1, 'production trial preflight fails closed without operator approval and budget');
putenv('CJ_REAL_REMOTE_WRITES_ENABLED=1');
putenv('CJ_REAL_ORDER_SUBMIT_ENABLED=1');
$orderOnlyPreflight = $preflight->evaluate([
    'operator_authorized' => '1',
    'max_product_minor' => '100',
    'max_shipping_minor' => '200',
    'max_total_minor' => '300',
    'shipping_identity_confirmed' => '1',
    'shipping_address_confirmed' => '1',
]);
j7_check($orderOnlyPreflight['allowed'] === true, 'production trial preflight can pass for auth and order submission without supplier payment or webhook registration');
$paymentPreflight = $preflight->evaluate([
    'operator_authorized' => '1',
    'max_product_minor' => '100',
    'max_shipping_minor' => '200',
    'max_total_minor' => '300',
    'shipping_identity_confirmed' => '1',
    'shipping_address_confirmed' => '1',
    'supplier_payment_allowed' => '1',
]);
j7_check($paymentPreflight['allowed'] === false, 'production trial preflight keeps supplier payment behind a separate gate');
putenv('CJ_REAL_SUPPLIER_PAYMENT_ENABLED=1');
putenv('CJ_REAL_WEBHOOK_REGISTRATION_ENABLED=1');
$fullPreflight = $preflight->evaluate([
    'operator_authorized' => '1',
    'max_product_minor' => '100',
    'max_shipping_minor' => '200',
    'max_total_minor' => '300',
    'shipping_identity_confirmed' => '1',
    'shipping_address_confirmed' => '1',
    'supplier_payment_allowed' => '1',
    'webhook_registration_allowed' => '1',
]);
j7_check($fullPreflight['allowed'] === true, 'production trial preflight passes only when optional payment and webhook gates are explicitly enabled');

$authenticatedCalls = 0;
$authenticatedTransport = new CjRealHttpTransport(
    $registry->allowedHosts(),
    1,
    static fn (): array => ['8.8.8.8'],
    static function (string $method, string $url, array $headers) use (&$authenticatedCalls): array {
        $authenticatedCalls++;
        j7_check((string) ($headers['CJ-Access-Token'] ?? '') === 'real-access-fixture', 'non-auth CJ API calls receive CJ-Access-Token server-side');
        return ['status' => 200, 'headers' => ['requestId' => 'j7-points'], 'body' => '{"code":200,"success":true,"data":{"pointsInfo":{"usedToday":1,"remaining":99,"total":100}}}', 'url' => $url];
    }
);
$authenticatedClient = new CjHttpClient($registry, $authenticatedTransport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, $redactor, 10, 1048576, null, null, null, static fn (): string => $tokens->accessToken());
$authenticatedClient->call('points.query', [], [], 'P0', 'j7-authenticated-read');
j7_check($authenticatedCalls === 1, 'authenticated read uses the real transport once');

putenv('CJ_REAL_ORDER_SUBMIT_ENABLED');
$blockedWriteCalls = 0;
$writeTransport = new CjRealHttpTransport($registry->allowedHosts(), 1, static fn (): array => ['8.8.8.8'], static function () use (&$blockedWriteCalls): array {
    $blockedWriteCalls++;
    return ['status' => 200, 'headers' => [], 'body' => '{"code":200,"success":true}', 'url' => 'https://developers.cjdropshipping.com/api2.0/v1/shopping/order/createOrderV2'];
});
$writeClient = new CjHttpClient($registry, $writeTransport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, $redactor);
j7_throws_code(static fn () => $writeClient->call('order.create', ['order' => 'blocked'], [], 'P0', 'j7-write-block'), 'real_write_disabled', 'real CJ order creation is blocked unless the order-submit gate is separately enabled');
j7_check($blockedWriteCalls === 0, 'blocked real write does not call the transport');

putenv('CJ_REAL_REMOTE_WRITES_ENABLED=1');
putenv('CJ_REAL_ORDER_SUBMIT_ENABLED=1');
$productionPayload = [];
$productionTransport = new CjRealHttpTransport($registry->allowedHosts(), 1, static fn (): array => ['8.8.8.8'], static function (string $method, string $url, array $headers, string $body) use (&$productionPayload): array {
    $productionPayload = json_decode($body, true) ?: [];
    return ['status' => 200, 'headers' => ['requestId' => 'j7-production-order'], 'body' => '{"code":200,"success":true,"data":{"orderId":"CJ-PROD-FAKE-1","shipmentOrderId":"CJ-SHIP-PROD-FAKE-1"}}', 'url' => $url];
});
$productionClient = new CjHttpClient($registry, $productionTransport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, $redactor, 10, 1048576, null, null, null, static fn (): string => $tokens->accessToken());
$productionProvider = new CjProductionFulfillmentProvider($productionClient);
$command = (object) [
    'local_order_number' => 'J7-TEST-ORDER',
    'shipping_address' => ['country' => 'US', 'state' => 'CA', 'city' => 'Test City', 'postal_code' => '90001', 'line1' => '1 Test St', 'phone' => '+15550000000'],
    'logistics_preference' => 'CJ Standard',
    'items' => [['vid' => 'VID-J7', 'sku' => 'SKU-J7', 'quantity' => 1]],
    'correlation_id' => 'j7-production-provider',
];
$providerResult = $productionProvider->submit($command);
j7_check($providerResult->success && ($productionPayload['isSandbox'] ?? null) === 0, 'production fulfillment provider submits order.create with isSandbox=0 behind explicit gates');
j7_check((string) ($providerResult->data['cj_order_id'] ?? '') === 'CJ-PROD-FAKE-1', 'production fulfillment provider maps CJ production order response');
putenv('CJ_REAL_ORDER_SUBMIT_ENABLED');
putenv('CJ_REAL_REMOTE_WRITES_ENABLED');

$privateDnsTransport = new CjRealHttpTransport($registry->allowedHosts(), 1, static fn (): array => ['127.0.0.1'], static fn (): array => throw new RuntimeException('must not call private sender'));
j7_throws_code(static fn () => $privateDnsTransport->request('GET', $registry->resolve('points.query')['url'], [], '', 1, 1024), 'endpoint_private_ip', 'real transport rejects private or reserved DNS results');

$redirectTransport = new CjRealHttpTransport($registry->allowedHosts(), 1, static fn (): array => ['8.8.8.8'], static fn (): array => ['status' => 302, 'headers' => ['Location' => 'https://evil.example/api'], 'body' => '', 'url' => 'https://developers.cjdropshipping.com/api2.0/v1/product/query']);
j7_throws_code(static fn () => $redirectTransport->request('GET', $registry->resolve('product.query')['url'], [], '', 1, 1024), 'endpoint_not_allowed', 'real transport revalidates each redirect and rejects non-CJ hosts');

j7_remove($root);
j7_env(null, null, null, null);

if ($failures > 0) {
    echo '[RESULT] FAILURES=' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ J7 production connectivity foundation checks passed.' . PHP_EOL;
