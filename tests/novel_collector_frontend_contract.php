<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/content/plugins/official.novel-collector/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$plugin = (string) file_get_contents($root . '/content/plugins/official.novel-collector/plugin.php');
$system = (string) file_get_contents($root . '/content/plugins/official.novel-collector/src/NovelSystem.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(($manifest['plugin_id'] ?? '') === 'official.novel-collector', 'Novel collector plugin ID must remain official.novel-collector.');
$assert(($manifest['version'] ?? '') === '0.4.17', 'Novel collector version should be 0.4.17.');
$assert(($manifest['core']['max'] ?? '') === '2.0.0', 'Novel collector core max should be a concrete semver upper bound.');
$manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$assert(is_string($manifestJson) && str_contains($manifestJson, 'scheduled_tasks'), 'Novel collector manifest should declare scheduled tasks.');
$assert(is_string($manifestJson) && str_contains($manifestJson, 'novel_collector_auto_tick'), 'Novel collector manifest should expose auto tick task.');

$routes = [];
foreach (($manifest['public_routes'] ?? []) as $route) {
    $routes[] = ($route['method'] ?? 'GET') . ' ' . ($route['path'] ?? '');
}

foreach (['GET /novels', 'GET /novels/search', 'GET /novels/bookshelf', 'GET /novels/book', 'GET /novels/chapter', 'GET /novels/export.txt', 'GET /novel-collector/cron'] as $route) {
    $assert(in_array($route, $routes, true), 'Missing public route: ' . $route);
}

foreach ([
    '$novelUrl',
    '$novelChapterUrl',
    '$novelSearchUrl',
    '/novels/search',
    '/novels/bookshelf',
    'daiying_novel_bookshelf',
    'daiying_novel_reading_progress',
    'daiying_novel_reader_settings',
    'data-continue-link',
    '最近 100 章',
    'data-reader-progress',
    '$discoverCatalogUrls',
    '/admin/novel-collector/site',
    '/admin/novel-collector/site/discover',
    '/admin/novel-collector/site/create',
    '$loadAutoSettings',
    '$pickRunnableJob',
    '$runAutoBatch',
    '$runAutoTick',
    '$compactAutoTick',
    '$ensureAutoCronToken',
    '/admin/novel-collector/auto',
    '/admin/novel-collector/auto/save',
    '/admin/novel-collector/auto/tick',
    '/novel-collector/cron',
    'cron_token',
    'invalid_token',
    'novel_collector_auto',
    'formal_',
    '同域名、同端口',
] as $needle) {
    $assert(str_contains($plugin, $needle), 'Missing frontend contract token: ' . $needle);
}
foreach (['publicNovels', 'publicSections', 'publicChapterIndex', 'publicChapterSorts', 'publicChapters', 'publicChapter', "job_id'] = 'formal_'"] as $needle) {
    $assert(str_contains($system, $needle), 'Missing novel repository token: ' . $needle);
}
$assert(str_contains($system, 'extractCoverUrl'), 'Novel detector should extract cover URLs.');
$assert(str_contains($system, 'cover_url'), 'Novel repository should persist and expose cover_url.');

$assert(!str_contains($plugin, 'local.novel-collector'), 'Plugin PHP must not reference local.novel-collector.');
$assert(!str_contains($plugin, 'local.novel_collector.manage'), 'Plugin PHP must not reference local capability names.');
$assert(!str_contains($manifestJson, 'local.novel-collector'), 'Manifest must not reference local.novel-collector.');

echo "novel_collector_frontend_contract: PASS\n";
