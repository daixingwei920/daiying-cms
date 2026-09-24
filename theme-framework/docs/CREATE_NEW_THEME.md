# Create A New Daiying CMS Theme

This guide starts from `starters/blank/`.

## Isolated Executor Validation

This short section is used to validate isolated executor workspace changes before any source update.

## 1. Copy Starter

Copy:

```text
starters/blank/
```

to a new working directory, then rename the theme folder and update `theme.json`.

## 2. Required Files

Minimum:

```text
theme.json
_theme.php
templates/home.php
templates/list.php
templates/content.php
templates/error.php
assets/css/theme.css
assets/js/theme.js
```

## 3. Use Theme API

Use `$context` only:

```php
$items = $context->get('items', []);
$url = $context->asset('css/theme.css');
$seo = $context->seo();
```

Do not read database tables or repositories from templates.

## 4. Content Links

Create one helper:

```php
function theme_content_url(array $item): string {
    if (!empty($item['url'])) return (string) $item['url'];
    $content = is_array($item['content'] ?? null) ? $item['content'] : [];
    if (!empty($content['url'])) return (string) $content['url'];
    $slug = trim((string)($item['slug'] ?? $content['slug'] ?? ''), '/');
    if ($slug === '') return '';
    return (($item['content_type'] ?? $content['content_type'] ?? 'article') === 'article' ? '/articles/' : '/') . rawurlencode($slug);
}
```

Use it for all article/page links.

`/articles`, search, and taxonomy list items may be ViewModel wrappers. In that shape, the raw content record lives under `$item['content']`. Do not assume list cards always receive top-level `slug`, `url`, or `content_type`.

## 5. Pagination

The `/articles` route provides:

```php
'items' => [...],
'pagination' => [
  'page' => 1,
  'per_page' => 10,
  'total' => 42
]
```

Build page URLs through:

```php
$pager = $context->pagination($page, $totalPages, '/articles', [], $perPage, $totalItems);
```

## 6. Logo

Resolve in this order:

1. Theme logo override
2. Site global logo
3. Site name text

Logo links to `/` and must preserve aspect ratio.

## 7. Package

Market package layout:

```text
market-package.json
content/themes/{theme_id}/theme.json
content/themes/{theme_id}/templates/...
content/themes/{theme_id}/assets/...
```

Each market file must declare SHA-256.
