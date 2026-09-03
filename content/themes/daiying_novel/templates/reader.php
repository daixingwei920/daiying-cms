<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$novelChapterUrl = $urls['novel_chapter_url'];
$novelUrl = $urls['novel_url'];
$chapter = $get('chapter', []);
$novel = $get('novel', []);
$chapter = is_array($chapter) ? $chapter : [];
$novel = is_array($novel) ? $novel : [];
$assetBase = '/content/themes/daiying_novel/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$readerTheme = (string) $setting('reader_default_theme', 'paper');
$readerTheme = in_array($readerTheme, ['paper', 'green', 'night'], true) ? $readerTheme : 'paper';
$readerWidth = max(640, min(980, (int) $setting('reader_width', 800)));
$readerFontSize = max(14, min(28, (int) $setting('reader_font_size', 18)));
$catalogUrl = (string) ($chapter['catalog_url'] ?? $novelUrl($novel));
$prevUrl = (string) ($chapter['prev_url'] ?? '');
$nextUrl = (string) ($chapter['next_url'] ?? $novelChapterUrl($chapter + ['sort_order' => (int) ($chapter['sort_order'] ?? $chapter['chapter_number'] ?? 1) + 1], $novel));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($chapter['title'] ?? '阅读') ?></title>
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>.reader-content{max-width:<?= $e((string) $readerWidth) ?>px;font-size:<?= $e((string) $readerFontSize) ?>px}</style>
    <script defer src="<?= $e($assetBase) ?>/reader.js"></script>
</head>
<body class="reader <?= $e($readerTheme) ?>" data-novel-id="<?= $e($chapter['novel_id'] ?? $novel['job_id'] ?? $novel['id'] ?? '') ?>" data-chapter-id="<?= $e($chapter['id'] ?? $chapter['sort_order'] ?? '') ?>" data-chapter-title="<?= $e($chapter['title'] ?? '阅读') ?>" data-book-title="<?= $e($novel['title'] ?? '') ?>" data-book-url="<?= $e($catalogUrl) ?>">
<div class="reader-shell">
    <nav class="reader-bar" aria-label="阅读工具">
        <a href="<?= $e($prevUrl !== '' ? $prevUrl : '#') ?>">上一章</a>
        <a href="<?= $e($catalogUrl) ?>">目录</a>
        <a href="<?= $e($nextUrl) ?>" data-next-chapter>下一章</a>
        <button data-theme="paper">白色</button>
        <button data-theme="green">护眼</button>
        <button data-theme="night">夜间</button>
        <input type="range" min="14" max="28" value="<?= $e((string) $readerFontSize) ?>" data-font aria-label="字号">
        <button type="button" data-fullscreen>全屏</button>
        <span class="reader-progress"><span data-reader-percent>0%</span></span>
    </nav>
    <article class="reader-content">
        <h1><?= $e($chapter['title'] ?? '阅读') ?></h1>
        <?= $chapter['content'] ?? '' ?>
    </article>
</div>
</body>
</html>
