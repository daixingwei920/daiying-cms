<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionResult.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionTransportInterface.php';

use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Seo\SearchEngine\OfficialBaiduSubmitBridge;
use Cms\Core\Seo\SearchEngine\SearchEngineDataRepository;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionResult;
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

final class SeoP3BaiduSubmitFakeTransport implements BaiduUrlSubmissionTransportInterface
{
    public int $calls = 0;
    /** @var list<list<string>> */
    public array $submitted = [];

    public function submit(string $siteUrl, string $token, array $urls, int $timeoutSeconds): BaiduUrlSubmissionResult
    {
        $this->calls++;
        $this->submitted[] = $urls;
        if ($siteUrl !== 'https://www.daiyingcms.com' || $token !== 'plugin-owned-token') {
            return new BaiduUrlSubmissionResult(500, null, null, [], [], [], 'Unexpected plugin bridge request.');
        }

        return new BaiduUrlSubmissionResult(200, count($urls), 88, [], [], ['success' => count($urls), 'remain' => 88]);
    }
}

$db = sys_get_temp_dir() . '/daiying-seo-search-engine-p3-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000003_plugin_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_08_15_000001_plugin_public_contract_schema.php',
    '2026_09_08_000003_foundation_system_services.php',
    '2026_10_03_000001_seo_keyword_system_p1.php',
    '2026_10_03_000002_seo_keyword_center_p2.php',
    '2026_10_03_000003_seo_search_engine_p3.php',
] as $migrationFile) {
    (require CMS_ROOT . '/system/migrations/' . $migrationFile)->up($pdo);
}
$pluginMigration = require CMS_ROOT . '/content/plugins/official.seo.baidu-submit/migrations/001_baidu_url_submission.php';
$pluginMigration['up']($pdo);
$now = gmdate('c');
$pdo->prepare('INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at) VALUES (:plugin_id, :name, :version, :author, :status, :trust_level, :capabilities_json, :installed_at, :updated_at)')
    ->execute([
        ':plugin_id' => 'official.seo.baidu-submit',
        ':name' => 'Baidu URL Submit',
        ':version' => '0.1.0-alpha.2',
        ':author' => 'Daiying CMS',
        ':status' => 'Enabled',
        ':trust_level' => 'trusted_php',
        ':capabilities_json' => json_encode(['seo.manage', 'seo.submit', 'queue.register', 'network.external'], JSON_UNESCAPED_SLASHES),
        ':installed_at' => $now,
        ':updated_at' => $now,
    ]);

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$repo->create('article', 'PHP CMS Guide', 'php-cms-guide', [
    ['type' => 'paragraph', 'data' => ['text' => 'PHP CMS guide.']],
], 'published', [
    'robots_index' => true,
    'target_keywords' => 'PHP CMS',
]);
$repo->saveTerm('category', 'PHP CMS', 'php-cms', null, [
    'robots_index' => true,
    'target_keywords' => 'PHP CMS',
]);
$repo->saveSeoKeyword('PHP CMS', '/category/php-cms', 'active', 'manual');

