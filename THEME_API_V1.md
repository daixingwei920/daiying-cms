# Daiying CMS Theme API v1

Theme API v1 is the stable public contract for Daiying CMS themes.

The API is additive within the `1.x` line. Minor Core releases may add helpers or
view model fields, but they must not remove or change published method
signatures. Breaking changes require a future major API version with a
compatibility window.

## Public Classes

- `Cms\Core\Theme\ThemeManifest`
- `Cms\Core\Theme\ThemeManager`
- `Cms\Core\Theme\ThemeRuntime`
- `Cms\Core\Theme\TemplateContext`
- `Cms\Core\Theme\ThemeViewModel`

Themes should not call admin controllers, read Core private tables, or inspect
internal service wiring.

## Manifest

Themes are installed under `content/themes/{theme_id}` and declare `theme.json`.

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

`theme_api` defaults to `1.0` for older themes.

## Runtime

`ThemeRuntime::render($template, $data)` renders
`templates/{template}.php` and injects a `TemplateContext` instance as
`$context`.

The runtime always injects:

- `theme_settings`
- `settings`

Explicit render data may override those keys for compatibility with existing
themes.

## TemplateContext

Stable helpers:

- `get($key, $default = null)`
- `setting($key, $default = null)`
- `e($value)`
- `apiVersion()`
- `themeId()`
- `asset($path)`
- `assetPath($path)`
- `url($path, $query = [])`
- `media($media)`
- `pagination($current, $total, $basePath, $query = [], $perPage = 20, $totalItems = null)`
- `breadcrumb($items)`
- `menu($name = 'primary')`
- `seo($overrides = [])`

`asset()` and `assetPath()` reject path traversal. Templates should use these
helpers instead of manually building theme asset paths.

`asset()` accepts paths relative to the theme `assets/` directory:

```php
$context->asset('css/base.css');        // correct
$context->asset('assets/css/base.css'); // incorrect
```

The stable URL contract is:

`/content/themes/{theme_id}/assets/{relative_asset_path}`

Core serves this URL through the generic theme asset serving contract. Themes
must not require per-theme Apache or Nginx aliases. Core only exposes static
assets from `assets/`; PHP templates, `_theme.php`, `theme.json`, hidden files
and sensitive configuration files remain private.

### Theme Audio Assets

Theme API v1 supports packaged audio assets for scene themes and immersive
themes. Audio files should live under:

`content/themes/{theme_id}/assets/audio/`

Templates and JavaScript should continue to use `asset()` with a path relative
to `assets/`:

```php
$context->asset('audio/fate-question.mp3');
```

Do not use `/media/{id}` for audio bundled with a public theme. `/media/{id}`
belongs to site content/data, while theme audio belongs to the theme package and
must work immediately after the theme is installed.

Supported Theme Audio Asset v1 extensions:

- `mp3` → `audio/mpeg`
- `ogg` / `oga` → `audio/ogg`
- `wav` → `audio/wav`
- `m4a` → `audio/mp4`
- `aac` → `audio/aac`

Theme audio responses use the same asset security boundary as CSS, JavaScript,
images and fonts. Core only serves files below the current installed theme's
`assets/` directory, rejects traversal and symlink escapes, and only serves
allowlisted static extensions. PHP, PHAR, PHTML, shell scripts, manifests,
hidden files and configuration files remain private.

Audio responses advertise byte range support and support single `Range:
bytes=...` requests with `206 Partial Content`, `Content-Range`,
`Accept-Ranges: bytes` and correct `Content-Length`, so HTML5 `<audio>` can
seek and stream stable theme-bundled audio.

Browser autoplay is not guaranteed. Scene themes should start music, voiceover
or effects from a user gesture when needed, handle `play()` promise rejection,
and provide a visible fallback control. Use `loop`, `preload` and volume
carefully; mobile browsers may delay or block playback until interaction.

## Content Permalinks

Theme API v1 does not currently expose a dedicated public
`TemplateContext::permalink()` or `TemplateContext::contentUrl()` helper. Core
contains internal content URL builders, but themes must not depend on private
controller or repository methods.

Until Core publishes a stable permalink helper, themes should follow the
Default Theme reference implementation:

```php
function theme_content_url(array $content): string
{
    $slug = trim((string) ($content['slug'] ?? ''), '/');
    if ($slug === '') {
        return '#';
    }

    return (($content['content_type'] ?? 'article') === 'article'
        ? '/articles/'
        : '/') . rawurlencode($slug);
}
```

Standard Content Permalink V1 paths:

- Article: `/articles/{rawurlencode(slug)}`
- Page: `/{rawurlencode(slug)}`

Themes must not assume `$item['url']` or `$content['url']` is always present in
content view data. Missing `url` fields are normal and must not break links.

