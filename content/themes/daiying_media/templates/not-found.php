<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$siteName = dm_site_name($context);
?>
<!doctype html>
<html lang="zh-CN">
<?php dm_head($context, '页面未找到 - ' . $siteName, ['robots' => 'noindex,nofollow']); ?>
<body>
<?php dm_header($context, ''); ?>
<main id="content" class="entry-shell">
    <section class="entry not-found">
        <p class="kicker">404</p>
        <h1>这里好像没有内容。</h1>
        <p class="entry-summary">你访问的页面可能已经移动或删除。可以返回首页，或搜索已经发布的内容。</p>
        <form class="search-form" method="get" action="/search" role="search">
            <label><span class="eyebrow">搜索内容</span><input name="q" placeholder="输入关键词..."></label>
            <button type="submit">搜索</button>
        </form>
        <p><a class="button-link" href="/">返回首页</a></p>
    </section>
</main>
<?php dm_footer($context); ?>
</body>
</html>
