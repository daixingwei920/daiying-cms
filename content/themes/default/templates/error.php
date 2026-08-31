<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$title = (string) $context->get('title', '页面未找到');
$message = (string) $context->get('message', '你访问的页面不存在，或内容已经移动。');
$seo = $context->get('seo', []);
?>
<!doctype html>
<html lang="zh-CN">
<?php dy_head($context, $title, is_array($seo) ? $seo : [], 'website'); ?>
<body>
<?php dy_header($context); ?>
<main class="wrap not-found" aria-labelledby="error-title">
    <section>
        <p class="entry-kicker">404</p>
        <h1 id="error-title"><?= $context->e($title) ?></h1>
        <p><?= $context->e($message) ?></p>
        <form class="search-form" method="get" action="/search">
            <input name="q" placeholder="搜索你想找的内容" aria-label="搜索关键词">
            <button class="content-button" type="submit">搜索</button>
        </form>
        <p><a class="content-button" href="/">返回首页</a> <a class="back-link" href="/articles">浏览文章</a></p>
    </section>
</main>
<?php dy_footer($context); ?>
</body>
</html>
