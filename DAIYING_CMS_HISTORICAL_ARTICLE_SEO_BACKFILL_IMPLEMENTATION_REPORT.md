# Daiying CMS Historical Article SEO Backfill Implementation Report

## Scope

Implemented a controlled historical article SEO backfill and content-quality optimization workflow.

This change does not deploy to production and does not modify production content. Production backfill must be run later through the admin UI after release approval.

## Changed Files

- `system/migrations/2026_10_06_000001_historical_article_seo_backfill.php`
- `system/core/Seo/Historical/HistoricalArticleSeoBackfillService.php`
- `system/core/Admin/AdminController.php`
- `system/core/Bootstrap/Application.php`
- `system/core/Support/View.php`
- `tests/historical_article_seo_backfill.php`

## Database Changes

Added two workflow tables:

- `cms_historical_article_seo_jobs`
  - stores per-article quality grade, SEO status, recommendation, generated SEO proposal, and optional pending body optimization draft.
- `cms_historical_article_seo_logs`
  - stores scan/generate/apply logs.

No new search metrics table was created. Existing `cms_seo_keywords`, `target_keywords`, Keyword Center, and SEO Lifecycle semantics are reused.

## Admin UI

New entries:

- SEO -> 历史文章 SEO 补全
- SEO -> 历史内容优化

UI supports:

- article summary stats
- one-click historical scan
- batch generate missing SEO, 10 articles per batch
- per-article generate SEO
- preview suggested SEO
- save missing SEO
- regenerate suggestion
- quality filters
- SEO status filters
- title search

## Data Protection

Default behavior:

- existing `seo_title` is not overwritten
- existing `seo_description` is not overwritten
- existing `seo_keywords` is not overwritten
- existing `target_keywords` is not overwritten
- existing `canonical_url` is not overwritten
- existing article body is not overwritten

Regenerate creates a new proposal. Saving SEO only fills allowed fields according to the selected action.

## Keyword Quality

The generator does not copy the title directly into keywords. It derives target and auxiliary keywords from:

- article title
- body text
- CMS/site positioning
- detected topic signals such as install/config/payment/theme/plugin/WordPress alternative

Generated fields:

- SEO title
- SEO description
- `target_keywords`
- `seo_keywords`
- canonical URL
- primary Keyword Center URL through existing `cms_seo_keywords`

## Content Quality

The scanner records:

- word count
- paragraph count
- heading count
- image presence
- duplicate fingerprint status
- early batch-content signal
- SEO completeness

Grades:

- A: body is usable; SEO-only workflow.
- B: usable but thin; expansion draft.
- C: weak/short; focused rewrite or expansion draft.
- D: duplicate/low value; manual review queue.

For B/C articles, the service can generate a pending body optimization draft. It is stored in `cms_historical_article_seo_jobs.proposed_blocks_json` and is not published automatically.

## Existing SEO Integration

Backfilled articles enter the existing SEO system by writing:

- `cms_contents.meta_json.target_keywords`
- `cms_contents.meta_json.seo_keywords`
- `cms_contents.meta_json.seo_description`
- `cms_contents.meta_json.canonical_url`
- `cms_seo_keywords` via `ContentRepository::saveSeoKeyword()`

This makes them visible to:

- `targetKeywordBindings()`
- Keyword Center
- SEO Lifecycle Aggregator

No observed metrics are fabricated. Generated SEO data does not imply Baidu indexed, ranked, clicked, or crawled the page.

## Production Audit Result

| Metric | Count |
| --- | ---: |
| Historical articles total | 61 |
| Missing SEO count | 59 incomplete |
| Completely missing SEO | 0 |
| Already entered Keyword Center | 2 |
| Production successful backfill count | 0 |
| Production skipped existing manual SEO count | 0 |
| Production failure count | 0 |

Production counts are zero for backfill execution because this implementation was not deployed or run against production content.

## Automated Tests

PASS:

- `php tests/historical_article_seo_backfill.php`
- `php tests/seo_keyword_center_p2.php`
- `php tests/seo_lifecycle_p4_1.php`
- `php tests/core_sitemap.php`
- `php tests/rest_api_v1.php`
- PHP syntax checks for changed files
- `git diff --check`

## Runtime Verification

Local runtime-level verification covered route wiring, admin rendering code paths, migration compatibility, service behavior, and existing SEO lifecycle regressions through automated tests.

Production admin UI was not deployed in this task.

## Known Limits

- The first implementation uses deterministic keyword/content suggestions, not external AI generation.
- Body optimization drafts require administrator review before replacing live content.
- Duplicate detection is conservative fingerprint-based; deeper semantic similarity can be added later.
- No production batch backfill has been executed yet.

## Final Status

Historical article total: 61

Missing/incomplete SEO quantity: 59

Successful production backfill quantity: 0

Skipped existing manual SEO quantity: 0 in production execution; code path protects existing manual fields.

Failure quantity: 0 in tests; 0 production execution.

Keyword Center and SEO Lifecycle integration: implemented and verified in tests.

HISTORICAL ARTICLE SEO BACKFILL: PASS
