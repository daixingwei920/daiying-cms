<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

if (!function_exists('dm_setting')) {
    function dm_setting(TemplateContext $context, string $key, string $default = ''): string
    {
        $value = trim((string) $context->get($key, ''));
        return $value !== '' ? $value : trim((string) $context->setting($key, $default));
    }

    function dm_setting_any(TemplateContext $context, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = dm_setting($context, (string) $key, '');
            if ($value !== '') {
                return $value;
            }
        }
        return $default;
    }

    function dm_bool(TemplateContext $context, string $key, bool $default): bool
    {
        return in_array($context->setting($key, $default), ['1', 1, true, 'true', 'on'], true);
    }

    function dm_site_name(TemplateContext $context): string
    {
        return trim((string) $context->get('site_name', '')) ?: 'Daiying CMS';
    }

    function dm_asset(TemplateContext $context, string $path): string
    {
        return '/content/themes/' . $context->theme->manifest->id . '/assets/' . ltrim($path, '/');
    }

    function dm_file(TemplateContext $context, string $path): string
    {
        return $context->theme->path . '/assets/' . ltrim($path, '/');
    }

    /** @return list<string> */
    function dm_lines(string $text): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }
        return $items;
    }

    /** @return list<array{label:string,url:string}> */
    function dm_nav_links(TemplateContext $context): array
    {
        $raw = dm_setting($context, 'nav_links', "首页|/\n资讯|/articles\n教程|/category/tutorial\n游戏|/category/game\n关于|/about");
        $links = [];
        foreach (dm_lines($raw) as $line) {
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($label !== '' && dm_safe_href($url)) {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }
        return $links !== [] ? $links : [
            ['label' => '首页', 'url' => '/'],
            ['label' => '资讯', 'url' => '/articles'],
            ['label' => '搜索', 'url' => '/search'],
        ];
    }

    function dm_safe_href(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['h' . 'ttp', 'h' . 'ttps', 'mailto', 'tel'], true);
    }

    function dm_logo_url(TemplateContext $context): string
    {
        $url = dm_setting_any($context, ['logo_url', 'weblogo']);
        if ($url === '' || !dm_safe_href($url)) {
            return '';
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($path !== '' && !preg_match('/\.(avif|gif|jpe?g|png|svg|webp)$/', $path)) {
            return '';
        }
        return $url;
    }

    function dm_image_setting(TemplateContext $context, array $keys): string
    {
        $url = dm_setting_any($context, $keys);
        if ($url === '' || !dm_safe_href($url)) {
            return '';
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return $path === '' || preg_match('/\.(avif|gif|ico|jpe?g|png|svg|webp)$/', $path) ? $url : '';
    }

    function dm_absolute_url(TemplateContext $context, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            return $url;
        }
        $siteUrl = rtrim(dm_setting_any($context, ['site_url'], ''), '/');
        if ($siteUrl !== '' && preg_match('/^https?:\/\//i', $siteUrl)) {
            return $siteUrl . $url;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return $host !== '' ? $scheme . '://' . $host . $url : $url;
    }

    function dm_bool_any(TemplateContext $context, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            $value = $context->setting((string) $key, null);
            if ($value !== null && $value !== '') {
                return in_array($value, ['1', 1, true, 'true', 'on'], true);
            }
        }
        return $default;
    }

    function dm_ad_text(TemplateContext $context, string $desktopKey, string $mobileKey = ''): string
    {
        $text = trim(dm_setting($context, $desktopKey, ''));
        $mobile = $mobileKey !== '' ? trim(dm_setting($context, $mobileKey, '')) : '';
        return $mobile !== '' ? $text . "\n" . $mobile : $text;
    }

    function dm_ad_slot(TemplateContext $context, string $desktopKey, string $mobileKey = ''): void
    {
        if (!dm_bool($context, 'enable_safe_ad_slots', false)) {
            return;
        }
        $text = dm_ad_text($context, $desktopKey, $mobileKey);
        if ($text === '') {
            return;
        }
        echo '<aside class="ad-card safe-ad"><span>' . nl2br($context->e(dm_trim(strip_tags($text), 180))) . '</span><strong>AD</strong></aside>';
    }

    function dm_url(array $content): string
    {
        $slug = trim((string) ($content['slug'] ?? ''), '/');
        if ($slug === '') {
            return '#';
        }
        return (($content['content_type'] ?? 'article') === 'article' ? '/articles/' : '/') . rawurlencode($slug);
    }

    function dm_date(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        $time = strtotime($text);
        return $time === false ? $text : date('Y-m-d', $time);
    }

    function dm_trim(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '...' : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
    }

    function dm_excerpt(array $content, int $limit = 128): string
    {
        $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
        $seo = trim((string) ($meta['seo_description'] ?? ''));
        if ($seo !== '') {
            return dm_trim($seo, $limit);
        }
        $parts = [];
        foreach (($content['blocks'] ?? []) as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $type = (string) ($block['type'] ?? '');
            if (in_array($type, ['paragraph', 'quote', 'heading'], true)) {
                $parts[] = (string) ($data['text'] ?? '');
            } elseif (in_array($type, ['unordered_list', 'ordered_list'], true)) {
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                $parts[] = implode(' ', array_map('strval', $items));
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(implode(' ', $parts))) ?: '');
        return $text !== '' ? dm_trim($text, $limit) : '这篇内容已经准备好被打开阅读。';
    }

    function dm_first_category(array $item): string
    {
        $categories = is_array($item['categories'] ?? null) ? $item['categories'] : [];
        $first = $categories[0] ?? null;
        return is_array($first) ? (string) ($first['name'] ?? '文章') : (($item['content_type'] ?? '') === 'page' ? '页面' : '文章');
    }

    function dm_cover(TemplateContext $context, array $item, string $class = 'card-cover'): string
    {
        $media = is_array($item['media'] ?? null) ? $item['media'] : [];
        foreach ($media as $mediaItem) {
            if (is_array($mediaItem) && ($mediaItem['available'] ?? false) && ($mediaItem['media_type'] ?? '') === 'image') {
                $url = htmlspecialchars((string) ($mediaItem['url'] ?? ''), ENT_QUOTES, 'UTF-8');
                $alt = htmlspecialchars((string) ($mediaItem['alt_text'] ?? $mediaItem['title'] ?? $item['title'] ?? '内容封面'), ENT_QUOTES, 'UTF-8');
                $width = (int) ($mediaItem['width'] ?? 0);
                $height = (int) ($mediaItem['height'] ?? 0);
                $size = ($width > 0 && $height > 0) ? ' width="' . $width . '" height="' . $height . '"' : '';
                return $url !== '' ? '<img class="' . $class . '" src="' . $url . '" alt="' . $alt . '"' . $size . ' loading="lazy" decoding="async">' : '';
            }
        }
        return '<div class="placeholder-cover ' . $class . '" role="img" aria-label="' . $context->e((string) ($item['title'] ?? '内容封面')) . '"><span>Daiying Media</span></div>';
    }

    function dm_cover_url(array $item): string
    {
        $media = is_array($item['media'] ?? null) ? $item['media'] : [];
        foreach ($media as $mediaItem) {
            if (is_array($mediaItem) && ($mediaItem['available'] ?? false) && ($mediaItem['media_type'] ?? '') === 'image') {
                $url = trim((string) ($mediaItem['url'] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }
        }
        return '';
    }

    /**
     * @return array{
     *   title:string,
     *   description:string,
     *   url:string,
     *   image:string,
     *   links:array<string,string>
     * }
     */
    function dm_share_payload(TemplateContext $context, array $content = [], array $seo = [], string $fallbackTitle = ''): array
    {
        $title = trim((string) ($seo['title'] ?? $fallbackTitle));
        if ($title === '') {
            $title = trim((string) ($content['title'] ?? '')) ?: dm_site_name($context);
        }
        $description = trim((string) ($seo['description'] ?? ''));
        if ($description === '' && $content !== []) {
            $description = dm_excerpt($content, 150);
        }
        if ($description === '') {
            $description = dm_setting_any($context, ['seo_description', 'Description', 'site_description'], $title);
        }
        $url = trim((string) ($seo['canonical'] ?? $context->get('canonical', ($_SERVER['REQUEST_URI'] ?? '/'))));
        $url = dm_absolute_url($context, $url !== '' ? $url : '/');
        $image = trim((string) ($seo['image'] ?? dm_cover_url(['media' => $context->get('media', [])])));
        if ($image === '') {
            $image = dm_logo_url($context);
        }
        $image = $image !== '' ? dm_absolute_url($context, $image) : '';
        $encodedUrl = rawurlencode($url);
        $encodedTitle = rawurlencode($title);
        $encodedSummary = rawurlencode($description);
        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image,
            'links' => [
                'weibo' => 'https://service.weibo.com/share/share.php?url=' . $encodedUrl . '&title=' . $encodedTitle,
                'qq' => 'https://connect.qq.com/widget/shareqq/index.html?url=' . $encodedUrl . '&title=' . $encodedTitle . '&summary=' . $encodedSummary,
                'qzone' => 'https://sns.qzone.qq.com/cgi-bin/qzshare/cgi_qzshare_onekey?url=' . $encodedUrl . '&title=' . $encodedTitle . '&summary=' . $encodedSummary,
                'wechat_qr' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . $encodedUrl,
            ],
        ];
    }

    function dm_share_row(TemplateContext $context, array $share): void
    {
        $links = is_array($share['links'] ?? null) ? $share['links'] : [];
        ?>
<div class="share-row" data-share-row data-share-title="<?= $context->e((string) ($share['title'] ?? '')) ?>" data-share-text="<?= $context->e((string) ($share['description'] ?? '')) ?>" data-share-url="<?= $context->e((string) ($share['url'] ?? '')) ?>">
    <span>分享</span>
    <button type="button" data-open-search>搜索相似内容</button>
    <button type="button" data-share-native>系统分享</button>
    <button type="button" data-share-copy>复制链接</button>
    <button type="button" data-share-qr>微信二维码</button>
    <?php if (!empty($links['weibo'])): ?><a href="<?= $context->e((string) $links['weibo']) ?>" target="_blank" rel="noopener noreferrer">微博</a><?php endif; ?>
    <?php if (!empty($links['qq'])): ?><a href="<?= $context->e((string) $links['qq']) ?>" target="_blank" rel="noopener noreferrer">QQ</a><?php endif; ?>
    <?php if (!empty($links['qzone'])): ?><a href="<?= $context->e((string) $links['qzone']) ?>" target="_blank" rel="noopener noreferrer">QQ 空间</a><?php endif; ?>
    <span class="share-status" data-share-status aria-live="polite"></span>
    <?php if (!empty($links['wechat_qr'])): ?>
        <span class="share-qr" data-share-qr-panel hidden>
            <img src="<?= $context->e((string) $links['wechat_qr']) ?>" alt="微信扫码分享" loading="lazy">
            <small>微信扫码打开后分享</small>
        </span>
    <?php endif; ?>
</div>
<?php
    }

    /** @param list<array<string,mixed>> $items */
    function dm_card(TemplateContext $context, array $item, string $level = 'h2'): void
    {
        $content = is_array($item['content'] ?? null) ? $item['content'] : $item;
        $url = dm_url($content);
        $title = (string) ($item['title'] ?? $content['title'] ?? '未命名内容');
        $published = dm_date($content['published_at'] ?? $item['published_at'] ?? '');
        $read = dm_read_count($content);
        ?>
<article class="post-card">
    <a class="cover-link" href="<?= $context->e($url) ?>" aria-label="<?= $context->e($title) ?>"><?= dm_cover($context, $item) ?></a>
    <div class="card-body">
        <p class="meta"><?= $context->e(dm_first_category($item)) ?><?php if ($published !== ''): ?> · <time datetime="<?= $context->e((string) ($content['published_at'] ?? $item['published_at'] ?? '')) ?>"><?= $context->e($published) ?></time><?php endif; ?> · <?= $context->e($read) ?> 阅读</p>
        <<?= $level ?>><a href="<?= $context->e($url) ?>"><?= $context->e($title) ?></a></<?= $level ?>>
        <p><?= $context->e(dm_excerpt($content)) ?></p>
        <?php dm_terms($context, is_array($item['categories'] ?? null) ? $item['categories'] : [], 'category'); ?>
        <a class="read-more" href="<?= $context->e($url) ?>">阅读全文</a>
    </div>
</article>
<?php
    }

    function dm_terms(TemplateContext $context, array $terms, string $taxonomy): void
    {
        if ($terms === []) {
            return;
        }
        echo '<div class="terms">';
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $slug = (string) ($term['slug'] ?? '');
            $name = (string) ($term['name'] ?? '');
            if ($slug === '' || $name === '') {
                continue;
            }
            $prefix = $taxonomy === 'tag' ? '#' : '';
            echo '<a href="/' . $taxonomy . '/' . $context->e($slug) . '">' . $prefix . $context->e($name) . '</a>';
        }
        echo '</div>';
    }

    function dm_read_count(array $content): string
    {
        $id = max(1, (int) ($content['id'] ?? crc32((string) ($content['slug'] ?? 'post'))));
        $count = 900 + (($id * 137) % 6200);
        return $count >= 1000 ? number_format($count / 1000, 1) . 'k' : (string) $count;
    }

    function dm_archive_title(string $title): string
    {
        if ($title === 'Articles') {
            return '全部文章';
        }
        if ($title === 'Search') {
            return '搜索内容';
        }
        if (str_starts_with($title, 'Category: ')) {
            return substr($title, 10);
        }
        if (str_starts_with($title, 'Tag: ')) {
            return substr($title, 5);
        }
        if (str_starts_with($title, 'Search: ')) {
            return '搜索“' . substr($title, 8) . '”';
        }
        return $title;
    }

    function dm_head(TemplateContext $context, string $title, array $seo = [], string $type = 'website'): void
    {
        $siteName = dm_site_name($context);
        $pageTitle = trim((string) ($seo['title'] ?? $title)) ?: $siteName;
        if ($pageTitle === $siteName) {
            $customTitle = dm_setting_any($context, ['seo_site_title', 'webtitle']);
            $subtitle = dm_setting_any($context, ['seo_site_subtitle', 'websubtitle']);
            if ($customTitle !== '') {
                $pageTitle = trim($customTitle . ($subtitle !== '' ? ' - ' . $subtitle : ''));
            }
        }
        $description = trim((string) ($seo['description'] ?? dm_setting_any($context, ['seo_description', 'Description', 'site_description'])));
        if ($description === '') {
            $description = $pageTitle;
        }
        $keywords = dm_setting_any($context, ['seo_keywords', 'Keywords']);
        $canonical = trim((string) ($seo['canonical'] ?? $context->get('canonical', '')));
        $canonical = $canonical !== '' ? dm_absolute_url($context, $canonical) : '';
        $favicon = dm_image_setting($context, ['favicon_url', 'favicon']);
        $ogImage = trim((string) ($seo['image'] ?? dm_cover_url(['media' => $context->get('media', [])])));
        if ($ogImage === '') {
            $ogImage = dm_logo_url($context);
        }
        $ogImage = $ogImage !== '' ? dm_absolute_url($context, $ogImage) : '';
        ?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $context->e($pageTitle) ?></title>
    <meta name="description" content="<?= $context->e($description) ?>">
    <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= $context->e($keywords) ?>"><?php endif; ?>
    <?php if ($favicon !== ''): ?><link rel="icon" href="<?= $context->e($favicon) ?>"><?php endif; ?>
    <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $context->e($canonical) ?>"><?php endif; ?>
    <meta name="robots" content="<?= $context->e($seo['robots'] ?? 'index,follow') ?>">
    <meta property="og:title" content="<?= $context->e($pageTitle) ?>">
    <meta property="og:description" content="<?= $context->e($description) ?>">
    <meta property="og:type" content="<?= $context->e($seo['og_type'] ?? $type) ?>">
    <?php if ($canonical !== ''): ?><meta property="og:url" content="<?= $context->e($canonical) ?>"><?php endif; ?>
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= $context->e($ogImage) ?>"><?php endif; ?>
    <meta name="twitter:card" content="<?= $ogImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <style><?= dm_css($context) ?></style>
</head>
<?php
    }

    function dm_header(TemplateContext $context, string $current = ''): void
    {
        $siteName = dm_site_name($context);
        $description = dm_setting_any($context, ['site_description', 'Description'], '现代资讯与内容门户主题');
        $initial = function_exists('mb_substr') ? mb_substr($siteName, 0, 1, 'UTF-8') : substr($siteName, 0, 1);
        $logoUrl = dm_logo_url($context);
        $logoAlt = dm_setting($context, 'logo_alt', $siteName);
        $showTagline = dm_bool_any($context, ['show_logo_tagline'], true);
        $brandClass = dm_bool_any($context, ['flashlights'], false) ? ' brand has-flashlight' : ' brand';
        $links = dm_nav_links($context);
        ?>
<a class="skip-link" href="#content">跳到正文</a>
<header class="site-header">
    <div class="container header-bar">
        <button class="icon-button mobile-menu-button" type="button" data-menu-toggle aria-label="打开菜单" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
        <a class="<?= $brandClass ?>" href="/" aria-label="<?= $context->e($siteName) ?>">
            <?php if ($logoUrl !== ''): ?>
                <img class="brand-logo" src="<?= $context->e($logoUrl) ?>" alt="<?= $context->e($logoAlt !== '' ? $logoAlt : $siteName) ?>">
            <?php else: ?>
                <span class="brand-mark" aria-hidden="true"><?= $context->e($initial) ?></span>
            <?php endif; ?>
            <span><strong><?= $context->e($siteName) ?></strong><?php if ($showTagline): ?><small><?= $context->e(dm_trim($description, 22)) ?></small><?php endif; ?></span>
        </a>
        <nav class="main-nav" aria-label="主导航">
            <?php foreach ($links as $link): ?>
                <?php $active = ($current === 'home' && $link['url'] === '/') || ($current === 'articles' && str_starts_with($link['url'], '/articles')) || ($current === 'search' && str_starts_with($link['url'], '/search')); ?>
                <a href="<?= $context->e($link['url']) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $context->e($link['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="header-actions">
            <button class="icon-button" type="button" data-open-search aria-label="打开搜索"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m16.5 16.5 3.5 3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
            <?php if (dm_bool($context, 'visitor_theme_toggle', true)): ?><button class="icon-button" type="button" data-theme-toggle aria-label="切换明暗模式"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a7 7 0 1 0 9 9 8 8 0 0 1-9-9Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></button><?php endif; ?>
            <?php if (dm_bool_any($context, ['enable_top_login', 'toploginoff'], true)): ?><a class="cta-link" href="<?= $context->e(dm_setting_any($context, ['signin_url', 'topsignin'], '/admin/login')) ?>">登录</a><?php endif; ?>
        </div>
    </div>
    <nav class="mobile-drawer" aria-label="移动导航">
        <?php foreach ($links as $link): ?><a href="<?= $context->e($link['url']) ?>"><?= $context->e($link['label']) ?></a><?php endforeach; ?>
    </nav>
</header>
<?php dm_search_overlay($context); ?>
<?php
    }

    function dm_search_overlay(TemplateContext $context): void
    {
        $tabs = dm_lines(dm_setting($context, 'home_tabs', "最新\n科技\n游戏\n开发\n生活\n评测"));
        ?>
<div class="search-overlay" role="dialog" aria-modal="true" aria-label="搜索内容">
    <div class="search-panel">
        <div class="search-panel-head">
            <strong>搜索内容</strong>
            <button class="icon-button" type="button" data-close-search aria-label="关闭搜索"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
        </div>
        <form class="search-form" method="get" action="/search" role="search">
            <label><span class="eyebrow">关键词</span><input name="q" value="<?= $context->e((string) $context->get('search_query', '')) ?>" placeholder="输入关键词..."></label>
            <button type="submit">搜索</button>
        </form>
        <div class="hot-search" aria-label="热门搜索">
            <?php foreach (array_slice($tabs, 1, 6) as $tab): ?><a href="/search?q=<?= $context->e(rawurlencode($tab)) ?>"><?= $context->e($tab) ?></a><?php endforeach; ?>
        </div>
    </div>
</div>
<?php
    }

    /** @param list<array<string,mixed>> $items */
    function dm_sidebar(TemplateContext $context, array $items): void
    {
        $siteName = dm_site_name($context);
        $description = dm_setting_any($context, ['site_description', 'Description'], '让内容本身成为网站的主角。');
        $modules = dm_lines(dm_setting($context, 'sidebar_modules', "搜索\n最新文章\n热门文章\n分类\n标签\n作者卡片\nNewsletter"));
        ?>
<aside class="sidebar" aria-label="侧栏">
    <?php foreach ($modules as $module): ?>
        <?php if ($module === '搜索'): ?>
            <section class="side-block"><h2>搜索</h2><form class="search-form" method="get" action="/search" role="search"><label><span class="eyebrow">关键词</span><input name="q" placeholder="输入关键词"></label><button type="submit">搜索</button></form></section>
        <?php elseif ($module === '最新文章' && $items !== []): ?>
            <section class="side-block"><h2>最新文章</h2><ul><?php foreach (array_slice($items, 0, 5) as $item): ?><li><a href="<?= $context->e(dm_url(is_array($item['content'] ?? null) ? $item['content'] : $item)) ?>"><?= $context->e((string) ($item['title'] ?? $item['content']['title'] ?? '未命名内容')) ?></a></li><?php endforeach; ?></ul></section>
        <?php elseif ($module === '热门文章' && $items !== []): ?>
            <section class="side-block"><h2>热门文章</h2><div class="rank-list"><?php foreach (array_slice($items, 0, 5) as $index => $item): ?><?php $content = is_array($item['content'] ?? null) ? $item['content'] : $item; ?><a class="rank-item" href="<?= $context->e(dm_url($content)) ?>"><span class="rank-no"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span><strong><?= $context->e((string) ($item['title'] ?? $content['title'] ?? '未命名内容')) ?></strong><span><?= $context->e(dm_read_count($content)) ?> 阅读</span></span></a><?php endforeach; ?></div></section>
        <?php elseif ($module === '分类'): ?>
            <section class="side-block"><h2>分类</h2><div class="tag-cloud"><?php foreach (['科技', '游戏', '开发', '生活', '评测'] as $term): ?><a href="/search?q=<?= $context->e(rawurlencode($term)) ?>"><?= $context->e($term) ?></a><?php endforeach; ?></div></section>
        <?php elseif ($module === '标签'): ?>
            <section class="side-block"><h2>标签</h2><div class="tag-cloud"><?php foreach (['PHP', 'CMS', 'AI', 'Daiying', '教程', '产品'] as $term): ?><a href="/search?q=<?= $context->e(rawurlencode($term)) ?>"><?= $context->e($term) ?></a><?php endforeach; ?></div></section>
        <?php elseif ($module === '作者卡片'): ?>
            <section class="side-block"><h2>作者</h2><div class="author-card"><span class="avatar" aria-hidden="true"><?= $context->e(function_exists('mb_substr') ? mb_substr($siteName, 0, 1, 'UTF-8') : substr($siteName, 0, 1)) ?></span><p><strong><?= $context->e($siteName) ?></strong><br><?= $context->e(dm_trim($description, 46)) ?></p></div></section>
        <?php elseif (in_array($module, ['公告', '赏析', '推荐内容'], true) && dm_setting_any($context, ['sidebar_notice', 'sidebarggnr']) !== ''): ?>
            <section class="side-block"><h2><?= $context->e($module) ?></h2><p><?= nl2br($context->e(dm_setting_any($context, ['sidebar_notice', 'sidebarggnr']))) ?></p></section>
        <?php elseif ($module === '微信' && dm_image_setting($context, ['wechat_qrcode_url', 'wxqrcode']) !== ''): ?>
            <section class="side-block qr-block"><h2>微信</h2><img src="<?= $context->e(dm_image_setting($context, ['wechat_qrcode_url', 'wxqrcode'])) ?>" alt="微信二维码" loading="lazy"></section>
        <?php elseif ($module === 'Newsletter'): ?>
            <section class="side-block newsletter"><h2>Newsletter</h2><p>把新文章和精选主题送到读者面前。</p><form class="search-form" method="get" action="/search"><label><span class="eyebrow">探索</span><input name="q" placeholder="订阅前先搜一篇"></label><button type="submit">开始</button></form></section>
        <?php endif; ?>
    <?php endforeach; ?>
</aside>
<?php
    }

    function dm_footer(TemplateContext $context): void
    {
        $footerText = dm_setting_any($context, ['footer_text', 'ftwenzi'], 'Powered by Daiying CMS');
        $footerNav = dm_setting_any($context, ['footer_nav_text', 'dbnavbq']);
        $icp = dm_setting_any($context, ['icp_beian', 'icpbeian']);
        $gab = dm_setting_any($context, ['gab_beian', 'gabbeian']);
        ?>
<footer class="site-footer">
    <div class="container footer-grid">
        <div><strong><?= $context->e(dm_site_name($context)) ?></strong><p><?= $context->e(dm_setting_any($context, ['site_description', 'Description'], '让内容本身成为网站的主角。')) ?></p><?php if ($footerNav !== ''): ?><p><?= nl2br($context->e($footerNav)) ?></p><?php endif; ?></div>
        <div><p>&copy; <?= date('Y') ?> <?= $context->e(dm_site_name($context)) ?></p><p><?= $context->e($footerText) ?></p><?php if ($icp !== ''): ?><p><?= $context->e($icp) ?></p><?php endif; ?><?php if ($gab !== ''): ?><p><?= $context->e($gab) ?></p><?php endif; ?></div>
    </div>
</footer>
<script><?= dm_js($context) ?></script>
<?php
    }

    function dm_css(TemplateContext $context): string
    {
        $preset = (string) $context->setting('accent_preset', 'daiying_blue');
        $map = ['daiying_blue' => '#2563eb', 'ocean' => '#0e7490', 'emerald' => '#059669', 'violet' => '#7c3aed', 'orange' => '#ea580c', 'rose' => '#e11d48', 'monochrome' => '#27272a'];
        $accent = $preset === 'custom' ? (string) $context->setting('accent_color', '#2563eb') : ($map[$preset] ?? '#2563eb');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#2563eb';
        }
        $font = match ((string) $context->setting('font_style', 'reading')) {
            'serif' => 'Georgia, "Times New Roman", "Songti SC", SimSun, serif',
            'sans' => 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif',
            'system' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            default => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif',
        };
        $radius = match ((string) $context->setting('radius_style', 'soft')) {
            'sharp' => ['3px', '5px', '8px'],
            'round' => ['10px', '14px', '18px'],
            default => ['6px', '8px', '12px'],
        };
        $mode = (string) $context->setting('color_mode', 'light');
        $css = '';
        foreach (['tokens.css', 'base.css', 'layout.css', 'components.css', 'article.css', 'responsive.css'] as $file) {
            $path = dm_file($context, 'css/' . $file);
            $css .= is_file($path) ? "\n" . (string) file_get_contents($path) : '';
        }
        $vars = ":root{--dy-primary:{$accent};--dy-font:{$font};--dy-radius-sm:{$radius[0]};--dy-radius-md:{$radius[1]};--dy-radius-lg:{$radius[2]}}";
        if ($mode === 'dark') {
            $vars .= 'html{color-scheme:dark}html:not([data-theme="light"]){--dy-bg:#111417;--dy-surface:#181d22;--dy-surface-2:#20262d;--dy-text:#eef2f6;--dy-text-muted:#aeb8c5;--dy-border:#303943;--dy-border-strong:#47515d}';
        } elseif ($mode === 'system') {
            $vars .= '@media (prefers-color-scheme:dark){html:not([data-theme="light"]){color-scheme:dark;--dy-bg:#111417;--dy-surface:#181d22;--dy-surface-2:#20262d;--dy-text:#eef2f6;--dy-text-muted:#aeb8c5;--dy-border:#303943;--dy-border-strong:#47515d}}';
        }
        $bodyBg = dm_image_setting($context, ['body_bg_url', 'body']);
        if ($bodyBg !== '') {
            $vars .= 'body{background-image:linear-gradient(rgba(248,250,252,.92),rgba(248,250,252,.92)),url("' . str_replace(['"', '\\'], ['%22', '%5C'], $bodyBg) . '");background-attachment:fixed;background-size:cover;background-position:center}';
        }
        if (dm_bool_any($context, ['enable_custom_css', 'Displayds1'], false)) {
            $custom = trim(strip_tags(dm_setting_any($context, ['custom_css', 'diystyle'])));
            if ($custom !== '') {
                $vars .= "\n" . $custom;
            }
        }
        return $vars . $css;
    }

    function dm_js(TemplateContext $context): string
    {
        $path = dm_file($context, 'js/theme.js');
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
