<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionResult.php';
require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionTransportInterface.php';

use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Seo\Keyword\SeoLifecycleAggregator;
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

final class SeoP41FakeBaiduTransport implements BaiduUrlSubmissionTransportInterface
{
    public int $calls = 0;

    public function submit(string $siteUrl, string $token, array $urls, int $timeoutSeconds): BaiduUrlSubmissionResult
    {
        $this->calls++;
        return new BaiduUrlSubmissionResult(200, count($urls), 9, [], [], ['success' => count($urls), 'remain' => 9]);
    }
}

$db = sys_get_temp_dir() . '/daiying-seo-lifecycle-p4-1-' . bin2hex(random_bytes(4)) . '.sqlite';
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
$repo->saveTerm('category', 'PHP CMS', 'php-cms', null, [
    'seo_title' => 'PHP CMS Category SEO',
    'seo_description' => 'PHP CMS category description',
    'canonical_url' => 'https://www.daiyingcms.com/category/php-cms',
    'robots_index' => true,
    'robots_follow' => true,
    'target_keywords' => 'PHP CMS',
]);
$repo->create('article', 'PHP CMS Guide', 'php-cms-guide', [
    ['type' => 'paragraph', 'data' => ['text' => 'PHP CMS guide.']],
], 'published', [
    'seo_title' => 'PHP CMS Guide SEO',
    'seo_description' => 'Guide description',
    'robots_index' => true,
    'target_keywords' => 'PHP CMS',
], ['PHP CMS']);
$repo->create('article', 'Ready Guide', 'ready-guide', [
    ['type' => 'paragraph', 'data' => ['text' => 'Ready guide.']],
], 'published', [
    'seo_title' => 'Ready Guide SEO',
    'seo_description' => 'Ready description',
    'canonical_url' => 'https://www.daiyingcms.com/articles/ready-guide',
    'robots_index' => true,
    'target_keywords' => 'Ready Keyword',
]);
$repo->create('article', 'Canonical Broken', 'canonical-broken', [
    ['type' => 'paragraph', 'data' => ['text' => 'Canonical broken.']],
], 'published', [
    'seo_title' => 'Canonical Broken SEO',
    'seo_description' => 'Canonical broken description',
    'canonical_url' => 'https://www.daiyingcms.com/articles/not-the-primary',
    'robots_index' => true,
    'target_keywords' => 'Canonical Mismatch',
]);
$repo->create('article', 'Noindex Page', 'noindex-page', [
    ['type' => 'paragraph', 'data' => ['text' => 'Noindex page.']],
], 'published', [
    'seo_title' => 'Noindex SEO',
    'seo_description' => 'Noindex description',
    'canonical_url' => 'https://www.daiyingcms.com/articles/noindex-page',
    'robots_index' => false,
    'target_keywords' => 'Noindex Keyword',
]);

$repo->saveSeoKeyword('Ready Keyword', '/articles/ready-guide', 'active', 'manual');
$repo->saveSeoKeyword('PHP CMS', '/category/php-cms', 'active', 'manual');
$repo->saveSeoKeyword('Canonical Mismatch', '/articles/canonical-broken', 'active', 'manual');
$repo->saveSeoKeyword('Noindex Keyword', '/articles/noindex-page', 'active', 'manual');
$repo->saveSeoKeyword('Missing Landing', '', 'draft', 'manual');

$searchRepo = new SearchEngineDataRepository($pdo);
$searchRepo->importMetrics([
    ['keyword' => 'Ready Keyword', 'url_path' => '/articles/ready-guide', 'search_engine' => 'baidu', 'impressions' => '100', 'clicks' => '1', 'ctr' => '1', 'average_position' => '11', 'period_start' => '2026-10-01', 'period_end' => '2026-10-01'],
    ['keyword' => 'Ready Keyword', 'url_path' => '/articles/ready-guide', 'search_engine' => 'baidu', 'impressions' => '300', 'clicks' => '3', 'ctr' => '1', 'average_position' => '9', 'period_start' => '2026-10-02', 'period_end' => '2026-10-02'],
    ['keyword' => 'Ready Keyword', 'url_path' => '/articles/ready-guide', 'search_engine' => 'google', 'impressions' => '', 'clicks' => '', 'ctr' => '', 'average_position' => '', 'period_start' => '2026-10-01', 'period_end' => '2026-10-02'],
    ['keyword' => 'Ready Keyword', 'url_path' => '/articles/ready-guide', 'search_engine' => 'bing', 'impressions' => '10', 'clicks' => '0', 'ctr' => '0', 'average_position' => '18', 'period_start' => '2026-10-02', 'period_end' => '2026-10-02'],
], 'manual_import');

