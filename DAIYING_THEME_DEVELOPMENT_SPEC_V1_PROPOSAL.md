# Daiying Theme Development Specification V1 Proposal

Status: Proposal for review, not yet a finalized Theme Development Specification.

Target Core: Daiying CMS Core 1.2.70+

Last updated: 2026-09-27

Source of truth inputs:

- `THEME_API_V1.md`
- `docs/themes.md`
- `Cms\Core\Theme\TemplateContext`
- `Cms\Core\Theme\ThemeRuntime`
- `Cms\Core\Theme\ThemeManifest`
- `Cms\Core\Theme\ThemeViewModel`
- `Cms\Core\Content\ContentFrontController`
- bundled official themes under `content/themes/`
- Daiying Theme Framework `theme-framework/`

This proposal exists because the older public website theme specification dated 2026-08-31 no longer fully reflects Core 1.2.70, official themes, or the Theme Framework. Do not publish this document as a final V1 contract until reviewed.

## Core Boundary

Themes are presentation packages. They render frontend HTML, CSS, JavaScript, images, fonts, audio, responsive layout, and theme settings.

Themes must not implement business workflows such as payment, inventory, orders, card delivery, licensing, media provider logic, plugin lifecycle, updates, or Core migrations.

Themes must not read private Core tables, call private repositories/controllers, mutate application state, or require manual edits to `system/`, `config/`, `storage/`, or `public/index.php`.

## Theme Structure

Themes are installed under:

```text
content/themes/{theme_id}/
```

Standard structure:

```text
content/themes/{theme_id}/
  theme.json
  templates/
    home.php
    list.php
    content.php
    error.php
  assets/
    css/
    js/
    images/
    fonts/
    audio/
```

Theme-specific helper files such as `templates/_theme.php` are allowed, but helpers remain part of the theme package and must not become a private Core dependency.

## Manifest

Current Core 1.2.70 reads `theme.json` through `ThemeManifest`.

Stable fields:

- `theme_id`
- `name`
- `version`
- `author`
- `core.min`
- `core.max`
- `theme_api`
- `content_types`
- `recommended_plugins`
- `required_plugins`
- `settings_schema`

Additional package metadata used by official market workflows may include:

- `product_id`
- `description`
- `package_type`
- `market_release`

`theme_id` must match Core validation:

- starts with a lowercase letter
- may contain lowercase letters and digits
- may contain `_` or `-` separators followed by lowercase letters or digits
- length no more than 64

`theme_api` defaults to the current Theme API version for older manifests.

## Lifecycle

Core discovers themes from `content/themes`, parses `theme.json`, checks compatibility and required plugins, loads active theme settings, and renders templates through `ThemeRuntime`.

`ThemeManager::loadWithFallback()` falls back to the safe theme when the active theme cannot load, is incompatible, lacks required plugins, or is missing `templates/home.php`.

Switching or disabling a theme must not delete content, media, comments, plugin data, payment records, or other Core-owned data.

## Template Contract

`ThemeRuntime::render($template, $data)` renders:

```text
templates/{template}.php
```

and injects:

```php
/** @var Cms\Core\Theme\TemplateContext $context */
```

Core always injects theme settings under:

- `theme_settings`
- `settings`

Templates should read data through `$context`:

```php
$title = $context->get('title', '');
$accent = $context->setting('accent_color', '#1f6feb');
echo $context->e($title);
```

Current public `TemplateContext` helpers:

- `get(string $key, mixed $default = null): mixed`
- `setting(string $key, mixed $default = null): mixed`
- `e(mixed $value): string`
- `apiVersion(): string`
- `themeId(): string`
- `asset(string $path): string`
- `assetPath(string $path): string`
- `url(string $path, array $query = []): string`
- `media(array $media): array`
- `pagination(int $current, int $total, string $basePath, array $query = [], int $perPage = 20, ?int $totalItems = null): array`
- `breadcrumb(array $items): array`
- `menu(string $name = 'primary'): array`
- `seo(array $overrides = []): array`

## View Data

Home templates commonly receive:

- `site_name`
- `site_logo_url`
- `site_favicon_url`
- `navigation`
- `contents`
- `seo`
- `ad_slots`

List, category, and tag templates commonly receive:

- `site_name`
- `site_logo_url`
- `site_favicon_url`
- `navigation`
- `title`
- `items`
- `pagination`
- `term`
- `base_path`
- `empty_message`
- `seo`
- `ad_slots`

Search templates receive:

- `query`
- `items`
- `pagination`
- `seo`

Content detail templates receive:

- `content`
- `title`
- `media`
- `rendered_blocks`
- `paid_content`
- `published_at`
- `updated_at`
- `categories`
- `tags`
- `page_category`
- `page_category_items`
- `page_category_total`
- `seo`
- `canonical`
- `preview`
- `ad_slots`
- `comments`
- `previous`
- `next`

`previous` and `next` are present only when Core renders a detail page with adjacent article data. Each is either `null` or an article summary.

## Content URLs

Core 1.2.70 does not publish `TemplateContext::permalink()` or `TemplateContext::contentUrl()`.

Themes should use one local helper for article/page URLs, matching the Theme API V1 rule:

