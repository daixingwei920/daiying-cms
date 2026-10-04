# Daiying CMS SEO Structural P0 Report

Date: 2026-10-02

## 1. Root Cause

- Archive pages used stable routes but did not carry page-aware canonical URLs. `/articles`, `/category/{slug}`, and `/tag/{slug}` could render with incorrect or missing canonical output depending on active theme/runtime fallback.
- Sitemap generation only included home, `/articles`, and published content items. Non-empty public category/tag archive pages were omitted.
- The Daiying Media home template used the featured article title as the only visible `<h1>`. If the hero was disabled, the page could render with no `<h1>`; if enabled, the home page semantic H1 described the featured article instead of the site.
- Core home rendering did not pass a stable homepage canonical into the theme context.

## 2. Changed Files

- `system/core/Bootstrap/Application.php`
- `system/core/Content/ContentFrontController.php`
- `system/core/Content/ContentRepository.php`
- `content/themes/daiying_media/templates/home.php`
- `content/themes/daiying_media/assets/css/base.css`
- `tests/core_sitemap.php`

## 3. Exact Diff Scope

- Added homepage canonical data to Core home render context.
- Added page-aware archive canonical path generation for `/articles`, `/category/{slug}`, and `/tag/{slug}`.
- Added category/tag sitemap term selection for non-empty public article terms.
- Added a single accessible Daiying Media homepage H1 and changed the featured card title from H1 to H2 without changing its visual styling.
- Expanded existing sitemap regression tests to cover canonical, pagination, category/tag sitemap inclusion, duplicate detection, H1, and article SEO regression.

No version bump, release artifact, update server, market page, keyword system, AI keyword generation, slug rewrite, or production deployment changes were made.

## 4. Canonical Policy

- Homepage canonical: site base URL from `site.url`.
- `/articles`: canonical points to `/articles`.
- `/category/{slug}`: canonical points to the same category URL.
- `/tag/{slug}`: canonical points to the same tag URL.
- Existing article canonical behavior remains unchanged: explicit valid `canonical_url` wins; otherwise the article URL is used.

## 5. Pagination Canonical Policy

Paginated archive pages are treated as distinct indexable pages because their item lists differ.

- `?page=1` canonical normalizes to the first-page URL without query.
- `?page=N` where `N > 1` canonical points to the same paginated URL, e.g. `/articles?page=2`.
- This applies consistently to `/articles`, `/category/{slug}`, and `/tag/{slug}`.

## 6. Sitemap Category/Tag Inclusion Policy

- Include only `category` and `tag` terms attached to at least one published article.
- Empty category/tag terms are excluded.
- Term URLs are canonical-consistent:
  - `/category/{slug}`
  - `/tag/{slug}`
- Term `<lastmod>` uses the latest `updated_at` among published article content in that term.
- Existing article/page sitemap behavior and XML generation remain intact.

## 7. H1 Implementation

- Daiying Media homepage now renders exactly one page-level `<h1>` using the site/home title.
- The H1 uses a standard accessible `.visually-hidden` pattern.
- The existing featured article title remains visually unchanged but is semantically an `<h2>`.
- Reason: the homepage needs a stable site-level H1 without breaking the current visual hero layout or adding hidden keyword spam.

## 8. Before / After Examples

Before:

- `/category/docs` could canonicalize incorrectly or lack reliable self-canonical behavior.
- `/articles?page=2` did not explicitly canonicalize to its paginated URL.
- Sitemap omitted `/category/docs` and `/tag/daiying-cms`.
- Daiying Media homepage H1 depended on hero/featured content.

After:

- `/articles` -> `http://127.0.0.1:8765/articles`
- `/articles?page=2` -> `http://127.0.0.1:8765/articles?page=2`
- `/category/docs` -> `http://127.0.0.1:8765/category/docs`
- `/category/docs?page=2` -> `http://127.0.0.1:8765/category/docs?page=2`
- `/tag/daiying-cms` -> `http://127.0.0.1:8765/tag/daiying-cms`
- `/tag/daiying-cms?page=2` -> `http://127.0.0.1:8765/tag/daiying-cms?page=2`
- Sitemap includes non-empty category/tag URLs and excludes `/category/empty`.
- Homepage has exactly one H1: `Daiying CMS`.

## 9. Automated Test Results

- `php -l system/core/Bootstrap/Application.php`: PASS
- `php -l system/core/Content/ContentFrontController.php`: PASS
- `php -l system/core/Content/ContentRepository.php`: PASS
- `php -l content/themes/daiying_media/templates/home.php`: PASS
- `php -l tests/core_sitemap.php`: PASS
- `php tests/core_sitemap.php`: PASS
- `php tests/release_phase0_gate.php`: PASS

## 10. Manual Runtime Verification

Local temporary HTTP site:

- Root: `/tmp/daiying-seo-p0-http`
- Server: `http://127.0.0.1:8765`
- Theme: `daiying_media`
- Fixture: one published article, one non-empty category, one non-empty tag, one empty category.

Verified URLs:

- `/`: HTTP 200, description YES, keywords YES, canonical `http://127.0.0.1:8765`, robots `index,follow`, H1 count 1.
- `/articles`: HTTP 200, canonical `http://127.0.0.1:8765/articles`, H1 count 1.
- `/articles?page=2`: HTTP 200, canonical `http://127.0.0.1:8765/articles?page=2`, H1 count 1.
- `/category/docs`: HTTP 200, canonical `http://127.0.0.1:8765/category/docs`, H1 count 1.
- `/category/docs?page=2`: HTTP 200, canonical `http://127.0.0.1:8765/category/docs?page=2`, H1 count 1.
- `/tag/daiying-cms`: HTTP 200, canonical `http://127.0.0.1:8765/tag/daiying-cms`, H1 count 1.
- `/tag/daiying-cms?page=2`: HTTP 200, canonical `http://127.0.0.1:8765/tag/daiying-cms?page=2`, H1 count 1.
- `/articles/how-to-install-daiying-cms`: HTTP 200, title/description/keywords/canonical/robots present, H1 count 1.
- `/sitemap.xml`: HTTP 200, XML Content-Type, XML parser PASS, root `urlset`, namespace `http://www.sitemaps.org/schemas/sitemap/0.9`, duplicate URLs NO, category URL YES, tag URL YES, empty category NO.

## 11. Regression Results

- Existing Sitemap P0 XML structure remains valid.
- Absolute URL normalization and double-prefix protection remain covered.
- Existing article canonical behavior remains covered.
- Existing article SEO title/description output remains covered.
- Multiple domain sitemap behavior remains covered.
- Robots sitemap URL behavior remains covered.
- No production release/deployment was performed.

## 12. Known Remaining SEO Debt

- No full Keyword System was implemented in this P0.
- Content does not yet have formal `seo_keywords` / `target_keywords` fields.
- Category/tag entities do not yet have editable SEO title/description/keywords/canonical/robots fields.
- Theme/plugin marketplace public SEO landing pages remain out of scope and still need a separate task.
- Baidu impression keyword/landing-page attribution still requires Baidu Search Resource Platform data.

## 13. P1 Readiness

This P0 establishes stable canonical and sitemap behavior for home, articles, category archives, tag archives, and article pages. It is ready to support the next `SEO Keyword System P1` task without needing to revisit the structural archive/sitemap foundation first.

SEO STRUCTURAL P0: PASS
