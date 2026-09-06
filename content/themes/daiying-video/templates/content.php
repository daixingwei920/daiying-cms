<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$videoUrl = $urls['video_url'];
$videoEpisodeUrl = $urls['video_episode_url'];
$video = $get('video', $get('content', []));
$episodes = $get('episodes', []);
$progress = $get('watch_progress', []);
$video = is_array($video) ? $video : [];
$episodes = is_array($episodes) ? $episodes : [];
$progress = is_array($progress) ? $progress : [];
$assetBase = '/content/themes/daiying-video/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$boolSetting = static fn (string $key, bool $default): bool => filter_var($settings[$key] ?? $get('theme.' . $key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
$accent = (string) $setting('accent_color', '#0b7a75');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#0b7a75';
$showFavorite = $boolSetting('enable_favorites', true);
$pageSize = 80;
$page = max(1, (int) $get('page', (int) ($_GET['page'] ?? 1)));
$totalEpisodes = count($episodes);
$pageCount = max(1, (int) ceil(max(1, $totalEpisodes) / $pageSize));
$shownEpisodes = array_slice($episodes, ($page - 1) * $pageSize, $pageSize);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($video['title'] ?? '影视') ?></title>
    <meta name="description" content="<?= $e($video['description'] ?? '') ?>">
    <link rel="canonical" href="<?= $e($get('canonical', $videoUrl($video))) ?>">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>:root{--accent:<?= $e($accent) ?>}</style>
</head>
<body>
<main class="video-detail">
    <section class="detail-head" style="--backdrop:url('<?= $e($video['backdrop'] ?? '') ?>')">
        <img src="<?= $e($video['poster'] ?? $assetBase . '/poster-placeholder.svg') ?>" alt="">
        <div>
            <h1><?= $e($video['title'] ?? '') ?></h1>
            <p><?= $e($video['year'] ?? '') ?> · <?= $e($video['region'] ?? '') ?> · <?= $e($video['type'] ?? '') ?> · <?= $e($video['status'] ?? '') ?></p>
            <p><?= $e($video['actors'] ?? '') ?></p>
            <p><?= $e($video['directors'] ?? '') ?></p>
            <p><?= $e($video['description'] ?? '') ?></p>
            <nav class="actions"><a href="<?= $e($progress['continue_url'] ?? (isset($episodes[0]) && is_array($episodes[0]) ? $videoEpisodeUrl($episodes[0], $video) : '#')) ?>">立即播放</a><?php if ($showFavorite): ?><button data-favorite="<?= $e($video['id'] ?? '') ?>">收藏</button><?php endif; ?></nav>
        </div>
    </section>
    <section class="episode-list">
        <h2>选集</h2>
        <?php if ($totalEpisodes > $pageSize): ?>
            <nav class="page-strip" aria-label="选集分页">
                <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                    <a class="<?= $i === $page ? 'active' : '' ?>" href="<?= $e($videoUrl($video) . (str_contains($videoUrl($video), '?') ? '&' : '?') . 'page=' . $i) ?>"><?= $e((string) $i) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
        <div><?php foreach ($shownEpisodes as $episode): ?><?php $episode = is_array($episode) ? $episode : []; ?><a href="<?= $e($videoEpisodeUrl($episode, $video)) ?>"><?= $e($episode['title'] ?? ('第' . ($episode['episode_number'] ?? '') . '集')) ?></a><?php endforeach; ?></div>
    </section>
</main>
</body>
</html>
