# Daiying CMS SEO P4.2 — Search Metrics Data Acquisition Architecture

Status: investigation / architecture only
Date: 2026-10-05
Scope: no production code changes, no production database changes

## Executive Summary

P4.2 should solve the current problem where `With Observed Data` stays at 0 unless someone manually imports metrics.

The existing semantics should stay unchanged:

```
Target Keyword + Primary Landing Page
-> cms_seo_keyword_metrics row for same keyword and canonical-equivalent URL
-> SeoLifecycleAggregator marks observed_search_data = yes
-> Keyword Center increments With Observed Data
```

Important separation remains mandatory:

```
Submitted != Crawled != Indexed != Observed Search Data
```

The recommended MVP is:

1. Keep Baidu URL submission owned only by `official.seo.baidu-submit`.
2. Do not implement Baidu search metrics sync unless Baidu provides a documented official metrics API.
3. Implement Google Search Console as the first automatic Search Metrics Provider.
4. Keep Manual CSV Import as the Baidu fallback and general fallback.
5. Write all observed search metrics into existing `cms_seo_keyword_metrics`.
6. Let existing `SeoLifecycleAggregator` and Keyword Center consume the same table.

## Current Daiying CMS Data Path

Current P4.1 reads observed metrics from:

- `cms_seo_keyword_metrics`
- `SeoLifecycleAggregator::metricsByKeyword()`
- `SeoLifecycleAggregator::filterMetricsForUrl()`
- `SeoLifecycleAggregator::stats()`

Relevant implementation:

- `system/core/Seo/Keyword/SeoLifecycleAggregator.php`
  - Loads metrics once from `cms_seo_keyword_metrics`.
  - Groups rows by `keyword`.
  - Filters metrics by canonical-equivalent `url_path` vs keyword `primary_url`.
  - Sets `observed_search_data = yes` only when primary URL metrics exist.
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php`
  - Inserts/updates rows into `cms_seo_keyword_metrics`.
  - Current source is mainly `manual_import`.
- `system/core/Admin/AdminController.php`
  - `/admin/seo/search-engines/import`
  - CSV import writes metrics through `SearchEngineDataRepository`.

Current production state observed before this architecture:

- `cms_seo_keywords`: 10 rows
- `cms_seo_keyword_metrics`: 0 rows
- SEO/search/Baidu scheduled tasks: 0 matching tasks

Therefore current production has no automatic observed metrics collection chain.

## Official Capability Research

### Baidu Search Resource Platform

Official Baidu Search Resource Platform exposes site owner tools for:

- resource submission
- normal inclusion / API submission
- sitemap submission
- index volume
- traffic and keywords
- crawl diagnostics

The public Baidu Search Resource Platform page lists `流量与关键词` under data statistics and resource submission tools such as `普通收录`, `快速抓取`, and sitemap submission. Source: https://ziyuan.baidu.com/site/index

Baidu official documentation/pages confirm API submission exists for URL discovery/resource submission, and that normal inclusion tools shorten crawler discovery time. Source: https://ziyuan.baidu.com/college/articleinfo?id=267&page=2 and https://ziyuan.baidu.com/college/articleinfo?id=3076

However, no official, stable, public Baidu Search Resource Platform API documentation was found for programmatically retrieving:

- keyword impressions
- keyword clicks
- CTR
- average position
- keyword + URL performance rows

Baidu has UI-level tools and historical announcements describing keyword/traffic reports, but this investigation did not find a documented Search Console-like API equivalent for export/sync of those metrics.

Architecture conclusion:

```
Baidu automatic keyword/search-performance provider: NOT AVAILABLE FROM PUBLIC OFFICIAL API at this time.
```

Do not scrape Baidu pages, simulate searches, bypass login/captcha, or reverse-engineer private endpoints.

### Google Search Console

Google Search Console has an official Search Analytics API.

The official API supports `searchanalytics.query`, returns rows with:

- `clicks`
- `impressions`
- `ctr`
- `position`

and can be grouped/filtered by dimensions such as query and page. Source: https://developers.google.com/webmaster-tools/v1/searchanalytics/query

The official guide states that Search Analytics exposes Search Console Performance report data and supports top queries/pages. Source: https://developers.google.com/webmaster-tools/v1/how-tos/search_analytics

Authorization requires OAuth 2.0. The read-only scope is:

```
https://www.googleapis.com/auth/webmasters.readonly
```

Source: https://developers.google.com/webmaster-tools/v1/how-tos/authorizing

Google also has a URL Inspection API for Google index status. This is not observed search performance. It belongs in index evidence, not observed metrics. Source: https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect

Architecture conclusion:

```
Google automatic keyword/search-performance provider: AVAILABLE via official API.
```

## Proposed Unified Provider Architecture

Introduce a dedicated search metrics provider abstraction. Do not reuse the P3 URL submission abstraction as-is, because URL submission and search performance sync have different credentials, capabilities, and semantics.

Suggested interface:

```php
interface SearchMetricsProviderInterface
{
    public function engine(): string;

