<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$readJson = static function (string $path): array {
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};

$readTree = static function (string $dir): string {
    $buffer = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $buffer .= "\n### " . $file->getPathname() . "\n" . file_get_contents($file->getPathname());
    }

    return $buffer;
};

$novelThemeDir = $root . '/content/themes/daiying_novel';
$videoThemeDir = $root . '/content/themes/daiying-video';
$videoPluginDir = $root . '/content/plugins/official.video-collector';

$novelManifest = $readJson($novelThemeDir . '/theme.json');
$videoManifest = $readJson($videoThemeDir . '/theme.json');
$videoPluginManifest = $readJson($videoPluginDir . '/plugin.json');

$assert(($novelManifest['theme_id'] ?? '') === 'daiying_novel', 'Novel theme ID must remain daiying_novel.');
$assert(($novelManifest['version'] ?? '') === '1.0.0', 'Novel theme version must be 1.0.0.');
$assert(($novelManifest['local_dev'] ?? true) === false, 'Novel theme must not be marked local_dev.');
$assert(($novelManifest['market_release'] ?? false) === true, 'Novel theme must be marked market_release.');
$assert(in_array('official.novel-collector', $novelManifest['recommended_plugins'] ?? [], true), 'Novel theme must recommend official.novel-collector.');
$assert(in_array('official.novel-collector', $novelManifest['required_plugins'] ?? [], true), 'Novel theme must require official.novel-collector.');

$assert(($videoManifest['theme_id'] ?? '') === 'daiying-video', 'Video theme ID must remain daiying-video.');
$assert(($videoManifest['version'] ?? '') === '1.0.0', 'Video theme version must be 1.0.0.');
$assert(($videoManifest['local_dev'] ?? true) === false, 'Video theme must not be marked local_dev.');
$assert(($videoManifest['market_release'] ?? false) === true, 'Video theme must be marked market_release.');
$assert(in_array('official.video-collector', $videoManifest['recommended_plugins'] ?? [], true), 'Video theme must recommend official.video-collector.');
$assert(in_array('official.video-collector', $videoManifest['required_plugins'] ?? [], true), 'Video theme must require official.video-collector.');

$novelTree = $readTree($novelThemeDir);
$videoTree = $readTree($videoThemeDir);

foreach (['local.novel-collector', '/novel/'] as $forbidden) {
    $assert(!str_contains($novelTree, $forbidden), 'Novel theme must not contain legacy token: ' . $forbidden);
}

foreach (['/video/', '/movie/', '/tv/', '/short-drama/', '/anime/', '/variety/'] as $forbidden) {
    $assert(!str_contains($videoTree, $forbidden), 'Video theme must not contain legacy hard-coded route: ' . $forbidden);
}

foreach ([
    'novel_url',
    'novel_chapter_url',
    'novel_search_url',
    'novel_bookshelf_url',
    '/novels/search',
    '/novels/bookshelf',
    'daiying_novel_bookshelf',
    'daiying_novel_reading_progress',
    'data-fullscreen',
    '最近 100 章',
    'update-table',
] as $needle) {
    $assert(str_contains($novelTree, $needle), 'Novel theme missing product token: ' . $needle);
}

foreach ([
    'video_url',
    'video_episode_url',
    'video_search_url',
    'video_type_url',
    '/videos/search',
    'short_drama',
    'sandbox="allow-same-origin allow-presentation"',
    'let currentUrl',
    'failovers > 0',
    '$pageSize = 80',
] as $needle) {
    $assert(str_contains($videoTree, $needle), 'Video theme missing product token: ' . $needle);
}

$shortDrama = (string) file_get_contents($videoThemeDir . '/templates/short-drama.php');
$assert(!str_contains($shortDrama, "require __DIR__ . '/home.php'"), 'Short drama template must not require home.php.');

$routes = [];
foreach (($videoPluginManifest['public_routes'] ?? []) as $route) {
    $routes[] = ($route['method'] ?? 'GET') . ' ' . ($route['path'] ?? '');
}

$assert(($videoPluginManifest['version'] ?? '') === '0.2.1', 'Video collector plugin version must be 0.2.1.');
$assert(in_array('GET /videos/search', $routes, true), 'Video collector manifest must expose GET /videos/search.');

$videoPlugin = (string) file_get_contents($videoPluginDir . '/plugin.php');
$videoSystem = (string) file_get_contents($videoPluginDir . '/src/VideoSystem.php');
$assert(str_contains($videoPlugin, "frontRoute('GET', '/videos/search'"), 'Video collector must register /videos/search.');
$assert(str_contains($videoSystem, 'publicVideos(int $limit = 24, string $type = \'\', string $query = \'\')'), 'Video repository public listing must support type and query filters.');

echo "theme_productization_contract: PASS\n";
