# Theme Framework Core 1.2.70 Alignment And Dogfood Report

Date: 2026-09-27

Branch/worktree:

```text
theme/theme-contract-v1-alignment
/Users/xingweidai/Documents/Codex/2026-09-25/files-mentioned-by-the-user-daiying-2/work/daiying-cms-theme-contract-v1-alignment
```

Base:

```text
origin/main = 723e95143a16b5b84e788e1bdb48184841c95c1e
```

## Core Reality

Daiying CMS Core 1.2.70 Theme API V1 is implemented through:

- `Cms\Core\Theme\ThemeManifest`
- `Cms\Core\Theme\ThemeManager`
- `Cms\Core\Theme\ThemeRuntime`
- `Cms\Core\Theme\TemplateContext`
- `Cms\Core\Theme\ThemeViewModel`
- `Cms\Core\Content\ContentFrontController`
- `Cms\Core\Theme\LocalThemePackageInstaller`
- `Cms\Core\Market\MarketPackageInstaller`

The real manifest contract requires:

- `theme_id`
- `name`
- `version`
- `author`
- `core`

The optional public manifest fields verified in Core are:

- `theme_api`
- `content_types`
- `recommended_plugins`
- `required_plugins`
- `settings_schema`

`theme_id` must match:

```text
^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*$
```

and must be no longer than 64 characters.

The real public `TemplateContext` helpers are:

- `get()`
- `setting()`
- `e()`
- `apiVersion()`
- `themeId()`
- `asset()`
- `assetPath()`
- `url()`
- `media()`
- `pagination()`
- `breadcrumb()`
- `menu()`
- `seo()`

Templates render through `templates/{template}.php`. Core injects `$context` and adds `theme_settings` / `settings` to render data. Themes are responsible for escaping theme-owned text with `$context->e()`. `rendered_blocks` is Core-rendered HTML and is the Theme API path for plugin/Core block output.

Core serves public theme assets only from `content/themes/{theme_id}/assets/...`. It rejects template PHP, `theme.json`, hidden files, environment-style files, traversal, symlink escapes, and high-risk executable extensions. Audio files under `assets/audio/` are supported with byte range responses.

Local theme ZIP installation requires:

- single ZIP root directory
- root directory name equals `theme_id`
- root contains `theme.json`
- `templates/home.php` exists
- no protected paths, nested ZIPs, symlinks/special files, or high-risk executable extensions

Market package installation accepts:

- explicit `market-package.json` packages with files under `content/themes/{theme_id}/...`
- extension-root packages containing `{theme_id}/theme.json`, templates, and assets, which the installer maps to `content/themes/{theme_id}/...`

## Proposal Accuracy

Correct:

- Theme boundary: presentation only, no Core/private data ownership.
- Manifest should use `theme_id`, `core`, and `settings_schema`.
- Template API should be `$context` based.
- Content URL helper is still theme-local because Core does not expose `TemplateContext::contentUrl()` or `permalink()`.
- Comments, previous/next article data, site logo, assets, audio, block rendering, security, and plugin/theme boundaries match Core 1.2.70.

Corrected:

- Market package text now reflects both supported package shapes instead of implying only explicit `market-package.json` packages are valid.
- Theme Framework starter note now records that starter manifest and asset paths are aligned.

## Framework Gap

FRAMEWORK GAP 1: `theme-framework/starters/blank/theme.json` used the older shape:

```text
id
min_core_version
settings
templates
```

Core 1.2.70 does not parse this as a valid installable theme manifest.

FRAMEWORK GAP 2: `theme-framework/starters/blank/templates/*.php` referenced:

```php
$context->asset('css/theme.css')
$context->asset('js/theme.js')
```

but the starter files lived under:

```text
css/theme.css
js/theme.js
```

Core serves only files below `assets/`, so the starter would generate missing asset URLs if installed as written.

No CORE GAP was found.

## Framework Changes

Changed:

- `theme-framework/starters/blank/theme.json`
  - replaced `id` with `theme_id`
  - added `product_id`, `theme_api`, `package_type`
  - replaced `min_core_version` with `core.min` / `core.max`
  - replaced `settings` with `settings_schema`
  - added `content_types`, `recommended_plugins`, `required_plugins`

- `theme-framework/starters/blank/assets/css/theme.css`
  - moved starter CSS under Core-served `assets/`

- `theme-framework/starters/blank/assets/js/theme.js`
  - moved starter JS under Core-served `assets/`

- `theme-framework/starters/blank/css/theme.css`
  - removed old non-served asset location

- `theme-framework/starters/blank/js/theme.js`
  - removed old non-served asset location

- `theme-framework/starters/blank/README.md`
  - documented assets under `assets/`
  - documented both package shapes

- `theme-framework/docs/CREATE_NEW_THEME.md`
  - documented extension-root and explicit market package layouts

