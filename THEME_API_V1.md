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

## View Models

`ThemeViewModel` normalizes Core data into stable array shapes.

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
