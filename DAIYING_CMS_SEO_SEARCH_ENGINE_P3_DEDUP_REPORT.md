# Daiying CMS SEO Search Engine P3 Dedup Report

Date: 2026-10-03

## Verdict

P3 Baidu URL submission dedupe remediation: PASS.

No production deploy, no stable push, no latest API update, no Core version bump.

## Scope

Executed remediation according to `DAIYING_CMS_BAIDU_PUSH_DUPLICATION_AUDIT.md`.

`official.seo.baidu-submit` is now the only Baidu URL submission implementation. Core Keyword Center keeps P3 target/observed-data features, metrics storage, CSV import, and generic search engine UI, but it no longer owns Baidu credentials or performs direct Baidu POST.

## Root Cause

The initial P3 implementation duplicated an already existing official Baidu URL submission plugin:

- Core had its own Baidu provider class capable of direct POST.
- Core introduced its own Baidu token owner: `core.seo.search_engine.baidu`.
- Core introduced its own submission log table: `cms_seo_search_engine_submissions`.
- Keyword Center submit could bypass the existing plugin's settings, logs, dedupe, queue, and retry behavior.

This created two token owners, two possible POST paths, and two submission fact sources.

## Changed Files

P3 dedupe-specific changes:

- `system/core/Seo/SearchEngine/BaiduSearchResourceProvider.php` deleted.
- `system/core/Seo/SearchEngine/OfficialBaiduSubmitBridge.php` added.
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php` reduced to observed metrics / CSV import data.
- `system/migrations/2026_10_03_000003_seo_search_engine_p3.php` no longer creates Baidu connection/submission tables.
- `system/core/Bootstrap/Application.php` removed Core Baidu save/sync routes.
- `system/core/Admin/AdminController.php` now reads/submits through the official plugin bridge.
- `tests/seo_search_engine_p3.php` rewritten to enforce dedupe invariants.

Existing P0/P1/P2 files remain in the working tree and were not deployed.

## Final Ownership Model

Token owner:

- Only `official.seo.baidu-submit`
- Secret key remains the plugin's existing `baidu_url_submit_token`
- Core no longer writes or reads `core.seo.search_engine.baidu:token`

Actual Baidu POST implementation:

- Only `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php`
- Core bridge instantiates/calls the plugin service; it does not build Baidu endpoints or POST directly.

Submission fact source:

- Only plugin table `baidu_url_submission_logs`
- Core no longer creates `cms_seo_search_engine_submissions`

## Core Bridge

Added `OfficialBaiduSubmitBridge` as the minimal stable interface:

- Reads plugin settings and masked credential status.
- Reads plugin recent logs.
- Calls plugin `BaiduUrlSubmissionService::submitUrls()` with trigger `keyword_center`.
- Preserves plugin validation, dedupe, logging, token handling, and transport ownership.
- Fails closed if plugin files or plugin schema are missing.

## Migration Changes

P3 migration now keeps only generic observed metrics support:

- Adds `cms_seo_keyword_metrics.url_path` when needed.
- Adds lookup index for `keyword + url_path + search_engine + period`.

Removed duplicate Baidu-only schema from P3:

- `cms_seo_search_engine_connections`
- `cms_seo_search_engine_submissions`

Existing plugin settings/log tables are untouched:

- `baidu_url_submission_settings`
- `baidu_url_submission_logs`

## Admin UI

`SEO -> 搜索引擎连接` now:

- Shows Baidu connection state from `official.seo.baidu-submit`.
- Shows token owner as `official.seo.baidu-submit`.
- Links to `/admin/seo/baidu-submit` for token/settings management.
- Keeps CSV manual import for observed metrics.
- Uses `/admin/seo/search-engines/submit` only as a Core admin/CSRF/current-site validation wrapper before delegating to the plugin.

Removed Core-owned Baidu token save/sync forms and routes.

## Preservation of Existing Plugin Behavior

Verified unchanged by `php tests/official_baidu_url_submission.php`:

- Article publish enqueue remains supported.
- Queue processing remains supported.
- Retry contract remains owned by the plugin queue job.
- Dedupe window remains enforced.
- Manual/batch submit service remains supported.
- Failed/invalid/not-same-site/quota responses remain logged by the plugin.
- Token remains encrypted/masked and absent from logs.

## Proof Checks

Symbol audit:

- `https://data.zz.baidu.com/urls` appears only in `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php`.
- `baidu_url_submit_token` appears only in the official plugin repository.
- `core.seo.search_engine.baidu` appears only in P3 tests as a negative assertion.
- `cms_seo_search_engine_submissions` appears only in P3 tests as a negative assertion.
- `BaiduSearchResourceProvider` appears only in P3 tests as a negative assertion.

Therefore the system has:

- One Baidu Token owner.
- One actual Baidu POST implementation.
- One Baidu submission fact source.

## Automated Tests

All requested local checks passed:

- `php tests/official_baidu_url_submission.php` PASS, 18 checks.
- `php tests/seo_keyword_center_p2.php` PASS, 40 checks.
- `php tests/seo_search_engine_p3.php` PASS, 15 checks.
- `php tests/core_sitemap.php` PASS, 42 checks.
- `php tests/rest_api_v1.php` PASS, 27 checks.
- `php -l` PASS for touched PHP files.
- `git diff --check` PASS.

Total reported PASS checks: 142.

## Runtime Verification

Local runtime-level verification was covered by the plugin and P3 tests using the real plugin repository/service/log schema with a fake Baidu transport:

- Plugin-owned token was saved through `PluginSecretStore`.
- Core bridge reported connected state from plugin settings.
- Keyword Center submission path called plugin `submitUrls()`.
- Plugin wrote `keyword_center` submission logs.
- Duplicate submit was deduped by the plugin without a second POST.
- Observed metrics import remained independent of target keyword data.

No real production database or production Baidu API call was performed.

## Production Readiness

Local remediation is ready for review. Production release remains blocked on the normal human-approved release/deploy process.

## Final Statement

全系统只有一个百度 Token owner：`official.seo.baidu-submit`。

全系统只有一个百度实际 POST 实现：`official.seo.baidu-submit` 的 `BaiduUrlSubmissionHttpClient`。

全系统只有一个百度提交事实来源：`baidu_url_submission_logs`。

Final Verdict: PASS
