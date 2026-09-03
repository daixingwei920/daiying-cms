<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

if (!function_exists('dy_setting')) {
    function dy_setting(TemplateContext $context, string $key, mixed $default = ''): mixed
    {
        $value = $context->get($key, null);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if ($value !== null && !is_string($value)) {
            return $value;
        }
        return $context->setting($key, $default);
    }

    function dy_text(TemplateContext $context, string $key, string $default = ''): string
    {
        $value = dy_setting($context, $key, $default);
        return trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    function dy_bool(TemplateContext $context, string $key, bool $default = false): bool
    {
        return in_array(dy_setting($context, $key, $default), [true, 1, '1', 'true', 'on'], true);
    }

    function dy_int(TemplateContext $context, string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) dy_setting($context, $key, $default)));
    }

    function dy_color(TemplateContext $context, string $key, string $default): string
    {
        $value = (string) dy_setting($context, $key, $default);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $default;
    }

    function dy_choice(TemplateContext $context, string $key, string $default, array $allowed): string
    {
        $value = (string) dy_setting($context, $key, $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    function dy_site_name(TemplateContext $context): string
    {
        return trim((string) $context->get('site_name', '')) ?: 'Daiying CMS';
    }

    function dy_url(array $content): string
    {
        $slug = trim((string) ($content['slug'] ?? ''), '/');
        if ($slug === '') {
            return '#';
        }
        return (($content['content_type'] ?? 'article') === 'article' ? '/articles/' : '/') . rawurlencode($slug);
    }

    function dy_safe_url(string $url, string $fallback = '#'): string
    {
        $url = trim($url);
        if ($url === '') {
            return $fallback;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        return preg_match('/^https?:\/\/[^\s<>"\']+$/i', $url) === 1 ? $url : $fallback;
    }

    function dy_date(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        $time = strtotime($text);
        return $time === false ? $text : date('Y-m-d', $time);
    }

    function dy_excerpt(array $content, int $limit = 132): string
    {
        $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
        $seo = trim((string) ($meta['seo_description'] ?? ''));
        if ($seo !== '') {
            return dy_trim($seo, $limit);
        }
        $parts = [];
        foreach (($content['blocks'] ?? []) as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $type = (string) ($block['type'] ?? '');
            if (in_array($type, ['paragraph', 'quote', 'heading'], true)) {
                $parts[] = (string) ($data['text'] ?? '');
            } elseif (in_array($type, ['unordered_list', 'ordered_list', 'list'], true)) {
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                $parts[] = implode(' ', array_map('strval', $items));
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(implode(' ', $parts))) ?: '');
        return $text !== '' ? dy_trim($text, $limit) : '这篇内容暂时没有摘要。';
    }

    function dy_trim(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '...' : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
    }

    function dy_first_image_html(array $media, string $class = 'entry-cover'): string
    {
        foreach ($media as $item) {
            if (is_array($item) && ($item['available'] ?? false) && ($item['media_type'] ?? '') === 'image') {
                $url = htmlspecialchars((string) ($item['url'] ?? ''), ENT_QUOTES, 'UTF-8');
                $alt = htmlspecialchars((string) ($item['alt_text'] ?? $item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                return $url !== '' ? '<img class="' . $class . '" src="' . $url . '" alt="' . $alt . '" loading="lazy">' : '';
            }
        }
        return '';
    }

    function dy_link_lines(TemplateContext $context, string $key): array
    {
        $lines = preg_split('/\R/u', dy_text($context, $key, '')) ?: [];
        $links = [];
        foreach ($lines as $line) {
            [$label, $url] = array_pad(explode('|', trim($line), 2), 2, '');
            $label = trim($label);
            $url = dy_safe_url(trim($url), '');
            if ($label !== '' && $url !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }
        return $links;
    }

    function dy_head(TemplateContext $context, string $title, array $seo = [], string $type = 'website'): void
    {
        $siteName = dy_site_name($context);
        $pageTitle = trim((string) ($seo['title'] ?? $title));
        $description = trim((string) ($seo['description'] ?? dy_text($context, 'site_description', '')));
        $canonical = trim((string) ($seo['canonical'] ?? $context->get('canonical', '')));
        ?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $context->e($pageTitle !== '' ? $pageTitle : $siteName) ?></title>
    <meta name="description" content="<?= $context->e($description !== '' ? $description : $siteName) ?>">
    <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $context->e($canonical) ?>"><?php endif; ?>
    <meta name="robots" content="<?= $context->e($seo['robots'] ?? 'index,follow') ?>">
    <meta property="og:title" content="<?= $context->e($pageTitle !== '' ? $pageTitle : $siteName) ?>">
    <meta property="og:description" content="<?= $context->e($description !== '' ? $description : $siteName) ?>">
    <meta property="og:type" content="<?= $context->e($seo['og_type'] ?? $type) ?>">
    <?php if ($canonical !== ''): ?><meta property="og:url" content="<?= $context->e($canonical) ?>"><?php endif; ?>
    <style><?= dy_css($context) ?></style>
</head>
<?php
    }

    function dy_header(TemplateContext $context, string $current = ''): void
    {
        $siteName = dy_site_name($context);
        $description = dy_text($context, 'site_description', '记录、发布与分享');
        $logo = dy_safe_url(dy_text($context, 'logo_image', ''), '');
        $initial = function_exists('mb_substr') ? mb_substr($siteName, 0, 1, 'UTF-8') : substr($siteName, 0, 1);
        $navigation = dy_navigation_items($context);
        $showSearch = dy_bool($context, 'show_search', true);
        ?>
<header class="site-header">
    <div class="site-bar">
        <a class="brand" href="/" aria-label="<?= $context->e($siteName) ?>">
            <?php if ($logo !== ''): ?><img class="brand-logo" src="<?= $context->e($logo) ?>" alt=""><?php else: ?><span class="brand-mark" aria-hidden="true"><?= $context->e($initial) ?></span><?php endif; ?>
            <?php if (dy_bool($context, 'show_site_name', true)): ?><span><strong><?= $context->e($siteName) ?></strong><small><?= $context->e($description) ?></small></span><?php endif; ?>
        </a>
        <input class="nav-toggle" id="nav-toggle" type="checkbox" aria-label="展开导航">
        <label class="nav-button" for="nav-toggle"><span></span><span></span><span></span></label>
        <nav class="main-nav" aria-label="主导航">
            <?php foreach ($navigation as $item): ?>
                <a href="<?= $context->e($item['url']) ?>"<?= dy_nav_current($current, $item) ? ' aria-current="page"' : '' ?>><?= $context->e($item['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($showSearch): ?><a class="nav-search" href="/search">搜索</a><?php endif; ?>
        </nav>
    </div>
</header>
<?php
    }

    function dy_navigation_items(TemplateContext $context): array
    {
        $items = $context->get('navigation', []);
        if (!is_array($items) || $items === []) {
            return [
                ['label' => '首页', 'url' => '/', 'type' => 'home'],
                ['label' => '文章', 'url' => '/articles', 'type' => 'articles'],
            ];
        }
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || !($item['enabled'] ?? true)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $url = dy_safe_url((string) ($item['url'] ?? ''), '');
            if ($label !== '' && $url !== '') {
                $clean[] = ['label' => $label, 'url' => $url, 'type' => (string) ($item['type'] ?? 'custom')];
            }
        }
        return $clean !== [] ? $clean : [['label' => '首页', 'url' => '/', 'type' => 'home'], ['label' => '文章', 'url' => '/articles', 'type' => 'articles']];
    }

    function dy_nav_current(string $current, array $item): bool
    {
        return $current !== '' && $current === (string) ($item['type'] ?? '');
    }

    function dy_footer(TemplateContext $context): void
    {
        $links = dy_link_lines($context, 'footer_links');
        $social = dy_link_lines($context, 'social_links');
        $layout = dy_choice($context, 'footer_layout', 'three', ['one', 'two', 'three', 'four']);
        ?>
<?php dy_ad_slot($context, 'footer_top'); ?>
<footer class="site-footer footer-<?= $context->e($layout) ?>">
    <div class="footer-inner">
        <section>
            <h2><?= $context->e(dy_site_name($context)) ?></h2>
            <p><?= $context->e(dy_text($context, 'footer_about', '使用 Daiying CMS 构建的现代内容网站。')) ?></p>
        </section>
        <section>
            <h2>站点链接</h2>
            <ul><?php foreach ($links as $link): ?><li><a href="<?= $context->e($link['url']) ?>"><?= $context->e($link['label']) ?></a></li><?php endforeach; ?></ul>
        </section>
        <section>
            <h2>社交与联系</h2>
            <?php if ($social === []): ?><p class="muted">可在主题设置中填写社交链接。</p><?php endif; ?>
            <ul><?php foreach ($social as $link): ?><li><a href="<?= $context->e($link['url']) ?>"><?= $context->e($link['label']) ?></a></li><?php endforeach; ?></ul>
        </section>
        <section>
            <h2>版权</h2>
            <p>&copy; <?= date('Y') ?> <?= $context->e(dy_site_name($context)) ?></p>
            <?php if (dy_text($context, 'icp_text', '') !== ''): ?><p><?= $context->e(dy_text($context, 'icp_text', '')) ?></p><?php endif; ?>
            <?php if (dy_text($context, 'footer_custom_text', '') !== ''): ?><p><?= $context->e(dy_text($context, 'footer_custom_text', '')) ?></p><?php endif; ?>
        </section>
    </div>
</footer>
<?php
    }

    function dy_ad_slot(TemplateContext $context, string $slot): void
    {
        $slotKey = strtolower(trim($slot));
        if (!preg_match('/^[a-z0-9_-]{1,48}$/', $slotKey)) {
            return;
        }
        $injectedSlots = $context->get('ad_slots', null);
        $slots = is_array($injectedSlots) && $injectedSlots !== [] ? $injectedSlots : $context->setting('ad_slots', []);
        if (!is_array($slots)) {
            return;
        }
        $config = $slots[$slotKey] ?? null;
        if (is_string($config)) {
            $config = ['enabled' => true, 'html' => $config];
        }
        if (!is_array($config) || !($config['enabled'] ?? true)) {
            return;
        }
        $html = trim((string) ($config['html'] ?? ''));
        if ($html === '') {
            return;
        }
        $safeHtml = dy_ad_safe_html($html);
        if (trim(strip_tags($safeHtml)) === '' && !str_contains($safeHtml, '<img')) {
            return;
        }
        echo '<aside class="ad-slot ad-slot-' . htmlspecialchars($slotKey, ENT_QUOTES, 'UTF-8') . '">' . $safeHtml . '</aside>';
    }

    function dy_ad_safe_html(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = preg_replace('/<\/?(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>/is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("|\')?\s*(javascript|data|vbscript):[^"\'>\s]*(\2)?/i', '', $html) ?? '';
        return strip_tags($html, '<a><img><p><span><strong><em><b><i><br><div>');
    }

    function dy_article_card(TemplateContext $context, array $item, bool $featured = false): void
    {
        $content = is_array($item['content'] ?? null) ? $item['content'] : $item;
        $url = dy_url($content);
        $categories = is_array($item['categories'] ?? null) ? $item['categories'] : [];
        ?>
    <?php
    $coverHtml = dy_first_image_html(is_array($item['media'] ?? null) ? $item['media'] : [], 'card-cover');
    ?>
    <article class="post-card<?= $featured ? ' post-card-featured' : '' ?><?= $coverHtml === '' ? ' post-card-no-cover' : '' ?>">
        <?= $coverHtml ?>
    <div class="card-body">
        <p class="meta">
            <?php if (($content['content_type'] ?? 'article') === 'page'): ?>页面<?php else: ?>文章<?php endif; ?>
            <?php if (dy_date($content['published_at'] ?? $item['published_at'] ?? '') !== ''): ?> · <time datetime="<?= $context->e((string) ($content['published_at'] ?? $item['published_at'] ?? '')) ?>"><?= $context->e(dy_date($content['published_at'] ?? $item['published_at'] ?? '')) ?></time><?php endif; ?>
        </p>
        <h2><a href="<?= $context->e($url) ?>"><?= $context->e($item['title'] ?? $content['title'] ?? '未命名内容') ?></a></h2>
        <p><?= $context->e(dy_excerpt($content, $featured ? 180 : 128)) ?></p>
        <?php if ($categories !== []): ?><div class="terms"><?php foreach ($categories as $term): ?><a href="/category/<?= $context->e(rawurlencode((string) ($term['slug'] ?? ''))) ?>"><?= $context->e($term['name'] ?? '') ?></a><?php endforeach; ?></div><?php endif; ?>
        <a class="read-more" href="<?= $context->e($url) ?>">阅读全文</a>
    </div>
</article>
<?php
    }

    function dy_css(TemplateContext $context): string
    {
        $primary = dy_color($context, 'primary_color', '#1f6feb');
        $accent = dy_color($context, 'accent_color', '#0f766e');
        $background = dy_color($context, 'background_color', '#f7f8fb');
        $surface = dy_color($context, 'surface_color', '#ffffff');
        $text = dy_color($context, 'text_color', '#172033');
        $muted = dy_color($context, 'muted_color', '#667085');
        $border = dy_color($context, 'border_color', '#d9e0ea');
        $logoHeight = dy_int($context, 'logo_height', 40, 28, 72);
        $headerHeight = dy_int($context, 'header_height', 68, 56, 92);
        $articleSize = dy_int($context, 'article_font_size', 17, 15, 20);
        $articleLine = dy_int($context, 'article_line_height', 178, 155, 195) / 100;
        $widthKey = dy_choice($context, 'site_width', 'standard', ['narrow', 'standard', 'wide']);
        $wrap = ['narrow' => '1040px', 'standard' => '1180px', 'wide' => '1320px'][$widthKey];
        $sticky = dy_bool($context, 'header_sticky', true) ? 'sticky' : 'relative';
        $customCss = str_replace('</style', '<\/style', dy_text($context, 'custom_css', ''));
        $darkMode = dy_choice($context, 'color_mode', 'light', ['light', 'dark', 'system']);
        $darkCss = $darkMode === 'dark' ? 'body{--theme-background:#101522;--theme-surface:#151c2b;--theme-text:#eef2ff;--theme-text-muted:#a8b3c7;--theme-border:#2b3548}' : '';
        $systemCss = $darkMode === 'system' ? '@media (prefers-color-scheme: dark){body{--theme-background:#101522;--theme-surface:#151c2b;--theme-text:#eef2ff;--theme-text-muted:#a8b3c7;--theme-border:#2b3548}}' : '';

        return <<<CSS
:root{--theme-primary:{$primary};--theme-secondary:#243149;--theme-accent:{$accent};--theme-background:{$background};--theme-surface:{$surface};--theme-text:{$text};--theme-text-muted:{$muted};--theme-border:{$border};--theme-link:{$primary};--theme-link-hover:{$accent};--theme-wrap:{$wrap};--logo-height:{$logoHeight}px;--header-height:{$headerHeight}px;--article-size:{$articleSize}px;--article-line:{$articleLine}}
{$darkCss}{$systemCss}
*{box-sizing:border-box}html{overflow-x:hidden;scroll-behavior:smooth}body{margin:0;background:var(--theme-background);color:var(--theme-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;line-height:1.65;text-rendering:optimizeLegibility}a{color:var(--theme-link);text-decoration:none}a:hover{color:var(--theme-link-hover);text-decoration:underline}img,video,audio,iframe{max-width:100%}img{height:auto}table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}td,th{border:1px solid var(--theme-border);padding:.7rem;text-align:left}pre{max-width:100%;overflow:auto;background:#101828;color:#f9fafb;padding:1rem;border-radius:8px}code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:rgba(31,111,235,.1);padding:.15rem .35rem;border-radius:4px}pre code{background:transparent;padding:0}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.wrap,.site-bar,.footer-inner{width:min(var(--theme-wrap),100% - 32px);margin:0 auto}.site-header{position:{$sticky};top:0;z-index:20;background:color-mix(in srgb,var(--theme-surface),transparent 5%);border-bottom:1px solid var(--theme-border);backdrop-filter:blur(14px)}.site-bar{min-height:var(--header-height);display:flex;align-items:center;justify-content:space-between;gap:1rem}.brand{display:flex;align-items:center;gap:.75rem;color:var(--theme-text);min-width:0}.brand:hover{text-decoration:none}.brand-logo{height:var(--logo-height);width:auto;max-width:180px;object-fit:contain}.brand-mark{width:var(--logo-height);height:var(--logo-height);border-radius:8px;background:linear-gradient(135deg,var(--theme-primary),var(--theme-accent));color:#fff;display:grid;place-items:center;font-weight:800}.brand strong{display:block;font-size:1rem;white-space:nowrap}.brand small{display:block;color:var(--theme-text-muted);font-size:.78rem;line-height:1.3;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.main-nav{display:flex;align-items:center;gap:.1rem}.main-nav a{color:var(--theme-text);padding:.48rem .7rem;border-radius:6px;font-weight:650}.main-nav a[aria-current=page],.main-nav a:hover{background:color-mix(in srgb,var(--theme-primary),transparent 90%);text-decoration:none;color:var(--theme-primary)}.nav-toggle,.nav-button{display:none}.hero{padding:3.4rem 0 2rem;background:linear-gradient(180deg,var(--theme-surface),var(--theme-background));border-bottom:1px solid var(--theme-border)}.compact-hero{padding:2.4rem 0 1.5rem}.hero-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);gap:2rem;align-items:center}.eyebrow,.entry-kicker{color:var(--theme-accent);font-weight:800;font-size:.9rem;letter-spacing:0}.hero h1{font-size:clamp(2.2rem,5vw,4.4rem);line-height:1.08;margin:.35rem 0 .8rem;letter-spacing:0}.hero p{max-width:760px;color:var(--theme-text-muted);font-size:1.08rem;margin:.4rem 0}.hero-panel{border:1px solid var(--theme-border);background:var(--theme-surface);padding:1.2rem;border-radius:8px}.hero-panel dl{margin:0;display:grid;gap:.75rem}.hero-panel div{display:flex;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--theme-border);padding-bottom:.65rem}.hero-panel div:last-child{border-bottom:0;padding-bottom:0}.button,.content-button,.back-link,.pagination a,.read-more{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border-radius:6px;background:var(--theme-primary);color:#fff;padding:.4rem .82rem;font-weight:750}.read-more{background:transparent;color:var(--theme-primary);padding:0;min-height:0}.section{padding:2.4rem 0}.section-head{display:flex;justify-content:space-between;gap:1rem;align-items:end;margin-bottom:1rem}.section h2{font-size:1.65rem;line-height:1.25;margin:0}.section-lead,.muted{color:var(--theme-text-muted)}.layout{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:2rem;padding:2rem 0 2.8rem}.layout-left_sidebar{grid-template-columns:290px minmax(0,1fr)}.layout-left_sidebar .sidebar{order:-1}.layout-full_width{grid-template-columns:1fr}.layout-full_width .sidebar{display:none}.post-list{display:grid;gap:1rem}.post-list-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.post-card{display:grid;grid-template-columns:170px minmax(0,1fr);gap:1rem;background:var(--theme-surface);border:1px solid var(--theme-border);border-radius:8px;overflow:hidden}.post-list-grid .post-card,.post-card-featured{grid-template-columns:1fr}.card-cover{width:100%;height:100%;min-height:145px;object-fit:cover;background:color-mix(in srgb,var(--theme-primary),transparent 92%)}.card-body{padding:1rem}.post-card h2{font-size:1.22rem;line-height:1.34;margin:.1rem 0 .45rem}.post-card h2 a{color:var(--theme-text)}.post-card p{margin:.45rem 0;color:var(--theme-text-muted)}.meta{color:var(--theme-text-muted);font-size:.88rem;margin:0 0 .6rem}.terms{display:flex;flex-wrap:wrap;gap:.4rem;margin:.75rem 0}.terms a{font-size:.82rem;color:var(--theme-text);background:color-mix(in srgb,var(--theme-primary),transparent 91%);border-radius:999px;padding:.12rem .55rem}.sidebar{display:grid;align-content:start;gap:1rem}.side-block{border:1px solid var(--theme-border);border-radius:8px;background:var(--theme-surface);padding:1rem}.side-block h2{font-size:1rem;margin:0 0 .55rem}.side-block p,.side-block li{color:var(--theme-text-muted)}.side-block ul{margin:.2rem 0 0;padding-left:1.2rem}.empty{background:var(--theme-surface);border:1px dashed var(--theme-border);border-radius:8px;padding:1.7rem;color:var(--theme-text-muted)}.pagination{display:flex;justify-content:space-between;gap:.8rem;margin-top:1.2rem}.pagination span{color:var(--theme-text-muted);align-self:center}.entry-shell{width:min(860px,100% - 32px);margin:0 auto;padding:2rem 0 2.8rem}.entry{background:var(--theme-surface);border:1px solid var(--theme-border);border-radius:8px;padding:clamp(1.1rem,4vw,2.3rem)}.entry-header{margin-bottom:1.4rem}.entry h1{font-size:clamp(2rem,4vw,3.1rem);line-height:1.15;margin:.1rem 0 .8rem}.entry-summary{font-size:1.08rem;color:var(--theme-text-muted)}.entry-cover{width:100%;max-height:440px;object-fit:cover;border-radius:8px;margin:0 0 1.2rem}.entry-content{font-size:var(--article-size);line-height:var(--article-line)}.entry-content h2,.entry-content h3,.entry-content h4{line-height:1.35;margin:1.8rem 0 .7rem}.entry-content p,.entry-content ul,.entry-content ol,.entry-content blockquote,.entry-content table,.entry-content figure,.entry-content pre{margin:1rem 0}.entry-content blockquote{border-left:4px solid var(--theme-accent);padding:.2rem 0 .2rem 1rem;color:var(--theme-text-muted);background:color-mix(in srgb,var(--theme-accent),transparent 94%)}.entry-content img,.media-gallery img{border-radius:8px}.media-gallery{display:grid;grid-template-columns:repeat(var(--columns,3),minmax(0,1fr));gap:.75rem}.media-audio audio,.media-video video{width:100%}.media-missing,.missing-extension{border:1px dashed var(--theme-border);background:color-mix(in srgb,var(--theme-primary),transparent 94%);color:var(--theme-text-muted);border-radius:8px;padding:1rem}.entry-footer{border-top:1px solid var(--theme-border);margin-top:1.8rem;padding-top:1rem}.post-nav{display:flex;justify-content:space-between;gap:1rem;margin-top:1rem}.not-found{min-height:56vh;display:grid;place-items:center;text-align:center}.search-form,.search-panel{display:flex;gap:.6rem;margin-top:1rem}.search-form input,.search-panel input{flex:1;min-height:42px;border:1px solid var(--theme-border);border-radius:6px;padding:0 .8rem;background:var(--theme-surface);color:var(--theme-text)}.search-panel{max-width:760px}.search-panel button{min-height:42px;border:0;border-radius:6px;background:var(--theme-primary);color:#fff;padding:0 1rem;font-weight:750;cursor:pointer}.result-count{margin:0;color:var(--theme-text-muted)}.site-footer{border-top:1px solid var(--theme-border);background:var(--theme-surface);margin-top:2rem}.footer-inner{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1.2rem;padding:1.6rem 0;color:var(--theme-text-muted);font-size:.92rem}.footer-two .footer-inner{grid-template-columns:repeat(2,minmax(0,1fr))}.footer-three .footer-inner{grid-template-columns:1.4fr 1fr 1fr}.footer-one .footer-inner{grid-template-columns:1fr}.footer-inner h2{font-size:1rem;color:var(--theme-text);margin:.1rem 0 .5rem}.footer-inner ul{margin:0;padding:0;list-style:none}.footer-inner li{margin:.25rem 0}.ad-slot{width:min(var(--theme-wrap),100% - 32px);margin:1rem auto;padding:.85rem 1rem;border:1px dashed var(--theme-border);border-radius:8px;background:var(--theme-surface);color:var(--theme-text-muted);text-align:center;font-size:.95rem}.entry .ad-slot,.sidebar .ad-slot{width:100%;margin:1rem 0}
p,h1,h2,h3,h4,h5,h6,li,td,th,a{overflow-wrap:anywhere}
.post-card-no-cover{grid-template-columns:1fr}
.comments{border-top:1px solid var(--theme-border);margin-top:1.6rem;padding-top:1.4rem}.comments-head{display:flex;align-items:center;justify-content:space-between;gap:1rem}.comments h2{font-size:1.35rem;margin:0}.comments-head span,.comment-login-state{color:var(--theme-text-muted)}.comment-list{list-style:none;margin:1rem 0;padding:0;display:grid;gap:.8rem}.comment-item{border:1px solid var(--theme-border);border-radius:8px;padding:.9rem;background:color-mix(in srgb,var(--theme-surface),var(--theme-background) 35%)}.comment-item time{display:block;color:var(--theme-text-muted);font-size:.84rem}.comment-item p{margin:.55rem 0 0;white-space:pre-wrap}.comment-form{margin-top:1rem;display:grid;gap:.8rem}.comment-form label{font-weight:750}.comment-form input,.comment-form textarea{width:100%;margin-top:.35rem;border:1px solid var(--theme-border);border-radius:6px;background:var(--theme-surface);color:var(--theme-text);padding:.65rem}.comment-fields{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}.comment-form button,.inline-logout button{width:max-content;border:0;border-radius:6px;background:var(--theme-primary);color:#fff;padding:.55rem .9rem;font-weight:750;cursor:pointer}.inline-logout{margin:.3rem 0 1rem}.inline-logout button{background:transparent;color:var(--theme-primary);border:1px solid var(--theme-border)}.comment-notice,.comment-error{border-radius:6px;padding:.65rem .75rem}.comment-notice{background:color-mix(in srgb,var(--theme-accent),transparent 88%);color:var(--theme-accent)}.comment-error{background:#fff1f0;border:1px solid #ffccc7;color:#8c1d18}
{$customCss}
@media (max-width: 860px){.hero-grid,.layout,.layout-left_sidebar,.post-list-grid,.footer-inner,.footer-two .footer-inner,.footer-three .footer-inner,.comment-fields{grid-template-columns:1fr}.layout-left_sidebar .sidebar{order:0}.site-bar{min-height:64px}.nav-button{display:grid;width:42px;height:42px;place-items:center;border:1px solid var(--theme-border);border-radius:8px;cursor:pointer}.nav-button span{display:block;width:20px;height:2px;background:var(--theme-text);margin:2px 0}.main-nav{display:none;position:absolute;left:16px;right:16px;top:64px;background:var(--theme-surface);border:1px solid var(--theme-border);border-radius:8px;padding:.6rem;box-shadow:0 18px 40px rgba(15,23,42,.12)}.nav-toggle:checked~.main-nav{display:grid}.main-nav a{padding:.75rem}.brand small{display:none}.layout{gap:1.2rem;padding:1.2rem 0 2rem}.hero{padding:2rem 0 1rem}.post-card{grid-template-columns:1fr}.card-cover{height:auto;aspect-ratio:16/9}.entry{border-left:0;border-right:0;border-radius:0}.entry-shell{width:100%;padding:1rem 0 2rem}.entry>*{width:min(100% - 32px,860px);margin-left:auto;margin-right:auto}.media-gallery{grid-template-columns:1fr 1fr}.post-nav,.pagination{flex-wrap:wrap}.pagination span{order:-1;width:100%;text-align:center}.search-form,.search-panel{display:block}.search-form input,.search-panel input{width:100%;margin-bottom:.6rem}.search-panel button{width:100%}}
@media (max-width: 420px){.site-bar,.wrap,.footer-inner{width:min(100% - 24px,var(--theme-wrap))}.brand-mark{width:36px;height:36px}.brand strong{font-size:.98rem}.hero h1{font-size:2rem}.entry h1{font-size:1.75rem}.media-gallery{grid-template-columns:1fr}}
@media (max-width: 760px){.hero-grid,.layout,.layout-left_sidebar,.post-list-grid{grid-template-columns:1fr}.post-card{grid-template-columns:1fr}}
@media (max-width: 380px){.site-bar,.wrap,.footer-inner{width:min(100% - 24px,var(--theme-wrap))}.hero h1{font-size:2rem}.entry h1{font-size:1.75rem}}
CSS;
    }
}
