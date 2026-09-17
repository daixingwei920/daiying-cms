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