    public function capabilities(): array;

    public function connectionStatus(SiteContext $site): SearchMetricsConnectionStatus;

    public function sync(SearchMetricsSyncRequest $request): SearchMetricsSyncResult;
}
```

Suggested providers:

```text
SEO/SearchMetrics/
  SearchMetricsProviderInterface
  SearchMetricsSyncRequest
  SearchMetricsSyncResult
  SearchMetricRow
  ManualCsvSearchMetricsProvider
  GoogleSearchConsoleMetricsProvider
  BaiduSearchResourceMetricsProvider
```

Provider routing:

```text
Google Search Console API
  -> GoogleSearchConsoleMetricsProvider
  -> SearchMetricRow[]
  -> SearchEngineDataRepository
  -> cms_seo_keyword_metrics
  -> SeoLifecycleAggregator
  -> Keyword Center

Baidu Search Resource Platform
  -> only if official metrics API is later documented
  -> otherwise Not Available

Manual CSV Import
  -> ManualCsvSearchMetricsProvider / existing import parser
  -> SearchEngineDataRepository
  -> cms_seo_keyword_metrics
```

## Existing Metrics Table Mapping

Continue using:

```text
cms_seo_keyword_metrics
```

Required fields already exist:

- `keyword`
- `url_path`
- `search_engine`
- `impressions`
- `clicks`
- `ctr`
- `average_position`
- `period_start`
- `period_end`
- `source`
- `created_at`

Provider-to-table mapping:

| Provider | search_engine | source |
|---|---:|---|
| Google Search Console | `google` | `google_search_console` |
| Baidu official metrics API, only if available later | `baidu` | `baidu_search_resource_api` |
| Manual CSV Import | `baidu`, `google`, `bing`, or `manual` from CSV | `manual_import` |

## Deduplication Design

Current repository dedupes by:

```text
keyword + url_path + search_engine + period_start + period_end + source
```

This is enough for idempotent retry of the same provider.

P4.2 should also protect against cross-source double counting. Example risk:

- same Baidu row imported manually as `manual_import`
- same future Baidu API row imported as `baidu_search_resource_api`
- aggregator may read both rows and double count trend totals

Minimum policy:

1. Automatic providers must use stable source IDs.
2. Re-running the same provider updates existing rows, not inserts duplicates.
3. UI should show source-level provenance.
4. If an automatic provider is enabled for an engine, manual import for the same engine/date/url/keyword should warn before importing.
5. Long-term, trend aggregation should either:
   - prefer provider source over manual source for identical engine/date/url/keyword, or
   - allow admin to select active metric source per engine.

For P4.2 MVP, avoid implementing automatic Baidu metrics, so the main duplicate risk is Google manual import vs Google API.

## Authorization Requirements

### Baidu

Current Baidu token ownership must remain:

```text
Token owner: official.seo.baidu-submit
Actual URL POST: official.seo.baidu-submit
Submission facts: baidu_url_submission_logs
```

That token is for URL submission, not confirmed as a search performance API token.

If Baidu later documents a metrics API, P4.2 must re-evaluate whether it uses:

- the same Search Resource Platform token
- OAuth/account authorization
- site-bound credentials
- partner-only authorization

Until then:

```text
Baidu Observed Search Data = manual CSV import only.
```

### Google

Google provider needs OAuth 2.0:

- Google Cloud project
- Search Console API enabled
- OAuth client
- admin consent flow
- refresh token stored using existing secure secret storage
- read-only scope: `https://www.googleapis.com/auth/webmasters.readonly`
- selected GSC property, e.g. `https://www.daiyingcms.com/` or `sc-domain:daiyingcms.com`

