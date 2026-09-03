<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$videoEpisodeUrl = $urls['video_episode_url'];
$episode = $get('episode', []);
$sources = $get('play_sources', []);
$episodes = $get('episodes', []);
$episode = is_array($episode) ? $episode : [];
$sources = is_array($sources) ? $sources : [];
$episodes = is_array($episodes) ? $episodes : [];
$assetBase = '/content/themes/daiying-video/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$boolSetting = static fn (string $key, bool $default): bool => filter_var($settings[$key] ?? $get('theme.' . $key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
$accent = (string) $setting('accent_color', '#0b7a75');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#0b7a75';
$autoplay = $boolSetting('player_autoplay', false);
$orderedSources = $sources;
usort($orderedSources, static fn (array $a, array $b): int => ['healthy' => 0, 'unknown' => 1, 'degraded' => 2, 'failed' => 3][(string) ($a['health_status'] ?? 'unknown')] <=> ['healthy' => 0, 'unknown' => 1, 'degraded' => 2, 'failed' => 3][(string) ($b['health_status'] ?? 'unknown')]);
$current = $orderedSources[0] ?? [];
$currentType = (string) ($current['url_type'] ?? $current['protocol'] ?? 'mp4');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($episode['title'] ?? '播放') ?></title>
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>:root{--accent:<?= $e($accent) ?>}</style>
    <script defer src="<?= $e($assetBase) ?>/player.js"></script>
</head>
<body data-video-id="<?= $e($episode['video_id'] ?? '') ?>" data-episode-id="<?= $e($episode['id'] ?? '') ?>">
<main class="player-page">
    <h1><?= $e($episode['video_title'] ?? $episode['title'] ?? '正在播放') ?></h1>
    <p class="muted"><?= $e($episode['title'] ?? '') ?></p>
    <section class="player-shell">
        <?php if ($currentType === 'embed'): ?>
            <iframe data-player-embed src="<?= $e($current['url'] ?? '') ?>" sandbox="allow-same-origin allow-presentation" referrerpolicy="no-referrer" loading="lazy"></iframe>
        <?php else: ?>
            <video controls playsinline preload="metadata" <?= $autoplay ? 'autoplay' : '' ?> data-player src="<?= $e($current['url'] ?? '') ?>"></video>
        <?php endif; ?>
        <div class="embed-fallback" hidden>当前播放线路暂不可用</div>
    </section>
    <nav class="source-tabs">
        <?php foreach ($orderedSources as $index => $source): ?><button data-url="<?= $e($source['url'] ?? '') ?>" data-type="<?= $e($source['url_type'] ?? $source['protocol'] ?? '') ?>" data-index="<?= $e((string) $index) ?>"><?= $e($source['display_name'] ?? ('播放线路' . ($index + 1))) ?></button><?php endforeach; ?>
    </nav>
    <nav class="episode-nav"><a href="<?= $e($episode['prev_url'] ?? '#') ?>">上一集</a><a href="<?= $e($episode['detail_url'] ?? '#') ?>">返回详情</a><a href="<?= $e($episode['next_url'] ?? '#') ?>">下一集</a></nav>
    <?php if ($episodes !== []): ?>
    <section class="episode-list player-episodes">
        <h2>选集</h2>
        <div><?php foreach ($episodes as $item): ?><?php $item = is_array($item) ? $item : []; ?><a href="<?= $e($videoEpisodeUrl($item, ['id' => $episode['video_id'] ?? ''])) ?>"><?= $e($item['title'] ?? ('第' . ($item['episode_number'] ?? '') . '集')) ?></a><?php endforeach; ?></div>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
