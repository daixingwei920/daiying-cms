<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

/** Full-page theme enhancement; no theme source rewrite or new theme API. */
final class SeoHeadRenderer
{
    /** @param array<string,mixed> $data */
    public static function render(string $html, string $template, array $data): string
    {
        if (!preg_match('/<head\b[^>]*>.*?<\/head\s*>/is', $html, $match, PREG_OFFSET_CAPTURE)) { return $html; }
        $head = self::renderHead($match[0][0], $template, $data);
        return substr_replace($html, $head, $match[0][1], strlen($match[0][0]));
    }

    /** @param array<string,mixed> $data */
    private static function renderHead(string $html, string $template, array $data): string
    {
        if (str_contains($html, 'data-cms-seo="1"') || stripos($html, '</head>') === false || !in_array($template, ['home', 'content', 'list', 'taxonomy'], true)) {
            return $html;
        }
        $seo = is_array($data['seo'] ?? null) ? $data['seo'] : [];
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        foreach ($seo['_core_tdk_replace'] ?? [] as $key) {
            $tag = $key === 'title' ? '<title>' . $e($seo[$key] ?? '') . '</title>'
                : '<meta name="' . $key . '" content="' . $e($seo[$key] ?? '') . '">';
            if ($key === 'title') {
                $html = preg_replace('/<title\b[^>]*>.*?<\/title\s*>/is', '', $html) ?? $html;
            } else {
                $html = preg_replace_callback('/<meta\b[^>]*>/i', static function (array $m) use ($key): string {
                    return preg_match('/\bname\s*=\s*([\'"])' . $key . '\1/i', $m[0]) ? '' : $m[0];
                }, $html) ?? $html;
            }
            $html = self::head($html, $tag);
        }
        if (!($seo['_core_schema_enabled'] ?? false) || !empty($data['preview'])
            || !empty($data['paid_content']['enabled']) || str_contains((string) ($seo['robots'] ?? ''), 'noindex')) {
            return $html;
        }
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        if ($template === 'content' && ($content['status'] ?? '') !== 'published') {
            return $html;
        }
        $url = (string) ($seo['canonical'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $html;
        }
        $type = $template === 'home' ? 'WebSite' : ($template === 'content'
            ? (($content['content_type'] ?? '') === 'article' ? 'Article' : 'WebPage') : 'CollectionPage');
        $default = ['@context' => 'https://schema.org', '@type' => $type, 'url' => $url,
            'name' => (string) ($seo['title'] ?? $data['site_name'] ?? ''),
            'description' => (string) ($seo['description'] ?? '')];
        if ($type === 'Article') { $default['headline'] = $default['name']; }
        $context = ['seo' => array_intersect_key($seo, array_flip(['title', 'description', 'keywords', 'canonical', 'robots'])),
            'site_name' => (string) ($data['site_name'] ?? ''), 'template' => $template, 'url' => $url];
        // Providers receive only public metadata, never the view's blocks/session.
        try { $extraMeta = SeoExtensionRegistry::meta($context); } catch (\Throwable) { $extraMeta = []; }
        foreach ($extraMeta as $name => $value) {
            if (!preg_match('/^(?:og:|twitter:)[a-zA-Z0-9:_-]{1,80}$/', $name)) { continue; }
            $pattern = '/<(?:meta)\b[^>]*(?:name|property)\s*=\s*([\'\"])' . preg_quote($name, '/') . '\1/i';
            if (!preg_match($pattern, $html)) {
                $html = self::head($html, '<meta ' . (str_starts_with($name, 'og:') ? 'property' : 'name') . '="' . $e($name) . '" content="' . $e($value) . '">');
            }
        }
        try { $items = SeoExtensionRegistry::jsonLd($context); } catch (\Throwable) { $items = []; }
        $existingTypes = [];
        if (preg_match_all('/<script\b[^>]*type\s*=\s*([\'"])application\/ld\+json\1[^>]*>(.*?)<\/script\s*>/is', $html, $matches)) {
            foreach ($matches[2] as $json) { self::types(json_decode($json, true), $existingTypes); }
        }
        foreach ($items as $item) { self::types($item, $existingTypes); }
        if (!isset($existingTypes[$type])) { array_unshift($items, $default); }
        foreach ($items as $item) {
            $json = json_encode($item, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) { $html = self::head($html, '<script type="application/ld+json" data-cms-seo="1">' . $json . '</script>'); }
        }
        return $html;
    }

    /** @param array<string,bool> $types */
    private static function types(mixed $value, array &$types): void
    {
        if (!is_array($value)) { return; }
        foreach ((array) ($value['@type'] ?? []) as $type) { if (is_string($type)) { $types[$type] = true; } }
        foreach ($value as $child) { if (is_array($child)) { self::types($child, $types); } }
    }

    private static function head(string $html, string $tag): string
    {
        $offset = stripos($html, '</head>');
        return $offset === false ? $html : substr_replace($html, $tag . "\n", $offset, 0);
    }
}
