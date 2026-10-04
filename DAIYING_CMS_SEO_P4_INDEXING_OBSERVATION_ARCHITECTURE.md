# Daiying CMS SEO P4 - Indexing Observation & Keyword Feedback Loop Architecture

Date: 2026-10-04
Scope: Read-only audit and architecture design only
Baseline: SEO P0/P1/P2/P3 completed and production-frozen at Core 1.2.78

Final Status: P4 ARCHITECTURE READY

## 1. Executive Summary

P4 should turn the current Keyword Center from a target-keyword registry into an SEO decision center by joining existing target keyword data, landing page readiness, sitemap status, Baidu submission logs, and observed search performance metrics.

No P4 implementation should duplicate Baidu submission ownership. The single Baidu URL submission implementation remains:

- Token owner: `official.seo.baidu-submit`
- Actual Baidu POST owner: `official.seo.baidu-submit`
- Submission fact source: `baidu_url_submission_logs`
- Core bridge: `OfficialBaiduSubmitBridge`

The existing `cms_seo_keyword_metrics` table is sufficient for P4 observed keyword/page/search-engine performance metrics. P4.1 does not require new DB tables if it computes lifecycle status and opportunity scores from existing data at runtime. Optional later stages may add audit snapshot or index-evidence history tables, but only if persistent history is explicitly required.

Indexing must remain evidence-based. A submitted URL is not an indexed URL. If Baidu does not provide reliable URL-level index evidence, the UI must show `Unknown` or `Not Available`, not `No`.

## 2. Existing P0/P1/P2/P3 Capability Inventory

P0 SEO Structural:

- Canonical normalization for homepage, `/articles`, category, tag, and pagination.
- XML sitemap generation with valid `urlset`, escaping, dedupe, noindex exclusion, non-empty category/tag inclusion, and multi-site-safe URL normalization.
- Homepage has one accessible H1.
- Robots/sitemap relationship verified.

P1 Keyword System:

- Content metadata supports `seo_keywords` and `target_keywords`.
- Category/tag metadata supports `seo_title`, `seo_description`, `seo_keywords`, `target_keywords`, `canonical_url`, `robots_index`, and `robots_follow`.
- Fallback policy is manual SEO first, system defaults second.
- `target_keywords` are internal target data and are not automatically emitted as `<meta name="keywords">`.
- `ContentRepository::targetKeywordBindings()` exposes keyword-to-page bindings.
- `ContentRepository::targetKeywordConflicts()` detects multiple bindings for the same target keyword.

P2 Keyword Center:

- Admin UI: `/admin/seo/keywords`.
- Keyword registry table: `cms_seo_keywords`.
- Metrics table: `cms_seo_keyword_metrics`.
- Primary landing page, status, source, and notes are supported.
- Status values support `draft`, `active`, and `paused`.
- Search/filter/conflict views use the existing P1 binding/conflict source.

P3 Search Engine Layer:

- Generic search-engine metrics/import model exists.
- CSV import writes observed metrics into `cms_seo_keyword_metrics`.
- `cms_seo_keyword_metrics.url_path` supports URL/page-level observed metrics.
- Search Engine UI exists at `/admin/seo/search-engines`.
- Baidu submission dedup was corrected: Core does not post directly to Baidu.
- `OfficialBaiduSubmitBridge` reads the existing Baidu plugin status/logs and submits through the plugin.
- Production P3 verification confirmed real Baidu response, plugin log consistency, dedupe, and single ownership.

## 3. Reusable Existing Components

Reusable as-is:

- `targetKeywordBindings()` for keyword-to-page facts.
- `targetKeywordConflicts()` for cannibalization facts.
- `cms_seo_keywords` for keyword status, source, notes, and primary URL.
- `cms_seo_keyword_metrics` for observed search performance.
- `SearchEngineDataRepository` for import, dedupe/update, and metrics reads.
- `OfficialBaiduSubmitBridge` for plugin-backed Baidu status, logs, and submission.
- `baidu_url_submission_logs` for Baidu submission evidence.
- Existing sitemap generator for sitemap membership checks.
- Existing SEO metadata output for title, description, keywords, canonical, robots, and H1.

P4 should consume these sources, not replace them.

## 4. Missing Capabilities

Missing read/aggregation capabilities:

