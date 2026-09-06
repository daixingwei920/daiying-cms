<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$title = (string) $context->get('title', 'Articles');
$seo = $context->get('seo', []);
$items = $context->get('items', []);
$pagination = $context->get('pagination', []);
$archive = is_array($context->get('archive', [])) ? $context->get('archive', []) : [];
$searchQuery = (string) $context->get('search_query', (string) $context->get('query', ''));
$page = max(1, (int) ($pagination['page'] ?? 1));
$perPage = max(1, (int) ($pagination['per_page'] ?? 10));
$total = max(count($items), (int) ($pagination['total'] ?? count($items)));
$hasNext = $page * $perPage < $total;
$basePath = $searchQuery !== '' ? '/search?q=' . rawurlencode($searchQuery) : (!empty($archive['slug']) ? '/' . ($archive['type'] === 'tag' ? 'tag' : 'category') . '/' . rawurlencode((string) $archive['slug']) : '/articles');
$separator = str_contains($basePath, '?') ? '&' : '?';
$current = $searchQuery !== '' || $title === 'Search' || $title === '搜索' || str_starts_with($title, 'Search: ') || str_starts_with($title, '搜索：') ? 'search' : 'articles';
$showSidebar = dm_bool($context, 'show_sidebar', true);
$archiveTitle = dm_archive_title($title);
?>
<!doctype html>
<html lang="zh-CN">
<?php dm_head($context, $title, is_array($seo) ? $seo : []); ?>
<body>
<?php dm_header($context, $current); ?>
<main id="content">
    <section class="archive-head">
        <div class="container">
            <p class="kicker"><?= $current === 'search' ? 'Search' : (($archive['type'] ?? '') === 'tag' ? 'Tag' : 'Archive') ?></p>
            <h1><?= $context->e($archiveTitle) ?></h1>
            <p><?= $context->e($current === 'search' && $searchQuery !== '' ? '找到 ' . $total . ' 篇内容' : ($seo['description'] ?? '关于科技、互联网和数字生活的最新内容。')) ?></p>
            <form class="search-form" method="get" action="/search" role="search">
                <label><span class="eyebrow">搜索内容</span><input name="q" value="<?= $context->e($searchQuery) ?>" placeholder="输入关键词..."></label>
                <button type="submit">搜索</button>
            </form>
        </div>
    </section>
    <div class="container site-main<?= $showSidebar ? '' : ' no-sidebar' ?>">
        <section class="content-stack" aria-label="文章列表">
            <?php dm_ad_slot($context, 'ad_list_top_desktop', 'ad_list_top_mobile'); ?>
            <div class="post-list <?= $context->e(dm_setting($context, 'list_layout', 'classic')) ?>">
                <?php if ($items === []): ?>
                    <article class="empty"><h2>这里好像还没有内容</h2><p><?= $context->e($context->get('empty_message', '当前页面暂时没有已发布文章，请稍后再来查看。')) ?></p></article>
                <?php endif; ?>
                <?php foreach ($items as $index => $item): ?><?php dm_card($context, $item); ?><?php if ($index === 1): ?><?php dm_ad_slot($context, 'ad_list_between_desktop', 'ad_list_between_mobile'); ?><?php endif; ?><?php endforeach; ?>
            </div>
            <?php dm_ad_slot($context, 'ad_list_bottom_desktop', 'ad_list_bottom_mobile'); ?>
            <nav class="pagination" aria-label="分页">
                <?php if ($page > 1): ?><a href="<?= $context->e($basePath . $separator . 'page=' . ($page - 1)) ?>">上一页</a><?php else: ?><span></span><?php endif; ?>
                <span>第 <?= $page ?> 页，共 <?= $total ?> 条</span>
                <?php if ($hasNext): ?><a href="<?= $context->e($basePath . $separator . 'page=' . ($page + 1)) ?>">下一页</a><?php else: ?><span></span><?php endif; ?>
            </nav>
        </section>
        <?php if ($showSidebar): ?><?php dm_sidebar($context, is_array($items) ? $items : []); ?><?php endif; ?>
    </div>
</main>
<?php dm_footer($context); ?>
</body>
</html>