No Google credential should be stored in normal settings, HTML, logs, reports, or URLs.

## Cron / Queue Runtime

Use existing Core scheduler foundation, not an ad-hoc cron endpoint.

Suggested scheduled task:

```text
task_id: core.seo.search_metrics.sync
owner: core.seo
interval: daily
```

Runtime policy:

1. Site-scoped sync.
2. Lock per site + provider to prevent concurrent duplicate imports.
3. Default date window:
   - normal run: finalized recent dates only, e.g. 3 to 7 days behind today
   - initial backfill: configurable 30 / 90 days
4. Retry-safe: same provider/date rows update existing rows.
5. Failure-safe: failed sync must not delete existing metrics.
6. Last sync status should be displayed in Search Engine UI.
7. Sync should never modify target keywords, primary landing pages, SEO title, description, canonical, slug, or sitemap.

## Google Sync Query Strategy

Recommended query:

```json
{
  "startDate": "YYYY-MM-DD",
  "endDate": "YYYY-MM-DD",
  "dimensions": ["query", "page", "date"],
  "rowLimit": 25000,
  "startRow": 0,
  "type": "web",
  "dataState": "final"
}
```

Row mapping:

```text
keys[0] -> keyword
keys[1] -> url_path
keys[2] -> period_start / period_end
clicks -> clicks
impressions -> impressions
ctr -> ctr
position -> average_position
```

For each date row:

```text
period_start = date
period_end = date
```

Use pagination via `startRow` until no rows remain.

## With Observed Data Automatic Change

After P4.2 automatic Google sync is implemented, `With Observed Data` changes automatically when:

1. A scheduled/manual sync successfully fetches Google Search Console rows.
2. At least one fetched row maps to:
   - an existing target keyword, and
   - the keyword's primary landing page by canonical-equivalent URL.
3. The row is inserted or updated in `cms_seo_keyword_metrics`.
4. Keyword Center dashboard reloads and `SeoLifecycleAggregator` sees primary URL metrics.

Example:

```text
cms_seo_keywords.keyword = "Daiying CMS Stripe"
cms_seo_keywords.primary_url = "/articles/daiying-cms-stripe-payment-settings"

cms_seo_keyword_metrics.keyword = "Daiying CMS Stripe"
cms_seo_keyword_metrics.url_path = "https://www.daiyingcms.com/articles/daiying-cms-stripe-payment-settings"
cms_seo_keyword_metrics.search_engine = "google"
cms_seo_keyword_metrics.impressions = 12
cms_seo_keyword_metrics.period_start = "2026-10-04"
cms_seo_keyword_metrics.period_end = "2026-10-04"
cms_seo_keyword_metrics.source = "google_search_console"
```

Then that keyword row counts as `With Observed Data`.

Baidu crawling alone will still not change this number unless a real Baidu metrics provider or manual import writes metrics rows.

## Submitted / Indexed / Observed Model

Keep separate fact models:

| State | Meaning | Source | Writes to metrics? |
|---|---|---|---|
| Submitted | CMS/plugin submitted URL to a search engine | `baidu_url_submission_logs` for Baidu | No |
| Crawled | Search engine fetched page | Only if official API exposes crawl evidence | No |
| Indexed | Search engine index evidence exists | Google URL Inspection API, or future official Baidu equivalent | No |
| Observed Search Data | Search performance exists | `cms_seo_keyword_metrics` | Yes |

For Baidu:

- URL submission success means only submitted/accepted by submission channel.
- It does not prove crawl.
- It does not prove index.
- It does not create observed search data.

For Google:

- Search Analytics rows create observed search data.
- URL Inspection results create index evidence.
- These should not be merged into the same table.

## Manual Import Requirements

Manual CSV Import remains necessary for:

1. Baidu search performance unless/until Baidu exposes a documented official metrics API.
2. Bing or other engines before dedicated providers exist.
3. Historical backfills.
4. Recovery from failed provider auth.
5. One-off external data enrichment.

CSV format should stay compatible with current UI:

```csv
keyword,url_path,search_engine,impressions,clicks,ctr,average_position,period_start,period_end
```

## P4.2 Minimal Implementation Scope

Worth implementing: YES, but only with Google automatic sync first.

Minimum viable P4.2:

