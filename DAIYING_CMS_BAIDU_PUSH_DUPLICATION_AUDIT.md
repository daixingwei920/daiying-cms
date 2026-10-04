# Daiying CMS Baidu Push Duplication Audit

Date: 2026-10-03
Scope: Read-only audit of existing Baidu URL submission plugin versus P3 Core `BaiduSearchResourceProvider` and `/admin/seo/search-engines/submit`.

No code changes, migrations, deletion, production deployment, or release actions were performed as part of this audit.

## Executive Summary

Existing official plugin:

- `official.seo.baidu-submit`
- Path: `content/plugins/official.seo.baidu-submit`
- Purpose: Baidu Search Resource Platform URL submission

P3 introduced a second Core-level Baidu URL submission path:

- `Cms\Core\Seo\SearchEngine\BaiduSearchResourceProvider`
- `/admin/seo/search-engines/submit`
- `cms_seo_search_engine_submissions`
- `core.seo.search_engine.baidu:token`

These overlap materially with the existing official plugin.

Conclusion:

Do not keep two independent Baidu push implementations.

Recommended ownership:

`official.seo.baidu-submit` should remain the unique implementation for actual Baidu URL submission, token ownership, queueing, dedupe, Baidu response parsing, and submission logs.

Core SEO Keyword/Search Engine Center should only consume a unified submission/status interface and display plugin-provided status. It should not store a separate Baidu token or POST directly to Baidu.

## Existing Baidu Push Plugin

### Identity

Manifest:

- `plugin_id`: `official.seo.baidu-submit`
- `name`: `Baidu URL Submit`
- `version`: `0.1.0-alpha.2`
- `entry`: `plugin.php`
- `trust_level`: `trusted_php`
- `capabilities`: `seo.manage`, `seo.submit`, `queue.register`, `network.external`
- `table_prefixes`: `baidu_url_submission_`

Evidence:

- `content/plugins/official.seo.baidu-submit/plugin.json:2`
- `content/plugins/official.seo.baidu-submit/plugin.json:16`
- `content/plugins/official.seo.baidu-submit/plugin.json:27`

### Admin Routes

The plugin registers:

- `GET /admin/seo/baidu-submit`
- `POST /admin/seo/baidu-submit/save`
- `POST /admin/seo/baidu-submit/submit`
- `POST /admin/seo/baidu-submit/process-queue`

Evidence:

- `content/plugins/official.seo.baidu-submit/plugin.php:24`
- `content/plugins/official.seo.baidu-submit/plugin.php:25`
- `content/plugins/official.seo.baidu-submit/plugin.php:26`
- `content/plugins/official.seo.baidu-submit/plugin.php:27`

### Baidu API Endpoint

The plugin posts to:

`https://data.zz.baidu.com/urls?site={siteUrl}&token={token}`

Request body is newline-separated URLs with `Content-Type: text/plain`.

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php:14`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php:20`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php:21`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php:22`

### Token Storage

The plugin stores the Baidu token through `PluginSecretStore`:

- plugin id: `official.seo.baidu-submit`
- secret key: `baidu_url_submit_token`

Blank token saves preserve the existing token.

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:13`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:14`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:61`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:66`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:71`

### Tables

Plugin-owned tables:

- `baidu_url_submission_settings`
- `baidu_url_submission_logs`

Evidence:

- `content/plugins/official.seo.baidu-submit/migrations/001_baidu_url_submission.php`

### Manual Submission

The admin UI supports manual submission of one or more URLs via textarea, split by line.

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionController.php:41`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionController.php:43`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionController.php:89`

This can be used for historical URL backfill if an admin pastes historical URLs. I did not find a built-in “crawl all historical content and enqueue all URLs” button.

### Publish Auto-Push

The plugin listens to `ContentPublishedEvent` and enqueues the public URL.

Evidence:

