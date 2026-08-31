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
$summary = dy_excerpt($content, 180);
$comments = is_array($context->get('comments', [])) ? $context->get('comments', []) : [];
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
            <?php if ($summary !== ''): ?><p class="entry-summary"><?= $context->e($summary) ?></p><?php endif; ?>
            <?php if (!$isPage): ?>
                <p class="meta">
                    <?php if ($published !== ''): ?>发布于 <time datetime="<?= $context->e((string) $context->get('published_at', '')) ?>"><?= $context->e($published) ?></time><?php endif; ?>
                    <?php if ($updated !== '' && $updated !== $published): ?> · 更新于 <time datetime="<?= $context->e((string) $context->get('updated_at', '')) ?>"><?= $context->e($updated) ?></time><?php endif; ?>
                    <?php if (!empty($categories)): ?> · <?= $context->e((string) ($categories[0]['name'] ?? '')) ?><?php endif; ?>
                </p>
            <?php endif; ?>
        </header>
        <?= dy_first_image_html(is_array($context->get('media', [])) ? $context->get('media', []) : [], 'entry-cover') ?>
        <?php dy_ad_slot($context, 'article_top'); ?>
        <div class="entry-content"><?= $context->get('rendered_blocks', '') ?></div>
        <?php dy_ad_slot($context, 'article_bottom'); ?>
        <?php if (!empty($categories) || !empty($tags)): ?>
            <footer class="entry-footer">
                <?php if (!empty($categories)): ?><div class="terms"><?php foreach ($categories as $term): ?><a href="/category/<?= $context->e(rawurlencode((string) ($term['slug'] ?? ''))) ?>"><?= $context->e($term['name'] ?? '') ?></a><?php endforeach; ?></div><?php endif; ?>
                <?php if (!empty($tags)): ?><div class="terms"><?php foreach ($tags as $term): ?><a href="/tag/<?= $context->e(rawurlencode((string) ($term['slug'] ?? ''))) ?>">#<?= $context->e($term['name'] ?? '') ?></a><?php endforeach; ?></div><?php endif; ?>
            </footer>
        <?php endif; ?>
        <nav class="post-nav" aria-label="内容导航">
            <a class="back-link" href="<?= $isPage ? '/' : '/articles' ?>"><?= $isPage ? '返回首页' : '返回列表' ?></a>
            <a class="back-link" href="/search">搜索更多内容</a>
        </nav>
        <?php if (!$isPage && ($comments['enabled'] ?? false)): ?>
            <?php $commentItems = is_array($comments['items'] ?? null) ? $comments['items'] : []; ?>
            <?php $frontUser = is_array($comments['user'] ?? null) ? $comments['user'] : null; ?>
            <section class="comments" id="comments">
                <div class="comments-head">
                    <h2>评论</h2>
                    <span><?= count($commentItems) ?> 条</span>
                </div>
                <?php if ((string) ($comments['notice'] ?? '') !== ''): ?><p class="comment-notice"><?= $context->e((string) $comments['notice']) ?></p><?php endif; ?>
                <?php if ((string) ($comments['error'] ?? '') !== ''): ?><p class="comment-error"><?= $context->e((string) $comments['error']) ?></p><?php endif; ?>
                <?php if ($commentItems === []): ?>
                    <p class="muted">还没有评论。</p>
                <?php else: ?>
                    <ol class="comment-list">
                        <?php foreach ($commentItems as $comment): ?>
                            <li class="comment-item">
                                <strong><?= $context->e((string) ($comment['author_name'] ?? '访客')) ?></strong>
                                <time datetime="<?= $context->e((string) ($comment['created_at'] ?? '')) ?>"><?= $context->e(dy_date($comment['created_at'] ?? '')) ?></time>
                                <p><?= nl2br($context->e((string) ($comment['body'] ?? ''))) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
                <?php if ($frontUser !== null): ?>
                    <p class="comment-login-state">当前以 <?= $context->e((string) ($frontUser['display_name'] ?? '会员')) ?> 身份评论。</p>
                    <form class="inline-logout" method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= $context->e((string) ($comments['csrf'] ?? '')) ?>">
                        <input type="hidden" name="redirect" value="<?= $context->e((string) ($comments['redirect'] ?? '/')) ?>">
                        <button type="submit">退出登录</button>
                    </form>
                <?php elseif (!($comments['allow_guest'] ?? true)): ?>
                    <p><a class="back-link" href="/login?redirect=<?= $context->e(rawurlencode((string) ($comments['redirect'] ?? '/'))) ?>">登录后评论</a></p>
                <?php endif; ?>
                <?php if ($frontUser !== null || ($comments['allow_guest'] ?? true)): ?>
                    <form class="comment-form" method="post" action="/comments">
                        <input type="hidden" name="_csrf" value="<?= $context->e((string) ($comments['csrf'] ?? '')) ?>">
                        <input type="hidden" name="content_id" value="<?= (int) ($comments['content_id'] ?? 0) ?>">
                        <input type="hidden" name="redirect" value="<?= $context->e((string) ($comments['redirect'] ?? '/')) ?>">
                        <?php if ($frontUser === null): ?>
                            <div class="comment-fields">
                                <label>昵称<input name="author_name" maxlength="80" required></label>
                                <label>邮箱<input name="author_email" type="email" maxlength="191"></label>
                            </div>
                            <p class="muted">也可以 <a href="/login?redirect=<?= $context->e(rawurlencode((string) ($comments['redirect'] ?? '/'))) ?>">登录</a> 或 <a href="/register?redirect=<?= $context->e(rawurlencode((string) ($comments['redirect'] ?? '/'))) ?>">注册</a> 后评论。</p>
                        <?php endif; ?>
                        <label>评论内容<textarea name="body" rows="5" maxlength="2000" required></textarea></label>
                        <button type="submit">提交评论</button>
                        <?php if ($comments['require_approval'] ?? true): ?><p class="muted">评论审核通过后会显示。</p><?php endif; ?>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </article>
</main>
<?php dy_footer($context); ?>
</body>
</html>
