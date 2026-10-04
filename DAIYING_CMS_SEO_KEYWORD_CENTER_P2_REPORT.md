# Daiying CMS SEO Keyword Center P2 Report

Date: 2026-10-03
Scope: Core admin SEO keyword center only. No production release, no deployment, no Core version bump.

## Architecture

P2 adds a Core admin page at `/admin/seo/keywords` under the new sidebar group `SEO -> 关键词中心`.

The keyword center uses the P1 factual binding source:

- `ContentRepository::targetKeywordBindings()`
- `ContentRepository::targetKeywordConflicts()`

No second binding source was introduced. Admin-managed keyword metadata is layered on top of existing P1 target keyword bindings.

## Schema

New migration:

- `system/migrations/2026_10_03_000002_seo_keyword_center_p2.php`

Tables:

- `cms_seo_keywords`
  - `keyword`
  - `primary_url`
  - `status`
  - `source`
  - `notes`
  - `created_at`
  - `updated_at`

- `cms_seo_keyword_metrics`
  - `keyword`
  - `search_engine`
  - `impressions`
  - `clicks`
  - `ctr`
  - `average_position`
  - `period_start`
  - `period_end`
  - `source`
  - `created_at`

Search engine metrics are intentionally separated from CMS target keyword data. P2 does not connect Baidu, Google, or any paid SEO API.

## Migration

Migration is additive and uses `CREATE TABLE IF NOT EXISTS`.

Existing P1 content, category, and tag `target_keywords` continue to work without a `cms_seo_keywords` row. Binding-only keywords default to:

- `status = active`
- `source = content`

Manual unbound keywords default to draft/manual when saved by an admin.

## Keyword Aggregation Strategy

The center groups rows by normalized keyword text from `targetKeywordBindings()`.

For each keyword, it displays:

- keyword
- primary landing page
- binding count
- content types
- source
- status
- conflict flag
- updated timestamp

`2+` bindings are marked as `Potential Cannibalization`. No automatic resolution is performed.

## Primary Landing Page Design

Admins can manually set `primary_url` for a keyword through `/admin/seo/keywords/save`.

Validation accepts:

- empty value
- site path beginning with `/`
- absolute `http://` or `https://` URL

AI never chooses or changes the primary page.

## Conflict Detection

P2 treats multiple bindings for the same keyword as a factual warning only:

- single binding: normal
- multiple bindings: `Potential Cannibalization`

No keywords, article metadata, slugs, canonical URLs, titles, or descriptions are changed automatically.

## Admin UI

Added admin routes:

- `GET /admin/seo/keywords`
- `GET /admin/seo/keywords/detail?keyword=...`
- `POST /admin/seo/keywords/save`

List page shows:

- Keyword
- Primary Landing Page
- Bindings
- Content Type
- Source
- Status
- Conflict
- Updated

Detail page shows:

- Keyword
- Bindings
- URL
- Content Type
- Content Title
- Index Status
- Canonical
- SEO Title
- SEO Description
- Target Keywords

## Search / Filter

Implemented filters:

- keyword search
- content type
- conflict status
- keyword status

The UI also supports manual creation of draft/manual keywords.

## Data Source Separation

CMS target keyword data and search engine observed performance data are separate.

Baidu/GSC fields are shown as `Not Connected`, not `0`, because there is no connected analytics source in P2.

## Upgrade Compatibility

Old P1 `target_keywords` values remain readable and become keyword center rows automatically.

No existing content/category/tag SEO fields are removed or renamed.

## Automated Tests

Added:

- `tests/seo_keyword_center_p2.php`

Validated:

- single keyword -> single page
- single keyword -> multiple pages
- article + category conflict
- article + tag conflict
- primary landing page
- draft / active / paused
- keyword search
- status / content type / conflict filters
- empty result state
- old P1 `target_keywords` compatibility
- robots noindex status in bindings
- canonical information display
- future metrics schema columns

Results:

- `php tests/seo_keyword_center_p2.php`: PASS
- `php tests/core_sitemap.php`: PASS
- `php tests/rest_api_v1.php`: PASS
- `php tests/content_category_slug_preservation.php`: PASS
- `php tests/release_phase0_gate.php`: PASS
- `git diff --check`: PASS
- PHP syntax checks for changed P2 files: PASS

## Runtime Verification

Runtime environment:

- Temporary root: `/tmp/daiying-seo-p2-http`
- HTTP URL: `http://127.0.0.1:8767`
- SQLite temp database
- Admin login through real `/admin/login`

Exact UI evidence from HTTP checks:

- `GET /admin/seo/keywords`: HTTP 200
- Page contains `关键词中心`
- Page contains `Total Target Keywords`
- Page contains `Not Connected`
- Page contains existing P1 keyword `Daiying CMS安装教程`
- Page contains manual draft keyword `Draft Keyword`
- `GET /admin/seo/keywords?conflict=yes`: contains `Potential Cannibalization`
- `GET /admin/seo/keywords?status=draft`: contains `Draft Keyword`
- `GET /admin/seo/keywords?type=tag`: contains `WordPress替代`
- `GET /admin/seo/keywords?q=安装`: contains `Daiying CMS安装教程`
- `GET /admin/seo/keywords/detail?keyword=PHP%20CMS`: contains `关键词详情`
- Detail contains `PHP CMS Category SEO`
- Detail contains `https://www.daiyingcms.com/category/php-cms`
- Detail contains `Potential Keyword Cannibalization`
- `POST /admin/seo/keywords/save`: HTTP 302
- Reopened detail contains saved primary URL `/category/php-cms`

## Known Debt

- P2 does not connect Baidu Search Resource Platform or Google Search Console.
- P2 does not calculate ranking, impressions, clicks, CTR, or average position.
- P2 does not provide advanced conflict resolution workflows.
- P2 does not add AI keyword suggestions.
- P2 does not create marketplace SEO landing pages.

## P3 Readiness

The schema is ready for future observed keyword metrics by search engine and time period.

Recommended P3:

- optional Baidu/GSC connectors
- keyword performance import
- conflict workflow states
- AI suggestions as draft-only proposals requiring human approval

## Final Verdict

SEO KEYWORD CENTER P2: PASS
