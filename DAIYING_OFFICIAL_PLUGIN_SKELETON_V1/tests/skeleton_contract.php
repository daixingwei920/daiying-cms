<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__, 2));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Auth\FrontUserAuthenticator;
use Cms\Core\Auth\FrontUserService;
use Cms\Core\Cache\ArrayCache;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\PluginContentService;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Queue\QueueService;
use Cms\Core\Scheduler\SchedulerService;
use Cms\Core\Webhook\WebhookService;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}\n");
        return;
    }
    echo "[PASS] {$message}\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_09_08_000003_foundation_system_services.php',
    '2026_09_08_000004_content_foundation_safety.php',
    '2026_09_08_000005_core_scheduler_foundation.php',
    '2026_08_29_000001_members_comments_schema.php',
    '2026_09_26_000001_plugin_sdk_foundation_v1.php',
] as $migrationFile) {
    $migration = require CMS_ROOT . '/system/migrations/' . $migrationFile;
    $migration->up($pdo);
}

$manifestData = json_decode((string) file_get_contents(dirname(__DIR__) . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = PluginManifest::fromArray($manifestData);
$events = new EventDispatcher();
$blocks = new BlockRegistry();
$runtime = new PluginRuntimeRegistry();
$types = ContentTypeRegistry::defaults();
$queue = new QueueService($pdo);
$scheduler = new SchedulerService($pdo);
$content = new PluginContentService($manifest, new ContentRepository($pdo, $types, array_keys($blocks->all())), fn (): ContentRepository => new ContentRepository($pdo, $types, array_keys($blocks->all())));
$context = new PluginContext(
    $manifest,
    $events,
    $blocks,
    new PluginDataStore($pdo, $manifest->id),
    null,
    $runtime,
    new PluginSecretStore($pdo, str_repeat('k', 32)),
    false,
    dirname(__DIR__),
    null,
    null,
    $queue,
    new ArrayCache(),
    new WebhookService($pdo, $queue),
    $types,
    null,
    $scheduler,
    null,
    $content,
    new FrontUserService($manifest, $pdo, new FrontUserAuthenticator($pdo, $events), $events),
);

$register = require dirname(__DIR__) . '/plugin.php';
$register($context);

$routes = $runtime->routes();
$routeByKey = [];
foreach ($routes as $route) {
    $routeByKey[$route->method . ' ' . $route->path] = $route;
}
$check(isset($routeByKey['GET /admin/sdk-skeleton']), 'Skeleton registers admin route.');
$check(isset($routeByKey['POST /sdk-skeleton/webhook']), 'Skeleton registers raw-body webhook route.');
$check($scheduler->find('official.sdk_skeleton.heartbeat') !== null, 'Skeleton registers scheduled task.');
$check(isset($blocks->all()['sdk_skeleton_card']), 'Skeleton registers block.');

$webhook = $routeByKey['POST /sdk-skeleton/webhook']->handler;
$response = $webhook(new Request('POST', '/sdk-skeleton/webhook', [], [], [], '<xml/>'));
$check($response->status() === 200 && str_contains($response->body(), '"bytes":6'), 'Skeleton webhook reads Request::rawBody().');

if ($failures > 0) {
    fwrite(STDERR, "Skeleton contract FAILED: {$failures}\n");
    exit(1);
}

echo "Skeleton contract PASS\n";
