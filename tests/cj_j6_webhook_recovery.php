<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginAdminRequestContext;
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
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;
use Official\CjDropshipping\Webhook\CjWebhookRecoveryService;
use Official\CjDropshipping\Webhook\CjWebhookVerifier;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.php';

$failures = 0;
function j6_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function j6_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}
function j6_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}
function j6_core(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}
function j6_table(PDO $pdo, string $table): bool
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
function j6_column(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int) $stmt->fetchColumn() === 1;
    }
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}
function j6_root(string $name): array
{
    $root = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
    j6_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'storage/plugin-installs/staging', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/j6.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['security']['encryption_key'] = 'cj-j6-static-test-key';
    j6_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    j6_core($pdo);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, true);
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.cj-dropshipping', 1, true);
    (new PluginSecretStore($pdo, 'cj-j6-static-test-key'))->set('official.cj-dropshipping', 'cj.open_id', 'open-id-j6');
    return [$root, $pdo];
}
function j6_client(PDO $pdo, array $overrides = []): CjHttpClient
{
    $repo = new CjRepository($pdo);
    $registry = new CjEndpointRegistry();
    $transport = new CjFixtureTransport();
    $fixtures = [
        'order.query' => [200, '{"code":"200","success":true,"data":{"orderId":"CJ-UNKNOWN","shipmentOrderId":"CJ-SHIP-UNKNOWN","status":"UNPAID","sandbox":true},"pointsInfo":{"remaining":70,"total":100}}'],
    ];
    foreach ($overrides as $operation => $fixture) {
        $fixtures[$operation] = $fixture;
    }
    foreach ($fixtures as $operation => [$status, $body]) {
        $endpoint = $registry->resolve((string) $operation);
        $transport->add($endpoint['method'], $endpoint['url'], (int) $status, ['requestId' => 'j6-' . $operation], (string) $body);
    }
    return new CjHttpClient($registry, $transport, new CjErrorMapper(), new CjRetryPolicy(), new CjPointsParser(), $repo, new CjRedactor(), 10, 1048576, new CjRateLimiter($repo, 100), new CjPointsBudget($repo, 100, 30), new CjCircuitBreaker($repo));
}
function j6_service(PDO $pdo, array $overrides = []): CjWebhookRecoveryService
{
    $repo = new CjRepository($pdo);
    return new CjWebhookRecoveryService($pdo, $repo, new CjWebhookVerifier(new PluginSecretStore($pdo, 'cj-j6-static-test-key'), $repo, new CjRedactor()), j6_client($pdo, $overrides), new AuditLogger($pdo));
}
function j6_sign(string $body): string
{
    return base64_encode(hash_hmac('sha256', $body, 'open-id-j6', true));
}
function j6_seed_link(PDO $pdo, string $status = 'awaiting_supplier_payment', string $orderId = 'CJ-ORDER-J6'): int
{
    $now = gmdate('c');
    $pdo->prepare("INSERT INTO cms_cj_fulfillment_links (commerce_order_id, cj_order_id, shipment_order_id, local_order_number, mode, status, cost_snapshot_json, created_at, updated_at) VALUES (:commerce_order, :order_id, :ship_id, :number, 'sandbox', :status, '{}', :created, :updated)")
        ->execute([':commerce_order' => random_int(1000, 9999), ':order_id' => $orderId, ':ship_id' => 'SHIP-' . $orderId, ':number' => 'LOCAL-' . $orderId, ':status' => $status, ':created' => $now, ':updated' => $now]);
    return (int) $pdo->lastInsertId();
}

[$root, $pdo] = j6_root('cms-cj-j6');
$service = j6_service($pdo);
$linkId = j6_seed_link($pdo);