$searchRepo = new SearchEngineDataRepository($pdo);
$missingCoreToken = (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_secrets WHERE plugin_id = 'core.seo.search_engine.baidu'")->fetchColumn();
$check($missingCoreToken === 0, 'Core does not own a Baidu token secret.');
$hasCoreSubmissionTable = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cms_seo_search_engine_submissions'")->fetchColumn();
$check($hasCoreSubmissionTable === 0, 'P3 migration does not create a second Baidu submission log table.');
$check(!class_exists('Cms\\Core\\Seo\\SearchEngine\\BaiduSearchResourceProvider'), 'Core direct Baidu provider class is not present.');

$secrets = new PluginSecretStore($pdo, 'unit-secret-key');
$pluginRepoClass = 'Official\\Seo\\BaiduSubmit\\BaiduUrlSubmissionRepository';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php';
$pluginRepo = new $pluginRepoClass($pdo, $secrets);
$pluginRepo->saveSettings('https://www.daiyingcms.com', true, 1800, 'plugin-owned-token');
$secretOwners = $pdo->query("SELECT plugin_id, secret_key FROM cms_plugin_secrets ORDER BY plugin_id, secret_key")->fetchAll(PDO::FETCH_ASSOC);
$check(count($secretOwners) === 1 && ($secretOwners[0]['plugin_id'] ?? '') === 'official.seo.baidu-submit', 'official.seo.baidu-submit is the single Baidu token owner.');

$fakeTransport = new SeoP3BaiduSubmitFakeTransport();
$bridge = new OfficialBaiduSubmitBridge($pdo, CMS_ROOT, 'unit-secret-key', $fakeTransport);
$status = $bridge->status();
$check(($status['status'] ?? '') === 'connected' && ($status['credential_status'] ?? '') === 'configured', 'Core reads Baidu connected state from the official plugin.');

$submit = $bridge->submitUrl('https://www.daiyingcms.com/category/php-cms');
$check(($submit['status'] ?? '') === 'submitted' && $fakeTransport->calls === 1, 'Keyword Center Baidu submit goes through the official plugin service.');
$pluginLogs = $bridge->recentLogs(10);
$check(count($pluginLogs) === 1 && ($pluginLogs[0]['trigger_type'] ?? '') === 'keyword_center', 'Baidu submission facts are read from plugin-owned logs.');
$deduped = $bridge->submitUrl('https://www.daiyingcms.com/category/php-cms');
$check(($deduped['status'] ?? '') === 'deduped' && $fakeTransport->calls === 1, 'Plugin dedupe behavior is preserved through the Core bridge.');

$importOne = $searchRepo->importMetrics([[
    'keyword' => 'PHP CMS',
    'url_path' => '/category/php-cms',
    'search_engine' => 'baidu',
    'impressions' => '100',
    'clicks' => '10',
    'ctr' => '10',
    'average_position' => '3.2',
    'period_start' => '2026-10-01',
    'period_end' => '2026-10-02',
]], 'manual_import');
$importDup = $searchRepo->importMetrics([[
    'keyword' => 'PHP CMS',
    'url_path' => '/category/php-cms',
    'search_engine' => 'baidu',
    'impressions' => '120',
    'clicks' => '12',
    'ctr' => '10',
    'average_position' => '2.9',
    'period_start' => '2026-10-01',
    'period_end' => '2026-10-02',
]], 'manual_import');
$searchRepo->importMetrics([[
    'keyword' => 'PHP CMS',
    'url_path' => '/category/php-cms',
    'search_engine' => 'google',
    'impressions' => '',
    'clicks' => '',
    'ctr' => '',
    'average_position' => '',
    'period_start' => '2026-10-01',
    'period_end' => '2026-10-02',
]], 'manual_import');
$check(($importOne['inserted'] ?? 0) === 1 && ($importDup['updated'] ?? 0) === 1, 'manual import remains idempotent after Baidu submit dedupe.');

$metrics = $searchRepo->metricsForKeyword('PHP CMS');
$check(count($metrics) === 2, 'historical metrics still support multiple search engines.');
$baiduMetric = array_values(array_filter($metrics, static fn (array $row): bool => ($row['search_engine'] ?? '') === 'baidu'))[0] ?? [];
$googleMetric = array_values(array_filter($metrics, static fn (array $row): bool => ($row['search_engine'] ?? '') === 'google'))[0] ?? [];
$check(($baiduMetric['impressions'] ?? '') === '120' && ($baiduMetric['average_position'] ?? '') === '2.9', 'duplicate import updates Baidu observed metrics.');
$check(($googleMetric['impressions'] ?? '') === '', 'missing imported metrics stay empty at storage level.');

$detail = $repo->seoKeywordDetail('PHP CMS');
$observed = is_array($detail['observed_metrics'] ?? null) ? $detail['observed_metrics'] : [];
$googleObserved = array_values(array_filter($observed, static fn (array $row): bool => ($row['search_engine'] ?? '') === 'google'))[0] ?? [];
$check(($googleObserved['impressions'] ?? '') === 'Not Available', 'missing metrics render as Not Available in keyword center data.');
$check(($detail['primary_url'] ?? '') === '/category/php-cms' && ($detail['status'] ?? '') === 'active', 'observed data import does not modify target keyword data.');
$check(($detail['binding_count'] ?? 0) === 2 && ($detail['conflict'] ?? false) === true, 'targetKeywordConflicts behavior remains intact with observed data present.');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' SEO search engine P3 checks failed.' . PHP_EOL);
    exit(1);
}

echo 'SEO search engine P3 dedupe checks passed.' . PHP_EOL;
