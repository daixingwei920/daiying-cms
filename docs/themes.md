# Themes

Daiying CMS supports independent themes under `content/themes`.

The stable theme developer contract is documented in
[`THEME_API_V1.md`](../THEME_API_V1.md).

## Current Bundled Themes

| Theme ID | Version | Content Types | Notes |
| --- | --- | --- | --- |
| `default` | `1.0.0` | `article`, `page` | Default CMS theme. |
| `daiying_media` | `1.0.5` | `article`, `page` | Media/article oriented theme. |
| `daiying_novel` | `1.0.2` | `novel`, `novel_author`, `novel_chapter` | Novel theme; requires `official.novel-collector`. |
| `daiying-video` | `1.0.0` | `video`, `video_episode`, `short_drama` | Video theme; requires `official.video-collector`. |
| `safe` | `1.0.0` | `article`, `page` | Recovery fallback theme. |

## Manifest Basics

Themes use `theme.json`. Current manifests may include:

- `theme_id`
- `product_id`
- `name`
- `version`
- `author`
- `description`
- `core`
- `content_types`
- `recommended_plugins`
- `required_plugins`
- `package_type`
- `market_release`
- `settings_schema`
- `theme_api`

## Theme Behavior

Core theme infrastructure includes:

- Theme discovery.
- Compatibility checks.
- Settings isolation.
- Safe-theme fallback.
- Template context rendering.
- Stable TemplateContext helpers for assets, menus, media view models, SEO data,
  pagination, and breadcrumbs.
- Theme package installation.

## Required Template Files

Standard themes should provide these templates:

- `templates/home.php`
- `templates/list.php`
- `templates/content.php`
- `templates/error.php`

Additional templates may be provided for theme-specific presentation, but the
standard templates keep the theme compatible with Core fallback and content
rendering.

## Content Permalinks

Themes must treat content permalinks as part of the Theme API contract.

Do not assume content arrays always contain `url`. `$item['url']` and
`$content['url']` are optional compatibility fields, not the only source of
truth for linking article and page cards.

Theme API v1 currently has no public `TemplateContext::permalink()` or
`TemplateContext::contentUrl()` helper. Core has internal URL builders, but
themes must not call private Core controllers or repositories. Until a stable
public helper is added, use the Default Theme reference rule:

```php
$slug = trim((string) ($content['slug'] ?? ''), '/');

return (($content['content_type'] ?? 'article') === 'article'
    ? '/articles/'
    : '/') . rawurlencode($slug);
```

Standard Content Permalink V1:

- Articles: `/articles/{rawurlencode(slug)}`
- Pages: `/{rawurlencode(slug)}`

Themes should implement one local helper, such as `theme_content_url($content)`,
and call it from every content card or content list:

- Home
- Grid
- Banner
- Magazine
- List
- Search
- Related Content

Do not use `#`, `#gf-content`, `javascript:void(0)`, or another placeholder as
the fallback for article or page links. Page anchors are allowed only for
intentional current-page navigation, such as a hero button scrolling to a
home-page section. They are not valid article/page permalinks.

Theme release review should block packages when article or page cards use
placeholders or page anchors instead of real permalinks.

Required permalink review cases:

- Home to Article
- Grid to Article
- Banner to Article
- Magazine to Article
- List to Article
- Search to Article
- Page link to Page
- Chinese or special-character slug URL encoding
- Missing `url` field still produces the correct permalink

## Adjacent Article Navigation

Article detail pages may receive `previous` and `next` fields from Core. Each
field is either `null` or a published article summary containing at least:

- `id`
- `title`
- `slug`
- `content_type`
- `url`
- `published_at`

Optional fields such as `cover` and `excerpt` may also be present.

Ordering is part of the Theme API contract. Core sorts published articles by
`published_at`, then by `id`. `previous` is the nearest earlier article;
`next` is the nearest newer article. The first or last article receives `null`
on the missing side.

Themes should read `$context->get('previous')` and `$context->get('next')`.
Use the adjacent item's `url` first; fall back to the theme's local content URL
helper only for older Core releases. Do not access the database, repositories,
or private controllers to find adjacent content.

`previous` / `next` belong only to article detail ViewModels. List, home,
category, tag, and search ViewModels must not expose these keys (including
as `null`).

Example:

```php
$previous = $context->get('previous');
$next = $context->get('next');
if (is_array($previous)) {
    echo '<a href="' . $context->e($previous['url']) . '">' . $context->e($previous['title']) . '</a>';
}
```

Adjacent navigation review cases:

- Middle article shows both previous and next links
- Earliest article shows only next
- Latest article shows only previous
- Adjacent links use real article permalinks
- Chinese or special-character slug URLs are encoded
- Pages do not require adjacent article navigation

## Logo and Brand

Themes must resolve the site brand logo in this order:

1. Theme Logo override
2. Site Global Logo
3. Site Name text fallback

Default Theme reference:

```php
$logo = dy_image_url($context, ['logo_image', 'site_logo_url']);
```

When the administrator has configured a global site logo, a theme must not
replace it with text-only branding or with a theme-bundled default logo. Users
must not need to upload the same logo again after switching themes.

The brand/logo link must point to `/`.

Desktop and mobile headers must use the same resolver order. Mobile layouts may
hide secondary description text, but must not switch to a different logo source
or ignore the configured global logo.

Logo images must keep their aspect ratio. Do not stretch logos to fill fixed
width and height boxes; constrain the display area and use proportional sizing
or `object-fit: contain`.

If no logo is configured, the theme must render readable site-name text as the
fallback. An initial mark or decorative icon is allowed only as an enhancement
beside the text fallback.

Required logo review cases:

- Global Logo only
- Theme Logo override
- No Logo fallback
- Mobile Logo

## Theme Assets

Theme assets live under:

`content/themes/{theme_id}/assets/`

Templates must use `TemplateContext::asset()` instead of hard-coding URLs.
The argument is relative to the `assets/` directory.

Correct:

```php
<link rel="stylesheet" href="<?= $context->e($context->asset('css/base.css')) ?>">
```

Incorrect:

```php
<link rel="stylesheet" href="<?= $context->e($context->asset('assets/css/base.css')) ?>">
```

`TemplateContext::asset('css/base.css')` returns the stable public URL:

`/content/themes/{theme_id}/assets/css/base.css`

Daiying CMS serves that URL through its generic theme asset contract. Themes must
not require administrators to add per-theme Apache `Alias` or Nginx `location`
rules.

Only static theme assets are public. Template PHP files, `_theme.php`,
`theme.json`, hidden files, environment files, source/config files and other
non-asset paths remain private.

Allowed public theme asset types are:

- `css`
- `js`
- `mjs`
- `png`
- `jpg` / `jpeg`
- `webp`
- `gif`
- `svg`
- `ico`
- `woff` / `woff2`
- `ttf`

## Installation

Use the admin theme management UI for packaged themes when available. Direct file replacement should be reserved for development or recovery scenarios.

## Market Packages

Official market theme packages use this structure:

```text
market-package.json
content/themes/{theme_id}/theme.json
content/themes/{theme_id}/templates/...
content/themes/{theme_id}/assets/...
```

Every file listed in `market-package.json` must declare a SHA-256 checksum.

The theme manifest `core.min` / `core.max` describes the theme's own runtime
compatibility. The update server's minimum Core field describes the minimum CMS
version allowed to install a specific market release. Keep both aligned, but do
not confuse them: one is the theme contract, the other is release distribution
policy.
