<?php

declare(strict_types=1);

/** @var object $context */
$e = static fn ($v): string => method_exists($context, 'e') ? $context->e((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$get = static fn (string $key, $default = null) => method_exists($context, 'get') ? $context->get($key, $default) : $default;
$urls = require __DIR__ . '/_helpers.php';
$novelUrl = $urls['novel_url'];
$novelChapterUrl = $urls['novel_chapter_url'];
$novelSearchUrl = $urls['novel_search_url'];
$novelBookshelfUrl = $urls['novel_bookshelf_url'];
$novelTxtUrl = $urls['novel_txt_url'];
$novel = $get('novel', $get('content', []));
$chapters = $get('chapters', []);
$progress = $get('reading_progress', []);
$novel = is_array($novel) ? $novel : [];
$chapters = is_array($chapters) ? $chapters : [];
$progress = is_array($progress) ? $progress : [];
$assetBase = '/content/themes/daiying_novel/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
$settings = $get('theme_settings', $get('settings', []));
$settings = is_array($settings) ? $settings : [];
$setting = static fn (string $key, $default) => $settings[$key] ?? $get('theme.' . $key, $default);
$boolSetting = static fn (string $key, bool $default): bool => filter_var($settings[$key] ?? $get('theme.' . $key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
$accent = (string) $setting('accent_color', '#b8324a');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#b8324a';
$brandName = (string) $setting('brand_name', $get('site_name', 'Daiying Novel'));
$logoText = mb_substr((string) $setting('brand_logo_text', 'D'), 0, 2);
$logoUrl = (string) $setting('brand_logo_url', '');
$coverMarkup = static function (array $novel) use ($e): string {
    $cover = (string) ($novel['cover'] ?? $novel['cover_url'] ?? '');
    if ($cover !== '') {
        return '<img class="detail-cover" src="' . $e($cover) . '" alt="">';
    }
    return '<span class="detail-cover generated-cover"><strong>' . $e(mb_substr((string) ($novel['title'] ?? '小说'), 0, 10)) . '</strong><em>' . $e((string) ($novel['author'] ?? '佚名')) . '</em></span>';
};
$showSearch = $boolSetting('show_search', true);
$enableTxtDownload = $boolSetting('enable_txt_download', true);
$pageSize = 100;
$page = max(1, (int) $get('page', (int) ($_GET['page'] ?? 1)));
$latestOnly = (string) $get('latest', (string) ($_GET['latest'] ?? '')) === '1';
$totalChapters = count($chapters);
$shownChapters = $latestOnly ? array_slice($chapters, -100) : array_slice($chapters, ($page - 1) * $pageSize, $pageSize);
$pageCount = max(1, (int) ceil(max(1, $totalChapters) / $pageSize));
$firstChapterUrl = isset($chapters[0]) && is_array($chapters[0]) ? $novelChapterUrl($chapters[0], $novel) : '#';
$continueUrl = (string) ($progress['continue_url'] ?? $firstChapterUrl);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($novel['title'] ?? '小说') ?></title>
    <meta name="description" content="<?= $e($novel['description'] ?? '') ?>">
    <link rel="canonical" href="<?= $e($get('canonical', $novelUrl($novel))) ?>">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
    <style>:root{--accent:<?= $e($accent) ?>;--accent-strong:<?= $e($accent) ?>}</style>
    <script defer src="<?= $e($assetBase) ?>/reader.js"></script>
</head>
<body>
<div class="site-shell">
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/">
            <?php if ($logoUrl !== ''): ?><img class="brand-logo" src="<?= $e($logoUrl) ?>" alt=""><?php else: ?><span class="brand-mark"><?= $e($logoText) ?></span><?php endif; ?>
            <span><?= $e($brandName) ?></span>
        </a>
        <nav class="main-nav" aria-label="小说导航">
            <a href="/novels"><?= $e($setting('nav_label_library', '书库')) ?></a>
            <a href="/novels?tab=latest"><?= $e($setting('nav_label_latest', '更新')) ?></a>
            <a href="/novels?tab=completed"><?= $e($setting('nav_label_completed', '完本')) ?></a>
            <a href="/novels?tab=ranking"><?= $e($setting('nav_label_ranking', '排行')) ?></a>
        </nav>
        <?php if ($showSearch): ?>
        <form class="search" action="<?= $e($novelSearchUrl()) ?>" method="get">
            <input name="q" type="search" placeholder="<?= $e($setting('search_placeholder', '搜索书名、作者')) ?>">
            <button>搜索</button>
        </form>
        <?php endif; ?>
    </div>
</header>
<main class="novel-detail">
    <section class="detail-head">
        <?= $coverMarkup($novel) ?>
        <div class="detail-card">
            <h1><?= $e($novel['title'] ?? '') ?></h1>
            <p class="detail-meta"><?= $e($novel['author'] ?? '佚名') ?> · <?= $e($novel['status'] ?? '') ?> · <?= $e($novel['word_count'] ?? 0) ?> 字</p>
            <p class="detail-desc"><?= $e($novel['description'] ?? '') ?></p>
            <nav class="actions">
                <a href="<?= $e($continueUrl) ?>" data-continue-link>开始/继续阅读</a>
                <button class="secondary" data-bookshelf="<?= $e($novel['job_id'] ?? $novel['id'] ?? '') ?>" data-title="<?= $e($novel['title'] ?? '') ?>" data-url="<?= $e($novelUrl($novel)) ?>">书架</button>
                <?php if ($enableTxtDownload): ?><a class="secondary" href="<?= $e($novelTxtUrl($novel)) ?>">TXT 下载</a><?php endif; ?>
                <a class="secondary" href="<?= $e($novelBookshelfUrl()) ?>">我的书架</a>
            </nav>
        </div>
    </section>
    <section class="chapter-tools">
        <input type="search" id="chapter-search" placeholder="搜索章节">
        <a class="button secondary" href="<?= $e($novelUrl($novel)) ?>">全部目录</a>
        <a class="button secondary" href="<?= $e($novelUrl($novel) . (str_contains($novelUrl($novel), '?') ? '&' : '?') . 'latest=1') ?>">最近 100 章</a>
        <form method="get" action="<?= $e(parse_url($novelUrl($novel), PHP_URL_PATH) ?: '/novels/book') ?>">
            <?php if (($novel['job_id'] ?? '') !== ''): ?><input type="hidden" name="job_id" value="<?= $e($novel['job_id']) ?>"><?php endif; ?>
            <input type="number" name="page" min="1" max="<?= $e((string) $pageCount) ?>" value="<?= $e((string) $page) ?>" aria-label="页码">
            <button>跳转</button>
        </form>
    </section>
    <p class="catalog-note"><?= $e($latestOnly ? '正在显示最近 100 章' : ('第 ' . $page . ' / ' . $pageCount . ' 页')) ?></p>
    <section class="volume-list" id="chapter-list">
        <?php if ($chapters === []): ?>
            <div class="empty-state">这本书还没有可显示章节。完成一批采集后，目录会出现在这里。</div>
        <?php else: ?>
            <?php foreach ($shownChapters as $chapter): ?>
                <?php $chapter = is_array($chapter) ? $chapter : []; ?>
                <a data-title="<?= $e($chapter['title'] ?? '') ?>" href="<?= $e($novelChapterUrl($chapter, $novel)) ?>"><?= $e($chapter['title'] ?? '') ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
</div>
<script>
(() => {
  const button = document.querySelector("[data-bookshelf]");
  if (!button) return;
  const id = button.dataset.bookshelf || "";
  const shelf = JSON.parse(localStorage.getItem("daiying_novel_bookshelf") || "{}");
  const progress = JSON.parse(localStorage.getItem("daiying_novel_reading_progress") || "{}");
  const continueLink = document.querySelector("[data-continue-link]");
  if (id && progress[id] && progress[id].chapterUrl && continueLink) {
    continueLink.href = progress[id].chapterUrl;
    continueLink.textContent = "继续阅读 " + (progress[id].chapterTitle || "上次章节");
  }
  if (id && shelf[id]) button.textContent = "已在书架";
  button.addEventListener("click", () => {
    if (!id) return;
    shelf[id] = { title: button.dataset.title || id, url: button.dataset.url || location.pathname + location.search, updatedAt: new Date().toISOString() };
    localStorage.setItem("daiying_novel_bookshelf", JSON.stringify(shelf));
    button.textContent = "已在书架";
  });
})();
</script>
</body>
</html>
