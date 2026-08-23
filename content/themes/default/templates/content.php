<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$title = (string) $context->get('title', '');
$seo = $context->get('seo', []);
$categories = $context->get('categories', []);
$tags = $context->get('tags', []);
$content = is_array($context->get('content', [])) ? $context->get('content', []) : [];
$isPage = ($content['content_type'] ?? '') === 'page';
$published = dy_date($context->get('published_at', ''));
$updated = dy_date($context->get('updated_at', ''));
?>
<!doctype html>
<html lang="zh-CN">
<?php dy_head($context, $title, is_array($seo) ? $seo : [], $isPage ? 'website' : 'article'); ?>
<body>
<?php dy_header($context, ''); ?>
<main class="entry-shell">
    <article class="entry<?= $isPage ? ' page-entry' : '' ?>">
        <header class="entry-header">
            <p class="entry-kicker"><?= $isPage ? '页面' : '文章' ?></p>
            <h1><?= $context->e($title !== '' ? $title : '未命名内容') ?></h1>
            <?php if (!$isPage): ?>
                <p class="meta">
                    <?php if ($published !== ''): ?>发布于 <time datetime="<?= $context->e((string) $context->get('published_at', '')) ?>"><?= $context->e($published) ?></time><?php endif; ?>
                    <?php if ($updated !== '' && $updated !== $published): ?> · 更新于 <time datetime="<?= $context->e((string) $context->get('updated_at', '')) ?>"><?= $context->e($updated) ?></time><?php endif; ?>
                </p>
            <?php endif; ?>
        </header>
        <?= dy_first_image_html(is_array($context->get('media', [])) ? $context->get('media', []) : [], 'entry-cover') ?>
        <?php dy_ad_slot($context, 'article_top'); ?>
        <div class="entry-content"><?= $context->get('rendered_blocks', '') ?></div>
        <?php dy_ad_slot($context, 'article_bottom'); ?>
        <?php if (!empty($categories) || !empty($tags)): ?>
            <footer class="entry-footer">
                <?php if (!empty($categories)): ?><div class="terms"><?php foreach ($categories as $term): ?><a href="/category/<?= $context->e($term['slug'] ?? '') ?>"><?= $context->e($term['name'] ?? '') ?></a><?php endforeach; ?></div><?php endif; ?>
                <?php if (!empty($tags)): ?><div class="terms"><?php foreach ($tags as $term): ?><a href="/tag/<?= $context->e($term['slug'] ?? '') ?>">#<?= $context->e($term['name'] ?? '') ?></a><?php endforeach; ?></div><?php endif; ?>
            </footer>
        <?php endif; ?>
        <nav class="post-nav" aria-label="内容导航">
            <a class="back-link" href="<?= $isPage ? '/' : '/articles' ?>"><?= $isPage ? '返回首页' : '返回列表' ?></a>
        </nav>
    </article>
</main>
<?php dy_footer($context); ?>
</body>
</html>
