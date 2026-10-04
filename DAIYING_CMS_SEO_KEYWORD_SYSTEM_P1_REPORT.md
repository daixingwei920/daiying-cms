# Daiying CMS SEO Keyword System P1 Report

Date: 2026-10-03

## Scope

Implemented SEO Keyword System P1 without changing the P0 canonical/sitemap policy, without AI keyword generation, without marketplace SEO pages, without production release/deploy, and without Core version changes.

## Schema

- Content SEO keyword data uses existing `cms_contents.meta_json`.
- Category/tag SEO data uses new `cms_terms.meta_json`.
- New migration:
  - `system/migrations/2026_10_03_000001_seo_keyword_system_p1.php`
  - Adds nullable `cms_terms.meta_json` as `TEXT` on SQLite and `LONGTEXT` on MySQL/MariaDB.
  - Idempotent table/column checks preserve existing sites and existing term rows.

## Migration / Upgrade Compatibility

- Old content rows without `seo_keywords` or `target_keywords` continue using existing SEO title/description/canonical fallback.
- Old category/tag rows receive `meta_json = NULL` and are decoded as an empty meta array.
- Updating an existing term without passing `meta` preserves existing term SEO metadata.
- No content title, description, slug, or URL is auto-modified.

## Fields

Content meta:

- `seo_keywords`: public meta keywords for that content item.
- `target_keywords`: internal SEO target keywords, used for future cannibalization checks and not directly rendered.

Category/tag meta:

- `seo_title`
- `seo_description`
- `seo_keywords`
- `target_keywords`
- `canonical_url`
- `robots_index`
- `robots_follow`

## Field Validation

- Keywords accept comma, Chinese comma, and newline separators.
- Keywords are stripped of HTML, whitespace-normalized, de-duplicated case-insensitively, capped at 20 items, and each keyword is capped at 80 characters.
- Canonical URL remains restricted to `http://` or `https://` when provided.
- Robots fields are normalized to booleans.

## Admin UI

Content editor:

- Added `SEO Keywords` field.
- Added `Target Keywords` field with note that it is internal and not directly output to meta keywords.

Category/tag editor:

- Existing category admin now includes SEO fields.
- Added tag admin routes and UI:
  - `/admin/tags`
  - `/admin/tags/edit/{id}`
- Category/tag list marks rows with configured SEO data.

## Frontend Metadata Output

Fallback policy:

1. Manual SEO field wins.
2. Existing system/default value is used when manual field is empty.

Content:

- `seo_title` controls title.
- `seo_description` controls description.
- `seo_keywords` controls `<meta name="keywords">`.
- `target_keywords` is not rendered.

Category/tag:

- Manual `seo_title`, `seo_description`, `seo_keywords`, `canonical_url`, and robots values are used when present.
- Empty fields fall back to existing archive title/description/canonical policy.

Themes:

- Daiying Media theme now prefers `seo['keywords']`, falling back to theme global `seo_keywords`.
- Default theme now outputs `seo['keywords']` or theme `seo_keywords` when present.

## Sitemap Policy

P0 policy is preserved:

- Non-empty category/tag pages are included.
- Empty terms are excluded.
- `robots_index=false` term pages are excluded.
- Existing article/page sitemap XML structure and canonical URL normalization remain unchanged.

## Keyword Cannibalization Base Capability

Added repository query methods:

- `ContentRepository::targetKeywordBindings()`
- `ContentRepository::targetKeywordConflicts()`

These expose the base data needed to identify cases where the same `target_keywords` value is bound to more than one public content/term URL. No complex UI or ranking workflow was added in P1.

## Automated Tests

- PHP syntax checks: PASS
- `php tests/core_sitemap.php`: PASS
- `php tests/rest_api_v1.php`: PASS
- `php tests/content_category_slug_preservation.php`: PASS
- `php tests/release_phase0_gate.php`: PASS

Covered:

- Article `seo_keywords` output.
- Article canonical/description regression.
- Category/tag manual SEO title and keywords output.
- Category/tag sitemap inclusion.
- Empty term exclusion.
- `robots_index=false` term sitemap exclusion.
- Target keyword cannibalization grouping.
- REST create/update of term SEO metadata.
- Existing category slug behavior.
- Existing sitemap XML validity.

## Real Runtime Verification

Temporary local site:

- Root: `/tmp/daiying-seo-p1-http`
- Server: `http://127.0.0.1:8766`
- Theme: `daiying_media`

Verified:

- `/`: HTTP 200, title/description/keywords/canonical/robots present, H1 count 1.
- `/articles/how-to-install-daiying-cms`: HTTP 200, article SEO title/description/keywords/canonical/robots present.
- `/category/docs`: HTTP 200, category manual SEO title/description/keywords/canonical/robots present.
- `/tag/daiying-cms`: HTTP 200, tag manual SEO title/keywords/canonical/robots present.
- `/sitemap.xml`: HTTP 200, XML parser PASS, category/tag included, noindex category excluded, duplicate URLs NO.
- `target_keywords=internal-only-target` did not appear in meta keywords or body.
- Cannibalization query returned two bindings for `internal-only-target`: the article URL and the category URL.

## Known Remaining SEO Debt

- No AI keyword generation.
- No keyword ranking tracking.
- No Baidu API integration.
- No `/themes`, `/plugins`, or marketplace SEO public pages.
- No keyword center UI.
- No automatic recommendation or overwrite of human SEO fields.

## Result

SEO KEYWORD SYSTEM P1: PASS
