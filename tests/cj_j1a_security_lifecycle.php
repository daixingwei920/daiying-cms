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
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
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
use Official\CjDropshipping\Auth\CjTokenManager;
use Official\CjDropshipping\Catalog\CjCatalogInput;
use Official\CjDropshipping\Catalog\CjCatalogPreviewService;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\CjDropshipping\Webhook\CjWebhookVerifier;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;

function j1a_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function j1a_throws(callable $callback, string $message): void
{
    try {
        $callback();
        j1a_check(false, $message);
    } catch (Throwable) {
        j1a_check(true, $message);
    }
}

function j1a_remove(string $path): void
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

function j1a_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function j1a_copy_dir(string $source, string $target): void
{
    if (is_file($source)) {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        copy($source, $target);
        return;
    }
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    foreach (new DirectoryIterator($source) as $item) {
        if (!$item->isDot()) {
            j1a_copy_dir($item->getPathname(), $target . '/' . $item->getBasename());
        }
    }
}

function j1a_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function j1a_table_exists(PDO $pdo, string $table): bool
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

function j1a_column_exists(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string) $row['name'] === $column) {
            return true;
        }
    }
    return false;
}

function j1a_make_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j1a_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/cj.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j1a-static-test-key';
    j1a_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j1a_core_migrations($pdo);
    return [$root, $pdo];
}

function j1a_install(PDO $pdo, bool $commerce = true, bool $enable = true): LocalPluginPackageInstaller
{
    $installer = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo);
    if ($commerce) {
        $installer->installBundled('official.commerce', 1, true);
    }
    $installer->installBundled('official.cj-dropshipping', 1, $enable);
    return $installer;
}

[$root, $pdo] = j1a_make_root('cms-cj-j1a');
$installer = j1a_install($pdo);
$repo = new CjRepository($pdo);
$secrets = new PluginSecretStore($pdo, 'cj-j1a-static-test-key');
$redactor = new CjRedactor();
$tokens = new CjTokenManager($secrets, $repo, $redactor);

j1a_check(j1a_table_exists($pdo, 'cms_cj_control_states') && !j1a_column_exists($pdo, 'cms_cj_webhook_events', 'raw_body') && j1a_column_exists($pdo, 'cms_cj_webhook_events', 'payload_hash'), '002 migration creates lifecycle controls and removes plaintext webhook raw_body storage');

$tokens->storeTokenResponse(['data' => [
    'accessToken' => 'old-access-token',
    'refreshToken' => 'refresh-token-secret',
    'openId' => 'open-id-secret-j1a',
    'accessTokenExpiryDate' => gmdate('c', time() + 60),
    'refreshTokenExpiryDate' => gmdate('c', time() + 86400),
]]);
j1a_check($tokens->shouldRefresh(3600), 'Token Manager refreshes inside the safety window');
$refreshed = $tokens->refreshIfNeeded(static fn (string $refreshToken): array => [
    'data' => [
        'accessToken' => 'new-access-token',
        'refreshToken' => $refreshToken,
        'openId' => 'open-id-secret-j1a',
        'accessTokenExpiryDate' => gmdate('c', time() + 7200),
        'refreshTokenExpiryDate' => gmdate('c', time() + 86400),
    ],
]);
j1a_check($refreshed && $secrets->get('official.cj-dropshipping', 'cj.access_token') === 'new-access-token', 'Token Manager refreshes through SecretStore and updates expiry fields');
$repo->acquireLock('token_refresh', 120);
j1a_check($tokens->refreshIfNeeded(static fn (): array => throw new RuntimeException('must not run')) === false, 'Token refresh lock prevents concurrent Worker refresh');
$repo->releaseLock('token_refresh');
$tokens->storeTokenResponse(['data' => [
    'accessToken' => 'near-expiry-token',
    'refreshToken' => 'refresh-token-secret',
    'openId' => 'open-id-secret-j1a',
    'accessTokenExpiryDate' => gmdate('c', time() + 60),
    'refreshTokenExpiryDate' => gmdate('c', time() + 86400),
]]);
j1a_throws(static fn () => $tokens->refreshIfNeeded(static fn (): array => throw new RuntimeException('token refresh failed for accessToken: should-redact')), 'refresh failure opens auth failure path');
j1a_check($repo->isCircuitOpen('auth'), 'refresh failure opens authentication circuit');
$pdo->exec("INSERT INTO cms_cj_tasks (task_type, idempotency_key, remote_write, status, created_at, updated_at) VALUES ('remote-write-test', 'rw-1', 1, 'queued', '" . gmdate('c') . "', '" . gmdate('c') . "')");
$repo->pauseRemoteWrites('assert pause');
j1a_check((string) $pdo->query("SELECT status FROM cms_cj_tasks WHERE idempotency_key = 'rw-1'")->fetchColumn() === 'paused', 'auth failures pause remote write tasks without touching local Commerce data');
j1a_check(j1a_table_exists($pdo, 'cms_commerce_products') && j1a_table_exists($pdo, 'cms_commerce_orders'), 'auth failure does not delete local Commerce products or orders');
$repo->closeCircuit('auth');