- `content/plugins/official.seo.baidu-submit/plugin.php:38`
- `content/plugins/official.seo.baidu-submit/plugin.php:42`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php:22`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php:33`

### Queue / Retry

The plugin registers queue job type:

- `baidu_url_submission.submit`

Queued jobs are created with max attempts `3`.

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php:13`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php:37`
- `content/plugins/official.seo.baidu-submit/plugin.php:30`
- `content/plugins/official.seo.baidu-submit/plugin.php:35`

The admin UI exposes a manual `process-queue` action for ordinary PHP hosting.

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionController.php:49`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionController.php:92`

### Dedupe

The plugin dedupes by URL hash within configurable `dedupe_window_seconds`.

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php:28`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php:79`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:76`

### Logs

The plugin logs:

- URL
- URL hash
- trigger type
- content id/type
- status
- HTTP status
- Baidu `success`
- Baidu `remain`
- Baidu `not_same_site`
- Baidu `not_valid`
- redacted raw response
- redacted error summary
- created time

Evidence:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:87`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:102`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:118`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php:119`

## P3 Core Baidu Submission Additions

P3 added Core routes:

- `GET /admin/seo/search-engines`
- `POST /admin/seo/search-engines/baidu/save`
- `POST /admin/seo/search-engines/baidu/sync`
- `POST /admin/seo/search-engines/import`
- `POST /admin/seo/search-engines/submit`

Evidence:

- `system/core/Bootstrap/Application.php:480`
- `system/core/Bootstrap/Application.php:481`
- `system/core/Bootstrap/Application.php:482`
- `system/core/Bootstrap/Application.php:483`
- `system/core/Bootstrap/Application.php:484`

P3 added Core Baidu provider direct POST:

- `Cms\Core\Seo\SearchEngine\BaiduSearchResourceProvider::submitUrl()`
- endpoint: `https://data.zz.baidu.com/urls?site=...&token=...`

Evidence:

- `system/core/Seo/SearchEngine/BaiduSearchResourceProvider.php:47`
- `system/core/Seo/SearchEngine/BaiduSearchResourceProvider.php:54`
- `system/core/Seo/SearchEngine/BaiduSearchResourceProvider.php:77`

P3 added separate Core token owner:

- `core.seo.search_engine.baidu`
- key: `token`

Evidence:

- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php:12`
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php:49`
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php:58`

P3 added separate Core submission log table:

- `cms_seo_search_engine_submissions`

Evidence:

- `system/migrations/2026_10_03_000003_seo_search_engine_p3.php:36`
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php:88`

## Functional Overlap Matrix

| Capability | Existing plugin | P3 Core addition | Duplication risk |
|---|---:|---:|---|
| Baidu URL POST endpoint | Yes | Yes | High |
| Baidu token storage | Yes, `official.seo.baidu-submit:baidu_url_submit_token` | Yes, `core.seo.search_engine.baidu:token` | Critical |
| Admin save token/site | Yes | Yes | High |
| Manual URL submit | Yes, one or many URLs | Yes, one URL | High |
| Submission logs | Yes, rich Baidu fields | Yes, generic submission log | High |
| Same-site URL validation | Yes | Yes | Medium |
| CSRF/admin route | Yes via plugin route registration | Yes in Core controller | Medium |
| Dedupe/rate limit | Yes, configurable window | Minimal fixed 60s recent-submit check | P3 weaker |
| Publish auto-push | Yes | No | Plugin stronger |
| Queue/retry | Yes, queue max attempts 3 | No queue | Plugin stronger |
| Batch submit | Yes via textarea/newline | No | Plugin stronger |
| Historical backfill | Manual batch paste only; no auto-crawl button found | No | Plugin still stronger |
| Baidu response parsing | Parses success/remain/not_same_site/not_valid | Generic raw response only | Plugin stronger |
| Keyword metrics import | No | Yes | P3 unique |
| Observed keyword metrics display | No | Yes | P3 unique |