- Page SEO readiness audit: HTTP status, title, description, H1, canonical, robots, and same-site URL validation.
- Sitemap membership resolver for a given canonical URL.
- Lifecycle aggregator per keyword and primary landing page.
- Observed metric trend aggregation for 7d, 30d, and 90d windows.
- Evidence-based indexing status model.
- Deterministic SEO opportunity rules.
- Explainable opportunity score.
- Dashboard view that separates Target Data, Submission Data, Indexing Evidence, and Observed Search Data.
- CSV import mapping templates for Baidu Search Resource Platform exports.

Missing persistence only if later required:

- Page audit snapshot history.
- Index evidence observation history.
- Opportunity state history, such as dismissed or assigned opportunities.
- Import batch metadata.

These should not be added in P4 architecture-only work.

## 5. Baidu Data Availability Matrix

Official Baidu sources reviewed:

- [Baidu Search Resource Platform guide](https://ziyuan.baidu.com/college/articleinfo/?id=3329)
- [Baidu ordinary inclusion / API submission guide](https://ziyuan.baidu.com/college/courseinfo?id=267&page=2)
- [Baidu quick inclusion tool](https://ziyuan.baidu.com/dailysubmit/)
- [Baidu search keyword tool help](https://ziyuan.baidu.com/wiki/89)
- [Baidu index quantity tool](https://ziyuan.baidu.com/indexs/)
- [Baidu sitemap submit tool](https://ziyuan.baidu.com/wiki/44)

Automatic through official API/plugin:

| Capability | Availability | Evidence / Notes |
| --- | --- | --- |
| URL active submission | Available | Baidu documents API submission using `data.zz.baidu.com/urls?site=...&token=...`; current plugin already owns this. |
| Submission response | Available | Response includes accepted count such as `success` and quota-related `remain`. |
| Sitemap submission/management | UI/tool available | Baidu platform supports sitemap submission. Daiying CMS should keep generating valid sitemap; do not duplicate plugin submission unless explicitly scoped. |

Baidu platform UI / manual export candidates:

| Capability | Availability | P4 handling |
| --- | --- | --- |
| Keyword impressions | UI/tool available | Import by CSV/manual export when available in the verified site account. |
| Keyword clicks | UI/tool available | Import by CSV/manual export. |
| Keyword CTR | UI/tool available | Import by CSV/manual export or compute from clicks/impressions. |
| Keyword/page examples | UI/tool available | Map URL/page examples to `url_path` where possible. |
| Index quantity | UI/tool available | Treat as site/directory-level evidence, not URL-level index proof. |

NOT AVAILABLE FROM OFFICIAL BAIDU API:

- Keyword-level impressions API for external CMS ingestion.
- Keyword-level clicks API for external CMS ingestion.
- CTR API for external CMS ingestion.
- Average ranking API for external CMS ingestion.
- Reliable URL-level indexed/not-indexed API.
- Reliable URL-level crawl status API.
- API proof that a submitted URL is indexed.

No P4 feature should scrape the logged-in Baidu backend and call that an API.

## 6. Indexing Evidence Model

Index status must be evidence-tiered:

| Status | Meaning | Allowed evidence |
| --- | --- | --- |
| `not_available` | No data source is connected for index evidence. | No configured evidence source. |
| `unknown` | Page may or may not be indexed. | Sitemap/submission exists, but no reliable index evidence. |
| `submitted` | URL was accepted by a submission channel. | `baidu_url_submission_logs.status=success/submitted` and Baidu response. |
| `submission_failed` | Submission attempt failed. | Plugin log failure status or HTTP/API error. |
| `evidence_indexed` | Some evidence indicates discoverability/indexing. | Observed search metrics for that URL/keyword, or a future official source. |
| `not_indexed` | Only when a reliable source explicitly says URL is not indexed. | Currently NOT AVAILABLE FROM OFFICIAL BAIDU API. |

Default Baidu URL-level index status should be `unknown` after successful submission unless observed search data exists for that URL.

## 7. SEO Lifecycle Model

For each keyword and primary landing page:

1. Target Keyword
2. Landing Page assigned
3. Page exists and is HTTP 200
4. Page SEO Ready
5. In Sitemap
6. Submitted
7. Crawled/Indexed Unknown or Evidence Confirmed
8. Observed Search Data
9. Ranking / Position
10. Optimization Opportunity

Displayed fields:

- Keyword
- Primary Landing Page
- Page HTTP status
- Title
- Description
- H1
- Canonical
- Robots index/follow
- Sitemap inclusion
- Latest Baidu submit time
- Latest Baidu submit status
- Baidu `success` / `remain`
- Indexed status
- Indexed evidence
- Indexed last checked/evidence timestamp
- Impressions
- Clicks
- CTR
- Average position
- 7d trend
- 30d trend
- 90d trend

Lifecycle rules:

- `Submitted` does not mean `Indexed`.
- Missing observed data does not mean `Not Indexed`.
- No connected search data must render as `Not Available`.
- A noindex page must not be treated as an SEO opportunity to submit or rank until robots policy changes.

## 8. CSV Import Mapping

The current `cms_seo_keyword_metrics` schema is enough for observed metrics:

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

Baidu Search Resource Platform export mapping:

| Baidu export field | CMS field | Notes |
| --- | --- | --- |
| Keyword / Query | `keyword` | Trim and normalize whitespace. |
| Page URL / URL example | `url_path` | Normalize same-site URLs to canonical path; reject off-site URLs unless explicitly imported as external evidence. |
| Impressions | `impressions` | Integer or NULL. |
| Clicks | `clicks` | Integer or NULL. |
| CTR | `ctr` | Store raw percent/string or compute from clicks/impressions for display. |
| Average position / ranking | `average_position` | Nullable decimal/string; render as `Not Available` when missing. |
| Date or range start | `period_start` | Required for trend windows. |
| Date or range end | `period_end` | If single-day export, same as start. |
| Engine | `search_engine` | `baidu`. |
| Import source | `source` | `manual_import` initially. |

Duplicate import policy:

- Use the existing unique identity: keyword + url_path + search_engine + period_start + period_end + source.
- Re-importing the same row should update the metric values, not create duplicates.
- Missing numeric values remain NULL and must display as `Not Available`, not zero.

## 9. Metrics / Trend Design

Trend windows:

- 7d: latest 7-day range ending at the most recent `period_end`.
- 30d: latest 30-day range.
- 90d: latest 90-day range.

Aggregation:

- Impressions: sum known values.
- Clicks: sum known values.
- CTR: compute clicks / impressions when both are known and impressions > 0.
- Average position: weighted by impressions when impressions are available; otherwise simple average of known positions.
- Freshness: latest `period_end` per keyword + URL + engine.

Display:

- `0` only when observed data explicitly says zero.
- `Not Available` when the data source or field is missing.
- Multi-engine metrics remain separated by engine and can also be shown as an optional aggregate.

## 10. Opportunity Engine Rules

Rules are deterministic and explainable:

1. Near Page-One
   - Average position roughly 8-20 and impressions above threshold.
   - Suggest title/description/content improvement review.

2. Low CTR
   - High impressions and CTR below expected threshold.
   - Suggest SERP snippet review.

3. Missing Landing Page
   - Target keyword exists but no primary URL.
   - Suggest assigning a primary landing page.

4. Potential Cannibalization
   - Existing `targetKeywordConflicts()` reports 2+ bindings.
   - Suggest human review; do not auto-resolve.

5. On-page SEO Gap
   - Landing page missing title, description, H1, indexable robots, or canonical consistency.
   - Suggest fixing on-page SEO fields.

6. Submission Gap
   - Page is indexable and in sitemap but has no recent Baidu submission log.
   - Suggest submit through `official.seo.baidu-submit`.

7. No Observed Data
   - Target keyword/page has no imported search performance metrics.
   - Display as `No Observed Data`, never as `Not Indexed`.

8. Stale Data
   - Latest metrics period is older than the configured freshness window.
   - Suggest importing a fresh export.

## 11. Opportunity Score Proposal

Score range: 0-100. The score is not AI-generated and must show contributing reasons.

Suggested scoring:

- +0 to +30: observed impressions, log-scaled or percentile-scaled.
- +0 to +20: average position opportunity, highest around positions 8-20.
- +0 to +15: low CTR opportunity, only when impressions are known.
- +0 to +15: on-page SEO gaps.
- +0 to +10: sitemap/submission gap.
- +0 to +10: stale or missing observed data for an otherwise ready landing page.
- +0 to +10: conflict/cannibalization flag.
- Cap at 100.

Modifiers:

- Paused keyword: score hidden or deprioritized.
- Noindex landing page: rank opportunity suppressed; show technical blocker instead.
- Missing primary URL: show setup opportunity, not ranking opportunity.

Every score row must include reasons such as:

- `Position 11.4 with 1,200 impressions`
- `CTR 0.6% below threshold`
- `Primary URL not in sitemap`
- `No Baidu metrics imported in last 30 days`

## 12. Keyword Center Dashboard Design

Top-level dashboard: SEO Overview

Cards:

- Total Target Keywords
- Active Keywords
- Primary Landing Pages Assigned
- Potential Cannibalization
- Pages SEO Ready
- Pages In Sitemap
- Baidu Submitted URLs
- Keywords With Observed Data
- Keywords Missing Observed Data
- Search Engine Data Freshness

When no observed search data exists, show `Not Connected` or `Not Available`, not zero.

Today's SEO Opportunities table:

- Keyword
- Primary URL
- Engine
- Opportunity Type
- Score
- Evidence
- Suggested Human Action
- Last Metric Period
- Last Baidu Submission
- Index Evidence Status

Keyword detail should include:

- Target Data: keyword, status, source, primary URL, bindings, conflicts.
- Page Readiness: HTTP, title, description, H1, canonical, robots.
- Discovery: sitemap inclusion and latest submission log.
- Index Evidence: submitted/unknown/evidence-indexed/not available.
- Observed Metrics: per engine and trend windows.
- Opportunities: rule-based reasons and score.

## 13. Multi-Search-Engine Compatibility

P4 must not become a Baidu-only center.

Common model:

- `search_engine`: `baidu`, `google`, `bing`, `manual`, future engines.
- Metrics schema remains shared.
- Opportunity rules consume normalized metrics.
- Provider-specific connectors handle ingestion only.

Google Search Console readiness:

- GSC can map query/page/impressions/clicks/CTR/position into the existing metrics shape.
- The same trend and opportunity engine can consume GSC metrics.
- GSC credentials must follow a separate secure connector model, not the Baidu plugin token model.

Bing readiness:

- Bing metrics can use the same metrics/import model if available later.
- URL submission, if implemented, must use an engine-specific provider without affecting Baidu ownership.

## 14. Duplication Risk Audit

Risks to avoid:

| Risk | Decision |
| --- | --- |
| Second Baidu token in Core | Forbidden. Use `official.seo.baidu-submit`. |
| Core direct POST to `data.zz.baidu.com` | Forbidden. Use `OfficialBaiduSubmitBridge`. |
| Second Baidu submission log table | Forbidden. Use `baidu_url_submission_logs`. |
| Second metrics table for keyword performance | Avoid. Use `cms_seo_keyword_metrics`. |
| Second conflict detector | Avoid. Use `targetKeywordConflicts()`. |
| Separate sitemap membership logic | Avoid. Use existing sitemap generator/canonical resolver. |
| Treating submission as indexing | Forbidden. Use evidence-tiered indexing status. |
| Scraping Baidu logged-in UI as API | Forbidden. Use manual CSV import for exported data. |

## 15. Database Impact Assessment

Does P4 need new DB tables?

- P4.1: No. A useful lifecycle dashboard and opportunity engine can be built from existing tables and runtime page/sitemap checks.
- P4.2/P4.3: Maybe, only for persistent audit snapshots, index evidence history, opportunity workflow state, or import batch metadata.

Is existing `cms_seo_keyword_metrics` enough?

- Yes for keyword/page/search-engine observed performance metrics and 7d/30d/90d trends.
- It already supports keyword, URL path, search engine, impressions, clicks, CTR, average position, period range, source, and created timestamp.
- It should remain the single observed metrics table.

No production migration should be introduced until P4.1 proves that runtime aggregation is enough or identifies a concrete persistence requirement.

## 16. Proposed P4.1/P4.2/P4.3 Implementation Stages

P4.1 - Read-only lifecycle and opportunity MVP:

- Add lifecycle aggregator service.
- Add page SEO readiness audit.
- Add sitemap membership check using existing sitemap/canonical logic.
- Add metrics trend aggregation from `cms_seo_keyword_metrics`.
- Add opportunity rules and explainable score.
- Add Keyword Center dashboard views.
- No new migrations.
- No new Baidu token/submission implementation.

P4.2 - Evidence and workflow persistence if needed:

- Optional page audit snapshot table.
- Optional index evidence observation table.
- Optional import batch metadata.
- Optional opportunity state table for dismissed/assigned/resolved.
- Add only after P4.1 runtime evidence shows persistence is necessary.

P4.3 - External search-engine expansion:

- Google Search Console connector/import.
- Bing connector/import if scoped.
- Multi-engine trend comparison.
- AI suggestions only as draft proposals requiring human approval.

## 17. Test Strategy

Unit tests:

- Lifecycle status construction for ready, missing, noindex, and canonical-mismatch pages.
- Sitemap membership true/false.
- Submitted vs indexed distinction.
- Metrics trend aggregation for 7d/30d/90d.
- Missing metrics render as `Not Available`.
- Opportunity rule activation and score explanations.
- Conflict reuse from existing `targetKeywordConflicts()`.
- CSV mapping and duplicate import update behavior.
- Multi-engine metrics separation.

Integration tests:

- Keyword with primary URL and Baidu submission log.
- Keyword with imported Baidu metrics.
- Keyword with no observed metrics.
- Noindex page excluded from rank opportunity.
- OfficialBaiduSubmitBridge log/status read path.
- No Core direct Baidu POST.
- No Core Baidu token configuration.

Regression tests:

- P2 Keyword Center.
- P3 Search Engine UI.
- Baidu plugin submission/dedupe tests.
- Sitemap P0.
- REST API.

Runtime verification:

- Use a local or staging site with real pages.
- Verify dashboard values match DB rows and plugin logs.
- Verify UI text says `Submitted` or `Accepted by Baidu submission API`, never `Indexed`, unless evidence exists.

## 18. Production Migration Risk

P4.1 migration risk: Low, if implemented read-only without schema changes.

Operational risks:

- Mislabeling submitted URLs as indexed.
- Showing missing data as zero.
- Duplicate Baidu submission/token paths.
- Over-counting metrics when manual imports overlap periods.
- Treating Baidu UI exports as automated API data.

Mitigations:

- Evidence-tiered index model.
- Strict `Not Available` rendering for missing fields.
- Reuse plugin bridge and logs.
- Idempotent import identity.
- Explicit source labels.
- Release gate includes P2/P3/Baidu plugin/sitemap regression.

## 19. Recommendation

Proceed with P4.1 as a read-only lifecycle and opportunity dashboard using existing P1/P2/P3 data sources.

Do not create new DB tables in P4.1.

Do not implement new Baidu API submission code.

Do not claim URL-level indexing without reliable evidence.

Use `cms_seo_keyword_metrics` as the single observed metrics table and `baidu_url_submission_logs` as the single Baidu submission fact source.

Explicit answers:

1. Does P4 need new DB tables?
   - No for P4.1. Optional later tables may be justified only for persistent audit/evidence/workflow history.

2. Is existing `cms_seo_keyword_metrics` enough?
   - Yes for observed keyword/page/search-engine performance metrics and trend calculations.

3. How to display Baidu index status without lying?
   - Show `Unknown` or `Not Available` unless there is positive evidence. Show `Submitted` separately from `Indexed`. Never infer indexing from `success=1`.

4. Which Baidu data can be automatic vs manual import?
   - Automatic: URL submission and submission response through `official.seo.baidu-submit`.
   - Manual import: keyword/page impressions, clicks, CTR, average position, and any exported platform performance data.
   - Not available from official API: URL-level indexed/not-indexed proof and keyword performance API ingestion.

5. How P4 reuses existing Baidu plugin instead of duplicate implementation?
   - Keyword Center calls `OfficialBaiduSubmitBridge`, which reads plugin settings/logs and submits via `official.seo.baidu-submit`. Core owns no Baidu token, POST code, or submission log table.

6. How to upgrade Keyword Center into SEO decision center?
   - Join target keywords, primary URLs, page readiness, sitemap inclusion, plugin submission logs, observed metrics, trends, and deterministic opportunity rules into one dashboard with explainable scores.

Final Status: P4 ARCHITECTURE READY
