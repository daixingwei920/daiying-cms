# Daiying CMS Theme Asset Serving Fix Report

Date: 2026-09-17

## Scope

Fix the Core/deployment contract gap where newly installed market themes render
HTML correctly but their public assets under
`/content/themes/{theme_id}/assets/**` can return Apache 403 unless the server
has a per-theme `Alias`.

This change does not modify `guofeng_zhuhong` and does not add a theme-specific
server rule.

## Root Cause

`TemplateContext::asset('css/base.css')` correctly returns the stable public URL:

`/content/themes/{theme_id}/assets/css/base.css`

Production Apache had a special `Alias` for
`/content/themes/daiying_novel/assets/`, so `daiying_novel` assets were served.
Market-installed themes such as `guofeng_zhuhong` did not have a matching
Alias/Directory grant, so Apache denied direct access to `content/themes`.

The broken part was the deployment contract, not theme packaging, Linux
ownership, file chmod, or template rendering.

## Architecture

Core keeps the existing public theme asset URL contract:

`/content/themes/{theme_id}/assets/{relative_asset_path}`

The server configuration now routes only that theme asset path into the Daiying
CMS front controller. Core then serves the file through a generic theme asset
handler.

All other `content/themes` paths remain private.

## Modified Files

- `.htaccess`
- `nginx-root-security.conf`
- `system/core/Bootstrap/Application.php`
- `system/core/Extension/ExtensionAssetController.php`
- `system/core-manifest.json`
- `tests/theme_asset_serving_contract.php`
- `docs/themes.md`
- `THEME_API_V1.md`

## Security Boundary

Allowed public theme asset extensions:

- `css`
- `js`
- `mjs`
- `png`
- `jpg`
- `jpeg`
- `gif`
- `webp`
- `svg`
- `ico`
- `woff`
- `woff2`
- `ttf`

Core rejects:

- `templates/*.php`
- `_theme.php`
- other PHP files
- `theme.json`
- hidden files such as `.hidden.css`
- `.env`
- `.git`
- path traversal
- non-GET/HEAD asset requests
- files outside the theme directory
- themes without `theme.json`

Responses include existing Core security headers and asset responses include
`X-Content-Type-Options: nosniff`.

## Apache Deployment Contract

For project-root document roots, `.htaccess` now routes:

```apache
RewriteRule ^content/themes/[A-Za-z0-9._-]+/assets/.+ public/index.php [L,QSA]
```

This rule is intentionally placed before the broad `content/themes` deny rule.

For `public/` document roots, existing front-controller routing is sufficient
because `/content/themes/...` is not a physical file under `public/`.

## Nginx Deployment Contract

`nginx-root-security.conf` now documents this rule before the broad deny:

```nginx
location ~ ^/content/themes/[A-Za-z0-9._-]+/assets/.+ {
    rewrite ^ /public/index.php last;
}
```

Production Nginx configs should place an equivalent rule before any rule denying
`content/themes`.

## Disposable Site Verification

A disposable local site was created from the clean Core worktree. A third theme
`test_theme` was added only inside that temporary site.

Real HTTP requests through `public/index.php` returned:

- `/content/themes/daiying_novel/assets/style.css` => `200 text/css`
- `/content/themes/daiying-video/assets/style.css` => `200 text/css`
- `/content/themes/test_theme/assets/style.css` => `200 text/css`
- `/content/themes/test_theme/templates/home.php` => `404`
- `/content/themes/test_theme/theme.json` => `404`
- `/content/themes/test_theme/assets/.hidden.css` => `404`
- `/content/themes/test_theme/assets/secret.php` => `404`

No production files were changed during this verification.

## Automated Tests

Passed:

- `php -l system/core/Extension/ExtensionAssetController.php`
- `php -l system/core/Bootstrap/Application.php`
- `php -l tests/theme_asset_serving_contract.php`
- `php tests/theme_asset_serving_contract.php`
- `php tests/theme_api_v1.php`
- `php tests/theme_productization_contract.php`
- `php tests/market_theme_hyphen_extension_id.php`
- `php tests/release_gate_v1_contract.php`
- `php tests/market_plugin_migrations.php`
- `php tests/admin_market_trust_grant_authorization.php`
- `php tests/frontend_extension_api.php`
- `php tests/core_boundary_legacy_shell_dirs.php`
- `git diff --check`

`php scripts/validate_production_readiness.php` was also executed. Core manifest
verification passed. The script reports expected environment/setup errors in the
clean development checkout, such as missing installed lock, production site URL,
encryption key, database DSN and storage subdirectories. These are not caused by
this change.

## Theme Development Spec Updates

Documented in `docs/themes.md` and `THEME_API_V1.md`:

- Standard themes should provide:
  - `templates/home.php`
  - `templates/list.php`
  - `templates/content.php`
  - `templates/error.php`
- `TemplateContext::asset()` receives paths relative to `assets/`.
- Correct: `$context->asset('css/base.css')`
- Incorrect: `$context->asset('assets/css/base.css')`
- Market theme packages use:
  - `market-package.json`
  - `content/themes/{theme_id}/...`
- Every market package file must declare SHA-256.
- Theme manifest Core constraints and update-server minimum Core are related but
  separate contracts.
- Themes must not require administrators to modify Apache or Nginx per theme.

## Release / Deployment Decision

This is a Core compatibility/deployment contract fix.

Recommended next step:

1. Review this change.
2. If accepted, bump Core to the next patch version and build via Release Gate.
3. Publish a full Core update package, not a partial changed-file package.
4. Update the public installer package if new installations should include the
   corrected `.htaccess`, Nginx example and Core asset handler.

Existing production sites need a one-time deployment config migration if their
Apache/Nginx virtual host currently hard-denies `content/themes` before requests
can reach Daiying CMS. The migration should replace per-theme `Alias` rules with
the generic theme asset forwarding rule.

No per-theme migration is needed for installed theme directories.
