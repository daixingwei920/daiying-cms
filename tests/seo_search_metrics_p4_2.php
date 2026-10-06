<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Scheduler\ScheduledTask;
use Cms\Core\Scheduler\SchedulerService;
use Cms\Core\Scheduler\SchedulerTaskRegistry;
use Cms\Core\Seo\Keyword\SeoLifecycleAggregator;
use Cms\Core\Seo\SearchMetrics\GoogleSearchConsoleConnectionRepository;
use Cms\Core\Seo\SearchMetrics\GoogleSearchConsoleProvider;
use Cms\Core\Seo\SearchMetrics\SearchMetricsSyncService;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$db = sys_get_temp_dir() . '/daiying-seo-search-metrics-p4-2-' . bin2hex(random_bytes(4)) . '.sqlite';
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
    '2026_09_08_000005_core_scheduler_foundation.php',
    '2026_10_03_000001_seo_keyword_system_p1.php',
    '2026_10_03_000002_seo_keyword_center_p2.php',
    '2026_10_03_000003_seo_search_engine_p3.php',
] as $migrationFile) {
    (require CMS_ROOT . '/system/migrations/' . $migrationFile)->up($pdo);
}

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$repo->create('article', 'Stripe Guide', 'stripe-guide', [
    ['type' => 'paragraph', 'data' => ['text' => 'Stripe guide.']],
], 'published', [
    'seo_title' => 'Stripe Guide SEO',
    'seo_description' => 'Stripe payment guide.',
    'canonical_url' => 'https://www.daiyingcms.com/articles/stripe-guide',
    'robots_index' => true,
    'target_keywords' => 'Daiying CMS Stripe',
]);
$repo->saveSeoKeyword('Daiying CMS Stripe', '/articles/stripe-guide', 'active', 'manual');

$aggregator = new SeoLifecycleAggregator($pdo, $repo, null, 'https://www.daiyingcms.com');
$before = $aggregator->dashboard();
$check(($before['stats']['with_observed_data'] ?? -1) === 0, 'With Observed Data starts at 0 without metrics rows');

$secrets = new PluginSecretStore($pdo, 'unit-secret-key');
$connections = new GoogleSearchConsoleConnectionRepository($pdo, $secrets);
$connections->savePublicConfig('google-client-id', 'https://www.daiyingcms.com/', true);
$connections->saveClientSecret('google-client-secret');
$connections->saveRefreshToken('google-refresh-token');
$status = $connections->status();
$check(($status['status'] ?? '') === 'connected' && !str_contains(json_encode($status, JSON_UNESCAPED_SLASHES) ?: '', 'google-refresh-token'), 'Google connection status is connected without exposing tokens');

$httpCalls = [];
$http = static function (string $method, string $url, array $headers, array $body) use (&$httpCalls): array {
    $httpCalls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    if ($url === 'https://oauth2.googleapis.com/token') {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'unit-access-token'], JSON_UNESCAPED_SLASHES)];
    }
    if (str_contains($url, '/searchAnalytics/query')) {
        return ['status' => 200, 'body' => json_encode([
            'rows' => [[
                'keys' => ['Daiying CMS Stripe', 'https://www.daiyingcms.com/articles/stripe-guide', '2026-10-01'],
                'clicks' => 2,
                'impressions' => 44,
                'ctr' => 0.0454545,
                'position' => 9.7,
            ]],
        ], JSON_UNESCAPED_SLASHES)];
    }
    return ['status' => 404, 'body' => '{}'];
};

$sync = new SearchMetricsSyncService($pdo, new GoogleSearchConsoleProvider($connections, $http), $connections);
$result = $sync->syncGoogle('2026-10-01', '2026-10-01');
$check($result['ok'] && $result['inserted'] === 1 && $result['rows'] === 1, 'Google Search Console sync imports one metrics row');
$check(count(array_filter($httpCalls, static fn (array $call): bool => str_contains((string) $call['url'], '/searchAnalytics/query'))) === 1, 'Google provider calls Search Analytics API');