## Conflict / Risk Assessment

### Critical: Duplicate Token Ownership

Existing plugin stores the token under:

- `official.seo.baidu-submit`
- `baidu_url_submit_token`

P3 stores a second token under:

- `core.seo.search_engine.baidu`
- `token`

This violates the requirement that Token must not be saved twice. It also creates confusing admin behavior: `/admin/seo/baidu-submit` and `/admin/seo/search-engines` can disagree about whether Baidu is connected.

### High: Duplicate Submission Paths

Both implementations POST to Baidu independently. That risks:

- duplicate URL submissions
- inconsistent dedupe windows
- mismatched status wording
- split logs
- harder troubleshooting
- possible quota waste

### High: Split Logs

Existing plugin logs to:

- `baidu_url_submission_logs`

P3 logs to:

- `cms_seo_search_engine_submissions`

The Keyword Center would not reflect the richer existing plugin logs unless a bridge is added.

### Medium: P3 Core Implementation Is Weaker

The P3 Core route lacks:

- queue retry
- publish auto-push
- batch submit
- detailed Baidu response mapping
- configurable dedupe window

The plugin already has these.

## Recommended Single-Implementation Decision

Use the official plugin as the only Baidu URL submission implementation.

Core SEO Keyword/Search Engine Center should:

1. Stop owning Baidu token storage.
2. Stop directly POSTing to Baidu.
3. Stop writing separate Baidu submission logs.
4. Read plugin connection/status/log data through a unified Core-facing interface.
5. Call plugin submission through a stable interface when an admin submits a Primary Landing Page.

Recommended conceptual split:

- Plugin owns third-party Baidu business:
  - token
  - site URL
  - actual POST
  - queue
  - dedupe
  - Baidu response parsing
  - submission logs

- Core Keyword/Search Engine Center owns generic SEO data center:
  - target keywords
  - primary landing page
  - observed keyword metrics
  - manual CSV import
  - display of provider status
  - no direct Baidu credential or transport logic

## Existing Plugin Configuration Preservation

Do not migrate or delete current plugin config automatically.

Preserve:

- `baidu_url_submission_settings`
- `baidu_url_submission_logs`
- `PluginSecretStore` secret under `official.seo.baidu-submit:baidu_url_submit_token`

If future Core UI wants to show Baidu connection status, it should detect/read the plugin’s settings and masked token status. It should not create `core.seo.search_engine.baidu:token`.

## P3 Adjustment Required Before Continuing

Before continuing P3.1/P3.2 implementation:

1. Remove or disable the Core direct Baidu submission provider path from P3.
2. Keep generic observed metrics and manual import only if they do not depend on Core-owned Baidu token/submission.
3. Introduce a small provider/status abstraction that can be backed by `official.seo.baidu-submit`.
4. Wire Keyword Center “Submit Primary to Baidu” to the plugin interface, not to `BaiduSearchResourceProvider`.
5. Display plugin logs/status in Keyword Center or link to `/admin/seo/baidu-submit`.
6. Do not run P3 migration in production until the duplication is resolved.

## Audit Evidence

Command run:

- `php tests/official_baidu_url_submission.php`

Result:

- PASS

Important tested guarantees:

- Manifest plugin id and capabilities.
- Token encrypted at rest.
- Token masked in admin output.
- Manual same-site submit.
- Baidu `success`, `remain`, `not_same_site`, and `not_valid` preserved.
- Dedupe prevents repeated POST.
- Cross-site URLs rejected.
- Queue integration.
- Content publish event integration.
- Logs do not contain token.

## Final Audit Verdict

BLOCKED_FOR_P3_CONTINUATION

Reason:

P3 currently contains a duplicate Core Baidu URL submission implementation. Daiying CMS should converge on the existing `official.seo.baidu-submit` plugin as the unique Baidu push implementation before any further P3 development, tests, release packaging, or production deployment.