require_once CMS_ROOT . '/content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php';
$pluginRepoClass = 'Official\\Seo\\BaiduSubmit\\BaiduUrlSubmissionRepository';
$pluginRepo = new $pluginRepoClass($pdo, new PluginSecretStore($pdo, 'unit-secret-key'));
$pluginRepo->saveSettings('https://www.daiyingcms.com', true, 1800, 'plugin-owned-token');
$transport = new SeoP41FakeBaiduTransport();
$bridge = new OfficialBaiduSubmitBridge($pdo, CMS_ROOT, 'unit-secret-key', $transport);
$submit = $bridge->submitUrl('https://www.daiyingcms.com/articles/ready-guide');
$check(($submit['status'] ?? '') === 'submitted' && $transport->calls === 1, 'fixture submission goes through official plugin bridge');

$aggregator = new SeoLifecycleAggregator($pdo, $repo, $bridge, 'https://www.daiyingcms.com');
$dashboard = $aggregator->dashboard();
$rows = [];
foreach ($dashboard['rows'] as $row) {
    $rows[(string) ($row['keyword'] ?? '')] = $row;
}

$ready = $rows['Ready Keyword'] ?? [];
$phpCms = $rows['PHP CMS'] ?? [];
$canonical = $rows['Canonical Mismatch'] ?? [];
$noindex = $rows['Noindex Keyword'] ?? [];
$missing = $rows['Missing Landing'] ?? [];

$check(($ready['page_readiness']['ready'] ?? false) === true, 'ready page passes page SEO readiness');
$check(($ready['sitemap_status'] ?? '') === 'in_sitemap', 'ready page is found in sitemap membership');
$check(($ready['index_evidence']['status'] ?? '') === 'evidence_indexed', 'observed metrics create positive index evidence');
$check(in_array(($ready['latest_baidu_submission']['status'] ?? ''), ['submitted', 'success'], true), 'latest Baidu submission is read from plugin logs');
$check(($ready['metric_trends']['baidu']['30d']['impressions'] ?? null) === 400, '30d impressions sum observed data');
$check(($ready['metric_trends']['baidu']['30d']['clicks'] ?? null) === 4, '30d clicks sum observed data');
$check(abs((float) ($ready['metric_trends']['baidu']['30d']['average_position'] ?? 0) - 9.5) < 0.01, 'weighted average position uses impressions');
$check(isset($ready['metric_trends']['google']) && ($ready['metric_trends']['google']['30d']['impressions'] ?? '') === 'Not Available', 'missing metrics render as Not Available');
$check(isset($ready['metric_trends']['bing']), 'Bing metrics stay separated by search engine');
$check(in_array('Near Page-One', $ready['opportunity']['types'] ?? [], true), 'near page-one opportunity rule fires');
$check(in_array('Low CTR', $ready['opportunity']['types'] ?? [], true), 'low CTR opportunity rule fires');
$check((int) ($ready['opportunity']['score'] ?? 0) > 0 && count($ready['opportunity']['reasons'] ?? []) > 0, 'opportunity score is explainable with reasons');

$check(($phpCms['conflict'] ?? false) === true, 'potential cannibalization reuses target keyword conflicts');
$check(($phpCms['sitemap_status'] ?? '') === 'in_sitemap', 'non-empty category is in sitemap membership');
$check(in_array('Submission Gap', $phpCms['opportunity']['types'] ?? [], true), 'submission gap uses sitemap plus missing plugin log');

