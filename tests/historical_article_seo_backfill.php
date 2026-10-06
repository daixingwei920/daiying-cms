<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Ai\AiProviderClientInterface;
use Cms\Core\Ai\AiService;
use Cms\Core\Ai\SiteAiSettingsRepository;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Seo\Historical\HistoricalArticleSeoBackfillService;

final class HistoricalSeoMockAiClient implements AiProviderClientInterface
{
    public array $lastMessages = [];

    public function chat(array $messages, array $config): array
    {
        $this->lastMessages = $messages;
        return [
            'provider' => (string) ($config['provider'] ?? 'mock'),
            'model' => (string) ($config['model'] ?? 'mock-model'),
            'content' => json_encode([
                'search_intent' => '用户想了解如何安装戴影 CMS，并确认服务器环境、后台配置和上线检查。',
                'primary_keyword' => '戴影 CMS 安装',
                'auxiliary_keywords' => ['PHP CMS 安装', '戴影 CMS 教程', '自建网站 CMS'],
                'seo_title' => '戴影 CMS 安装教程 | PHP CMS 建站配置指南',
                'seo_description' => '面向站长的戴影 CMS 安装教程，介绍 PHP CMS 建站前置条件、配置步骤、常见注意事项和上线验证方法。',
                'body_markdown' => "## 安装前准备\n安装戴影 CMS 前，应先确认 PHP、数据库、域名解析和服务器权限。管理员还需要准备后台账号，并确认当前 Core 版本支持所需功能。\n\n## 操作步骤\n进入服务器环境后，按安装向导填写数据库连接信息，完成基础站点配置。上线前建议检查 Sitemap、SEO 设置和支付/插件配置是否符合当前站点需求。\n\n### 常见注意事项\n不要把测试 API Key、数据库密码或后台 Token 写进公开文章。涉及插件能力时，应以当前后台真实配置为准。",
                'change_summary' => '补充安装前准备、操作步骤、注意事项，并根据优化后正文生成 SEO。',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw' => ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 80, 'total_tokens' => 180]],
        ];
    }
}

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$db = sys_get_temp_dir() . '/daiying-historical-article-seo-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_09_08_000004_content_foundation_safety.php',
    '2026_09_08_000006_ai_foundation_v1.php',
    '2026_10_03_000001_seo_keyword_system_p1.php',
    '2026_10_03_000002_seo_keyword_center_p2.php',
    '2026_10_06_000001_historical_article_seo_backfill.php',
] as $migrationFile) {
    $migration = require CMS_ROOT . '/system/migrations/' . $migrationFile;
    $migration->up($pdo);
}

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$settings = Settings::fromArray(['site' => ['url' => 'https://www.daiyingcms.com'], 'security' => ['encryption_key' => 'historical-seo-ai-key']]);
$service = new HistoricalArticleSeoBackfillService($pdo, $repo, $settings);

$missingId = $repo->create('article', '如何安装戴影 CMS', 'install-daiying-cms', [
    ['type' => 'heading', 'data' => ['level' => 2, 'text' => '准备环境']],
    ['type' => 'paragraph', 'data' => ['text' => '安装戴影 CMS 需要准备 PHP、数据库、域名和服务器环境。']],
], 'published', [
    'seo_title' => '',
    'seo_description' => '',
    'target_keywords' => '',
    'canonical_url' => '',
]);

$manualId = $repo->create('article', 'Stripe 支付配置', 'stripe-payment-config', [
    ['type' => 'paragraph', 'data' => ['text' => 'Stripe 支付插件配置需要 API Key 和 Webhook。']],
], 'published', [
    'seo_title' => '人工 SEO Title',
    'seo_description' => '人工描述',
    'seo_keywords' => '人工关键词',
    'target_keywords' => '人工目标关键词',
    'canonical_url' => 'https://www.daiyingcms.com/articles/manual-canonical',
]);

$shortId = $repo->create('article', '早期短文章', 'early-short-article', [
    ['type' => 'heading', 'data' => ['level' => 2, 'text' => '介绍']],
    ['type' => 'heading', 'data' => ['level' => 2, 'text' => '功能']],
    ['type' => 'heading', 'data' => ['level' => 2, 'text' => '配置']],
    ['type' => 'heading', 'data' => ['level' => 2, 'text' => '总结']],
    ['type' => 'paragraph', 'data' => ['text' => '戴影 CMS 可用于快速建站，适合希望用 PHP 自建网站的管理员。文章目前只有概念介绍，缺少实际步骤、后台路径、截图和验证方法，因此需要补充更多操作细节。后续应加入安装准备、服务器环境、数据库配置、常见错误和上线检查，让读者可以按步骤完成操作。']],
], 'published', [
    'seo_title' => '',
]);

$stats = $service->auditStats();
$check($stats['article_total'] === 3, '审计统计文章总数');
$check($stats['incomplete_seo'] === 2, '审计识别 SEO 不完整文章');
$check($stats['target_keywords'] === 1, '审计识别已有 target keywords');

$scan = $service->scan();
$check($scan['processed'] === 3 && $scan['failed'] === 0, '扫描全部历史文章');

