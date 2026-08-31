<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$title = (string) $context->get('title', 'Articles');
$seo = $context->get('seo', []);
$items = $context->get('items', []);
$pagination = $context->get('pagination', []);
$page = max(1, (int) ($pagination['page'] ?? 1));
$perPage = max(1, (int) ($pagination['per_page'] ?? 10));
$total = max(count($items), (int) ($pagination['total'] ?? count($items)));
$hasNext = $page * $perPage < $total;
$basePath = (string) $context->get('base_path', '');
if ($basePath === '') {
    $basePath = str_starts_with($title, 'Tag:') ? '/tag/' . rawurlencode(trim(substr($title, 4))) : (str_starts_with($title, 'Category:') ? '/category/' . rawurlencode(trim(substr($title, 9))) : '/articles');
}
$emptyMessage = (string) $context->get('empty_message', '当前归档暂时没有已发布文章，请稍后再来查看。');
$displayTitle = str_replace(['Articles', 'Category: ', 'Category:', 'Tag: ', 'Tag:'], ['全部文章', '分类：', '分类：', '标签：', '标签：'], $title);
$layout = dy_choice($context, 'content_layout', 'right_sidebar', ['right_sidebar', 'left_sidebar', 'full_width']);
$listLayout = dy_choice($context, 'article_list_layout', 'mixed', ['list', 'grid', 'mixed']);
$description = dy_text($context, 'site_description', '记录、发布与分享');
?>
<!doctype html>
<html lang="zh-CN">
<?php dy_head($context, $title, is_array($seo) ? $seo : []); ?>
<body>
<?php dy_header($context, 'articles'); ?>
<section class="hero">
    <div class="wrap">
        <p class="entry-kicker">内容归档</p>
        <h1><?= $context->e($displayTitle) ?></h1>
        <p><?= $context->e($seo['description'] ?? '按发布时间浏览已经发布的内容。') ?></p>
    </div>
</section>
<?php dy_ad_slot($context, 'archive_top'); ?>
<main class="wrap layout layout-<?= $context->e($layout) ?>">
    <section class="post-list<?= $listLayout === 'grid' ? ' post-list-grid' : '' ?>" aria-label="文章列表">
        <?php if ($items === []): ?>
            <article class="empty"><h2>这里还没有内容</h2><p><?= $context->e($emptyMessage) ?></p><p><a href="/">返回首页</a></p></article>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <?php dy_article_card($context, $item); ?>
        <?php endforeach; ?>
        <nav class="pagination" aria-label="分页">
            <?php if ($page > 1): ?><a href="<?= $context->e($basePath . '?page=' . ($page - 1)) ?>">上一页</a><?php else: ?><span></span><?php endif; ?>
            <span>第 <?= $page ?> 页，共 <?= $total ?> 条</span>
            <?php if ($hasNext): ?><a href="<?= $context->e($basePath . '?page=' . ($page + 1)) ?>">下一页</a><?php else: ?><span></span><?php endif; ?>
        </nav>
    </section>
    <?php if (dy_bool($context, 'sidebar_enabled', true)): ?>
        <aside class="sidebar" aria-label="侧栏">
            <section class="side-block"><h2><?= $context->e(dy_text($context, 'sidebar_title', '站点简介')) ?></h2><p><?= $context->e($description) ?></p></section>
            <?php dy_ad_slot($context, 'sidebar'); ?>
            <section class="side-block"><h2>浏览提示</h2><p>文章按发布时间排序。分类和标签链接会在文章正文下方显示。</p></section>
            <section class="side-block"><h2>站点</h2><ul><li><a href="/">返回首页</a></li><li><a href="/search">搜索内容</a></li><li><a href="/sitemap.xml">站点地图</a></li></ul></section>
        </aside>
    <?php endif; ?>
</main>
<?php dy_footer($context); ?>
</body>
</html>