1. Add `SearchMetricsProviderInterface`.
2. Keep existing `SearchEngineDataRepository` as the single writer to `cms_seo_keyword_metrics`.
3. Wrap existing CSV import as Manual CSV provider.
4. Add Google Search Console provider.
5. Add Google OAuth connection UI.
6. Add site-scoped scheduled sync using existing scheduler.
7. Add Search Engine UI status:
   - Google: Connected / Not Connected / Auth Error / Last Sync
   - Baidu metrics: Not Available from official API
   - Manual CSV: Available
8. Keep Baidu URL submission plugin untouched.
9. Do not add Baidu scraping or SERP simulation.

Optional later:

1. Google URL Inspection provider for index evidence.
2. Source-precedence policy in trend aggregation.
3. Bing Webmaster API provider if official capability is confirmed.
4. Export/import audit trail if legal/compliance reporting requires it.

## Direct Answers Required by Task

### 1. 百度能否官方自动获取关键词搜索表现？

Current answer: not confirmed / not available from public official API.

Baidu Search Resource Platform has UI tools for `流量与关键词`, but this investigation did not find a stable public official API for keyword+URL impressions/clicks/CTR/average position.

Do not implement automatic Baidu observed metrics unless official API documentation is obtained.

### 2. Google 能否自动获取？

Yes.

Google Search Console Search Analytics API can return clicks, impressions, CTR, and average position, grouped by query/page/date, with OAuth 2.0 authorization.

### 3. 哪些数据必须人工导入？

For now:

- Baidu keyword/search performance
- any Baidu URL-level observed metrics
- any unsupported search engine metrics
- historical data not accessible through connected providers

### 4. 自动同步需要哪些授权？

Google:

- verified Search Console property
- OAuth 2.0 credentials
- admin consent
- refresh token
- read-only scope `https://www.googleapis.com/auth/webmasters.readonly`

Baidu:

- no automatic metrics authorization should be implemented until official metrics API exists.

### 5. Cron/队列如何运行？

Use Core scheduler:

- daily site-scoped task
- provider lock
- idempotent import
- no deletion of previous metrics on failure
- no target keyword mutation

### 6. 如何写入现有 metrics 表？

Providers emit normalized `SearchMetricRow`.

`SearchEngineDataRepository` upserts into `cms_seo_keyword_metrics`.

### 7. 如何防止重复数据？

Use stable row identity:

```text
keyword + url_path + search_engine + period_start + period_end + source
```

Add cross-source duplicate policy before enabling multiple providers for the same engine.

### 8. With Observed Data 在什么情况下自动变化？

It changes automatically only after a provider sync writes at least one metrics row whose:

- `keyword` matches a target keyword
- `url_path` matches that keyword's primary URL after canonical normalization

### 9. 如何区分 Submitted / Indexed / Observed？

Use separate sources and UI labels:

- Submitted: submission logs, e.g. `baidu_url_submission_logs`
- Indexed: official index evidence API, e.g. Google URL Inspection
- Observed: `cms_seo_keyword_metrics`

Never convert submitted success into indexed or observed status.

### 10. P4.2 是否值得实施以及最小实施范围？

Worth implementing: YES.

Minimum scope:

- Google Search Console automatic metrics provider
- Manual CSV retained
- Baidu metrics marked Not Available unless official API appears
- no Baidu scraping
- no change to Baidu plugin ownership
- no change to Keyword Center observed data semantics

## Recommended Final P4.2 Plan

1. Implement provider abstraction.
2. Implement Google Search Console provider.
3. Implement Google OAuth storage with existing secret mechanism.
4. Implement scheduler sync.
5. Keep manual CSV import.
6. Display Baidu metrics as `Not Available from official API`.
7. Keep Baidu URL submission plugin as-is.
8. Add tests for:
   - Google sync writes metrics
   - missing provider data remains Not Available
   - Baidu Submitted does not count as Observed
   - manual import still works
   - duplicate sync updates not duplicates
   - provider failure preserves existing metrics
   - no Core direct Baidu POST
   - no Baidu token duplication

Final architecture verdict:

```
P4.2 ARCHITECTURE: READY
RECOMMENDED MVP: Google Search Console automatic metrics + Manual CSV fallback
BAIDU AUTOMATIC METRICS: BLOCKED UNTIL OFFICIAL API EXISTS
```