$after = (new SeoLifecycleAggregator($pdo, $repo, null, 'https://www.daiyingcms.com'))->dashboard();
$check(($after['stats']['with_observed_data'] ?? 0) === 1, 'With Observed Data becomes 1 after Google metrics sync');
$stripe = $after['rows'][0] ?? [];
$check(($stripe['lifecycle']['observed_search_data'] ?? '') === 'yes', 'SeoLifecycleAggregator observes synced Google data for primary URL');
$check(($stripe['metric_trends']['google']['30d']['impressions'] ?? 0) === 44, 'Google metrics enter 30d trend aggregation');

$second = $sync->syncGoogle('2026-10-01', '2026-10-01');
$metricCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_seo_keyword_metrics')->fetchColumn();
$check($second['updated'] === 1 && $metricCount === 1, 'Duplicate Google sync updates existing metrics row');

SchedulerTaskRegistry::clear();
SchedulerTaskRegistry::register(SearchMetricsSyncService::TASK_ID, static function () use ($sync): void {
    $sync->syncGoogle('2026-10-01', '2026-10-01');
});
$scheduler = new SchedulerService($pdo);
$scheduler->register(new ScheduledTask(SearchMetricsSyncService::TASK_ID, 'core.seo', 60));
$pdo->prepare('UPDATE cms_core_scheduled_tasks SET next_run_at = :next_run_at WHERE task_id = :task_id')
    ->execute([':next_run_at' => gmdate('c', time() - 60), ':task_id' => SearchMetricsSyncService::TASK_ID]);
$scheduled = $scheduler->runDue(1);
$check($scheduled['succeeded'] === 1, 'Core scheduler can run Search Metrics sync task');

$coreBaiduSecrets = (int) $pdo->query("SELECT COUNT(*) FROM cms_plugin_secrets WHERE plugin_id = 'core.seo.search_engine.baidu'")->fetchColumn();
$check($coreBaiduSecrets === 0, 'P4.2 does not create a Core Baidu token owner');
$coreBaiduProvider = class_exists('Cms\\Core\\Seo\\SearchEngine\\BaiduSearchResourceProvider');
$check(!$coreBaiduProvider, 'P4.2 does not add a direct Baidu metrics provider');
$coreSubmissionTables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cms_seo_search_engine_submissions'")->fetchColumn();
$check($coreSubmissionTables === 0, 'P4.2 does not create a second Baidu submission table');

$admin = new AdminController(Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $db],
    'site' => ['url' => 'https://www.daiyingcms.com', 'name' => 'Daiying CMS'],
    'security' => ['encryption_key' => 'unit-secret-key'],
]), new FileLogger(sys_get_temp_dir() . '/daiying-seo-p4-2-admin.log'), CMS_ROOT);
$method = new ReflectionMethod(AdminController::class, 'seoSearchEnginesHtml');
$html = (string) $method->invoke($admin, ['status' => 'plugin_not_installed', 'message' => '百度推送插件未安装'], [], '', $connections->status(), $connections->config());
$check(str_contains($html, 'Google Search Console') && str_contains($html, '已连接'), 'Search Engine UI renders Google connected state');
$check(str_contains($html, '百度目前未提供可用的公开官方接口'), 'Search Engine UI renders Baidu Metrics as Not Available from official API');
$check(!str_contains($html, 'google-client-secret') && !str_contains($html, 'google-refresh-token'), 'Search Engine UI does not expose Google secrets');
$saveFormStart = strpos($html, 'action="/admin/seo/search-engines/google/save"');
$syncFormStart = strpos($html, 'action="/admin/seo/search-engines/google/sync"');
$saveFormEnd = $saveFormStart === false ? false : strpos($html, '</form>', $saveFormStart);
$check($saveFormStart !== false && $syncFormStart !== false, 'Search Engine UI renders separate Google save and sync forms');
$check($saveFormEnd !== false && $syncFormStart !== false && $saveFormEnd < $syncFormStart, 'Google sync form is not nested inside the Google save form');
$check(substr_count($html, 'action="/admin/seo/search-engines/google/sync"') === 1, 'Search Engine UI renders exactly one Google sync form');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' SEO P4.2 search metrics checks failed.' . PHP_EOL);
    exit(1);
}

echo 'SEO P4.2 search metrics checks passed.' . PHP_EOL;
