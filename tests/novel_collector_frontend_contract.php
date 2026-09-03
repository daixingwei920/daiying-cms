<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/content/plugins/official.novel-collector/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$plugin = (string) file_get_contents($root . '/content/plugins/official.novel-collector/plugin.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(($manifest['plugin_id'] ?? '') === 'official.novel-collector', 'Novel collector plugin ID must remain official.novel-collector.');
$assert(($manifest['version'] ?? '') === '0.4.4', 'Novel collector version should be 0.4.4.');

$routes = [];
foreach (($manifest['public_routes'] ?? []) as $route) {
    $routes[] = ($route['method'] ?? 'GET') . ' ' . ($route['path'] ?? '');
}

foreach (['GET /novels', 'GET /novels/search', 'GET /novels/bookshelf', 'GET /novels/book', 'GET /novels/chapter', 'GET /novels/export.txt'] as $route) {
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
] as $needle) {
    $assert(str_contains($plugin, $needle), 'Missing frontend contract token: ' . $needle);
}

$assert(!str_contains($plugin, 'local.novel-collector'), 'Plugin PHP must not reference local.novel-collector.');
$assert(!str_contains(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'local.novel-collector'), 'Manifest must not reference local.novel-collector.');

echo "novel_collector_frontend_contract: PASS\n";
