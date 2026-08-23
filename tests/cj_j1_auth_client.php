<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginSecretStore;
use Official\CjDropshipping\Api\CjApiException;
use Official\CjDropshipping\Api\CjEndpointRegistry;
use Official\CjDropshipping\Api\CjErrorMapper;
use Official\CjDropshipping\Api\CjFixtureTransport;
use Official\CjDropshipping\Api\CjHostGuard;
use Official\CjDropshipping\Api\CjHttpClient;
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Auth\CjTokenManager;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\CjDropshipping\Webhook\CjWebhookVerifier;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;

function cj_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function cj_throws(callable $callback, string $message): void
{
    try {
        $callback();
        cj_check(false, $message);
    } catch (Throwable) {
        cj_check(true, $message);
    }
}

function cj_remove(string $path): void
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

function cj_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function cj_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function cj_sqlite_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);
    return $stmt->fetchColumn() !== false;
}

function cj_mysql_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function cj_zip(string $path, string $root, string $pluginId, array $extra = [], array $files = []): string
{
    $manifest = array_replace_recursive([
        'package_type' => 'plugin',
        'plugin_id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'author' => 'CJ J1 Tests',
        'core' => ['min' => '1.0.0'],
        'php' => '8.0.0',
        'entry' => 'plugin.php',
        'trust_level' => 'api',
        'capabilities' => [$pluginId . '.view'],
        'migrations' => [],
        'data_policy' => ['uninstall' => 'retain'],
    ], $extra);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($root . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString($root . '/plugin.php', $files['plugin.php'] ?? "<?php\nreturn static function (): void {};\n");
    foreach ($files as $name => $content) {
        if ($name !== 'plugin.php') {
            $zip->addFromString($root . '/' . $name, $content);
        }
    }
    $zip->close();

    return $path;
}

function cj_make_sqlite_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    cj_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/cj.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j1-static-test-key';
    cj_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    cj_core_migrations($pdo);
    return [$root, $pdo];
}

function cj_install_commerce_and_cj(PDO $pdo, bool $enableCj = true): LocalPluginPackageInstaller
{
    $installer = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo);
    $installer->installBundled('official.commerce', 1, true);
    $installer->installBundled('official.cj-dropshipping', 1, $enableCj);
    return $installer;
}

cj_check(PluginManifest::fromArray([
    'plugin_id' => 'official.cj-dropshipping',
    'name' => 'CJ',
    'version' => '1.0.0',
    'author' => 'CMS',
    'core' => ['min' => '1.0.0'],
    'php' => '8.0.0',
    'entry' => 'plugin.php',
])->id === 'official.cj-dropshipping', 'allows safe hyphenated plugin ID segments');
foreach (['Official.cj', 'official.-cj', 'official.cj-', 'official.cj--dropshipping', 'official..cj', '../official.cj', 'official.cj/.bad'] as $id) {
    cj_throws(static fn () => PluginManifest::fromArray([
        'plugin_id' => $id,
        'name' => 'bad',
        'version' => '1.0.0',
        'author' => 'bad',
        'core' => ['min' => '1.0.0'],
        'php' => '8.0.0',
        'entry' => 'plugin.php',
    ]), 'rejects unsafe plugin ID: ' . $id);
}

