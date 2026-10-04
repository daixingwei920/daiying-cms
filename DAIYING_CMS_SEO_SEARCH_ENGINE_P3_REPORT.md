# Daiying CMS SEO Search Engine P3 Report

Date: 2026-10-03
Baseline: `DAIYING_CMS_SEO_KEYWORD_CENTER_P2_REPORT.md`
Scope: Search Engine Data Loop P3 first stage. No production release, no deployment, no Core version bump.

## 1. Baidu Official Capabilities

Reviewed Baidu Search Resource Platform public documentation:

- Baidu Search Resource Platform quick submit page says links can be submitted for processing, but submission does not guarantee inclusion/indexing: https://ziyuan.baidu.com/dailysubmit/index
- Baidu Search Resource Platform announcement says the normal inclusion API endpoint is `http://data.zz.baidu.com/urls?site=xxx&token=xxx`, visible in `资源提交-普通收录-API提交`: https://ziyuan.baidu.com/wiki/3019?qq-pf-to=pcqq.group
- Baidu Search Resource Platform docs describe API push and Sitemap submission, with token obtained after entering the API push tool: https://ziyuan.baidu.com/college/courseinfo?id=267&page=2
- Baidu index-volume documentation describes index volume as a logged-in Search Resource Platform data/statistics tool, not a public API: https://zy.baidu.com/cse/wiki/index?category_id=7&id=1994

Confirmed safe official capabilities:

- URL active push / normal inclusion API.
- Sitemap submission exists as a Search Resource Platform feature.
- Index volume and traffic/keyword tools exist in logged-in platform UI.
- API submit requires verified site and token.

## 2. Baidu Unavailable Capabilities

No public official Baidu API evidence was found for programmatic:

- keyword-level impressions
- keyword-level clicks
- keyword CTR
- average keyword position
- URL-level search performance
- definitive URL indexed/not-indexed status

Conclusion:

`NOT AVAILABLE FROM OFFICIAL BAIDU API`

P3 therefore does not fabricate Baidu search performance data. It records Baidu sync as `not_available` and uses manual CSV import as the first reliable fallback.

## 3. P3 Architecture

Added search engine connector layer:

- `system/core/Seo/SearchEngine/SearchEngineConnectorInterface.php`
- `system/core/Seo/SearchEngine/BaiduSearchResourceProvider.php`
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php`

Keyword Center continues to use P2 target data:

- `targetKeywordBindings()`
- `targetKeywordConflicts()`
- `cms_seo_keywords`

Observed search engine data is read separately from `cms_seo_keyword_metrics`.

## 4. Database Changes

New migration:

- `system/migrations/2026_10_03_000003_seo_search_engine_p3.php`

Changes:

- Adds `url_path` to `cms_seo_keyword_metrics`.
- Adds `cms_seo_search_engine_connections`.
- Adds `cms_seo_search_engine_submissions`.
- Adds lookup indexes.

Existing P2 tables and fields are preserved.

## 5. Admin UI

New backend entry:

- `SEO -> 搜索引擎连接`

Routes:

- `GET /admin/seo/search-engines`
- `POST /admin/seo/search-engines/baidu/save`
- `POST /admin/seo/search-engines/baidu/sync`
- `POST /admin/seo/search-engines/import`
- `POST /admin/seo/search-engines/submit`

Keyword detail now separates:

- Target Data / Bindings
- Observed Data
- Baidu URL Submission

Missing observed values display `Not Available`, not `0`.

## 6. Credential Safety

Baidu token uses existing Core secret mechanism:

- `PluginSecretStore`
- pseudo owner: `core.seo.search_engine.baidu`
- encrypted into `cms_plugin_secrets`

Security rules implemented:

- token is never rendered back into HTML
- token is not placed in GET params by admin routes
- token is not written to report
- token value is not logged
- runtime test verified token string did not appear in HTML

## 7. Sync Flow

P3 has manual Sync now for Baidu.

Because Baidu keyword metrics API is not confirmed available from official docs, Sync records:

- status: `not_available`
- message: `NOT AVAILABLE FROM OFFICIAL BAIDU API ...`
- last sync timestamp

Sync does not overwrite CMS target keywords.

## 8. URL Submission Flow

Implemented Baidu URL submission through provider abstraction.

Rules:

- admin required
- CSRF required
- URL must belong to current `site.url`
- only HTTP/HTTPS
- no credentials or fragments in URL
- simple repeated-submit rate limit
- submission log stores actual provider result
- UI states `Submitted / Submission Accepted != Indexed`

No code claims a submitted URL is indexed.

## 9. Manual Import Fallback

Implemented CSV import into `cms_seo_keyword_metrics`.

Required CSV columns:

- `keyword`
- `url_path`
- `search_engine`
- `impressions`
- `clicks`
- `ctr`
- `average_position`
- `period_start`
- `period_end`

Imported rows use:

- `source = manual_import`

Duplicate imports update existing rows by keyword, URL, engine, period, and source.

## 10. GSC Readiness

Schema and provider abstraction are ready for Google Search Console:

- same `cms_seo_keyword_metrics` table
- same `search_engine` field
- same observed data UI
- no second keyword center

GSC was not implemented in this stage.

## 11. Automated Tests

Added:

- `tests/seo_search_engine_p3.php`

Covered:

- provider abstraction
- disconnected state
- connected state
- credential failure
- sync not available result
- encrypted secret storage
- submission accepted != indexed
- submission evidence level
- historical metrics
- manual import
- duplicate import
- multiple search engines
- missing metrics -> `Not Available`
- target data not modified by observed data
- P2 conflict detection intact

Results:

- `php tests/seo_search_engine_p3.php`: PASS
- `php tests/seo_keyword_center_p2.php`: PASS
- `php tests/core_sitemap.php`: PASS
- `php tests/rest_api_v1.php`: PASS
- PHP syntax checks: PASS
- `git diff --check`: PASS

`php tests/release_phase0_gate.php` was started but did not complete after extended waiting; it was interrupted fail-closed and is not counted as PASS.

## 12. Runtime Verification

Temporary runtime:

- `/tmp/daiying-seo-p3-http`
- `http://127.0.0.1:8768`
- SQLite database
- real admin login

Verified:

- unauthenticated `/admin/seo/search-engines` redirects to login
- admin login succeeds
- connection page HTTP 200
- Baidu panel visible
- disconnected state visible
- Baidu connection save redirects
- connected state visible
- credential configured label visible
- raw token does not appear in HTML
- Baidu sync redirects
- sync records `NOT AVAILABLE FROM OFFICIAL BAIDU API`
- CSV import inserted observed rows
- Keyword detail shows Observed Data
- imported Baidu impressions visible
- missing Google metrics render `Not Available`
- submission requires CSRF
- external URL rejected
- same-site submission route records provider result
- submission log records `credential_missing` without claiming indexed
- UI distinguishes Submitted from Indexed

## 13. Known Debt

- No real Baidu account/token live submission was executed.
- No official Baidu keyword metrics API was available to integrate.
- `release_phase0_gate.php` did not complete in this run.
- GSC provider is prepared structurally but not implemented.
- Cron sync is not implemented; only manual sync exists.

## 14. Production Readiness

Not ready for production release yet.

Reasons:

- Final release gate did not complete.
- No live Baidu credential/account verification was performed.
- Baidu keyword performance data is manual-import only in this stage.

No production database, stable channel, latest API, or production deployment was modified.

## 15. Final Verdict

PARTIAL