$rows = $service->rows([], 10);
$short = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['content_id'] === $shortId))[0] ?? [];
$check(($short['quality_grade'] ?? '') === 'C', '早期短文章归类为 C');
$check(($short['recommendation'] ?? '') === 'rewrite', 'C 类文章进入重点扩写/重写建议');

$service->generateForArticleId($missingId);
$service->applySeo($missingId);
$updated = $pdo->query('SELECT meta_json FROM cms_contents WHERE id = ' . $missingId)->fetchColumn();
$meta = json_decode((string) $updated, true) ?: [];
$check(trim((string) ($meta['seo_description'] ?? '')) !== '', '补全缺失 SEO description');
$check(str_contains((string) ($meta['target_keywords'] ?? ''), '戴影 CMS'), '补全语义目标关键词');
$check((string) ($meta['canonical_url'] ?? '') === 'https://www.daiyingcms.com/articles/install-daiying-cms', '补全 canonical URL');

$keywordRows = $repo->seoKeywordCenterRows();
$check(count(array_filter($keywordRows, static fn (array $row): bool => str_contains((string) ($row['keyword'] ?? ''), '戴影 CMS'))) >= 1, '补全后进入关键词中心');

$service->generateForArticleId($manualId);
$service->applySeo($manualId);
$manual = json_decode((string) $pdo->query('SELECT meta_json FROM cms_contents WHERE id = ' . $manualId)->fetchColumn(), true) ?: [];
$check((string) ($manual['seo_title'] ?? '') === '人工 SEO Title', '不覆盖已有人工 SEO Title');
$check((string) ($manual['target_keywords'] ?? '') === '人工目标关键词', '不覆盖已有人工目标关键词');
$check((string) ($manual['canonical_url'] ?? '') === 'https://www.daiyingcms.com/articles/manual-canonical', '不覆盖已有 canonical');

$service->generateForArticleId($shortId);
$job = $pdo->query('SELECT proposed_blocks_json FROM cms_historical_article_seo_jobs WHERE content_id = ' . $shortId)->fetch(PDO::FETCH_ASSOC);
$originalBlocks = $pdo->query('SELECT blocks_json FROM cms_contents WHERE id = ' . $shortId)->fetchColumn();
$check(trim((string) ($job['proposed_blocks_json'] ?? '')) !== '', '短文章生成待审核正文优化草稿');
$check(str_contains((string) $originalBlocks, '适用场景') === false, '生成草稿不自动覆盖线上正文');

$mockAi = new HistoricalSeoMockAiClient();
(new SiteAiSettingsRepository($pdo, 'historical-seo-ai-key'))->save([
    'enabled' => true,
    'provider' => 'openai_compatible',
    'adapter' => 'openai_compatible',
    'base_url' => 'https://example.test/v1',
    'model' => 'mock-content-model',
    'timeout_seconds' => 30,
    'max_tokens' => 2048,
    'temperature' => 0.3,
], 'sk-test-historical-seo', false);
$aiService = new HistoricalArticleSeoBackfillService($pdo, $repo, $settings, new AiService($pdo, $settings, $mockAi));
$aiService->generateAiForArticleId($shortId);
$aiJob = $pdo->query('SELECT * FROM cms_historical_article_seo_jobs WHERE content_id = ' . $shortId)->fetch(PDO::FETCH_ASSOC) ?: [];
$aiMeta = json_decode((string) ($aiJob['proposed_meta_json'] ?? '{}'), true) ?: [];
$aiBlocks = json_decode((string) ($aiJob['proposed_blocks_json'] ?? '[]'), true) ?: [];
$check(($aiJob['suggestion_source'] ?? '') === 'ai', 'AI 优化草稿标记为 AI 来源');
$check(($aiJob['ai_provider'] ?? '') === 'openai_compatible' && ($aiJob['ai_model'] ?? '') === 'mock-content-model', '记录 AI Provider 和 Model');
$check(str_contains((string) ($aiJob['ai_usage_json'] ?? ''), 'total_tokens'), '记录 AI token usage');
$check(($aiJob['primary_keyword'] ?? '') === '戴影 CMS 安装', '记录 AI primary keyword');
$check(str_contains((string) ($aiJob['search_intent'] ?? ''), '安装戴影 CMS'), '记录 AI search intent');
$check(($aiMeta['seo_title'] ?? '') === '戴影 CMS 安装教程 | PHP CMS 建站配置指南', 'SEO 基于 AI 优化正文生成');
$check(count($aiBlocks) >= 3, 'AI markdown 转换为待审核 blocks 草稿');
$check(str_contains((string) $pdo->query('SELECT blocks_json FROM cms_contents WHERE id = ' . $shortId)->fetchColumn(), '安装前准备') === false, 'AI 草稿不覆盖线上正文');
$check(str_contains((string) json_encode($mockAi->lastMessages, JSON_UNESCAPED_UNICODE), 'trusted_product_context'), 'AI prompt 包含可信产品上下文');

@unlink($db);
if ($failures > 0) {
    exit(1);
}
