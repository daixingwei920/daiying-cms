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
$published = dm_date($context->get('published_at', ''));
$updated = dm_date($context->get('updated_at', ''));
$previousPost = is_array($context->get('previous_post', null)) ? $context->get('previous_post') : null;
$nextPost = is_array($context->get('next_post', null)) ? $context->get('next_post') : null;
$summary = dm_excerpt($content, 150);
$comments = is_array($context->get('comments', [])) ? $context->get('comments', []) : [];
$commentItems = is_array($comments['items'] ?? null) ? $comments['items'] : [];
$commentUser = is_array($comments['user'] ?? null) ? $comments['user'] : null;
$commentPrompt = dm_setting($context, 'comment_prompt_text', '请遵守相关法律与法规，文明评论。O(∩_∩)O~~');
$share = dm_share_payload($context, $content, is_array($seo) ? $seo : [], $title);
?>
<!doctype html>
<html lang="zh-CN">
<?php dm_head($context, $title, is_array($seo) ? $seo : [], $isPage ? 'website' : 'article'); ?>
<body>
<?php dm_header($context, ''); ?>
<main id="content" class="entry-shell">
    <article class="entry<?= $isPage ? ' page-entry' : '' ?>">
        <header class="entry-header">
            <p class="kicker"><?= $isPage ? 'Page' : $context->e($categories[0]['name'] ?? 'Article') ?></p>
            <h1><?= $context->e($title !== '' ? $title : '未命名内容') ?></h1>
            <p class="entry-summary"><?= $context->e($summary) ?></p>
            <?php if (!$isPage): ?>
                <div class="byline">
                    <span class="avatar" aria-hidden="true"><?= $context->e(function_exists('mb_substr') ? mb_substr(dm_site_name($context), 0, 1, 'UTF-8') : substr(dm_site_name($context), 0, 1)) ?></span>
                    <span><?= $context->e(dm_site_name($context)) ?></span>
                    <?php if ($published !== ''): ?><span><time datetime="<?= $context->e((string) $context->get('published_at', '')) ?>"><?= $context->e($published) ?></time></span><?php endif; ?>
                    <span><?= $context->e(dm_read_count($content)) ?> 阅读</span>
                    <span><?= count($commentItems) ?> 评论</span>
                    <?php if ($updated !== '' && $updated !== $published): ?><span>更新于 <?= $context->e($updated) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </header>
        <?php dm_ad_slot($context, 'ad_article_top_desktop', 'ad_article_top_mobile'); ?>
        <div class="entry-cover-wrap"><?= dm_cover($context, ['title' => $title, 'media' => $context->get('media', [])], 'entry-cover') ?></div>
        <div class="entry-content"><?= $context->get('rendered_blocks', '') ?></div>
        <footer class="entry-footer">
            <?php dm_terms($context, is_array($tags) ? $tags : [], 'tag'); ?>
            <?php dm_share_row($context, $share); ?>
            <?php if (!$isPage && dm_bool($context, 'enable_reward', false)): ?>
                <?php $wechat = dm_image_setting($context, ['reward_wechat_url', 'weipay']); $alipay = dm_image_setting($context, ['reward_alipay_url', 'alipay']); ?>
                <?php if ($wechat !== '' || $alipay !== ''): ?><section class="reward-box"><h2>赞赏支持</h2><div><?php if ($wechat !== ''): ?><img src="<?= $context->e($wechat) ?>" alt="微信赞赏码" loading="lazy"><?php endif; ?><?php if ($alipay !== ''): ?><img src="<?= $context->e($alipay) ?>" alt="支付宝赞赏码" loading="lazy"><?php endif; ?></div></section><?php endif; ?>
            <?php endif; ?>
            <?php if (!$isPage && dm_bool($context, 'enable_article_copyright', true)): ?><div class="copyright-box">版权声明：<?= $context->e(['original' => '原创内容，转载请保留出处。', 'repost' => '转载内容，请以原始来源信息为准。', 'source' => '来源内容，原始链接请查看正文说明。'][dm_setting($context, 'article_license', 'original')] ?? '原创内容，转载请保留出处。') ?></div><?php endif; ?>
            <?php dm_ad_slot($context, 'ad_related_bottom_desktop', 'ad_related_bottom_mobile'); ?>
            <nav class="post-nav" aria-label="内容导航"><a class="button-link" href="<?= $isPage ? '/' : '/articles' ?>"><?= $isPage ? '返回首页' : '返回列表' ?></a></nav>
            <?php if (!$isPage && ($previousPost !== null || $nextPost !== null)): ?>
                <nav class="adjacent-posts" aria-label="上一篇和下一篇">
                    <?php if ($previousPost !== null): ?><a class="adjacent-card" href="<?= $context->e($previousPost['url'] ?? '#') ?>"><span>上一篇</span><strong><?= $context->e($previousPost['title'] ?? '') ?></strong></a><?php else: ?><span></span><?php endif; ?>
                    <?php if ($nextPost !== null): ?><a class="adjacent-card" href="<?= $context->e($nextPost['url'] ?? '#') ?>"><span>下一篇</span><strong><?= $context->e($nextPost['title'] ?? '') ?></strong></a><?php else: ?><span></span><?php endif; ?>
                </nav>
            <?php endif; ?>
            <section class="side-block"><h2>作者信息</h2><div class="author-card"><span class="avatar" aria-hidden="true"><?= $context->e(function_exists('mb_substr') ? mb_substr(dm_site_name($context), 0, 1, 'UTF-8') : substr(dm_site_name($context), 0, 1)) ?></span><p><strong><?= $context->e(dm_site_name($context)) ?></strong><br><?= $context->e(dm_setting($context, 'site_description', '让内容本身成为网站的主角。')) ?></p></div></section>
            <?php if (!$isPage && !empty($comments['enabled'])): ?>
                <section class="comments" id="comments" aria-label="评论">
                    <div class="comments-head">
                        <h2>评论</h2>
                        <span><?= count($commentItems) ?> 条</span>
                    </div>
                    <?php if (!empty($comments['notice'])): ?><p class="comment-message is-success"><?= $context->e((string) $comments['notice']) ?></p><?php endif; ?>
                    <?php if (!empty($comments['error'])): ?><p class="comment-message is-error"><?= $context->e((string) $comments['error']) ?></p><?php endif; ?>
                    <div class="comment-list">
                        <?php if ($commentItems !== []): ?>
                            <?php foreach ($commentItems as $comment): ?>
                                <?php
                                $author = trim((string) ($comment['author_name'] ?? '读者')) ?: '读者';
                                $avatar = function_exists('mb_substr') ? mb_substr($author, 0, 1, 'UTF-8') : substr($author, 0, 1);
                                $created = dm_date($comment['created_at'] ?? '');
                                ?>
                                <article class="comment-item" id="comment-<?= (int) ($comment['id'] ?? 0) ?>">
                                    <span class="avatar" aria-hidden="true"><?= $context->e($avatar) ?></span>
                                    <div class="comment-body">
                                        <div class="comment-meta">
                                            <strong><?= $context->e($author) ?></strong>
                                            <?php if ($created !== ''): ?><time datetime="<?= $context->e((string) ($comment['created_at'] ?? '')) ?>"><?= $context->e($created) ?></time><?php endif; ?>
                                        </div>
                                        <p><?= nl2br($context->e((string) ($comment['body'] ?? ''))) ?></p>
                                        <button type="button" data-comment-reply data-comment-author="<?= $context->e($author) ?>">回复</button>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <article class="comment-item comment-empty">
                                <span class="avatar" aria-hidden="true">读</span>
                                <div class="comment-body">
                                    <strong>读者</strong>
                                    <p><?= $context->e($commentPrompt) ?></p>
                                </div>
                            </article>
                        <?php endif; ?>
                    </div>
                    <?php if ($commentUser !== null || !empty($comments['allow_guest'])): ?>
                        <form class="comment-form" method="post" action="/comments" data-comment-form>
                            <input type="hidden" name="_csrf" value="<?= $context->e((string) ($comments['csrf'] ?? '')) ?>">
                            <input type="hidden" name="content_id" value="<?= (int) ($comments['content_id'] ?? ($content['id'] ?? 0)) ?>">
                            <input type="hidden" name="redirect" value="<?= $context->e((string) ($comments['redirect'] ?? ($_SERVER['REQUEST_URI'] ?? '/'))) ?>">
                            <p class="comment-replying" data-comment-replying hidden>
                                正在回复 <strong></strong>
                                <button type="button" data-comment-cancel>取消</button>
                            </p>
                            <?php if ($commentUser === null): ?>
                                <div class="comment-fields">
                                    <label>昵称 <input type="text" name="author_name" autocomplete="name" required maxlength="80"></label>
                                    <label>邮箱 <input type="email" name="author_email" autocomplete="email"></label>
                                </div>
                            <?php else: ?>
                                <p class="comment-login-state">以 <?= $context->e((string) ($commentUser['display_name'] ?? $commentUser['email'] ?? '当前账号')) ?> 身份评论。</p>
                            <?php endif; ?>
                            <label class="comment-textarea">评论内容 <textarea name="body" rows="5" required maxlength="2000" data-comment-body placeholder="<?= $context->e($commentPrompt) ?>"></textarea></label>
                            <div class="comment-actions">
                                <button type="submit">提交评论</button>
                                <?php if (!empty($comments['require_approval'])): ?><span>评论提交后需要审核。</span><?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="comment-message">请先登录后再评论。</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </footer>
    </article>
</main>
<div class="lightbox" aria-hidden="true"><button type="button" aria-label="关闭图片预览">×</button><img alt=""></div>
<?php dm_footer($context); ?>
</body>
</html>