Themes must not use `#`, `#gf-content`, `javascript:void(0)`, or similar values
as article or page permalink fallbacks. `#anchor` URLs are allowed only for
explicit current-page navigation, such as a hero button scrolling to a home-page
content section. They must not be used for article cards, page cards, search
results, related content, magazine blocks, banner stories, or list items.

Each theme should define one content URL helper and reuse it everywhere content
cards are rendered, including:

- Home
- Grid
- Banner
- Magazine
- List
- Search
- Related Content

Different templates within one theme must not invent different URL rules for the
same Core content object.

Theme review must block publication if article or page content cards link to
`#`, page anchors, or JavaScript placeholders instead of real permalinks.

## Brand Logo

Theme API v1 brand rendering should follow this resolver order:

1. Theme Logo override
2. Site Global Logo
3. Site Name text fallback

The Default Theme reference implementation resolves image URLs with theme
settings first and the site-level logo second:

```php
$logo = dy_image_url($context, ['logo_image', 'site_logo_url']);
```

If a Site Global Logo is configured, themes must not ignore it and default to a
text site name or theme-bundled brand image. Changing themes must not require
the administrator to upload the same logo again.

The logo/brand link must point to `/`.

Desktop and mobile headers must use the same logo resolver. Themes may change
layout responsively, but they must not use a different fallback chain on mobile.

Logo images must preserve their original aspect ratio. Themes must not stretch
or distort logos; use constrained dimensions with `width: auto`,
`height: auto`, or `object-fit: contain` as appropriate.

If no logo is configured, the fallback must include readable site-name text.
Decorative marks or initials may be used only as an enhancement, not as a
replacement for the site-name fallback.

## View Models

`ThemeViewModel` normalizes Core data into stable array shapes.

Article detail view model:

- `previous`
- `next`

`previous` and `next` are available on article detail renders. They are
optional top-level fields; each value is either `null` or an adjacent published
article summary. Page detail renders may omit these fields or expose them as
`null`.

Adjacent article summary:

- `id`
- `title`
- `slug`
- `content_type`
- `url`
- `published_at`
- `cover`
- `excerpt`

Core builds `url` with the same public Article V1 permalink rule as normal
article links. Themes should use this `url` directly and only fall back to their
local content URL helper if an older Core release does not provide it.

Ordering is fixed for Theme API v1: among published articles on the same site,
Core sorts by `published_at` and then `id`. `previous` is the nearest earlier
article on that timeline; `next` is the nearest newer article. The earliest or
latest article receives `null` for the missing side.

Themes must not query Core repositories, controllers, or private tables to
build adjacent navigation. Read `$context->get('previous')` and
`$context->get('next')`, render only non-empty sides, and omit the whole
navigation block when both are empty.

`previous` / `next` exist only on article detail ViewModels. Home, category,
tag, search, and ordinary article-list ViewModels must not include these keys
(not even as `null`). Do not conflate them with list/`query`/`items` contracts.

### Adjacent navigation example

```php
$previous = $context->get('previous');
$next = $context->get('next');

if (is_array($previous) || is_array($next)): ?>
<nav class="adjacent-nav" aria-label="Adjacent articles">
  <?php if (is_array($previous)): ?>
    <a class="adjacent-prev" href="<?= $context->e((string) ($previous['url'] ?? '')) ?>">
      <?= $context->e((string) ($previous['title'] ?? '')) ?>
    </a>
  <?php endif; ?>
  <?php if (is_array($next)): ?>
    <a class="adjacent-next" href="<?= $context->e((string) ($next['url'] ?? '')) ?>">
      <?= $context->e((string) ($next['title'] ?? '')) ?>
    </a>
  <?php endif; ?>
</nav>
<?php endif;
```


Media view model:

- `id`
- `url`
- `title`
- `alt`
- `mime`
- `size`
- `provider`
- `metadata`

Pagination view model:

- `current`
- `total`
- `per_page`
- `total_items`
- `has_prev`
- `has_next`
- `prev_url`
- `next_url`
- `pages`

Breadcrumb view model:

- `label`
- `url`
- `current`

Menu view model:

- `label`
- `url`
- `current`

## Safe Fallback

`ThemeManager::loadWithFallback()` keeps the existing safe-theme behavior. If the
active theme cannot load, is incompatible, has missing required plugins, or lacks
`templates/home.php`, Core falls back to the `safe` theme and logs the reason.

## Extension Boundary

Themes render presentation. Business workflows stay in plugins.

- Commerce data should come from Commerce plugin routes or public Core view data.
- Distribution data should come from Distribution plugin routes or public Core
  view data.
- External media storage should be accessed through Storage Provider API v1 or
  prepared media view models.

Themes must not depend on private database schemas or private Core controller
methods.