[$root, $pdo] = cj_make_sqlite_root('cms-cj-j1');
$installer = cj_install_commerce_and_cj($pdo);
cj_check(cj_sqlite_table($pdo, 'cms_cj_connections') && cj_sqlite_table($pdo, 'cms_cj_webhook_events') && cj_sqlite_table($pdo, 'cms_cj_control_states'), 'bundled official.cj-dropshipping installs and creates CJ-owned SQLite tables');
$row = $pdo->query("SELECT source, review_status, table_prefixes_json, status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetch();
cj_check((string) $row['source'] === 'bundled_official' && str_contains((string) $row['table_prefixes_json'], 'cj_') && (string) $row['status'] === PluginLifecycle::ENABLED, 'official CJ trust metadata grants bundled trusted install with cj_ ownership');

$tmp = $root . '/storage/tmp';
cj_throws(static fn () => $installer->preview(cj_zip($tmp . '/fake-official.zip', 'official.cj-dropshipping', 'official.cj-dropshipping', ['type' => 'system-plugin', 'bundled' => true, 'trust_level' => 'trusted_php']), 1), 'rejects forged local official.cj-dropshipping ZIP');
cj_throws(static fn () => $installer->preview(cj_zip($tmp . '/pretend.zip', 'vendor.safe-plugin', 'vendor.safe-plugin', ['type' => 'system-plugin', 'bundled' => true, 'trust_level' => 'trusted_php']), 1), 'rejects ordinary plugin impersonating bundled/system-plugin/trusted_php');
$preview = $installer->preview(cj_zip($tmp . '/vendor-safe.zip', 'vendor.safe-plugin', 'vendor.safe-plugin', ['capabilities' => ['vendor.view']]), 1);
cj_check($preview['plugin_id'] === 'vendor.safe-plugin', 'allows third-party dotted hyphen plugin ID in safe namespace');
cj_throws(static fn () => $installer->preview(cj_zip($tmp . '/mismatch.zip', 'vendor.safe-plugin', 'vendor.other-plugin', ['capabilities' => ['vendor.view']]), 1), 'rejects ZIP root directory and manifest plugin ID mismatch');
cj_throws(static fn () => $installer->preview(cj_zip($tmp . '/cj-prefix.zip', 'vendor.prefix-plugin', 'vendor.prefix-plugin', ['table_prefixes' => ['cj_']]), 1), 'rejects third-party declaration of reserved cj_ prefix');
cj_throws(static fn () => $installer->preview(cj_zip($tmp . '/cj-cap.zip', 'vendor.cap-plugin', 'vendor.cap-plugin', ['capabilities' => ['cj.settings.manage']]), 1), 'rejects third-party declaration of reserved cj.* capabilities');

$installer->disableWithDependents('official.cj-dropshipping', 1, true);
$pdo->exec("UPDATE cms_plugins SET version = '2.0.0' WHERE plugin_id = 'official.commerce'");
cj_throws(static fn () => $installer->enable('official.cj-dropshipping', 1), 'treats dependency max_version as exclusive upper bound');
$pdo->exec("UPDATE cms_plugins SET version = '1.0.0-rc1' WHERE plugin_id = 'official.commerce'");
$installer->enable('official.cj-dropshipping', 1);
cj_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED, 'dependency max_version 2.0.0 permits official.commerce 1.0.0-rc1');

$secretStore = new PluginSecretStore($pdo, 'cj-j1-static-test-key');
$repository = new CjRepository($pdo);
$redactor = new CjRedactor();
$tokens = new CjTokenManager($secretStore, $repository, $redactor);
$tokens->storeTokenResponse(['data' => [
    'accessToken' => 'access-secret-15',
    'refreshToken' => 'refresh-secret-180',
    'openId' => 'open-id-secret',
    'accessTokenExpiryDate' => '2026-08-30T12:00:00+08:00',
    'refreshTokenExpiryDate' => '2027-02-11T12:00:00+08:00',
]]);
$connection = $repository->connection();
cj_check(str_starts_with((string) $connection['access_token_expires_at'], '2026-08-30T04:00:00') && str_starts_with((string) $connection['refresh_token_expires_at'], '2027-02-11T04:00:00'), 'uses CJ accessTokenExpiryDate and refreshTokenExpiryDate with timezone normalization');
cj_check($secretStore->masked('official.cj-dropshipping', 'cj.open_id') !== 'open-id-secret' && $secretStore->get('official.cj-dropshipping', 'cj.webhook_secret') === null, 'stores open_id encrypted and does not create cj.webhook_secret');
cj_throws(static fn () => CjTokenManager::parseExpiry(''), 'rejects missing CJ token expiry');
cj_throws(static fn () => CjTokenManager::parseExpiry('not-a-time'), 'rejects invalid CJ token expiry');
cj_check(str_starts_with(CjTokenManager::parseExpiry('2026-08-30 12:00:00 Asia/Shanghai'), '2026-08-30T04:00:00'), 'parses named timezone expiry fixture');

$rawBody = '{"event":"order.updated","id":"cj-1"}';
$signature = base64_encode(hash_hmac('sha256', $rawBody, 'open-id-secret', true));
$webhooks = new CjWebhookVerifier($secretStore, $repository, $redactor);
$payload = $webhooks->receive($rawBody, $signature, 'evt-cj-1');
cj_check(is_array($payload) && (string) $payload['event'] === 'order.updated', 'verifies CJ webhook signature with Base64 HMAC-SHA256 over raw body bytes using open_id');
$prettyBody = "{\n  \"event\": \"order.updated\",\n  \"id\": \"cj-1\"\n}";
cj_check($webhooks->verify($prettyBody, $signature) === false, 'signature fails when raw body bytes change before JSON parsing');
cj_check((int) $pdo->query("SELECT COUNT(*) FROM cms_cj_webhook_events WHERE payload_hash = '" . hash('sha256', $rawBody) . "' AND signature_valid = 1")->fetchColumn() === 1 && !cj_sqlite_table($pdo, 'raw_body'), 'records webhook payload hash and verification evidence without plaintext body persistence');