```php
function theme_content_url(array $content): string
{
    if (!empty($content['url'])) {
        return (string) $content['url'];
    }
    $nested = is_array($content['content'] ?? null) ? $content['content'] : [];
    if (!empty($nested['url'])) {
        return (string) $nested['url'];
    }
    $slug = trim((string) ($content['slug'] ?? $nested['slug'] ?? ''), '/');
    if ($slug === '') {
        return '';
    }

    return (($content['content_type'] ?? $nested['content_type'] ?? 'article') === 'article'
        ? '/articles/'
        : '/') . rawurlencode($slug);
}
```

Do not use `#`, page anchors, or JavaScript placeholders for article cards, page cards, search results, related content, magazine blocks, banners, or list items.

## Blocks

Themes should render Core-provided block HTML through:

```php
<?= $context->get('rendered_blocks', '') ?>
```

Themes should not parse Core private block storage as the authoritative rendering path. Plugin block rendering belongs to the Core block renderer and Plugin SDK block renderer registry.

## Comments

The `comments` ViewModel may include:

- `enabled`
- `allow_guest`
- `require_approval`
- `items`
- `user`
- `csrf`
- `content_id`
- `redirect`
- `notice`
- `error`

Comment forms post to `/comments`, include `_csrf`, `content_id`, and `redirect`, and must escape all comment author/body output.

## Site Configuration And Logo

Brand rendering should resolve:

1. theme logo override
2. site global logo from `site_logo_url`
3. readable site-name text fallback

The brand link points to `/`. Logo images must preserve aspect ratio. Mobile and desktop should use the same resolver order.

## Assets

Theme assets live under:

```text
assets/
```

Use:

```php
$context->asset('css/theme.css');
$context->asset('js/theme.js');
$context->asset('images/hero.webp');
$context->asset('fonts/display.woff2');
$context->asset('audio/intro.mp3');
```

Do not pass `assets/...` to `asset()`. The argument is already relative to the `assets/` directory.

Core serves static allowlisted files from the installed theme's `assets/` directory. PHP templates, manifests, hidden files, environment files, shell scripts, and private config files remain non-public.

Audio assets are supported for scene themes and immersive themes. Use bundled theme audio for theme package audio; do not use `/media/{id}` for packaged theme audio.

## Responsive And Mobile

Themes must support desktop and mobile layouts without requiring Core changes. Use responsive CSS, avoid fixed-width overflow, preserve readable text, and test navigation, article cards, content pages, comment forms, search, and audio controls on mobile.

## Theme And Plugin Boundary

Themes render public ViewModels and assets. Plugins provide business capabilities and may contribute block renderers or routes through published plugin APIs.

Themes must not call plugin private code or inspect plugin tables. If a theme needs plugin-provided data, it should consume public route output, Core ViewModel data, rendered block HTML, or a documented Theme API extension.

## Security

Themes must escape untrusted text with `$context->e()`.

URLs, colors, CSS values, and image sources should be validated or constrained. Reject `javascript:` URLs and control characters. Do not output secrets, server paths, stack traces, tokens, update signing keys, or admin-only data.

`rendered_blocks` is Core-rendered HTML. Theme-owned text, settings, comments, terms, navigation labels, and content metadata still require escaping.

## Theme Framework

Daiying Theme Framework 1.0.1 is the recommended starting point for new official themes:

- `theme-framework/starters/blank/`
- `theme-framework/framework/immersive-culture-v1/`
- `theme-framework/examples/daojia-1.7.11/`

The framework is not Core. It is a reusable theme-layer baseline and reference implementation.

Known proposal note: the starter manifest currently uses an older shape (`id`, `min_core_version`, `settings`). Before promoting this proposal to a final V1 spec, either the starter should be aligned to Core 1.2.70 `theme.json` fields or documented as framework-local starter metadata that must be converted before packaging.

## Official Market Package

Official market packages should keep files under:

```text
market-package.json
content/themes/{theme_id}/theme.json
content/themes/{theme_id}/templates/...
content/themes/{theme_id}/assets/...
```

Every packaged file should be represented by a SHA-256 checksum in the market package manifest. Theme compatibility in `theme.json` and distribution policy in market metadata must be kept aligned but treated as separate concerns.

## Differences From The 2026-08-31 Website Spec

- Core 1.2.70 accepts hyphenated `theme_id`; the old spec said only lowercase letters, digits, and underscores.
- Core 1.2.70 publishes `TemplateContext::asset()`, `assetPath()`, `url()`, `media()`, `pagination()`, `breadcrumb()`, `menu()`, and `seo()`; the old spec documented only a smaller subset.
- Core 1.2.70 has a documented Theme API V1 asset serving contract, including fonts and audio assets.
- Core 1.2.70 passes comments and adjacent article navigation ViewModels to content templates.
- Core 1.2.70 expects themes to account for `site_logo_url` and `site_favicon_url`.
- Core 1.2.70 supports plugin block renderers through Core block rendering; themes should render `rendered_blocks`, not own plugin block parsing.
- Theme Framework 1.0.1 is now a recommended official starting point, but its starter metadata needs review before it can become a final normative spec.
