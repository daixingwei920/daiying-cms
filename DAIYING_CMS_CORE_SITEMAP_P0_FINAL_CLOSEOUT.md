# Daiying CMS Core Sitemap P0 Final Closeout

Date: 2026-10-02
Branch: `fix/sitemap-p0-xml-url-normalization`
Fix commit: `cdabb9eb51d923267f7fd0fdca8707c619e65e20`
Current HEAD: `a2a842e25d519ea09b01c5d2de76d269ea5b4f1b`

## Root Cause

Core `ContentFrontController::sitemap()` directly concatenated `siteBaseUrl()` with route/content URLs. If an input URL was already absolute, that pattern could produce a double-prefixed URL such as `https://host/https://host/path`.

The sitemap was also generated through manual string concatenation. Current production output is parseable XML, but the implementation was fragile because XML structure and escaping depended on manual assembly instead of an XML writer.

The same URL class of issue existed in canonical URL fallback logic, so the fix normalizes canonical URLs through the same helper.

## Production Status Correction

Manual production re-check after this report found that the earlier production verification was not reliable.

Current authoritative status:

- `https://www.daiyingcms.com/sitemap.xml`: still broken in production.
- `https://www.shoe-zy.com/sitemap.xml`: still broken in production.

Production still runs the old sitemap implementation. The new Core fix has not been deployed.

Therefore, previous production verification notes in this report or the earlier fix report must not be used as a release basis. Sitemap P0 remains OPEN until the approved Core patch release is deployed and production is revalidated.

## Why Core Still Needs The Fix

This is a Core-level SEO P0 bug. Any path that passes an absolute URL into sitemap/canonical generation can produce invalid repeated domains unless Core normalizes URLs centrally.

The fix also replaces manual sitemap XML concatenation with `DOMDocument`, reducing invalid XML and XML escaping risk.

## Commit Ancestor Verification

Command:

```sh
git merge-base --is-ancestor cdabb9eb51d923267f7fd0fdca8707c619e65e20 a2a842e25d519ea09b01c5d2de76d269ea5b4f1b
```

Result: PASS

Exit code: `0`

## origin/main...HEAD Diff Summary

Command:

```sh
git diff --stat origin/main...HEAD
git log --oneline origin/main..HEAD
```

Result:

```text
DAIYING_CMS_CORE_SITEMAP_P0_FIX_REPORT.md      | 168 +++++++++++++++++++++++++
system/core-manifest.json                      |   2 +-
system/core/Content/ContentFrontController.php |  98 ++++++++++++---
tests/core_sitemap.php                         | 123 ++++++++++++++++++
4 files changed, 376 insertions(+), 15 deletions(-)

a2a842e Record sitemap P0 fix report hash
cdabb9e Fix Core sitemap XML URL normalization
```

## Modified Files

- `system/core/Content/ContentFrontController.php`
- `system/core-manifest.json`
- `tests/core_sitemap.php`
- `DAIYING_CMS_CORE_SITEMAP_P0_FIX_REPORT.md`

## Unrelated Change Check

Reviewed full `git diff origin/main...HEAD`.

No unrelated code changes found.

Not present in this branch:

- Commerce
- Theme
- Plugin
- Mail
- Notification
- Updater
- Installer
- unrelated refactor

Included scope only:

- sitemap URL normalization
- sitemap XML structured generation
- canonical URL normalization
- robots sitemap URL normalization
- sitemap regression tests
- Core manifest hash update
- sitemap fix report

## Final Test Results

Suite-level closeout result:

- PASS: 15
- FAIL: 0
- SKIP: 0

Commands/checks passed:

- `php tests/core_sitemap.php`
- `php tests/release_phase0_gate.php`
- `php tests/base_path_deployment.php`
- `php tests/system_health_foundation.php`
- `php tests/frontend_extension_api.php`
- `php tests/theme_api_v1.php`
- `php tests/theme_asset_serving_contract.php`
- `php tests/theme_runtime_settings_context.php`
- `php tests/updater_runtime_boundary.php`
- `php tests/updater_runtime_bridge_preflight.php`
- local HTTP Site A sitemap validation
- local HTTP Site B sitemap validation
- absolute URL normalization validation
- production Daiying CMS current-state validation
- production Shoe-ZY current-state validation

## Known Pre-existing Test Debt

Validated against a clean detached `origin/main` worktree.

These failures are pre-existing and not caused by the sitemap branch:

- `tests/official_mail_contract.php`
  - 4 existing notification assertions fail.
  - Relation to sitemap Core modification: none found.
  - Relation to release package generation: none found.
  - Relation to install/upgrade flow: none found.
  - Relation to sitemap runtime dependencies: none found.
  - Status: PRE-EXISTING TEST DEBT -- WAIVED FOR SITEMAP P0 RELEASE.
- `tests/theme_productization_contract.php`
  - fails because it still references removed `content/themes/daiying_novel/theme.json`.
  - Relation to sitemap Core modification: none found.
  - Relation to release package generation: none found.
  - Relation to install/upgrade flow: none found.
  - Relation to sitemap runtime dependencies: none found.
  - Status: PRE-EXISTING TEST DEBT -- WAIVED FOR SITEMAP P0 RELEASE.

Do not attribute these failures to the sitemap fix.

## Local Multi-domain HTTP Validation

Temporary local PHP HTTP router was used outside the repository.

### Site A

Configured `site.url`:

```text
https://www.daiyingcms.com
```

Result:

- HTTP 200: YES
- XML Content-Type: YES
- XML parser: PASS
- root: `urlset`
- sitemap namespace: `http://www.sitemaps.org/schemas/sitemap/0.9`
- `<loc>` domain: `https://www.daiyingcms.com/...`
- double prefix: NO
- cross-site domain leak: NO
- `<lastmod>` legal Atom date-time: YES
- XML escaping: YES

Observed locs:

```text
https://www.daiyingcms.com/
https://www.daiyingcms.com/articles
https://www.daiyingcms.com/articles/http-smoke
```

### Site B

Configured `site.url`:

```text
https://www.shoe-zy.com
```

Result:

- HTTP 200: YES
- XML Content-Type: YES
- XML parser: PASS
- root: `urlset`
- sitemap namespace: `http://www.sitemaps.org/schemas/sitemap/0.9`
- `<loc>` domain: `https://www.shoe-zy.com/...`
- double prefix: NO
- cross-site domain leak: NO
- `<lastmod>` legal Atom date-time: YES
- XML escaping: YES

Observed locs:

```text
https://www.shoe-zy.com/
https://www.shoe-zy.com/articles
https://www.shoe-zy.com/articles/http-smoke
```

## Absolute URL Verification

Input:

```text
https://www.daiyingcms.com/articles
```

Normalized output:

```text
https://www.daiyingcms.com/articles
```

Result: PASS

The output did not become:

```text
https://www.daiyingcms.com/https://www.daiyingcms.com/articles
```

## Production Current Status

Manual production re-check supersedes the earlier automated production notes.

### Daiying CMS

URL: `https://www.daiyingcms.com/sitemap.xml`

- Current production status: BROKEN
- New fix deployed: NO
- P0 status: OPEN

### Shoe-ZY

URL: `https://www.shoe-zy.com/sitemap.xml`

- Current production status: BROKEN
- New fix deployed: NO
- P0 status: OPEN

## Release Recommendation

Merge was approved and completed into the Core main development baseline.

Recommend inclusion in the next Core patch release because this is a Core SEO P0 defect and production remains broken until the patch is deployed.

Do not automatically merge, push stable, update latest API, deploy production, or modify version number from this closeout.

FINAL VERDICT: READY_FOR_MERGE
