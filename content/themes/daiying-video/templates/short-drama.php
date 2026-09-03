<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$videoUrl = $urls['video_url'];
$videoSearchUrl = $urls['video_search_url'];
$items = $get('short_drama', $get('videos', []));
$sections = $get('video_sections', []);
if ($items === [] && is_array($sections)) {
    $items = $sections['short_drama'] ?? [];
}
$items = is_array($items) ? $items : [];
$assetBase = '/content/themes/daiying-video/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$accent = (string) $setting('accent_color', '#0b7a75');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#0b7a75';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($setting('section_short_drama_label', '短剧')) ?></title>
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>:root{--accent:<?= $e($accent) ?>}</style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/videos"><span class="brand-mark">V</span><span><?= $e($setting('brand_name', 'Daiying Video')) ?></span></a>
    <form class="search" action="<?= $e($videoSearchUrl()) ?>" method="get"><input name="q" type="search" placeholder="<?= $e($setting('search_placeholder', '搜索片名、演员')) ?>"><button>搜索</button></form>
</header>
<main>
    <section class="hero short-hero">
        <h1><?= $e($setting('section_short_drama_label', '短剧')) ?></h1>
        <p>竖屏短剧优先展示更新集数和最近更新时间。</p>
    </section>
    <section class="band">
        <?php if ($items === []): ?>
            <div class="empty-state"><?= $e($setting('empty_text', '这里还没有可显示的影片。采集入库后会自动显示。')) ?></div>
        <?php else: ?>
        <div class="short-grid">
            <?php foreach ($items as $video): ?>
                <?php $video = is_array($video) ? $video : []; ?>
                <article class="poster short-card">
                    <a href="<?= $e($videoUrl($video)) ?>">
                        <img src="<?= $e($video['poster'] ?? $assetBase . '/poster-placeholder.svg') ?>" alt="">
                        <strong><?= $e($video['title'] ?? '') ?></strong>
                        <span><?= $e($video['year'] ?? '') ?> · <?= $e($video['type'] ?? '短剧') ?> · <?= $e($video['latest_episode_label'] ?? ('更新至 ' . (string) ($video['episode_count'] ?? 0) . ' 集')) ?></span>
                        <small><?= $e($video['updated_at'] ?? $video['latest_episode_at'] ?? '') ?></small>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
