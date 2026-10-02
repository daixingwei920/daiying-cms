# Daiying CMS Core Sitemap P0 Fix Report

Date: 2026-10-02
Branch: `fix/sitemap-p0-xml-url-normalization`
Commit: `fc761d95bd8ad9c59e5d7f949b59a0422848bb2c`

## Root Cause

Core `ContentFrontController::sitemap()` generated sitemap URLs by concatenating `siteBaseUrl()` with route URLs directly. If an input URL was already absolute, this pattern could produce double-prefixed URLs such as `https://host/https://host/path`.

Sitemap XML was also built by manual string concatenation. Although the current route emitted XML-like tags, the implementation was fragile: XML escaping and document structure depended on manual concatenation instead of a structured XML writer.

The same Core controller also had a related canonical fallback path using direct base URL concatenation. That was normalized with the same helper to prevent the same class of URL bug from recurring.

## Modified Files

- `system/core/Content/ContentFrontController.php`
- `system/core-manifest.json`
- `tests/core_sitemap.php`
- `DAIYING_CMS_CORE_SITEMAP_P0_FIX_REPORT.md`

## Logic Changes

- Added `absoluteUrl()` normalization:
  - `http://` and `https://` URLs are returned as already absolute.
  - `/articles` is combined with the current `site.url`.
  - `articles` is normalized to `/articles` and combined with the current `site.url`.
  - protocol-relative `//...` input is treated as a site-local path, not an external host.
  - no hard-coded `daiyingcms.com`.
- Rebuilt sitemap XML via `DOMDocument`.
- Sitemap response now uses `Content-Type: application/xml; charset=UTF-8`.
- `lastmod` is normalized with `DateTimeImmutable::format(DateTimeInterface::ATOM)`.
- `robots.txt` Sitemap line now uses the same URL normalization.
- Canonical fallback now uses the same URL normalization.

## Sitemap XML Example

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>http://127.0.0.1:18076/</loc>
  </url>
  <url>
    <loc>http://127.0.0.1:18076/articles</loc>
  </url>
  <url>
    <loc>http://127.0.0.1:18076/articles/http-smoke</loc>
    <lastmod>2026-10-02T00:00:00+00:00</lastmod>
  </url>
</urlset>
```

## Test Results

Passed:

- `php -l system/core/Content/ContentFrontController.php`
- `php -l tests/core_sitemap.php`
- `php tests/core_sitemap.php`
- `php tests/base_path_deployment.php`
- `php tests/system_health_foundation.php`
- `php tests/frontend_extension_api.php`
- `php tests/rest_api_v1.php`
- `php tests/stripe_checkout_url_validator.php`
- `php tests/theme_api_v1.php`
- `php tests/theme_asset_serving_contract.php`
- `php tests/theme_runtime_settings_context.php`
- `php tests/updater_runtime_boundary.php`
- `php tests/updater_runtime_bridge_preflight.php`
- `php tests/video_collector_smart_mode.php`
- Core manifest hash check: PASS

`tests/core_sitemap.php` covers:

1. absolute URL is not double-prefixed
2. `/articles` becomes an absolute URL
3. `articles` becomes an absolute URL
4. sitemap parses as XML
5. root element is `urlset`
6. `loc` contents are correct
7. `lastmod` is legal Atom date-time
8. XML special characters are escaped
9. different `site.url` values do not leak domains
10. sitemap response Content-Type is XML

Core suite notes:

- Full non-live test loop was started.
- Existing unrelated failure observed in `tests/official_mail_contract.php`: 4 notification assertions fail.
- `tests/release_phase0_gate.php` did not complete within the working window and was interrupted.
- Existing unrelated failure observed in `tests/theme_productization_contract.php`: test still references removed `content/themes/daiying_novel/theme.json`.

## Local Real HTTP Verification

Temporary PHP server:

- URL: `http://127.0.0.1:18076/sitemap.xml`
- HTTP 200: YES
- Content-Type XML: YES
- DOM parse: YES
- Root: `urlset`
- Double prefix: NO
- `/articles` absolute loc: YES

`robots.txt` local check:

```text
Sitemap: http://127.0.0.1:18076/sitemap.xml
```

## Daiying CMS Verification

Checked live current production, without deploying this branch:

- URL: `https://www.daiyingcms.com/sitemap.xml`
- HTTP 200: YES
- Content-Type XML: YES
- XML parse: YES
- Double-prefix `https://www.daiyingcms.com/https://www.daiyingcms.com/`: NO
- Sample loc domain: `https://www.daiyingcms.com/...`

## Shoe-ZY Verification

Checked live current production, without deploying this branch:

- URL: `https://www.shoe-zy.com/sitemap.xml`
- HTTP 200: YES
- Content-Type XML: YES
- XML parse: YES
- Uses `https://www.shoe-zy.com/...`: YES
- Leaks `https://www.daiyingcms.com`: NO
- Double-prefix `https://www.shoe-zy.com/https://www.shoe-zy.com/`: NO

## Core Search Result

Searched Core/plugins/themes/tests for:

- `sitemap`
- `sitemap.xml`
- `urlset`
- `lastmod`
- `site_url`
- `base_url`
- `canonical`

Findings:

- Core sitemap route is registered once in `system/core/Bootstrap/Application.php`.
- Core sitemap implementation is in `system/core/Content/ContentFrontController.php`.
- Plugin frontend injection explicitly skips `/sitemap.xml`.
- Baidu submit plugin states it does not replace sitemap generation.
- No plugin override of `/sitemap.xml` was used or added.

## Git Diff Summary

Expected diff:

- `system/core/Content/ContentFrontController.php`: structured sitemap XML, URL normalization, canonical normalization.
- `system/core-manifest.json`: updated hash for `Content/ContentFrontController.php`.
- `tests/core_sitemap.php`: new regression coverage.
- `DAIYING_CMS_CORE_SITEMAP_P0_FIX_REPORT.md`: this report.

## Release Recommendation

Recommend entering the next Core release after human approval.

Do not publish automatically from this task. Before release, decide whether to separately fix the existing unrelated test debts in `official_mail_contract` and `theme_productization_contract`, or explicitly waive them for this P0 sitemap release.
