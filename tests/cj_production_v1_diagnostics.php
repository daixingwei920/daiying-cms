<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginSecretStore;
use Official\CjDropshipping\Api\CjApiException;
use Official\CjDropshipping\Api\CjLiveTestGate;
use Official\CjDropshipping\Auth\CjTokenManager;
use Official\CjDropshipping\Diagnostics\CjProductionDiagnostics;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;

function cjprod_check(bool $condition, string $message): void
{
    global $failures;
    echo '[' . ($condition ? 'PASS' : 'FAIL') . '] ' . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function cjprod_throws_code(callable $callback, string $code, string $message): void
{
    try {
        $callback();
        cjprod_check(false, $message);
    } catch (CjApiException $exception) {
        cjprod_check($exception->codeValue === $code, $message . ' (' . $exception->codeValue . ')');
    } catch (Throwable $exception) {
        cjprod_check(false, $message . ' unexpected ' . get_class($exception));
    }
}

function cjprod_remove(string $path): void
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

function cjprod_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function cjprod_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function cjprod_make_root(string $key = 'cj-production-v1-test-key'): array
{
    $root = sys_get_temp_dir() . '/cms-cj-prod-v1-' . bin2hex(random_bytes(4));
    cjprod_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/cjprod.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = $key;
    cjprod_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    cjprod_core_migrations($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    return [$root, $pdo];
}

function cjprod_env_clear(): void
{
    foreach (['APP_ENV', 'CJ_REAL_TRANSPORT_ENABLED', 'CJ_REAL_REMOTE_WRITES_ENABLED', 'CJ_REAL_ORDER_SUBMIT_ENABLED', 'CJ_REAL_SUPPLIER_PAYMENT_ENABLED', 'CJ_REAL_WEBHOOK_REGISTRATION_ENABLED', 'CJ_LIVE_TESTS', 'CJ_LIVE_READONLY_TESTS', 'CJ_LIVE_MUTATING_TESTS', 'CJ_LIVE_ORDER_TESTS', 'CJ_LIVE_PAYMENT_TESTS', 'CJ_LIVE_WEBHOOK_TESTS'] as $name) {
        putenv($name);
    }
}

cjprod_env_clear();
cjprod_check(!CjLiveTestGate::liveTestsEnabled() && !CjLiveTestGate::readOnlyEnabled(), 'live tests are disabled by default');
cjprod_throws_code(static fn () => CjLiveTestGate::assertReadOnlyAllowed(), 'live_readonly_disabled', 'read-only live test gate fails closed by default');
cjprod_throws_code(static fn () => CjLiveTestGate::assertOrderAllowed(), 'live_order_disabled', 'order live test gate fails closed by default');
cjprod_throws_code(static fn () => CjLiveTestGate::assertPaymentAllowed(), 'live_payment_disabled', 'payment live test gate fails closed by default');
cjprod_throws_code(static fn () => CjLiveTestGate::assertWebhookRegistrationAllowed(), 'live_webhook_disabled', 'webhook live test gate fails closed by default');

putenv('CJ_LIVE_TESTS=1');
putenv('CJ_LIVE_READONLY_TESTS=1');
cjprod_check(CjLiveTestGate::readOnlyEnabled(), 'read-only live gate opens only with both live and read-only flags');
cjprod_check(!CjLiveTestGate::orderEnabled() && !CjLiveTestGate::paymentEnabled(), 'read-only live gate does not imply order or payment permission');
putenv('CJ_LIVE_MUTATING_TESTS=1');
putenv('CJ_LIVE_ORDER_TESTS=1');
cjprod_check(CjLiveTestGate::orderEnabled() && !CjLiveTestGate::paymentEnabled(), 'order live gate is separate from payment gate');
putenv('CJ_LIVE_PAYMENT_TESTS=1');
putenv('CJ_LIVE_WEBHOOK_TESTS=1');
cjprod_check(CjLiveTestGate::paymentEnabled() && CjLiveTestGate::webhookRegistrationEnabled(), 'payment and webhook live gates require their own explicit flags');
cjprod_env_clear();

[$root, $pdo] = cjprod_make_root();
$repo = new CjRepository($pdo);
$tokens = new CjTokenManager(new PluginSecretStore($pdo, 'cj-production-v1-test-key'), $repo, new CjRedactor());
$diagnostics = new CjProductionDiagnostics($pdo, $repo, $tokens);
$initial = $diagnostics->checks();
$byId = [];
foreach ($initial as $check) {
    $byId[$check['id']] = $check;
}
cjprod_check(($byId['commerce_dependency']['status'] ?? '') === 'pass', 'diagnostics sees enabled Commerce dependency');
cjprod_check(($byId['secret_master_key']['status'] ?? '') === 'pass', 'diagnostics verifies plugin secret writes with configured master key');
cjprod_check(($byId['api_key']['status'] ?? '') === 'fail', 'diagnostics reports missing CJ API Key before configuration');
cjprod_check(($byId['live_order_gate']['status'] ?? '') === 'warning', 'diagnostics keeps real order gate visible but closed');

$secretApiKey = 'CJ-REAL-API-KEY-SHOULD-NOT-LEAK';
$secretAccess = 'CJ-REAL-ACCESS-TOKEN-SHOULD-NOT-LEAK';
$secretRefresh = 'CJ-REAL-REFRESH-TOKEN-SHOULD-NOT-LEAK';
$secretOpenId = 'CJ-REAL-OPEN-ID-SHOULD-NOT-LEAK';
$tokens->saveApiKey($secretApiKey, 'Production Account');
$tokens->storeTokenResponse([
    'data' => [
        'openId' => $secretOpenId,
        'accessToken' => $secretAccess,
        'refreshToken' => $secretRefresh,
        'accessTokenExpiryDate' => gmdate('c', time() + 86400),
        'refreshTokenExpiryDate' => gmdate('c', time() + 86400 * 30),
        'accountName' => 'Production Account',
    ],
], 'production');
putenv('APP_ENV=production');
putenv('CJ_REAL_TRANSPORT_ENABLED=1');
putenv('CJ_LIVE_TESTS=1');
putenv('CJ_LIVE_READONLY_TESTS=1');
$after = $diagnostics->checks();
$json = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
cjprod_check(is_string($json) && !str_contains($json, $secretApiKey) && !str_contains($json, $secretAccess) && !str_contains($json, $secretRefresh) && !str_contains($json, $secretOpenId), 'diagnostics output does not leak API Key, tokens, or openId');
$afterById = [];
foreach ($after as $check) {
    $afterById[$check['id']] = $check;
}
cjprod_check(($afterById['api_key']['status'] ?? '') === 'pass' && ($afterById['access_token']['status'] ?? '') === 'pass', 'diagnostics reports configured real credentials using masked values');
cjprod_check(($afterById['mode']['status'] ?? '') === 'pass' && ($afterById['real_transport']['status'] ?? '') === 'pass', 'diagnostics reports production mode and real transport gate when explicitly enabled');
cjprod_check(($afterById['live_readonly_gate']['status'] ?? '') === 'pass' && ($afterById['live_order_gate']['status'] ?? '') === 'warning', 'diagnostics permits read-only live checks without enabling orders');

[$emptyRoot, $emptyPdo] = cjprod_make_root('');
$emptyRepo = new CjRepository($emptyPdo);
$emptyTokens = new CjTokenManager(new PluginSecretStore($emptyPdo, ''), $emptyRepo, new CjRedactor());
$emptyDiagnostics = new CjProductionDiagnostics($emptyPdo, $emptyRepo, $emptyTokens);
$emptyById = [];
foreach ($emptyDiagnostics->checks() as $check) {
    $emptyById[$check['id']] = $check;
}
cjprod_check(($emptyById['secret_master_key']['status'] ?? '') === 'fail', 'diagnostics detects missing plugin encryption master key');

cjprod_remove($root);
cjprod_remove($emptyRoot);
cjprod_env_clear();

if ($failures > 0) {
    echo '[RESULT] FAILURES=' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ Production V1 diagnostics checks passed.' . PHP_EOL;
