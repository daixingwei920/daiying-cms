# Daiying CMS SEO P4.1 Implementation Report

Date: 2026-10-04
Scope: SEO P4.1 Read-only Lifecycle & Opportunity MVP
Baseline Architecture: `DAIYING_CMS_SEO_P4_INDEXING_OBSERVATION_ARCHITECTURE.md`

Final Status: P4.1 IMPLEMENTATION: PASS

## Changed Files

- `system/core/Seo/Keyword/SeoLifecycleAggregator.php`
- `system/core/Admin/AdminController.php`
- `system/core/Seo/SearchEngine/SearchEngineDataRepository.php`
- `tests/seo_lifecycle_p4_1.php`
- `DAIYING_CMS_SEO_P4_1_IMPLEMENTATION_REPORT.md`

Existing architecture report remains present:

- `DAIYING_CMS_SEO_P4_INDEXING_OBSERVATION_ARCHITECTURE.md`

## Database Changes = NONE

No database table was added.
No migration was added.
No existing migration was modified.
No Baidu token ownership changed.
No Core Baidu submission log table was created.

Verified migration tail remains:

- `2026_10_03_000001_seo_keyword_system_p1.php`
- `2026_10_03_000002_seo_keyword_center_p2.php`
- `2026_10_03_000003_seo_search_engine_p3.php`

## Lifecycle Aggregator

Added `Cms\Core\Seo\Keyword\SeoLifecycleAggregator`.

It builds read-only lifecycle state for each target keyword row using existing sources:

- `cms_seo_keywords`
- `cms_seo_keyword_metrics`
- `targetKeywordBindings()`
- `targetKeywordConflicts()`
- `OfficialBaiduSubmitBridge`
- `baidu_url_submission_logs`
- existing sitemap item/term sources
- existing SEO metadata exposed by bindings

Lifecycle fields:

- Target Keyword
- Landing Page Assigned
- Page Exists
- Page SEO Ready
- In Sitemap
- Submitted
- Index Evidence
- Observed Search Data
- Ranking
- Optimization Opportunity

Target data, submission data, index evidence, and observed data remain separate.

## Page Readiness

Implemented read-only checks:

- Primary URL assigned
- Page exists in CMS binding or sitemap membership
- Title
- Description
- H1 signal from content/term title
- Canonical consistency
- Robots index status
- Primary URL consistency

No production/public HTTP crawling is performed. P4.1 uses internal CMS data first, matching the approved performance and safety constraints.

## Sitemap Resolver

Sitemap membership is resolved from existing Core sitemap sources:

- `ContentRepository::sitemapItems()`
- `ContentRepository::sitemapTerms()`

It returns:

- `in_sitemap`
- `not_in_sitemap`
- `not_applicable`

No new sitemap URL construction policy or second sitemap generator was introduced.

## Index Evidence

Implemented approved evidence-tiered model:

- `not_available`
- `unknown`
- `submitted`
- `submission_failed`
- `evidence_indexed`

P4.1 does not emit `not_indexed` because there is no reliable URL-level negative evidence source.

Rules:

- Baidu `success=1` / plugin success means submitted, not indexed.
- Observed impressions or clicks can produce `evidence_indexed`.
- Missing metrics render as `Not Available` / `No Observed Data`, not `Not Indexed`.

## Metrics Trends

Trend aggregation reads `cms_seo_keyword_metrics`.

Implemented windows:

- 7d
- 30d
- 90d

Computed fields:

- impressions: sum
- clicks: sum
- CTR: clicks / impressions when possible
- average position: impression-weighted average when impressions exist
- latest period
- freshness/stale flag

Search engines are kept separate. Existing import support was expanded to accept `bing` in the same metrics table; no new table or data model was introduced.

Missing values display as `Not Available`; real observed zero remains zero.

## Opportunity Rules

Implemented deterministic rules:

- Near Page-One
- Low CTR
- Missing Landing Page
- Potential Cannibalization
- On-page SEO Gap
- Submission Gap
- No Observed Data
- Stale Data

No automatic page modification is performed.
No AI scoring is used.

## Score Formula

Score range: 0-100.

Implemented explainable components:

- impressions: 0-30
- position opportunity: 0-20
- CTR opportunity: 0-15
- on-page gaps: 0-15
- sitemap/submission gap: 0-10
- stale/missing data: 0-10
- conflict: 0-10
- cap: 100

