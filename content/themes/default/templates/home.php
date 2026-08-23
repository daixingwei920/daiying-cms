<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$siteName = dy_site_name($context);
$description = dy_setting($context, 'site_description', '一个干净、可靠、适合中文内容的网站。');
$items = array_values(array_filter($context->get('contents', []), static fn (array $item): bool => ($item['content_type'] ?? '') === 'article' && ($item['status'] ?? '') === 'published'));
?>
<!doctype html>
<html lang="zh-CN">
<?php dy_head($context, $siteName, ['title' => $siteName, 'description' => $description]); ?>
<body>
<?php dy_header($context, 'home'); ?>
<section class="hero">
    <div class="wrap">
        <p class="entry-kicker">官方默认主题</p>
        <h1><?= $context->e($siteName) ?></h1>
        <p><?= $context->e($description) ?></p>
    </div>
</section>
<?php dy_ad_slot($context, 'home_top'); ?>
<main class="wrap layout">
    <section class="post-list" aria-label="最新文章">
        <?php if ($items === []): ?>
            <article class="empty">
                <h2>还没有发布文章</h2>
                <p>当你在后台发布第一篇文章后，它会显示在这里。可以先创建“关于我们”或第一篇博客，让网站开始被访问。</p>
            </article>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <?php dy_article_card($context, $item); ?>
        <?php endforeach; ?>
    </section>
    <aside class="sidebar" aria-label="侧栏">
        <section class="side-block">
            <h2>站点简介</h2>
            <p><?= $context->e($description) ?></p>
        </section>
        <?php dy_ad_slot($context, 'sidebar'); ?>
        <section class="side-block">
            <h2>快速入口</h2>
            <ul>
                <li><a href="/articles">全部文章</a></li>
                <li><a href="/sitemap.xml">站点地图</a></li>
            </ul>
        </section>
    </aside>
</main>
<?php dy_footer($context); ?>
</body>
</html>