$check(($canonical['page_readiness']['canonical_consistent'] ?? true) === false, 'canonical mismatch is detected');
$check(in_array('Canonical mismatch', $canonical['page_readiness']['gaps'] ?? [], true), 'canonical mismatch is reported as an on-page gap');
$check(($noindex['page_readiness']['robots_index'] ?? '') === 'noindex', 'noindex page keeps robots status');
$check(($noindex['sitemap_status'] ?? '') === 'not_in_sitemap', 'noindex page is not considered in sitemap');
$check(($missing['lifecycle']['landing_page_assigned'] ?? '') === 'no', 'missing landing page lifecycle is detected');
$check(in_array('Missing Landing Page', $missing['opportunity']['types'] ?? [], true), 'missing landing page opportunity rule fires');

$submittedOnly = $aggregator->detail('Canonical Mismatch');
$check(($submittedOnly['index_evidence']['status'] ?? '') !== 'not_indexed', 'aggregator never emits not_indexed without reliable negative evidence');
$check(($submittedOnly['lifecycle']['observed_search_data'] ?? '') === 'not_available', 'missing observed data is not treated as indexed or not indexed');

$stats = $dashboard['stats'];
$check(($stats['total'] ?? 0) >= 5, 'dashboard stats count target keywords');
$check(($stats['with_observed_data'] ?? 0) >= 1 && ($stats['missing_observed_data'] ?? 0) >= 1, 'dashboard separates observed and missing observed data');
$check(count($dashboard['opportunities'] ?? []) > 0, 'dashboard returns sorted SEO opportunities');

$admin = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db],
    'site' => ['url' => 'https://www.daiyingcms.com', 'name' => 'Daiying CMS'],
    'security' => ['encryption_key' => 'unit-secret-key'],
]), new FileLogger(sys_get_temp_dir() . '/daiying-seo-p4-1-admin.log'), CMS_ROOT);
$indexMethod = new ReflectionMethod(AdminController::class, 'seoKeywordIndexHtml');
$indexHtml = (string) $indexMethod->invoke($admin, $dashboard['rows'], $dashboard['stats'], [], $dashboard['opportunities']);
$check(str_contains($indexHtml, '今日 SEO 机会'), 'Keyword Center dashboard renders opportunities UI');
$check(str_contains($indexHtml, 'SEO 已就绪') && str_contains($indexHtml, '已提交百度'), 'Keyword Center dashboard renders P4.1 overview stats');
$check(str_contains($indexHtml, 'Submitted 不等于 Indexed'), 'Keyword Center UI keeps Submitted separate from Indexed');

$detailMethod = new ReflectionMethod(AdminController::class, 'seoKeywordDetailHtml');
$detailHtml = (string) $detailMethod->invoke($admin, $aggregator->detail('Ready Keyword'));
$check(str_contains($detailHtml, 'Page Readiness') && str_contains($detailHtml, 'Discovery / Index Evidence'), 'Keyword detail renders readiness and index evidence sections');
$check(str_contains($detailHtml, '搜索表现趋势') && str_contains($detailHtml, '优化机会'), 'Keyword detail renders trends and opportunity sections');

$coreTokenSecrets = (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_secrets WHERE plugin_id = 'core.seo.search_engine.baidu'")->fetchColumn();
$check($coreTokenSecrets === 0, 'P4.1 does not create a Core Baidu token owner');
$coreSubmissionTables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cms_seo_search_engine_submissions'")->fetchColumn();
$check($coreSubmissionTables === 0, 'P4.1 does not create a second Baidu submission table');
$p4Tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cms_seo_lifecycle%'")->fetchColumn();
$check($p4Tables === 0, 'P4.1 does not create new lifecycle tables');
$check(!class_exists('Cms\\Core\\Seo\\SearchEngine\\BaiduSearchResourceProvider'), 'P4.1 does not restore Core direct Baidu POST provider');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' SEO lifecycle P4.1 checks failed.' . PHP_EOL);
    exit(1);
}

echo 'SEO lifecycle P4.1 checks passed.' . PHP_EOL;
