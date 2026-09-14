<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionResult.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionTransportInterface.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php';

use Cms\Core\Content\ContentPublishedEvent;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Queue\QueueHandlerRegistry;
use Cms\Core\Queue\QueueService;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionRepository;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionResult;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionService;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionTransportInterface;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

final class BaiduSubmitFakeTransport implements BaiduUrlSubmissionTransportInterface
{
    public int $calls = 0;
    /** @var list<list<string>> */
    public array $submitted = [];
    public BaiduUrlSubmissionResult $next;

    public function __construct()
    {
        $this->next = new BaiduUrlSubmissionResult(200, 1, 99, [], [], ['success' => 1, 'remain' => 99]);
    }

    public function submit(string $siteUrl, string $token, array $urls, int $timeoutSeconds): BaiduUrlSubmissionResult
    {
        $this->calls++;
        $this->submitted[] = $urls;
        if ($siteUrl !== 'https://www.daiyingcms.com' || $token !== 'unit-baidu-token' || $timeoutSeconds !== 20) {
            return new BaiduUrlSubmissionResult(500, null, null, [], [], [], 'Unexpected request contract.');
        }

        return $this->next;
    }
}

function baidu_submit_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE cms_plugin_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, secret_key TEXT, ciphertext TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX idx_plugin_secrets_plugin_key ON cms_plugin_secrets (plugin_id, secret_key)');
    $pdo->exec('CREATE TABLE cms_core_queue_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_type TEXT NOT NULL,
        owner TEXT NOT NULL DEFAULT "core",
        payload_json TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        attempts INTEGER NOT NULL DEFAULT 0,
        max_attempts INTEGER NOT NULL DEFAULT 3,
        not_before TEXT NOT NULL,
        last_error TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $migration = require CMS_ROOT . '/content/plugins/official.seo.baidu-submit/migrations/001_baidu_url_submission.php';
    $migration['up']($pdo);
    $migration['up']($pdo);

    return $pdo;
}

$manifest = json_decode((string) file_get_contents(CMS_ROOT . '/content/plugins/official.seo.baidu-submit/plugin.json'), true);
$check(($manifest['plugin_id'] ?? '') === 'official.seo.baidu-submit', 'Manifest declares the official Baidu URL Submit plugin ID.');
$check(in_array('seo.submit', $manifest['capabilities'] ?? [], true), 'Manifest declares seo.submit capability.');
$check(in_array('queue.register', $manifest['capabilities'] ?? [], true), 'Manifest declares queue.register capability.');
$migration = require CMS_ROOT . '/content/plugins/official.seo.baidu-submit/migrations/001_baidu_url_submission.php';
$check(($migration['id'] ?? '') === 'baidu_url_submission_001_core', 'Migration id is stable.');
$check(in_array('table:baidu_url_submission_logs', $migration['affected_objects'] ?? [], true), 'Migration declares plugin-owned log table.');

$pdo = baidu_submit_pdo();
$secrets = new PluginSecretStore($pdo, 'baidu-submit-test-key');
$repo = new BaiduUrlSubmissionRepository($pdo, $secrets);
$fake = new BaiduSubmitFakeTransport();
$service = new BaiduUrlSubmissionService($repo, $fake, new QueueService($pdo));

$repo->saveSettings('https://www.daiyingcms.com', true, 1800, 'unit-baidu-token');
$secretPayload = (string) $pdo->query("SELECT ciphertext FROM cms_plugin_secrets WHERE plugin_id = 'official.seo.baidu-submit'")->fetchColumn();
$check(!str_contains($secretPayload, 'unit-baidu-token'), 'Baidu token is encrypted at rest.');
$check($repo->maskedToken() !== 'unit-baidu-token' && is_string($repo->maskedToken()), 'Baidu token is masked in admin output.');

$result = $service->submitUrls(['https://www.daiyingcms.com/articles/passkey-ai-commerce'], 'manual');
$check($result['status'] === 'submitted', 'Manual submit accepts a same-site public URL.');
$check($result['http_status'] === 200 && $result['success'] === 1 && $result['remain'] === 99, 'Baidu response success/remain/http_status are preserved.');
$check($fake->calls === 1, 'Manual submit performs exactly one POST.');

$deduped = $service->submitUrls(['https://www.daiyingcms.com/articles/passkey-ai-commerce'], 'manual');
$check($deduped['status'] === 'deduped' && $fake->calls === 1, 'Dedupe window prevents repeated URL submission.');

$invalid = $service->submitUrls(['https://evil.example/articles/passkey-ai-commerce'], 'manual');
$check($invalid['status'] === 'invalid_url', 'Cross-site URLs are rejected before POST.');

$fake->next = new BaiduUrlSubmissionResult(200, 0, 98, ['https://www.daiyingcms.com/articles/wrong'], ['https://www.daiyingcms.com/articles/bad'], [
    'success' => 0,
    'remain' => 98,
    'not_same_site' => ['https://www.daiyingcms.com/articles/wrong'],
    'not_valid' => ['https://www.daiyingcms.com/articles/bad'],
]);
$mixed = $service->submitUrls(['https://www.daiyingcms.com/articles/wrong', 'https://www.daiyingcms.com/articles/bad'], 'manual');
$check($mixed['not_same_site'] === ['https://www.daiyingcms.com/articles/wrong'] && $mixed['not_valid'] === ['https://www.daiyingcms.com/articles/bad'], 'Baidu not_same_site and not_valid arrays are preserved.');

$fake->next = new BaiduUrlSubmissionResult(200, 0, 0, [], [], ['success' => 0, 'remain' => 0]);
$quota = $service->submitUrls(['https://www.daiyingcms.com/articles/quota'], 'manual');
$check($quota['status'] === 'quota_exhausted' && $quota['remain'] === 0, 'Remain zero is recorded without retry-loop behavior.');

QueueHandlerRegistry::clear();
QueueHandlerRegistry::register(BaiduUrlSubmissionService::QUEUE_JOB_TYPE, function (array $payload) use ($service): void {
    $service->submitUrls([(string) $payload['url']], 'queue', isset($payload['content_id']) ? (int) $payload['content_id'] : null, is_string($payload['content_type'] ?? null) ? $payload['content_type'] : null);
});
$jobId = $service->enqueueUrl('https://www.daiyingcms.com/articles/queued', 42, 'article');
$check(is_int($jobId) && $jobId > 0, 'Publish path enqueues a Baidu submit job.');
$processed = $service->processQueue(10);
$check($processed['processed'] === 1 && $processed['succeeded'] === 1, 'Baidu submit queue handler processes successfully.');

$events = new EventDispatcher();
$eventJobId = null;
$events->listen(ContentPublishedEvent::class, static function (object $event) use ($service, &$eventJobId): void {
    if ($event instanceof ContentPublishedEvent) {
        $eventJobId = $service->enqueueUrl($event->publicUrl, $event->contentId, $event->contentType);
    }
});
$events->dispatch(new ContentPublishedEvent(77, 'article', 'Event Article', 'event-article', '/articles/event-article', 'https://www.daiyingcms.com/articles/event-article', gmdate('c'), 'created'));
$check(is_int($eventJobId) && $eventJobId > 0, 'ContentPublishedEvent can drive plugin enqueue integration.');

$tokenLeak = json_encode($repo->recentLogs(50), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
$check(!str_contains($tokenLeak, 'unit-baidu-token'), 'Submission logs do not contain the Baidu token.');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' Baidu URL submission checks failed.' . PHP_EOL);
    exit(1);
}

echo 'Baidu URL submission V1 checks passed.' . PHP_EOL;
