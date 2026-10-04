<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$db = sys_get_temp_dir() . '/daiying-seo-keyword-center-p2-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_08_12_000001_core_schema.php',
    '2026_08_12_000002_content_media_schema.php',
    '2026_08_12_000008_media_release_schema.php',
    '2026_08_12_000011_content_scheduler_schema.php',
    '2026_10_03_000001_seo_keyword_system_p1.php',
    '2026_10_03_000002_seo_keyword_center_p2.php',
] as $migrationFile) {
    $migration = require CMS_ROOT . '/system/migrations/' . $migrationFile;
    $migration->up($pdo);
}

$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$articleId = $repo->create('article', 'Daiying CMS Install Guide', 'daiying-install-guide', [
    ['type' => 'paragraph', 'data' => ['text' => 'Install Daiying CMS.']],
], 'published', [
    'seo_title' => 'Daiying CMS Install SEO',
    'seo_description' => 'Install guide description',
    'canonical_url' => 'https://www.daiyingcms.com/articles/daiying-install-guide',
    'robots_index' => true,
    'target_keywords' => 'Daiying CMS安装教程, WordPress替代, PHP CMS',
]);
$repo->create('article', 'WordPress Alternative Guide', 'wordpress-alternative-guide', [
    ['type' => 'paragraph', 'data' => ['text' => 'Alternative guide.']],
], 'published', [
    'robots_index' => true,
    'target_keywords' => 'WordPress替代',
]);
$noindexId = $repo->create('article', 'Noindex Draft Target', 'noindex-target', [
    ['type' => 'paragraph', 'data' => ['text' => 'Noindex target.']],
], 'published', [
    'robots_index' => false,
    'target_keywords' => 'Noindex Target',
]);
$categoryId = $repo->saveTerm('category', 'PHP CMS', 'php-cms', null, [
    'seo_title' => 'PHP CMS Category SEO',
    'seo_description' => 'PHP CMS category description',
    'canonical_url' => 'https://www.daiyingcms.com/category/php-cms',
    'robots_index' => true,
    'robots_follow' => true,
    'target_keywords' => 'PHP CMS',
]);
$tagId = $repo->saveTerm('tag', 'WordPress替代', 'wordpress-alternative', null, [
    'seo_title' => 'WordPress Alternative Tag SEO',
    'seo_description' => 'WordPress alternative tag description',
    'canonical_url' => 'https://www.daiyingcms.com/tag/wordpress-alternative',
    'robots_index' => true,
    'robots_follow' => true,
    'target_keywords' => 'WordPress替代',
]);
$repo->saveTerm('category', 'Noindex Category', 'noindex-category', null, [
    'robots_index' => false,
    'target_keywords' => 'Noindex Category Target',
]);

$repo->saveSeoKeyword('PHP CMS', '/category/php-cms', 'active', 'manual', 'Primary category.');
$repo->saveSeoKeyword('Draft Keyword', '', 'draft', 'manual', '');
$repo->saveSeoKeyword('Paused Keyword', '/articles/daiying-install-guide', 'paused', 'manual', '');

$detail = $repo->seoKeywordDetail('PHP CMS');
$check(($detail['binding_count'] ?? 0) === 2, 'article + category conflict is detected for one target keyword');
$check(($detail['conflict'] ?? false) === true, 'keyword detail marks multiple bindings as potential cannibalization');
$check(($detail['primary_url'] ?? '') === '/category/php-cms', 'primary landing page is stored manually');
$check(($detail['status'] ?? '') === 'active' && ($detail['source'] ?? '') === 'manual', 'keyword status and source are persisted');
$check(in_array('article', $detail['content_types'] ?? [], true) && in_array('category', $detail['content_types'] ?? [], true), 'keyword detail exposes article and category content types');
$check(count(array_filter($detail['bindings'] ?? [], static fn (array $binding): bool => ($binding['canonical'] ?? '') === 'https://www.daiyingcms.com/category/php-cms')) === 1, 'canonical information is exposed for category bindings');

$wordpress = $repo->seoKeywordDetail('WordPress替代');
$check(($wordpress['binding_count'] ?? 0) >= 2, 'article + tag conflict is detected for one target keyword');
$check(in_array('tag', $wordpress['content_types'] ?? [], true), 'tag bindings are included in keyword detail');

$install = $repo->seoKeywordDetail('Daiying CMS安装教程');
$check(($install['binding_count'] ?? 0) === 1 && ($install['conflict'] ?? true) === false, 'single keyword to single page remains normal');
$check((string) (($install['bindings'][0]['url_path'] ?? '')) === '/articles/daiying-install-guide', 'single-page keyword exposes the article URL');
$check((string) (($install['bindings'][0]['seo_title'] ?? '')) === 'Daiying CMS Install SEO', 'SEO title is exposed on bindings');
$check((string) (($install['bindings'][0]['seo_description'] ?? '')) === 'Install guide description', 'SEO description is exposed on bindings');
$check(str_contains((string) (($install['bindings'][0]['target_keywords'] ?? '')), 'Daiying CMS安装教程'), 'target keywords are exposed on bindings');