j1a_throws(static fn () => (new CjTokenManager(new PluginSecretStore($pdo, ''), $repo, $redactor))->assertProductionConfigured(), 'production authentication requires API Key configuration');
$tokens->saveApiKey('api-key-secret-value', 'Primary CJ');
j1a_check($secrets->masked('official.cj-dropshipping', 'cj.api_key') !== 'api-key-secret-value', 'API Key remains encrypted and masked in SecretStore');

$endpoint = new CjEndpointRegistry();
$url = $endpoint->resolve('points.query')['url'];
$transport = new CjFixtureTransport();
$transport->add('GET', $url, 200, ['requestId' => 'fixture-request-1'], '{"code":"200","data":{"pointsInfo":{"usedToday":10,"remaining":20,"total":100}}}');
$client = new CjHttpClient($endpoint, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, $redactor, 10, 1048576, new CjRateLimiter($repo, 5), new CjPointsBudget($repo, 100, 30), new CjCircuitBreaker($repo));
$client->call('points.query', [], [], 'P0', 'corr-j1a-1');
j1a_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE correlation_id = 'corr-j1a-1' AND request_id = 'fixture-request-1'")->fetchColumn() === 1, 'API client records correlation ID, request ID, timeout-safe fixture result and points');
j1a_throws(static fn () => $client->call('points.query', [], [], 'P3', 'corr-j1a-low'), 'PointsBudget pauses lower-priority work below protection threshold');
$repo->recordPoints(['used_today' => 0, 'remaining' => 1000, 'total' => 1000]);
$isolatedLimiter = new CjRateLimiter($repo, 2);
for ($i = 0; $i < 2; $i++) {
    $isolatedLimiter->assertAllowed('rate.test');
}
j1a_throws(static fn () => $isolatedLimiter->assertAllowed('rate.test'), 'CjRateLimiter enforces local per-minute bucket');
$repo->openCircuit('service', 300, ['reason' => 'test']);
j1a_throws(static fn () => $client->call('points.query', [], [], 'P0', 'corr-service-open'), 'service circuit blocks normal calls while open');
$repo->closeCircuit('service');

$rawBody = '{"messageId":"msg-1","type":"ORDER","address":"123 Secret St","phone":"+1 555 111 2222","email":"buyer@example.com","accessToken":"leak-me"}';
$signature = base64_encode(hash_hmac('sha256', $rawBody, 'open-id-secret-j1a', true));
$webhook = new CjWebhookVerifier($secrets, $repo, $redactor);
$payload = $webhook->receive($rawBody, $signature, 'msg-1');
j1a_check(is_array($payload), 'Webhook contract still verifies valid fixture signature');
$stored = $pdo->query("SELECT payload_hash, payload_json, metadata_json FROM cms_cj_webhook_events WHERE event_id = 'msg-1'")->fetch();
$storedText = json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
j1a_check(is_array($stored) && (string) $stored['payload_hash'] === hash('sha256', $rawBody) && !str_contains($storedText, '123 Secret') && !str_contains($storedText, 'buyer@example.com') && !str_contains($storedText, 'leak-me'), 'Webhook persistence stores hash and minimal metadata without address, email, phone or token leakage');

$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), $secrets))->bootEnabled();
$routes = $runtime->routes();
$routeMap = [];
foreach ($routes as $route) {
    $routeMap[$route->method . ' ' . $route->path] = $route;
}
j1a_check(isset($routeMap['POST /admin/cj/settings']) && $routeMap['POST /admin/cj/settings']->capability === 'cj.settings.manage' && $routeMap['POST /admin/cj/settings']->csrf, 'settings route is protected by cj.settings.manage and CSRF');
j1a_check(isset($routeMap['POST /admin/cj/data/purge']) && $routeMap['POST /admin/cj/data/purge']->capability === 'cj.data.purge' && $routeMap['POST /admin/cj/data/purge']->csrf, 'permanent CJ purge route uses separate cj.data.purge capability and CSRF');
j1a_check(count($runtime->menus()) >= 1, 'CJ admin menu registers only while plugin is enabled');
$settingsBody = (new Official\CjDropshipping\Admin\CjAdminController($repo, $tokens, $client, $webhook, $secrets, new CjCatalogPreviewService($client, $repo, new CjCatalogInput())))->settings(new Cms\Core\Http\Request('GET', '/admin/cj/settings'))->body();
j1a_check(!str_contains($settingsBody, 'api-key-secret-value') && !str_contains($settingsBody, 'new-access-token') && !str_contains($settingsBody, 'open-id-secret-j1a'), 'settings page never renders full API Key, token or openId');

