<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$siteName = dy_site_name($context);
$description = dy_text($context, 'site_description', '一个干净、可靠、适合中文内容的网站。');
$items = array_values(array_filter($context->get('contents', []), static fn (array $item): bool => ($item['content_type'] ?? '') === 'article' && ($item['status'] ?? '') === 'published'));
$featuredCount = dy_int($context, 'home_featured_count', 3, 1, 6);
$latestCount = dy_int($context, 'home_latest_count', 8, 3, 20);
$featured = array_slice($items, 0, $featuredCount);
$latest = array_slice($items, $featuredCount, $latestCount);
$layout = dy_choice($context, 'content_layout', 'right_sidebar', ['right_sidebar', 'left_sidebar', 'full_width']);
$listLayout = dy_choice($context, 'article_list_layout', 'mixed', ['list', 'grid', 'mixed']);
?>
<!doctype html>
<html lang="zh-CN">
<?php dy_head($context, $siteName, ['title' => $siteName, 'description' => $description]); ?>
<body>
<?php dy_header($context, 'home'); ?>
<section class="hero">
    <div class="wrap hero-grid">
        <div>
            <p class="eyebrow">Daiying Default Theme</p>
            <h1><?= $context->e($siteName) ?></h1>
            <p><?= $context->e($description) ?></p>
            <p><a class="button" href="/articles">浏览全部文章</a></p>
        </div>
        <aside class="hero-panel" aria-label="主题能力">
            <dl>
                <div><dt>内容</dt><dd>文章 / 页面 / 区块</dd></div>
                <div><dt>布局</dt><dd>全宽 / 左侧栏 / 右侧栏</dd></div>
                <div><dt>显示</dt><dd>浅色 / 深色 / 跟随系统</dd></div>
                <div><dt>适配</dt><dd>桌面 / 平板 / 手机</dd></div>
            </dl>
        </aside>
    </div>
</section>
<?php dy_ad_slot($context, 'home_top'); ?>
<main class="wrap">
    <?php if ($featured !== []): ?>
        <section class="section" aria-label="推荐内容">
            <div class="section-head">
                <div>
                    <h2><?= $context->e(dy_text($context, 'home_featured_title', '推荐内容')) ?></h2>
                    <p class="section-lead">优先展示最近发布的重点内容，适合放产品公告、教程、案例或置顶文章。</p>
                </div>
                <a href="/articles">全部文章</a>
            </div>
            <div class="post-list post-list-grid">
                <?php foreach ($featured as $item): ?><?php dy_article_card($context, $item, true); ?><?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <section class="section">
        <div class="section-head">
            <div>
                <h2><?= $context->e(dy_text($context, 'home_latest_title', '最新文章')) ?></h2>
                <p class="section-lead">按发布时间显示最新内容。没有封面图时会显示一致的品牌占位视觉。</p>
            </div>
        </div>
        <div class="layout layout-<?= $context->e($layout) ?>">
            <section class="post-list<?= $listLayout === 'grid' ? ' post-list-grid' : '' ?>" aria-label="最新文章列表">
                <?php if ($items === []): ?>
                    <article class="empty">
                        <h2>还没有发布文章</h2>
                        <p>当你在后台发布第一篇文章后，它会显示在这里。可以先创建“关于我们”“产品介绍”或第一篇博客，让网站开始被访问。</p>
                    </article>
                <?php endif; ?>
                <?php foreach (($latest !== [] ? $latest : array_slice($items, 0, $latestCount)) as $item): ?>
                    <?php dy_article_card($context, $item); ?>
                <?php endforeach; ?>
            </section>
            <?php if (dy_bool($context, 'sidebar_enabled', true)): ?>
                <aside class="sidebar" aria-label="侧栏">
                    <section class="side-block">
                        <h2><?= $context->e(dy_text($context, 'sidebar_title', '站点简介')) ?></h2>
                        <p><?= $context->e($description) ?></p>
                    </section>
                    <?php dy_ad_slot($context, 'sidebar'); ?>
                    <section class="side-block">
                        <h2>快速入口</h2>
                        <ul>
                            <li><a href="/articles">全部文章</a></li>
                            <li><a href="/search">搜索内容</a></li>
                            <li><a href="/sitemap.xml">站点地图</a></li>
                        </ul>
                    </section>
                </aside>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php dy_footer($context); ?>
</body>
</html>
