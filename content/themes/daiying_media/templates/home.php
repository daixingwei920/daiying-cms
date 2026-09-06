<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

/** @var TemplateContext $context */
require_once __DIR__ . '/_theme.php';
$siteName = dm_site_name($context);
$description = dm_setting($context, 'site_description', '让内容本身成为网站的主角。');
$homeTitle = dm_setting($context, 'home_title', $siteName);
$heroMode = dm_setting($context, 'hero_mode', 'magazine');
$items = array_values(array_filter($context->get('contents', []), static fn (array $item): bool => ($item['content_type'] ?? '') === 'article' && ($item['status'] ?? '') === 'published'));
$featured = $items[0] ?? ['title' => $siteName, 'slug' => '', 'content_type' => 'article', 'blocks' => [['type' => 'paragraph', 'data' => ['text' => $description]]], 'published_at' => gmdate('c'), 'media' => []];
$secondaries = array_slice($items, 1, 2);
$rest = array_slice($items, 1);
$tabs = dm_lines(dm_setting($context, 'home_tabs', "最新\n科技\n游戏\n开发\n生活\n评测"));
$showSidebar = dm_bool($context, 'show_sidebar', true);
?>
<!doctype html>
<html lang="zh-CN">
<?php dm_head($context, $siteName, ['title' => $siteName, 'description' => $description]); ?>
<body>
<?php dm_header($context, 'home'); ?>
<main id="content">
    <?php if ($heroMode !== 'off'): ?>
        <section class="hero" aria-label="焦点内容">
            <div class="container">
                <?php if (dm_bool($context, 'ad_home_top_enabled', false)): ?>
                    <?php $adUrl = dm_setting($context, 'ad_home_top_url', ''); ?>
                    <div class="ad-card"><?= dm_safe_href($adUrl) ? '<a href="' . $context->e($adUrl) . '">' : '' ?><span><?= $context->e(dm_setting($context, 'ad_home_top_text', '品牌合作位')) ?></span><strong>AD</strong><?= dm_safe_href($adUrl) ? '</a>' : '' ?></div>
                <?php endif; ?>
                <div class="<?= $heroMode === 'magazine' ? 'home-grid' : '' ?>">
                    <?php $content = is_array($featured['content'] ?? null) ? $featured['content'] : $featured; ?>
                    <article class="focus-card">
                        <?= dm_cover($context, $featured, 'focus-cover') ?><span class="shade" aria-hidden="true"></span>
                        <div class="focus-body">
                            <p class="kicker"><?= $context->e(dm_first_category($featured)) ?></p>
                            <h1><a href="<?= $context->e(dm_url($content)) ?>"><?= $context->e((string) ($featured['title'] ?? $content['title'] ?? $homeTitle)) ?></a></h1>
                            <p><?= $context->e(dm_excerpt($content, 92)) ?></p>
                        </div>
                    </article>
                    <?php if ($heroMode === 'magazine'): ?>
                        <div class="mini-focus-list">
                            <?php foreach ($secondaries as $item): ?>
                                <?php $content = is_array($item['content'] ?? null) ? $item['content'] : $item; ?>
                                <article class="focus-card mini-focus">
                                    <?= dm_cover($context, $item, 'focus-cover') ?><span class="shade" aria-hidden="true"></span>
                                    <div class="focus-body">
                                        <p class="kicker"><?= $context->e(dm_first_category($item)) ?></p>
                                        <h2><a href="<?= $context->e(dm_url($content)) ?>"><?= $context->e((string) ($item['title'] ?? $content['title'] ?? '未命名内容')) ?></a></h2>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <nav class="channel-tabs" aria-label="首页分类导航">
                    <?php foreach ($tabs as $tab): ?><a href="<?= $tab === '最新' ? '/articles' : '/search?q=' . $context->e(rawurlencode($tab)) ?>"><?= $context->e($tab) ?></a><?php endforeach; ?>
                </nav>
                <?php dm_ad_slot($context, 'ad_home_bottom_desktop', 'ad_home_bottom_mobile'); ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="container site-main<?= $showSidebar ? '' : ' no-sidebar' ?>">
        <section class="content-stack" aria-label="首页内容">
            <div class="section-head">
                <div><p class="eyebrow">Latest</p><h2>最新文章</h2><p>清爽、克制，把注意力留给内容。</p></div>
                <a class="read-more" href="/articles">全部文章</a>
            </div>
            <div class="post-list <?= $context->e(dm_setting($context, 'list_layout', 'classic')) ?>">
                <?php if ($items === []): ?>
                    <article class="empty"><h2>内容即将开始</h2><p>发布第一篇文章后，首页会自动生成焦点区、文章流、精选排行和侧栏推荐。</p></article>
                <?php endif; ?>
                <?php foreach (array_slice($items, 0, 8) as $item): ?><?php dm_card($context, $item); ?><?php endforeach; ?>
            </div>

            <?php if ($items !== []): ?>
                <section class="side-block">
                    <div class="section-head"><div><p class="eyebrow"><?= $context->e(dm_setting($context, 'home_kicker', "Editor's Picks")) ?></p><h2><?= $context->e(dm_setting($context, 'featured_title', '编辑精选')) ?></h2><p><?= $context->e(dm_setting($context, 'featured_description', '值得读完，也值得收藏。')) ?></p></div></div>
                    <div class="rank-list">
                        <?php foreach (array_slice($items, 0, 5) as $index => $item): ?>
                            <?php $content = is_array($item['content'] ?? null) ? $item['content'] : $item; ?>
                            <a class="rank-item" href="<?= $context->e(dm_url($content)) ?>"><span class="rank-no"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span><strong><?= $context->e((string) ($item['title'] ?? $content['title'] ?? '未命名内容')) ?></strong><span><?= $context->e(dm_read_count($content)) ?> 阅读</span></span></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </section>
        <?php if ($showSidebar): ?><?php dm_sidebar($context, $items); ?><?php endif; ?>
    </div>
</main>
<?php dm_footer($context); ?>
</body>
</html>
