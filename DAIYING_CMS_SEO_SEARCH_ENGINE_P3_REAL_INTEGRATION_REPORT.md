# Daiying CMS SEO Search Engine P3 Real Integration Report

Date: 2026-10-03

## Scope

Final real integration validation for Baidu URL submission after P3 dedupe remediation.

No feature development, no production code deployment, no stable push, no latest API update, and no Core version bump were performed.

## Production Context

- Site: `https://www.daiyingcms.com`
- Production root: `/www/wwwroot/saas.daiyinggame.com`
- Health after validation: `ok`
- Mode after validation: `NORMAL`
- Core version: `1.2.77`
- Release id: `daiying-cms-core-update-1.2.77`
- Maintenance: `false`

## Preflight

Production plugin configuration was read without exposing secrets:

- Plugin installed: YES
- Plugin tables present:
  - `baidu_url_submission_settings`: YES
  - `baidu_url_submission_logs`: YES
  - `cms_plugin_secrets`: YES
- Plugin enabled: YES
- Plugin site URL: `https://www.daiyingcms.com`
- Token configured: YES
- Dedupe window: `1800` seconds

Selected public URL:

`https://www.daiyingcms.com/articles/daiying-webclip-iphone-install-guide`

Public HTTP check before submission:

- HTTP 200
- Content-Type: `text/html; charset=utf-8`

## Real Baidu Submission

Submission path used:

`official.seo.baidu-submit` plugin service with trigger `keyword_center_final_validation`.

The Core P3 direct Baidu POST path was not used.

Baidu response:

- HTTP status: `200`
- result status: `submitted`
- success: `1`
- remain: `9`
- not_same_site: `[]`
- not_valid: `[]`
- deduped: `[]`

## Plugin Log Verification

New plugin log inserted:

- log id: `14`
- URL: `https://www.daiyingcms.com/articles/daiying-webclip-iphone-install-guide`
- trigger_type: `keyword_center_final_validation`
- status: `success`
- http_status: `200`
- baidu_success: `1`
- baidu_remain: `9`
- not_same_site_json: `[]`
- not_valid_json: `[]`
- error_summary: `null`
- created_at: `2026-10-03T13:01:38+00:00`

The Baidu response and plugin log are consistent.

## Keyword Center Status Display

Production active release `1.2.77` does not yet contain the P3 Keyword Center / Search Engine routes:

- `/admin/seo/keywords`
- `/admin/seo/search-engines`

Therefore the Keyword Center UI/status display could not be truthfully verified on production in this pass.

Expected P3 behavior after formal deployment:

- Keyword Center reads Baidu connection status from `official.seo.baidu-submit`.
- Keyword Center reads submission facts from `baidu_url_submission_logs`.
- Keyword Center submit action delegates to the plugin service instead of Core direct POST.

This was already locally verified in `DAIYING_CMS_SEO_SEARCH_ENGINE_P3_DEDUP_REPORT.md`, but production UI verification remains pending until P3 is released/deployed through the normal process.

## Observed Debt

During the production CLI submission, PHP 8.5 emitted a deprecation warning:

- `curl_close()` is deprecated since PHP 8.5.
- File: `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php`

The submission still completed successfully and the plugin log was written. This should be handled in a future compatibility cleanup, not during this no-development validation step.

## Final Result

Real Baidu plugin integration: PASS

Plugin log consistency: PASS

Production Keyword Center UI/status display: NOT VERIFIED because P3 routes are not deployed on production `1.2.77`.

Final verdict: PARTIAL
