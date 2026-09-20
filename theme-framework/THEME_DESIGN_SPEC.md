# Theme Design Spec

## Theme API

Daiying CMS themes should use `Cms\Core\Theme\TemplateContext` as the stable entry point.

Common methods:

- `$context->get($key, $default)`
- `$context->setting($key, $default)`
- `$context->asset($relativePath)`
- `$context->url($path, $query)`
- `$context->pagination($current, $totalPages, $basePath, $query, $perPage, $totalItems)`
- `$context->menu('primary')`
- `$context->seo()`

Templates must not access CMS repositories, database connections, private controllers, or internal services directly.

## Standard Templates

Official themes should provide:

- `templates/home.php`
- `templates/list.php`
- `templates/content.php`
- `templates/error.php`

Optional templates such as `templates/search.php` are allowed when the theme supports a distinct search layout.

## Assets

Use:

```php
$context->asset('css/theme.css')
```

Do not use:

```php
$context->asset('assets/css/theme.css')
```

The argument is relative to the theme `assets/` directory.

## Content Permalinks

Themes must not assume `$item['url']` always exists.

Themes must also not assume list items expose `slug` and `content_type` at the top level. In Daiying CMS 1.2.65 list routes, each item may be a content ViewModel wrapper with the raw content record under `$item['content']`.

If a stable Core permalink field exists in the ViewModel, use it first. Current Theme API V1 reference behavior is:

- Article: `/articles/{rawurlencode(slug)}`
- Page: `/{rawurlencode(slug)}`

Do not use `#`, page anchors, or `javascript:void(0)` as article/page permalink fallbacks.

Recommended helper:

```php
function theme_content_url(array $item): string {
    if (!empty($item['url'])) {
        return (string) $item['url'];
    }
    $content = is_array($item['content'] ?? null) ? $item['content'] : [];
    if (!empty($content['url'])) {
        return (string) $content['url'];
    }
    $slug = trim((string) ($item['slug'] ?? $content['slug'] ?? ''), '/');
    if ($slug === '') {
        return '';
    }
    return (($item['content_type'] ?? $content['content_type'] ?? 'article') === 'article' ? '/articles/' : '/') . rawurlencode($slug);
}
```

Use the same helper in home cards, grids, banners, lists, search results, related content, and adjacent navigation.

## Logo / Brand

Logo resolution order:

1. Theme Logo override
2. Site Global Logo
3. Site Name text fallback

If a site global logo is configured, a theme must not replace it with text or a bundled brand image by default. Themes must not require users to re-upload a logo after switching themes.

Logo links must point to `/`. Desktop and mobile headers should use the same resolver. Images must preserve aspect ratio and must not be stretched.

## Publishing Gate

Theme review should block release if:

- Content cards link to `#` instead of real content permalinks.
- Content cards render empty links because the helper only reads top-level `slug/url` and ignores nested `content.slug/content.url`.
- Theme assets use `assets/assets/...`.
- PHP templates access private CMS storage or repositories.
- Theme requires manual Apache/Nginx changes.
- Market package files do not declare SHA-256.