j6_check(json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.cj-dropshipping/plugin.json'), true)['version'] === '1.0.0-rc1', 'CJ plugin version is 1.0.0-rc1');
j6_check(j6_table($pdo, 'cms_cj_webhook_events') && j6_column($pdo, 'cms_cj_webhook_events', 'processed_at') && j6_column($pdo, 'cms_cj_webhook_events', 'attempts'), '008 migration adds webhook recovery columns');
$raw = '{"messageId":"msg-1","type":"ORDER","orderId":"CJ-ORDER-J6","status":"SHIPPED","trackingNumber":"TRACK1"}';
$started = microtime(true);
$received = $service->receive($raw, j6_sign($raw), 'application/json');
j6_check($received['status'] === 200 && $received['queued'] && (microtime(true) - $started) < 3, 'valid raw-body HMAC webhook is queued within response budget');
$duplicate = $service->receive($raw, j6_sign($raw), 'application/json');
j6_check($duplicate['status'] === 200 && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_webhook_events WHERE event_id = 'msg-1'")->fetchColumn() === 1, 'duplicate messageId is deduplicated in inbox');
$changed = '{"messageId":"msg-2","type":"ORDER","orderId":"CJ-ORDER-J6","status":"SHIPPED"}';
j6_check($service->receive($changed, j6_sign($raw), 'application/json')['status'] === 401, 'changed JSON bytes fail signature before business queue');
j6_check($service->receive($raw, j6_sign($raw), 'text/plain')['status'] === 415, 'non-JSON webhook content type is rejected');
j6_check($service->processInbox(10) === 1 && (string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'shipped', 'worker asynchronously advances CJ fulfillment status from inbox');
$old = '{"messageId":"msg-3","type":"ORDER","orderId":"CJ-ORDER-J6","status":"PAID"}';
$service->receive($old, j6_sign($old), 'application/json');
$service->processInbox(10);
j6_check((string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'shipped', 'out-of-order older status does not regress fulfillment state');
$unknownRemote = '{"messageId":"msg-4","type":"ORDER","orderId":"CJ-ORDER-J6","status":"BRAND_NEW_STATUS"}';
$service->receive($unknownRemote, j6_sign($unknownRemote), 'application/json');
$service->processInbox(10);
j6_check((string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $linkId)->fetchColumn() === 'action_required', 'unknown remote status maps to action_required instead of unsafe automatic progress');
$unknownLink = j6_seed_link($pdo, 'unknown', 'CJ-UNKNOWN');
j6_check($service->pollUnknown(5) === 1 && (string) $pdo->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $unknownLink)->fetchColumn() === 'awaiting_supplier_payment', 'polling compensation resolves unknown Sandbox order state through fixture query');
$pii = '{"messageId":"msg-pii","type":"ORDER","orderId":"CJ-ORDER-J6","status":"PROCESSING","email":"buyer@example.com","phone":"+15551234567","token":"secret-token-sample","address":"1 Private Street"}';
$service->receive($pii, j6_sign($pii), 'application/json');
$storedPiiPayload = (string) $pdo->query("SELECT COALESCE(payload_json,'') || COALESCE(metadata_json,'') FROM cms_cj_webhook_events WHERE event_id = 'msg-pii'")->fetchColumn();
j6_check(!str_contains($storedPiiPayload, 'buyer@example.com') && !str_contains($storedPiiPayload, '+15551234567') && !str_contains($storedPiiPayload, 'secret-token-sample') && !str_contains($storedPiiPayload, 'Private Street'), 'webhook inbox stores minimal DTO and omits PII/token fields');

$runtime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new Cms\Core\Logging\FileLogger($root . '/storage/logs/plugin.log'), new Cms\Core\Events\EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, 'cj-j6-static-test-key')))->bootEnabled();
$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
j6_check(isset($routes['POST /api/cj/webhook']) && $routes['POST /api/cj/webhook']->capability === null && !$routes['POST /api/cj/webhook']->csrf, 'public CJ webhook receiver is POST JSON without admin CSRF');
j6_check(isset($routes['POST /admin/cj/webhook/process']) && $routes['POST /admin/cj/webhook/process']->capability === 'cj.webhook.manage' && $routes['POST /admin/cj/webhook/process']->csrf, 'webhook worker route requires cj.webhook.manage and CSRF');
j6_check(isset($routes['POST /admin/cj/fulfillment/poll-unknown']) && $routes['POST /admin/cj/fulfillment/poll-unknown']->capability === 'cj.fulfillment.submit' && $routes['POST /admin/cj/fulfillment/poll-unknown']->csrf, 'unknown fulfillment polling route requires submit capability and CSRF');
$before = (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.query'")->fetchColumn();
$forbidden = $routes['POST /admin/cj/fulfillment/poll-unknown']->handler->__invoke(new Request('POST', '/admin/cj/fulfillment/poll-unknown', [], ['limit' => 1], ['plugin_admin_context' => new PluginAdminRequestContext('official.cj-dropshipping', 9, ['cj.fulfillment.view'], 'c', 'r', '127.0.0.1')]));
j6_check($forbidden->status() === 403 && (int) $pdo->query("SELECT COUNT(*) FROM cms_cj_api_logs WHERE operation = 'order.query'")->fetchColumn() === $before, 'missing polling capability makes zero fixture calls');
j6_check(!str_contains((string) $pdo->query("SELECT COALESCE(payload_json,'') FROM cms_cj_webhook_events WHERE event_id = 'msg-1'")->fetchColumn(), 'open-id-j6'), 'webhook inbox does not persist openId secret');

$mysqlStatus = 'not-run';
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_cj_j6_' . bin2hex(random_bytes(3));
        $server->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            j6_core($mysql);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.commerce', 1, true);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysql))->installBundled('official.cj-dropshipping', 1, true);
            (new PluginSecretStore($mysql, 'cj-j6-static-test-key'))->set('official.cj-dropshipping', 'cj.open_id', 'open-id-j6');
            $mysqlLink = j6_seed_link($mysql);
            $mysqlService = j6_service($mysql);
            $mysqlService->receive($raw, j6_sign($raw), 'application/json');
            $mysqlService->processInbox(5);
            j6_check(j6_table($mysql, 'cms_cj_webhook_events') && (string) $mysql->query('SELECT status FROM cms_cj_fulfillment_links WHERE id = ' . $mysqlLink)->fetchColumn() === 'shipped', 'real MySQL/MariaDB validates J6 webhook inbox and status mapping');
            $mysqlStatus = 'passed';
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        $mysqlStatus = 'failed';
        j6_check(false, 'MySQL J6 validation failed: ' . $exception->getMessage());
    }
} else {
    $mysqlStatus = 'missing-extension';
    j6_check(false, 'pdo_mysql extension is required for CJ J6 MySQL validation');
}
echo '[INFO] MySQL status: ' . $mysqlStatus . PHP_EOL;

j6_remove($root);
if ($failures > 0) {
    echo '[RESULT] CJ J6 webhook recovery checks failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] CJ J6 webhook recovery checks passed.' . PHP_EOL;