$endpoint = new CjEndpointRegistry();
CjHostGuard::assertAllowed($endpoint->resolve('auth.accessToken')['url'], $endpoint->allowedHosts(), ['8.8.8.8']);
cj_throws(static fn () => CjHostGuard::assertAllowed('http://developers.cjdropshipping.com/api2.0/v1/authentication/getAccessToken', $endpoint->allowedHosts()), 'rejects non-HTTPS CJ endpoint');
cj_throws(static fn () => CjHostGuard::assertAllowed('https://127.0.0.1/api2.0/v1/authentication/getAccessToken', $endpoint->allowedHosts()), 'rejects direct private host SSRF target');
cj_throws(static fn () => CjHostGuard::assertAllowed($endpoint->resolve('auth.accessToken')['url'], $endpoint->allowedHosts(), ['127.0.0.1']), 'rejects official host resolving to private IP');

$transport = new CjFixtureTransport();
$url = $endpoint->resolve('points.query')['url'];
$transport->add('GET', $url, 200, [], json_encode(['code' => '200', 'data' => ['pointsInfo' => ['usedToday' => 2, 'remaining' => 98, 'total' => 100]]], JSON_UNESCAPED_SLASHES));
$client = new CjHttpClient($endpoint, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repository, $redactor);
$result = $client->call('points.query');
cj_check((string) $result['code'] === '200' && (int) $pdo->query('SELECT COUNT(*) FROM cms_cj_points_snapshots')->fetchColumn() === 1, 'parses CJ pointsInfo and records a points snapshot from fixture transport');

$redirectTransport = new CjFixtureTransport();
$redirectTransport->add('GET', $url, 200, [], '{"code":"200"}', 'https://evil.example/capture');
$redirectClient = new CjHttpClient($endpoint, $redirectTransport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repository, $redactor);
cj_throws(static fn () => $redirectClient->call('points.query', [], ['Authorization' => 'Bearer access-secret-15']), 'rejects CJ redirect to non-official host before accepting response');

$largeTransport = new CjFixtureTransport();
$largeTransport->add('GET', $url, 200, [], str_repeat('x', 1048577));
$largeClient = new CjHttpClient($endpoint, $largeTransport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repository, $redactor);
cj_throws(static fn () => $largeClient->call('points.query'), 'rejects oversized CJ API responses');

$retry = new CjRetryPolicy();
cj_check($retry->isRetryable('points.query', 500, '', false) === true && $retry->isRetryable('order.create', 500, '', true) === false, 'retry policy retries safe reads but not non-idempotent write operations');
cj_check(!str_contains($redactor->redact('accessToken: access-secret-15 phone +1 555 222 3333 test@example.com'), 'access-secret-15'), 'redacts tokens and PII from log summaries');

$installer->disableWithDependents('official.cj-dropshipping', 1, true);
cj_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::DISABLED && cj_sqlite_table($pdo, 'cms_cj_connections'), 'disabling CJ preserves connection, log and task tables');
$installer->enable('official.cj-dropshipping', 1);
cj_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED, 're-enabling CJ succeeds without repeating applied migrations');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j1_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            cj_core_migrations($mysql);
            $mysqlInstaller = cj_install_commerce_and_cj($mysql);
            cj_check(cj_mysql_table($mysql, 'cms_cj_connections') && cj_mysql_table($mysql, 'cms_cj_webhook_events') && cj_mysql_table($mysql, 'cms_cj_control_states'), 'MySQL installs CJ J1 schema from bundled official plugin');
            $mysqlInstaller->disableWithDependents('official.cj-dropshipping', 1, true);
            cj_check(cj_mysql_table($mysql, 'cms_cj_connections'), 'MySQL disable preserves CJ data tables');
            $mysqlInstaller->enable('official.cj-dropshipping', 1);
            cj_check((string) $mysql->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() === PluginLifecycle::ENABLED, 'MySQL re-enable does not repeat applied CJ migrations');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        cj_check(false, 'MySQL CJ J1 schema lifecycle failed: ' . $exception->getMessage());
        $mysqlStatus = 'failed';
    }
} else {
    cj_check(false, 'pdo_mysql extension is required for CJ J1 MySQL validation');
    $mysqlStatus = 'missing-extension';
}

echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;
cj_remove($root);

if ($failures > 0) {
    echo '[RESULT] FAILURES=' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] CJ J1 auth client checks passed.' . PHP_EOL;
