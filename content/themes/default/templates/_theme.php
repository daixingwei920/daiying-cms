<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

if (!function_exists('dy_setting')) {
    function dy_setting(TemplateContext $context, string $key, string $default = ''): string
    {
        $value = trim((string) $context->get($key, ''));
        return $value !== '' ? $value : trim((string) $context->setting($key, $default));
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
            if ($type === 'paragraph' || $type === 'quote') {
                $parts[] = (string) ($data['text'] ?? '');
            } elseif ($type === 'heading') {
                $parts[] = (string) ($data['text'] ?? '');
            } elseif (in_array($type, ['unordered_list', 'ordered_list'], true)) {
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

    function dy_head(TemplateContext $context, string $title, array $seo = [], string $type = 'website'): void
    {
        $siteName = dy_site_name($context);
        $pageTitle = trim((string) ($seo['title'] ?? $title));
        $description = trim((string) ($seo['description'] ?? dy_setting($context, 'site_description', '')));
        if ($description === '') {
            $description = $pageTitle !== '' ? $pageTitle : $siteName;
        }
        $canonical = trim((string) ($seo['canonical'] ?? $context->get('canonical', '')));
        ?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $context->e($pageTitle !== '' ? $pageTitle : $siteName) ?></title>
    <meta name="description" content="<?= $context->e($description) ?>">
    <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $context->e($canonical) ?>"><?php endif; ?>
    <meta name="robots" content="<?= $context->e($seo['robots'] ?? 'index,follow') ?>">
    <meta property="og:title" content="<?= $context->e($pageTitle !== '' ? $pageTitle : $siteName) ?>">
    <meta property="og:description" content="<?= $context->e($description) ?>">
    <meta property="og:type" content="<?= $context->e($seo['og_type'] ?? $type) ?>">
    <?php if ($canonical !== ''): ?><meta property="og:url" content="<?= $context->e($canonical) ?>"><?php endif; ?>
    <style><?= dy_css($context) ?></style>
</head>
<?php
    }

    function dy_header(TemplateContext $context, string $current = ''): void
    {
        $siteName = dy_site_name($context);
        $description = dy_setting($context, 'site_description', '记录、发布与分享');
        $initial = function_exists('mb_substr') ? mb_substr($siteName, 0, 1, 'UTF-8') : substr($siteName, 0, 1);
        $navigation = dy_navigation_items($context);
        ?>
<header class="site-header">
    <div class="site-bar">
        <a class="brand" href="/" aria-label="<?= $context->e($siteName) ?>">
            <span class="brand-mark" aria-hidden="true"><?= $context->e($initial) ?></span>
            <span><strong><?= $context->e($siteName) ?></strong><small><?= $context->e($description) ?></small></span>
        </a>
        <input class="nav-toggle" id="nav-toggle" type="checkbox" aria-label="展开导航">
        <label class="nav-button" for="nav-toggle"><span></span><span></span><span></span></label>
        <nav class="main-nav" aria-label="主导航">
            <?php foreach ($navigation as $item): ?>
                <a href="<?= $context->e($item['url']) ?>"<?= dy_nav_current($current, $item) ? ' aria-current="page"' : '' ?>><?= $context->e($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<?php
    }

    /** @return list<array{label:string,url:string,type:string}> */
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
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $clean[] = ['label' => $label, 'url' => $url, 'type' => (string) ($item['type'] ?? 'custom')];
        }

        return $clean !== [] ? $clean : [
            ['label' => '首页', 'url' => '/', 'type' => 'home'],
            ['label' => '文章', 'url' => '/articles', 'type' => 'articles'],
        ];
    }

    /** @param array{label:string,url:string,type:string} $item */
    function dy_nav_current(string $current, array $item): bool
    {
        return $current !== '' && $current === (string) ($item['type'] ?? '');
    }

    function dy_footer(TemplateContext $context): void
    {
        ?>
<?php dy_ad_slot($context, 'footer_top'); ?>
<footer class="site-footer">
    <div class="footer-inner">
        <p>&copy; <?= date('Y') ?> <?= $context->e(dy_site_name($context)) ?></p>
        <p>Powered by Daiying CMS</p>
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

        $slots = $context->get('ad_slots', $context->setting('ad_slots', []));
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

        $label = trim((string) ($config['label'] ?? '广告位'));
        $safeHtml = dy_ad_safe_html($html);
        if (trim(strip_tags($safeHtml)) === '' && !str_contains($safeHtml, '<img')) {
            return;
        }

        echo '<aside class="ad-slot ad-slot-' . htmlspecialchars($slotKey, ENT_QUOTES, 'UTF-8') . '" aria-label="' . $context->e($label) . '">' . $safeHtml . '</aside>';
    }

    function dy_ad_safe_html(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = preg_replace('/<\/?(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>/is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*(javascript|data|vbscript):.*?\2/i', '', $html) ?? '';
        $html = preg_replace_callback('/\s+(href|src)\s*=\s*([^\s>]+)\s*/i', static function (array $matches): string {
            $value = trim($matches[2], " \t\n\r\0\x0B'\"");
            if (preg_match('/^(javascript|data|vbscript):/i', $value)) {
                return ' ';
            }
            return $matches[0];
        }, $html) ?? '';

        return strip_tags($html, '<a><img><p><span><strong><em><b><i><br><div>');
    }

    function dy_article_card(TemplateContext $context, array $item): void
    {
        $content = is_array($item['content'] ?? null) ? $item['content'] : $item;
        $url = dy_url($content);
        $categories = is_array($item['categories'] ?? null) ? $item['categories'] : [];
        ?>
<article class="post-card">
    <?= dy_first_image_html(is_array($item['media'] ?? null) ? $item['media'] : [], 'card-cover') ?>
    <div class="card-body">
        <p class="meta">
            <?php if (($content['content_type'] ?? 'article') === 'page'): ?>页面<?php else: ?>文章<?php endif; ?>
            <?php if (dy_date($content['published_at'] ?? $item['published_at'] ?? '') !== ''): ?> · <time datetime="<?= $context->e((string) ($content['published_at'] ?? $item['published_at'] ?? '')) ?>"><?= $context->e(dy_date($content['published_at'] ?? $item['published_at'] ?? '')) ?></time><?php endif; ?>
        </p>
        <h2><a href="<?= $context->e($url) ?>"><?= $context->e($item['title'] ?? $content['title'] ?? '未命名内容') ?></a></h2>
        <p><?= $context->e(dy_excerpt($content)) ?></p>
        <?php if ($categories !== []): ?>
            <div class="terms"><?php foreach ($categories as $term): ?><a href="/category/<?= $context->e($term['slug'] ?? '') ?>"><?= $context->e($term['name'] ?? '') ?></a><?php endforeach; ?></div>
        <?php endif; ?>
        <a class="read-more" href="<?= $context->e($url) ?>">阅读全文</a>
    </div>
</article>
<?php
    }

    function dy_css(TemplateContext $context): string
    {
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $context->setting('accent_color', '#1f6feb')) ? (string) $context->setting('accent_color', '#1f6feb') : '#1f6feb';
        return <<<CSS
:root{--accent:{$accent};--ink:#172033;--muted:#667085;--line:#d9e0ea;--soft:#f6f7f9;--card:#fff;--focus:#b45309}
*{box-sizing:border-box}html{overflow-x:hidden}body{margin:0;background:#fbfbfc;color:var(--ink);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;line-height:1.75;text-rendering:optimizeLegibility;overflow-wrap:anywhere}a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid color-mix(in srgb,var(--accent),white 65%);outline-offset:3px}img,video,audio,iframe{max-width:100%}img{height:auto}table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}td,th{border:1px solid var(--line);padding:.65rem;text-align:left}pre{max-width:100%;overflow:auto;background:#101828;color:#f9fafb;padding:1rem;border-radius:8px}code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#eef2f6;padding:.15rem .35rem;border-radius:4px}pre code{background:transparent;padding:0}.site-header{background:rgba(255,255,255,.96);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}.site-bar,.wrap,.footer-inner{width:min(1120px,100% - 32px);margin:0 auto}.site-bar{min-height:72px;display:flex;align-items:center;justify-content:space-between;gap:1rem}.brand{display:flex;align-items:center;gap:.75rem;color:var(--ink)}.brand:hover{text-decoration:none}.brand-mark{width:40px;height:40px;border-radius:8px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800}.brand strong{display:block;font-size:1.05rem}.brand small{display:block;color:var(--muted);font-size:.78rem;line-height:1.3}.main-nav{display:flex;align-items:center;gap:.2rem}.main-nav a{color:#344054;padding:.55rem .75rem;border-radius:6px}.main-nav a[aria-current=page],.main-nav a:hover{background:#eef2f6;text-decoration:none;color:var(--ink)}.nav-toggle,.nav-button{display:none}.hero{padding:3rem 0 1.4rem;border-bottom:1px solid var(--line);background:#fff}.hero h1{font-size:clamp(2rem,5vw,3.8rem);line-height:1.12;margin:.25rem 0 .8rem;letter-spacing:0}.hero p{max-width:720px;color:#475467;font-size:1.08rem;margin:0}.layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:2rem;padding:2rem 0 2.5rem}.post-list{display:grid;gap:1rem}.post-card{display:grid;grid-template-columns:180px minmax(0,1fr);gap:1rem;background:var(--card);border:1px solid var(--line);border-radius:8px;overflow:hidden}.card-cover{width:100%;height:100%;min-height:150px;object-fit:cover;background:#eef2f6}.card-body{padding:1rem}.post-card h2{font-size:1.35rem;line-height:1.32;margin:.1rem 0 .45rem}.post-card h2 a{color:var(--ink)}.post-card p{margin:.45rem 0;color:#475467}.meta{color:var(--muted);font-size:.92rem;margin:0 0 .65rem}.terms{display:flex;flex-wrap:wrap;gap:.4rem;margin:.7rem 0}.terms a{font-size:.82rem;color:#344054;background:#eef2f6;border-radius:999px;padding:.15rem .55rem}.read-more{font-weight:700}.sidebar{display:grid;align-content:start;gap:1rem}.side-block{border:1px solid var(--line);border-radius:8px;background:#fff;padding:1rem}.side-block h2{font-size:1rem;margin:0 0 .55rem}.side-block p,.side-block li{color:#475467}.side-block ul{margin:.2rem 0 0;padding-left:1.2rem}.empty{background:#fff;border:1px dashed var(--line);border-radius:8px;padding:2rem;color:#475467}.pagination{display:flex;justify-content:space-between;gap:.8rem;margin-top:1.2rem}.pagination a,.back-link,.content-button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:6px;background:var(--accent);color:#fff;padding:.45rem .85rem;font-weight:700}.pagination span{color:var(--muted);align-self:center}.entry-shell{width:min(820px,100% - 32px);margin:0 auto;padding:2rem 0 2.8rem}.entry{background:#fff;border:1px solid var(--line);border-radius:8px;padding:clamp(1.1rem,4vw,2.2rem)}.entry-header{margin-bottom:1.4rem}.entry-kicker{color:var(--accent);font-weight:800;margin:0 0 .35rem}.entry h1{font-size:clamp(2rem,4vw,3rem);line-height:1.16;margin:.1rem 0 .8rem}.entry-content{font-size:1.06rem}.entry-content h2,.entry-content h3,.entry-content h4{line-height:1.35;margin:1.8rem 0 .7rem}.entry-content h5,.entry-content h6{line-height:1.4;margin:1.4rem 0 .5rem}.entry-content p,.entry-content ul,.entry-content ol,.entry-content blockquote,.entry-content table,.entry-content figure,.entry-content pre{margin:1rem 0}.entry-content blockquote{border-left:4px solid var(--accent);padding:.2rem 0 .2rem 1rem;color:#475467;background:#f8fafc}.entry-content img,.media-gallery img{border-radius:8px}.media-gallery{display:grid;grid-template-columns:repeat(var(--columns,3),minmax(0,1fr));gap:.75rem}.media-audio audio,.media-video video{width:100%}.media-missing,.missing-extension{border:1px dashed var(--line);background:#f8fafc;color:#667085;border-radius:8px;padding:1rem}.entry-footer{border-top:1px solid var(--line);margin-top:1.8rem;padding-top:1rem}.post-nav{display:flex;justify-content:space-between;gap:1rem;margin-top:1rem}.page-entry .entry-kicker{color:#667085}.not-found{min-height:50vh;display:grid;place-items:center;text-align:center}.site-footer{border-top:1px solid var(--line);background:#fff}.footer-inner{display:flex;justify-content:space-between;gap:1rem;padding:1.2rem 0;color:#667085;font-size:.92rem}
.ad-slot{width:min(1120px,100% - 32px);margin:1rem auto;padding:.85rem 1rem;border:1px dashed var(--line);border-radius:8px;background:#fff;color:#475467;text-align:center;font-size:.95rem}.entry .ad-slot{width:100%;margin:1rem 0}.sidebar .ad-slot{width:100%;margin:0}.ad-slot img{border-radius:6px}.ad-slot p{margin:.25rem 0}
@media (max-width: 760px){.site-bar{min-height:64px}.nav-button{display:grid;width:42px;height:42px;place-items:center;border:1px solid var(--line);border-radius:8px;cursor:pointer}.nav-button span{display:block;width:20px;height:2px;background:#344054;margin:2px 0}.main-nav{display:none;position:absolute;left:16px;right:16px;top:64px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:.6rem;box-shadow:0 18px 40px rgba(15,23,42,.12)}.nav-toggle:checked~.main-nav{display:grid}.main-nav a{padding:.75rem}.brand small{display:none}.layout{grid-template-columns:1fr;gap:1.2rem;padding:1.2rem 0 2rem}.hero{padding:2rem 0 1rem}.post-card{grid-template-columns:1fr}.card-cover{height:auto;aspect-ratio:16/9}.sidebar{display:none}.entry{border-left:0;border-right:0;border-radius:0}.entry-shell{width:100%;padding:1rem 0 2rem}.entry>*{width:min(100% - 32px,820px);margin-left:auto;margin-right:auto}.entry-content{font-size:1rem}.media-gallery{grid-template-columns:1fr 1fr}.post-nav,.footer-inner{flex-direction:column}.pagination{flex-wrap:wrap}.pagination span{order:-1;width:100%;text-align:center}}
@media (max-width: 380px){.site-bar,.wrap,.footer-inner{width:min(100% - 24px,1120px)}.brand-mark{width:36px;height:36px}.brand strong{font-size:.98rem}.hero h1{font-size:2rem}.post-card h2{font-size:1.18rem}.entry h1{font-size:1.75rem}.media-gallery{grid-template-columns:1fr}}
CSS;
    }
}
