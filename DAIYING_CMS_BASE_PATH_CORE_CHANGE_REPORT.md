# Daiying CMS Base Path Core Change Report

Date: 2026-09-20

## Scope

This change adds a minimal, generic deployment-prefix capability to Daiying CMS Core so a site can run under a configured base path such as:

```text
/daojia
```

The implementation is not demo-specific. It is exposed as a general `site.base_path` configuration option and defaults to an empty string for root deployments.

No production server, production database, official update server, GitHub Release, or Demo Platform deployment was modified.

## Baseline

- Worktree: `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms-core-clean-1.2.63`
- Branch: `release/1.2.65`
- HEAD before this report: `6131819ee21308b13cc708ca3177caf55d55548e`
- Current config version observed from `config/app.php`: `1.2.66`

## Architecture

### New Base Path Service

Added:

```text
system/core/Routing/BasePath.php
```

Responsibilities:

- Normalize `site.base_path`.
- Treat unset/root deployment as `''`.
- Strip the active base path from incoming request paths before routing.
- Prefix generated local URLs.
- Avoid double-prefixing.
- Never modify external URLs, fragments, mailto/tel/javascript/data/blob URLs, protocol-relative URLs, or empty values.

Invalid base paths containing query strings, fragments, null bytes, or `..` are ignored and collapse to root behavior.

### Request Routing

`Application` now loads `site.base_path`, stores it globally for the request, and strips the configured prefix before:

- Theme asset serving.
- Existing route dispatch.
- Health request matching.
- Admin notification context checks.

This keeps existing route handlers working with their current route names.

### URL Generation

Core output layers now prefix local public URLs where they are emitted:

- Content permalinks.
- Canonical URLs.
- Search URLs.
- Pagination URLs.
- Category/tag URLs.
- Theme assets.
- Plugin assets.
- Media URLs.
- Paid content/download/tip URLs.
- Card delivery URLs.
- Advertising tracking URLs.
- Admin shell/sidebar/topbar/logout/notification links.
- Local redirect `Location` headers.

Default Theme templates were updated to use a small theme-local helper that prefixes generated local URLs through the same Core base path service.

## Root Deployment Compatibility

When `site.base_path` is unset or empty:

- Generated URLs remain unchanged.
- Existing root paths continue to route normally.
- Theme and plugin asset URLs remain root-relative.
- Pagination, search, menu links, content links, and Default Theme links keep the previous behavior.

This is covered by `tests/base_path_deployment.php`.

## Demo-Style Subpath Compatibility

With:

```php
'site' => [
    'base_path' => '/daojia',
],
```

Core now supports:

- Incoming `/daojia/articles` routing as `/articles`.
- Generated content links such as `/daojia/articles/example`.
- Generated theme asset URLs such as `/daojia/content/themes/default/assets/...`.
- Generated plugin asset URLs such as `/daojia/extension-assets/plugin/...`.
- Search, pagination, menu, and Default Theme helper URLs under `/daojia`.

## Files Changed

- `config/app.php`
- `config/app.example.php`
- `system/core/Routing/BasePath.php`
- `system/core/Bootstrap/Application.php`
- `system/core/Http/Request.php`
- `system/core/Support/View.php`
- `system/core/Theme/ThemeViewModel.php`
- `system/core/Extension/ExtensionAssetController.php`
- `system/core/Plugin/PluginRuntimeRegistry.php`
- `system/core/Content/ContentFrontController.php`
- `system/core/Content/BlockRenderer.php`
- `system/core/Media/MediaLibrary.php`
- `system/core/Media/MediaController.php`
- `system/core/Payment/PaidContentService.php`
- `system/core/Payment/PaidContentController.php`
- `system/core/Payment/PaidDownloadService.php`
- `system/core/Payment/PaidDownloadController.php`
- `system/core/Payment/TipService.php`
- `system/core/Payment/TipController.php`
- `system/core/CardDelivery/CardDeliveryService.php`
- `system/core/Advertising/AdRenderer.php`
- `content/themes/default/templates/_theme.php`
- `content/themes/default/templates/home.php`
- `content/themes/default/templates/list.php`
- `content/themes/default/templates/content.php`
- `content/themes/default/templates/search.php`
- `content/themes/default/templates/error.php`
- `system/core-manifest.json`
- `tests/base_path_deployment.php`

## Tests

Targeted tests:

```text
php tests/base_path_deployment.php
php tests/theme_api_v1.php
php tests/theme_asset_serving_contract.php
php tests/frontend_extension_api.php
```

Result: PASS.

Release Gate tests:

```text
php tests/release_gate_v1_contract.php
php tests/release_phase0_gate.php
```

Result: PASS.

Full tracked PHP test suite:

```text
for f in tests/*.php; do php "$f"; done
```

Result: PASS, with expected credential/fixture skips:

- `tests/commerce_ai_deepseek_live.php` skipped because `DEEPSEEK_API_KEY` is not set.
- `tests/cross_version_fixture_upgrade.php` skipped because old fixture artifacts are unavailable in this clean workspace.
- `tests/official_affiliate_hub_cj_live.php` skipped because CJ live credentials are not set.

Syntax and diff checks:

```text
php -l <modified php files>
git diff --check
```

Result: PASS.

Production readiness script:

```text
php scripts/validate_production_readiness.php .
```

Result: local environment `not_ready` because this clean worktree does not contain production settings/secrets/package state. Core manifest parity passed. The reported blockers were local sample/runtime configuration issues such as missing encryption key, unset DB DSN, storage permissions, and missing release package.

## Known Limits

Public frontend/demo-path support is covered by the new base-path contract.

Legacy admin page bodies still contain many hard-coded `/admin/...` form actions and links inside controller-rendered HTML, especially in `AdminController`, maintenance, install, and recovery surfaces. The shared admin shell/sidebar/topbar and redirect `Location` headers are covered, but a full "admin under arbitrary subpath" guarantee should be treated as a separate P1 migration if needed.

For the official public theme demo use case, visitor-facing frontend rendering does not require admin pages under each demo slug.

## Gate Result

Root deployment regression: PASS.

Generic public base-path deployment: PASS for the tested `/daojia` scenario.

Core has not been released, tagged, pushed, or deployed as part of this task.
