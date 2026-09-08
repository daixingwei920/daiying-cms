<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Auth\CapabilityRegistry;
use Cms\Core\Auth\RoleCapabilityService;
use Cms\Core\Cache\ArrayCache;
use Cms\Core\Cache\FileCache;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Queue\QueueHandlerRegistry;
use Cms\Core\Queue\QueueJob;
use Cms\Core\Queue\QueueService;
use Cms\Core\Security\SecretRedactor;
use Cms\Core\Support\PublicApiRegistry;
use Cms\Core\Webhook\WebhookEndpointRepository;
use Cms\Core\Webhook\WebhookEventRegistry;
use Cms\Core\Webhook\WebhookService;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migration = require __DIR__ . '/../system/migrations/2026_09_08_000003_foundation_system_services.php';
$migration->up($pdo);
$migration->up($pdo);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');
$check((int) $pdo->query("SELECT COUNT(*) FROM cms_roles WHERE role_id IN ('super_admin','admin','editor','author')")->fetchColumn() === 4, 'foundation services migration seeds built-in roles idempotently');

$roles = new RoleCapabilityService($pdo);
$roles->grant('editor', 'content.edit');
$check($roles->has('editor', 'content.edit'), 'role capability grants work');
$check($roles->has('super_admin', 'anything.custom'), 'super_admin has all capabilities');
$check(isset(CapabilityRegistry::all()['content.publish']), 'default capability registry is populated');

$arrayCache = new ArrayCache();
$arrayCache->set('unit', 'key', ['ok' => true], 30);
$check(($arrayCache->get('unit', 'key')['ok'] ?? false) === true, 'array cache stores values with namespaces');
$arrayCache->delete('unit', 'key');
$check($arrayCache->get('unit', 'key', 'missing') === 'missing', 'array cache delete works');
$cacheRoot = sys_get_temp_dir() . '/daiying-cache-' . bin2hex(random_bytes(4));
$fileCache = new FileCache($cacheRoot);
$fileCache->set('unit', 'file-key', 'value', 30);
$check($fileCache->get('unit', 'file-key') === 'value', 'file cache stores values');
$check($fileCache->clear('unit') === 1 && $fileCache->get('unit', 'file-key', null) === null, 'file cache clears by namespace');

$queue = new QueueService($pdo);
$handled = [];
QueueHandlerRegistry::clear();
QueueHandlerRegistry::register('unit.success', static function (array $payload) use (&$handled): void {
    $handled[] = $payload['name'] ?? '';
});
$jobId = $queue->enqueue(new QueueJob('unit.success', ['name' => 'ok'], 'unit'));
$result = $queue->process(5);
$row = $queue->find($jobId);
$check($result['succeeded'] === 1 && ($row['status'] ?? '') === 'done' && $handled === ['ok'], 'queue processes registered handlers');
$deadId = $queue->enqueue(new QueueJob('unit.missing', ['api_key' => 'sk_live_secret'], 'unit', 1));
$dead = $queue->process(5);
$deadRow = $queue->find($deadId);
$check($dead['dead'] === 1 && ($deadRow['status'] ?? '') === 'dead', 'queue moves missing handlers to dead-letter state');
$check(!str_contains((string) ($deadRow['last_error'] ?? ''), 'sk_live_secret'), 'queue errors are redacted');

$endpointRepo = new WebhookEndpointRepository($pdo);
$endpointId = $endpointRepo->create('https://example.com/webhook', 'hook-secret', ['content.created']);
$webhookIds = (new WebhookService($pdo, $queue))->dispatch('content.created', ['id' => 10]);
$check($endpointId > 0 && count($webhookIds) === 1, 'webhook service creates delivery records for matching endpoints');
$signature = WebhookEndpointRepository::sign('{"ok":true}', 'hook-secret', 123);
$check($signature['signature'] === hash_hmac('sha256', '123.{"ok":true}', 'hook-secret'), 'webhook signature uses timestamped HMAC SHA-256');
WebhookEventRegistry::register('unit.event', 'Unit Event', '1.0', 'unit');
$check(isset(WebhookEventRegistry::all()['unit.event']), 'webhook event registry accepts plugin events');

$logPath = sys_get_temp_dir() . '/daiying-log-' . bin2hex(random_bytes(4)) . '.log';
$logger = new FileLogger($logPath);
$logger->error('secret=sk_live_123456789 password=hunter2', ['api_key' => 'sk_live_abcdefg', 'nested' => ['refresh_token' => 'refresh-secret']]);
$log = (string) file_get_contents($logPath);
$check(!str_contains($log, 'hunter2') && !str_contains($log, 'sk_live_abcdefg') && !str_contains($log, 'refresh-secret'), 'FileLogger uses central secret redaction');
$redacted = SecretRedactor::redact(['Authorization' => 'Bearer abc.def.ghi', 'safe' => 'ok']);
$check(($redacted['Authorization'] ?? '') === '[redacted]' && ($redacted['safe'] ?? '') === 'ok', 'SecretRedactor handles authorization-like keys');

$manifest = new PluginManifest('local.foundation', 'Foundation', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', ['queue.register', 'webhook.register', 'cache.use'], [], [], 'plugin', false, [], [], '');
$context = new PluginContext($manifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.foundation'), null, null, null, false, '', null, null, $queue, $arrayCache, new WebhookService($pdo, $queue));
$context->cache()->set('unit', 'ctx', 'value', 30);
$check($context->cache()->get('unit', 'ctx') === 'value', 'PluginContext exposes cache service');
$context->registerQueueHandler('unit.context', static function (array $payload): void {});
$check(QueueHandlerRegistry::get('unit.context') !== null, 'PluginContext registers queue handlers with capability');
$context->registerWebhookEvent('unit.context_event', 'Context Event');
$check(isset(WebhookEventRegistry::all()['unit.context_event']), 'PluginContext registers webhook events with capability');
$check($context->queue()->enqueue(new QueueJob('unit.context', [], 'local.foundation')) > 0, 'PluginContext exposes queue service');
$check($context->webhooks()->dispatch('unit.context_event', []) === [], 'PluginContext exposes webhook service');

$blockedContext = new PluginContext(new PluginManifest('local.noqueue', 'No Queue', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.noqueue'), null, null, null, false, '', null, null, $queue, $arrayCache, new WebhookService($pdo, $queue));
$blocked = false;
try {
    $blockedContext->registerQueueHandler('unit.blocked', static function (array $payload): void {});
} catch (PluginException) {
    $blocked = true;
}
$check($blocked, 'PluginContext queue registration requires capability');

$check(PublicApiRegistry::contract('queue.service')['version'] === '1.0', 'queue service is registered as public API');
$check(PublicApiRegistry::contract('cache.service')['version'] === '1.0', 'cache service is registered as public API');
$check(PublicApiRegistry::contract('webhook.service')['version'] === '1.0', 'webhook service is registered as public API');
$check(PublicApiRegistry::contract('role.capabilities')['version'] === '1.0', 'role capability service is registered as public API');

echo "Foundation system services tests PASS\n";
