<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$query = (string) $context->get('query', '');
$seo = $context->get('seo', []);
$items = $context->get('items', []);
$pagination = $context->get('pagination', []);
$page = max(1, (int) ($pagination['page'] ?? 1));
$perPage = max(1, (int) ($pagination['per_page'] ?? 10));
$total = max(count($items), (int) ($pagination['total'] ?? count($items)));
$hasNext = $page * $perPage < $total;
$layout = dy_choice($context, 'content_layout', 'right_sidebar', ['right_sidebar', 'left_sidebar', 'full_width']);
$description = dy_text($context, 'site_description', '记录、发布与分享');
$basePath = '/search?q=' . rawurlencode($query);
?>
<!doctype html>
<html lang="zh-CN">
<?php dy_head($context, '搜索', is_array($seo) ? $seo : []); ?>
<body>
<?php dy_header($context, 'search'); ?>
<section class="hero compact-hero">
    <div class="wrap">
        <p class="entry-kicker">站内搜索</p>
        <h1>搜索内容</h1>
        <p>输入关键词，查找已发布的文章、页面和区块内容。</p>
        <form class="search-panel" action="/search" method="get" role="search">
            <label class="sr-only" for="site-search">搜索关键词</label>
            <input id="site-search" type="search" name="q" value="<?= $context->e($query) ?>" placeholder="输入关键词">
            <button type="submit">搜索</button>
        </form>
    </div>
</section>
<?php dy_ad_slot($context, 'archive_top'); ?>
<main class="wrap layout layout-<?= $context->e($layout) ?>">
    <section class="post-list" aria-label="搜索结果">
        <?php if ($query === ''): ?>
            <article class="empty"><h2>请输入关键词</h2><p>可以搜索文章标题、页面地址、正文区块和 SEO 摘要。</p></article>
        <?php elseif ($items === []): ?>
            <article class="empty"><h2>没有找到匹配内容</h2><p>换一个更短的关键词试试，或返回文章归档浏览全部内容。</p><p><a href="/articles">浏览全部文章</a></p></article>
        <?php else: ?>
            <p class="result-count">找到 <?= $total ?> 条与“<?= $context->e($query) ?>”相关的内容。</p>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <?php dy_article_card($context, $item); ?>
        <?php endforeach; ?>
        <?php if ($query !== '' && $total > $perPage): ?>
            <nav class="pagination" aria-label="分页">
                <?php if ($page > 1): ?><a href="<?= $context->e($basePath . '&page=' . ($page - 1)) ?>">上一页</a><?php else: ?><span></span><?php endif; ?>
                <span>第 <?= $page ?> 页，共 <?= $total ?> 条</span>
                <?php if ($hasNext): ?><a href="<?= $context->e($basePath . '&page=' . ($page + 1)) ?>">下一页</a><?php else: ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
    <?php if (dy_bool($context, 'sidebar_enabled', true)): ?>
        <aside class="sidebar" aria-label="侧栏">
            <section class="side-block"><h2><?= $context->e(dy_text($context, 'sidebar_title', '站点简介')) ?></h2><p><?= $context->e($description) ?></p></section>
            <?php dy_ad_slot($context, 'sidebar'); ?>
            <section class="side-block"><h2>快速入口</h2><ul><li><a href="/">返回首页</a></li><li><a href="/articles">全部文章</a></li><li><a href="/sitemap.xml">站点地图</a></li></ul></section>
        </aside>
    <?php endif; ?>
</main>
<?php dy_footer($context); ?>
</body>
</html>
