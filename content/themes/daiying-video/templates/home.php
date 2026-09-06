<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$videoUrl = $urls['video_url'];
$videoSearchUrl = $urls['video_search_url'];
$videoTypeUrl = $urls['video_type_url'];
$sections = $get('video_sections', []);
$sections = is_array($sections) ? $sections : [];
$assetBase = '/content/themes/daiying-video/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$boolSetting = static fn (string $key, bool $default): bool => filter_var($settings[$key] ?? $get('theme.' . $key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
$accent = (string) $setting('accent_color', '#0b7a75');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#0b7a75';
$brandName = (string) $setting('brand_name', $get('site_name', 'Daiying Video'));
$logoText = mb_substr((string) $setting('brand_logo_text', 'V'), 0, 2);
$logoUrl = (string) $setting('brand_logo_url', '');
$showSearch = $boolSetting('show_search', true);
$showStats = $boolSetting('show_home_stats', true);
$posterRatio = (string) $setting('poster_ratio', '2:3');
$posterRatio = in_array($posterRatio, ['2:3', '3:4', '16:9'], true) ? $posterRatio : '2:3';
$totalVideos = 0;
foreach ($sections as $items) {
    $totalVideos += is_array($items) ? count($items) : 0;
}
$sectionCount = static fn (string $key): int => is_array($sections[$key] ?? null) ? count($sections[$key]) : 0;
$sectionLabels = [
    'hot' => (string) $setting('section_hot_label', '热门推荐'),
    'playing' => (string) $setting('section_playing_label', '热播'),
    'movies' => (string) $setting('section_movies_label', '最新电影'),
    'tv' => (string) $setting('section_tv_label', '电视剧'),
    'short_drama' => (string) $setting('section_short_drama_label', '短剧'),
    'anime' => (string) $setting('section_anime_label', '动漫'),
    'variety' => (string) $setting('section_variety_label', '综艺'),
    'latest' => (string) $setting('section_latest_label', '最近更新'),
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($brandName) ?></title>
    <meta name="description" content="<?= $e($get('seo.description', '电影、电视剧、短剧、动漫、综艺播放与更新')) ?>">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>:root{--accent:<?= $e($accent) ?>}.poster img{aspect-ratio:<?= $e($posterRatio) ?>}</style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/"><?php if ($logoUrl !== ''): ?><img class="brand-logo" src="<?= $e($logoUrl) ?>" alt=""><?php else: ?><span class="brand-mark"><?= $e($logoText) ?></span><?php endif; ?><span><?= $e($brandName) ?></span></a>
    <nav><a href="<?= $e($videoTypeUrl('movie')) ?>"><?= $e($setting('nav_label_movie', '电影')) ?></a><a href="<?= $e($videoTypeUrl('tv')) ?>"><?= $e($setting('nav_label_tv', '电视剧')) ?></a><a href="<?= $e($videoTypeUrl('short_drama')) ?>"><?= $e($setting('nav_label_short_drama', '短剧')) ?></a><a href="<?= $e($videoTypeUrl('anime')) ?>"><?= $e($setting('nav_label_anime', '动漫')) ?></a><a href="<?= $e($videoTypeUrl('variety')) ?>"><?= $e($setting('nav_label_variety', '综艺')) ?></a></nav>
    <?php if ($showSearch): ?><form class="search" action="<?= $e($videoSearchUrl()) ?>" method="get"><input name="q" type="search" placeholder="<?= $e($setting('search_placeholder', '搜索片名、演员')) ?>"><button>搜索</button></form><?php endif; ?>
</header>
<main>
    <section class="hero"><h1><?= $e($setting('home_title', '影视片库')) ?></h1><p><?= $e($setting('home_subtitle', '电影、电视剧、短剧、动漫、综艺聚合展示，多播放线路按集切换。')) ?></p><?php if ($showStats): ?><div class="video-stats"><span><?= $e((string) $totalVideos) ?> 部展示</span><span><?= $e((string) $sectionCount('latest')) ?> 最近更新</span><span><?= $e((string) $sectionCount('playing')) ?> 热播</span></div><?php endif; ?></section>
    <?php foreach ($sectionLabels as $key => $label): ?>
        <?php $items = is_array($sections[$key] ?? null) ? $sections[$key] : []; ?>
        <section class="band">
            <h2><?= $e($label) ?></h2>
            <?php if ($items === []): ?>
                <div class="empty-state"><?= $e($setting('empty_text', '这里还没有可显示的影片。采集入库后会自动显示。')) ?></div>
            <?php else: ?>
            <div class="poster-grid">
                <?php foreach ($items as $video): ?>
                    <article class="poster"><a href="<?= $e($videoUrl($video)) ?>"><img src="<?= $e($video['poster'] ?? $assetBase . '/poster-placeholder.svg') ?>" alt=""><strong><?= $e($video['title'] ?? '') ?></strong><span><?= $e($video['year'] ?? '') ?> · <?= $e($video['type'] ?? '') ?> · <?= $e($video['latest_episode_label'] ?? $video['status'] ?? '') ?></span></a></article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
