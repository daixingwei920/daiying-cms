# Daiying CMS Baidu Plugin Availability / UI State Fix Report

Date: 2026-10-04
Scope: Core 1.2.79 RC blocker fix, local only

Final Status: BAIDU PLUGIN AVAILABILITY UI FIX: PASS

## Root Cause

`OfficialBaiduSubmitBridge::status()` previously collapsed multiple states into `Not Connected`.

It only checked:

- plugin file existence
- plugin tables
- token presence

It did not distinguish:

- plugin row missing for the current site
- plugin disabled
- admin route unavailable
- plugin installed but missing Token
- integration/schema errors

The Search Engine UI then always rendered:

- `管理百度推送插件`
- `/admin/seo/baidu-submit`

even when the plugin was not installed/enabled/route-available for that site, producing a dead 404 link.

## Changed Files

- `system/core/Seo/SearchEngine/OfficialBaiduSubmitBridge.php`
- `system/core/Admin/AdminController.php`
- `tests/baidu_plugin_availability_ui.php`
- `tests/seo_search_engine_p3.php`
- `DAIYING_CMS_BAIDU_PLUGIN_AVAILABILITY_UI_FIX_REPORT.md`

Existing P4.1 files remain part of the local worktree:

- `system/core/Seo/Keyword/SeoLifecycleAggregator.php`
- `tests/seo_lifecycle_p4_1.php`
- `DAIYING_CMS_SEO_P4_INDEXING_OBSERVATION_ARCHITECTURE.md`
- `DAIYING_CMS_SEO_P4_1_IMPLEMENTATION_REPORT.md`

## Plugin State Model

`OfficialBaiduSubmitBridge::status()` now returns structured state:

- `plugin_files_present`
- `plugin_installed`
- `plugin_enabled`
- `route_available`
- `configured`
- `connected`
- `status`
- `status_label`
- `management_available`
- `manage_url`
- `message`

Status values:

- `connected`
- `not_configured`
- `plugin_disabled`
- `plugin_not_installed`
- `integration_error`

Definitions:

- Plugin installed: plugin files exist and current site DB has `cms_plugins.plugin_id = official.seo.baidu-submit` with non-removed status.
- Plugin enabled: installed and `cms_plugins.status = Enabled`.
- Route available: enabled and manifest declares `/admin/seo/baidu-submit`.
- Configured/connected: route available, plugin settings enabled, site URL set, and plugin-owned Token configured.

## UI State Matrix

| Scenario | Status | Manage Button |
| --- | --- | --- |
| Installed + Enabled + Configured + Route Available | Connected | Shown |
| Installed + Enabled + Missing Token + Route Available | Not Configured | Shown |
| Installed + Disabled | Plugin Disabled | Hidden |
| Not Installed | Plugin Not Installed | Hidden |
| Installed + Enabled + Route Missing | Integration Error | Hidden |

Hidden manage button states render explanatory text instead of a dead link:

- `百度推送插件未安装`
- `百度推送插件未启用`
- `百度推送插件路由不可用`

## Multi-site Isolation

The bridge reads state from the current site's PDO only:

- `cms_plugins`
- `baidu_url_submission_settings`
- `baidu_url_submission_logs`
- `cms_plugin_secrets`

Test coverage confirms:

- Site A configured and connected stays connected.
- Site B with same codebase but missing Token is `Not Configured`.
- Site B does not inherit Site A Token/config/log status.

## Runtime Verification

Runtime-style verification is covered by `tests/baidu_plugin_availability_ui.php`.

It renders the actual `seoSearchEnginesHtml()` UI for:

- connected plugin
- missing token
- disabled plugin
- not installed plugin
- route missing integration error

Verified:

- connected/not configured states render valid `/admin/seo/baidu-submit` manage button
- disabled/not installed/route missing states do not render the dead link
- user-facing text explains the exact state

## Automated Tests

New:

- `php tests/baidu_plugin_availability_ui.php` PASS

Required regression:

- `php tests/seo_lifecycle_p4_1.php` PASS
- `php tests/seo_keyword_center_p2.php` PASS
- `php tests/seo_search_engine_p3.php` PASS
- `php tests/official_baidu_url_submission.php` PASS
- `php tests/core_sitemap.php` PASS
- `php tests/rest_api_v1.php` PASS

Syntax:

- `php -l system/core/Seo/SearchEngine/OfficialBaiduSubmitBridge.php` PASS
- `php -l system/core/Admin/AdminController.php` PASS
- `php -l system/core/Seo/Keyword/SeoLifecycleAggregator.php` PASS
- `php -l system/core/Seo/SearchEngine/SearchEngineDataRepository.php` PASS
- `php -l tests/baidu_plugin_availability_ui.php` PASS
- `php -l tests/seo_lifecycle_p4_1.php` PASS
- `php -l tests/seo_search_engine_p3.php` PASS

Patch hygiene:

- `git diff --check` PASS

## P4.1 Regression

P4.1 remains PASS.

The fix does not change:

- lifecycle aggregator storage model
- metrics trend aggregation
- sitemap membership policy
- index evidence policy
- opportunity score formula
- Keyword Center dashboard/detail behavior

## Baidu Single Ownership Check

Still true:

- Token owner: `official.seo.baidu-submit`
- Actual POST: `official.seo.baidu-submit`
- Submission logs: `baidu_url_submission_logs`
- Core does not store Baidu Token
- Core does not directly call `data.zz.baidu.com`

Search check:

- The only `data.zz.baidu.com` occurrence is in `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php`.
- Core direct provider class `BaiduSearchResourceProvider` remains absent.
- Core token key `core.seo.search_engine.baidu` exists only in negative tests.

## Release Readiness

This blocker is fixed locally and ready for Core 1.2.79 RC review.

Not performed:

- no Core version bump
- no production deployment
- no stable/latest update
- no release package generation

Final Status: BAIDU PLUGIN AVAILABILITY UI FIX: PASS