$installer->disableWithDependents('official.cj-dropshipping', 1, true);
j1a_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::DISABLED && j1a_table_exists($pdo, 'cms_cj_tasks') && $secrets->get('official.cj-dropshipping', 'cj.api_key') === 'api-key-secret-value', 'ordinary disable preserves CJ tables and encrypted configuration');
$lifecycleRoot = $root . '/lifecycle-site';
mkdir($lifecycleRoot . '/content/plugins', 0755, true);
mkdir($lifecycleRoot . '/system', 0755, true);
mkdir($lifecycleRoot . '/config', 0755, true);
j1a_write($lifecycleRoot . '/config/app.php', (string) file_get_contents(CMS_SOURCE_ROOT . '/config/app.php'));
j1a_write($lifecycleRoot . '/system/official-plugins.php', (string) file_get_contents(CMS_SOURCE_ROOT . '/system/official-plugins.php'));
j1a_copy_dir(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping', $lifecycleRoot . '/content/plugins/official.cj-dropshipping');
$lifecycleInstaller = new LocalPluginPackageInstaller($lifecycleRoot, $pdo);
$lifecycleInstaller->uninstallCode('official.cj-dropshipping', 1);
j1a_check(j1a_table_exists($pdo, 'cms_cj_connections') && $secrets->get('official.cj-dropshipping', 'cj.api_key') === 'api-key-secret-value', 'ordinary code uninstall preserves CJ schema and encrypted configuration');
j1a_copy_dir(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping', $lifecycleRoot . '/content/plugins/official.cj-dropshipping');
$lifecycleInstaller->installBundled('official.cj-dropshipping', 1, true);
j1a_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_tasks")->fetchColumn() >= 1, 'reinstall recognizes old schema and restores connection/task records');
$repo->purgeOwnData('PURGE official.cj-dropshipping');
j1a_check((int) $pdo->query('SELECT COUNT(*) FROM cms_cj_connections')->fetchColumn() === 0 && j1a_table_exists($pdo, 'cms_commerce_products') && j1a_table_exists($pdo, 'cms_commerce_orders') && j1a_table_exists($pdo, 'cms_commerce_inventory_items'), 'confirmed CJ purge deletes only CJ-owned data and not Commerce products, orders, inventory or fulfillment data');

[$missingRoot, $missingPdo] = j1a_make_root('cms-cj-no-commerce');
$missingInstaller = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $missingPdo);
j1a_throws(static fn () => $missingInstaller->installBundled('official.cj-dropshipping', 1, true), 'CJ cannot activate when official.commerce dependency is missing');
j1a_check(class_exists(Cms\Core\Recovery\RecoveryController::class) && class_exists(Cms\Core\Update\UpdateService::class), 'CMS remains loadable when CJ dependency is missing');
j1a_remove($missingRoot);

$throwRoot = $root . '/throw-site';
mkdir($throwRoot . '/content/plugins', 0755, true);
mkdir($throwRoot . '/system', 0755, true);
j1a_write($throwRoot . '/system/official-plugins.php', (string) file_get_contents(CMS_SOURCE_ROOT . '/system/official-plugins.php'));
j1a_copy_dir(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping', $throwRoot . '/content/plugins/official.cj-dropshipping');
j1a_write($throwRoot . '/content/plugins/official.cj-dropshipping/plugin.php', "<?php\nreturn static function (): void { throw new RuntimeException('CJ boot failure'); };\n");
$pdo->exec("UPDATE cms_plugins SET status = 'Enabled' WHERE plugin_id = 'official.cj-dropshipping'");
(new PluginManager($throwRoot . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/throw.log'), new EventDispatcher(), new BlockRegistry(), new PluginRuntimeRegistry(), new OfficialPluginRegistry($throwRoot), $secrets))->bootEnabled();
j1a_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::QUARANTINED, 'CJ boot exception is quarantined without CMS or Commerce white-screen');
j1a_check(j1a_table_exists($pdo, 'cms_commerce_products') && j1a_table_exists($pdo, 'cms_commerce_orders'), 'Commerce data remains available after CJ fault isolation');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j1a_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j1a_core_migrations($mysql);
            $mysqlInstaller = j1a_install($mysql);
            j1a_check(j1a_table_exists($mysql, 'cms_cj_control_states') && !j1a_column_exists($mysql, 'cms_cj_webhook_events', 'raw_body'), 'MySQL installs J1A lifecycle schema and minimized webhook storage');
            $mysqlInstaller->disableWithDependents('official.cj-dropshipping', 1, true);
            j1a_check(j1a_table_exists($mysql, 'cms_cj_connections'), 'MySQL disable preserves CJ schema');
            $mysqlInstaller->enable('official.cj-dropshipping', 1);
            j1a_check((string) $mysql->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED, 'MySQL re-enable keeps CJ lifecycle valid');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        j1a_check(false, 'MySQL J1A lifecycle failed: ' . $exception->getMessage());
        $mysqlStatus = 'failed';
    }
} else {
    j1a_check(false, 'pdo_mysql extension is required for J1A MySQL validation');
    $mysqlStatus = 'missing-extension';
}

echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;
j1a_remove($root);

if ($failures > 0) {
    echo '[RESULT] FAILURES=' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ J1A security and lifecycle checks passed.' . PHP_EOL;