- `theme-framework/docs/RELEASE_CHECKLIST.md`
  - updated package checklist to match Core installer reality

- `DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md`
  - recorded installed directory / `theme_id` rule
  - replaced outdated starter warning with alignment note
  - updated market package section to match Core 1.2.70

## Official Theme Dogfood

### Daojia

Source:

```text
theme-framework/examples/daojia-1.7.11/daojia
```

Result: PASS

Evidence:

- Manifest parsed by Core `ThemeManifest` as `daojia` `1.7.11`.
- PHP syntax checks passed for templates, helper, partials, and compatibility entry files.
- Uses `TemplateContext` helpers for assets, menu, pagination, SEO, escaping, and ViewModel data.
- Does not use PDO, Core private repositories, Session mutation, PluginContext, or plugin SDK internals.

### Guofeng Zhuhong

Source:

```text
/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/outputs/guofeng-zhuhong-1.7.10-url-fix/src/content/themes/guofeng_zhuhong
```

Result: PASS

Evidence:

- Manifest parsed by Core `ThemeManifest` as `guofeng_zhuhong` `1.7.10`.
- PHP syntax checks passed for templates, helper, partials, and compatibility entry files.
- Uses local helpers around `TemplateContext` for content URLs, logo resolution, adjacent article data, layout selection, and constrained CSS values.
- Does not use PDO, Core private repositories, Session mutation, PluginContext, or plugin SDK internals.

### Xifang Ersheng

Source:

```text
/Users/xingweidai/Desktop/博客程序插件/xifang_ersheng-0.1.22-official-market.zip
```

Expected SHA-256 from closeout report and verified locally:

```text
33a199dde7b3d6d0f1dfbee023936785af8ea996cadc7d39de9d2b39bc75f983
```

Result: PASS

Evidence:

- ZIP manifest parsed by Core `ThemeManifest` as `xifang_ersheng` `0.1.22`.
- ZIP PHP syntax checks passed.
- ZIP contains `theme.json`, `templates/home.php`, `templates/list.php`, `templates/content.php`, `templates/error.php`, `templates/search.php`, `assets/css/theme.css`, `assets/js/theme.js`, `assets/audio/fate-question.mp3`, and `assets/preview.webp`.
- `MarketPackageInstaller::verifyAndPlan()` accepted the package and planned target `content/themes/xifang_ersheng`.
- Uses `TemplateContext::url()` for base-path-safe URLs and `TemplateContext::asset()` for theme assets.
- Does not use PDO, Core private repositories, Session mutation, PluginContext, or plugin SDK internals.

## Spec Gaps

No remaining blocking spec gaps were found for Theme Development Spec V1.

Non-blocking documentation note:

- `theme-framework/starters/blank/config/theme.json` is framework-local scene/content configuration. It is not an installable theme manifest. The Proposal now says this explicitly to avoid creating a second manifest contract.

## Core Gaps

None found.

Core 1.2.70 supported the verified framework and official theme cases without runtime changes.

## Market Contract

Verified real market installer behavior:

- Explicit `market-package.json` files must declare all packaged files and SHA-256 hashes.
- Theme package paths must resolve to `content/themes/{theme_id}/...`.
- Extension-root packages are accepted when they include `{theme_id}/theme.json`; the installer maps them to `content/themes/{theme_id}/...`.
- Theme market packages do not receive plugin trust grants or plugin migration execution.

## Validation

Commands run:

```text
php tests/theme_api_v1.php
php tests/theme_asset_serving_contract.php
find theme-framework/starters/blank theme-framework/examples/daojia-1.7.11/daojia -name '*.php' -print0 | xargs -0 -n1 php -l
php -r '<ThemeManifest parse check for blank, daojia, guofeng, xifang zip>'
php -r '<Xifang ZIP PHP lint>'
php -r '<Xifang MarketPackageInstaller verifyAndPlan>'
php -r '<starter asset path check>'
php -r '<theme boundary scan>'
php -r '<JSON parse check>'
```

Results:

- Theme API v1 tests: PASS
- Theme asset serving contract tests: PASS
- Starter and Daojia PHP syntax: PASS
- Guofeng PHP syntax: PASS
- Xifang ZIP PHP syntax: PASS
- Manifest parsing: PASS for blank, Daojia, Guofeng, Xifang
- Xifang market `verifyAndPlan`: PASS
- Boundary scan: PASS
- JSON parse checks: PASS

## Decision

READY_TO_FINALIZE_V1

The Theme Development Spec V1 Proposal can be promoted to a final V1 specification after human review of the wording. The Core 1.2.70 contract, Theme Framework starter, and the Daojia / Guofeng Zhuhong / Xifang Ersheng dogfood set are now aligned without modifying Core Runtime.