Every score includes human-readable reasons.

## Dashboard

Extended existing `/admin/seo/keywords`.

Added SEO Overview cards:

- Total Target Keywords
- Active Keywords
- Landing Pages Assigned
- SEO Ready
- In Sitemap
- Baidu Submitted
- With Observed Data
- Missing Observed Data
- Potential Cannibalization
- Data Freshness

Added `Today's SEO Opportunities` table:

- Keyword
- Primary URL
- Engine
- Opportunity Type
- Score
- Evidence
- Suggested Action
- Last Metric Period
- Last Baidu Submission
- Index Evidence

The existing Keyword Center remains the only Keyword Center.

## Keyword Detail

Extended existing keyword detail UI with:

- Target Data / Bindings
- Page Readiness
- Discovery / Index Evidence
- Observed Data
- Metric Trends
- Opportunities

UI copy explicitly keeps:

- Submitted != Indexed
- Missing observed data != Not Indexed

## Performance / N+1 Audit

P4.1 avoids per-keyword HTTP requests.

Single request lifecycle:

- Keyword rows: one repository read
- Target conflicts: one repository read
- Metrics: one bulk query from `cms_seo_keyword_metrics`
- Baidu submission logs: one `OfficialBaiduSubmitBridge::recentLogs(500)` read
- Sitemap membership: one pass over existing sitemap items/terms

No per-row Baidu API call.
No per-row sitemap parsing.
No per-row production HTTP request.

Known scaling note:

- `recentLogs(500)` is sufficient for MVP visibility. If sites grow beyond that, P4.2 can add a plugin-owned query method for exact URL batches without changing ownership.

## Automated Tests

New:

- `php tests/seo_lifecycle_p4_1.php` PASS

Regression:

- `php tests/seo_keyword_center_p2.php` PASS
- `php tests/seo_search_engine_p3.php` PASS
- `php tests/official_baidu_url_submission.php` PASS
- `php tests/core_sitemap.php` PASS
- `php tests/rest_api_v1.php` PASS

Syntax:

- `php -l system/core/Seo/Keyword/SeoLifecycleAggregator.php` PASS
- `php -l system/core/Admin/AdminController.php` PASS
- `php -l system/core/Seo/SearchEngine/SearchEngineDataRepository.php` PASS
- `php -l tests/seo_lifecycle_p4_1.php` PASS

Patch hygiene:

- `git diff --check` PASS

## Runtime Verification

Local runtime-style verification was performed in `tests/seo_lifecycle_p4_1.php` using a real SQLite CMS schema, real P1/P2/P3 migrations, the real official Baidu plugin schema, and AdminController HTML rendering.

Verified:

- Ready page lifecycle
- Missing landing page lifecycle
- Noindex page lifecycle
- Canonical mismatch lifecycle
- Sitemap yes/no membership
- Submitted is not indexed
- Observed metrics create positive evidence
- Missing metrics render as `Not Available`
- 7d/30d/90d trend aggregation
- Weighted average position
- Engine separation for Baidu, Google, and Bing
- Conflict reuse
- Opportunity rules
- Score reasons
- Keyword Center dashboard UI
- Keyword detail UI
- No Core Baidu token
- No Core direct Baidu POST provider
- No second Baidu submission table
- No new P4 lifecycle table

## Regression Results

P2 Keyword Center: PASS
P3 Search Engine: PASS
Official Baidu plugin: PASS
Sitemap P0: PASS
REST API v1: PASS

The only direct Baidu POST endpoint remains in:

- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php`

Core P4.1 does not access `data.zz.baidu.com`.

## Known Debt

- P4.1 page readiness uses CMS metadata rather than external page crawling. This is intentional for safety and performance.
- H1 readiness is inferred from the CMS content/term title. A future rendered-page audit could verify final theme output, but should be bounded and cached.
- `recentLogs(500)` may not cover very old submissions on large sites. A plugin-owned batch lookup method is preferable if needed later.
- Persistent lifecycle snapshots and opportunity workflow state remain out of P4.1 scope.
- Baidu URL-level indexed/not-indexed evidence remains unavailable from official API.

## Production Readiness

Ready for release candidate review.

Do not deploy automatically from this task.
Do not bump Core version from this task.
Do not update stable/latest from this task.

Final Status: P4.1 IMPLEMENTATION: PASS