$rows = $repo->seoKeywordCenterRows();
$byKeyword = [];
foreach ($rows as $row) {
    $byKeyword[(string) $row['keyword']] = $row;
}
$check(isset($byKeyword['Daiying CMS安装教程']) && ($byKeyword['Daiying CMS安装教程']['status'] ?? '') === 'active' && ($byKeyword['Daiying CMS安装教程']['source'] ?? '') === 'content', 'old P1 target_keywords data appears as active content-sourced rows');
$check(isset($byKeyword['Draft Keyword']) && ($byKeyword['Draft Keyword']['binding_count'] ?? -1) === 0, 'manual keyword with no binding appears as unassigned draft data');
$check(isset($byKeyword['Paused Keyword']) && ($byKeyword['Paused Keyword']['status'] ?? '') === 'paused', 'paused keyword status is supported without deleting bindings');

$stats = $repo->seoKeywordCenterStats();
$check(($stats['total'] ?? 0) >= 7, 'stats count target and manually configured keywords');
$check(($stats['conflicts'] ?? 0) >= 2, 'stats count conflict keywords');
$check(($stats['search_metrics_connected'] ?? true) === false, 'search engine metrics are represented as not connected instead of zero data');

$searchRows = $repo->seoKeywordCenterRows(['q' => '安装']);
$check(count($searchRows) === 1 && (string) ($searchRows[0]['keyword'] ?? '') === 'Daiying CMS安装教程', 'keyword search filters rows');
$activeRows = $repo->seoKeywordCenterRows(['status' => 'active']);
$check(count($activeRows) >= 1 && count(array_filter($activeRows, static fn (array $row): bool => ($row['status'] ?? '') !== 'active')) === 0, 'status filter returns only active keywords');
$draftRows = $repo->seoKeywordCenterRows(['status' => 'draft']);
$check(count($draftRows) >= 1 && count(array_filter($draftRows, static fn (array $row): bool => ($row['status'] ?? '') !== 'draft')) === 0, 'status filter returns only draft keywords');
$conflictRows = $repo->seoKeywordCenterRows(['conflict' => 'yes']);
$check(count($conflictRows) >= 2 && count(array_filter($conflictRows, static fn (array $row): bool => ($row['conflict'] ?? false) !== true)) === 0, 'conflict filter returns only potential cannibalization rows');
$articleRows = $repo->seoKeywordCenterRows(['type' => 'article']);
$check(count($articleRows) >= 1 && count(array_filter($articleRows, static fn (array $row): bool => !in_array('article', $row['content_types'] ?? [], true))) === 0, 'content type filter returns only rows with article bindings');
$emptyRows = $repo->seoKeywordCenterRows(['q' => 'does-not-exist']);
$check($emptyRows === [], 'no-data search state returns an empty list');

$noindexDetail = $repo->seoKeywordDetail('Noindex Target');
$check((string) (($noindexDetail['bindings'][0]['index_status'] ?? '')) === 'noindex', 'robots=noindex page status is visible in keyword bindings');
$noindexCategory = $repo->seoKeywordDetail('Noindex Category Target');
$check((string) (($noindexCategory['bindings'][0]['index_status'] ?? '')) === 'noindex', 'robots=noindex term status is visible in keyword bindings');

$metricColumns = array_column($pdo->query('PRAGMA table_info(cms_seo_keyword_metrics)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['keyword', 'search_engine', 'impressions', 'clicks', 'ctr', 'average_position', 'period_start', 'period_end', 'source'] as $column) {
    $check(in_array($column, $metricColumns, true), 'search metric schema includes ' . $column);
}

$bindings = $repo->targetKeywordBindings();
$check(count(array_filter($bindings, static fn (array $binding): bool => ($binding['source_id'] ?? null) === $articleId)) >= 3, 'targetKeywordBindings remains the factual content keyword source');
$check(count(array_filter($bindings, static fn (array $binding): bool => ($binding['source_id'] ?? null) === $categoryId && ($binding['content_type'] ?? '') === 'category')) === 1, 'targetKeywordBindings includes category bindings');
$check(count(array_filter($bindings, static fn (array $binding): bool => ($binding['source_id'] ?? null) === $tagId && ($binding['content_type'] ?? '') === 'tag')) === 1, 'targetKeywordBindings includes tag bindings');
$check(count(array_filter($bindings, static fn (array $binding): bool => ($binding['source_id'] ?? null) === $noindexId && ($binding['content_type'] ?? '') === 'article' && ($binding['index_status'] ?? '') === 'noindex')) === 1, 'targetKeywordBindings preserves noindex binding facts');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' SEO keyword center P2 checks failed.' . PHP_EOL);
    exit(1);
}

echo 'SEO keyword center P2 checks passed.' . PHP_EOL;
