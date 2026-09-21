# Daiying CMS Market Search + Pagination Implementation Report

Date: 2026-09-20

## Scope

This implementation adds a stable official-market browsing contract for Daiying CMS 1.2.67:

- Server-side market search.
- Server-side/data-layer pagination.
- Admin market UI search and page navigation.
- Root deployment compatibility.
- Base-path URL compatibility for in-scope market browse/install links.

This work does not publish 1.2.67, does not tag Git, does not build release packages, and does not update the official update server.

## Baseline

- Worktree: `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms-core-clean-1.2.63`
- Branch: `release/1.2.65`
- Current HEAD before release commit: `6131819ee21308b13cc708ca3177caf55d55548e`
- Target pending version: `1.2.67`
- Existing 1.2.67 scope already included: generic `site.base_path` deployment support.

## Files Changed

Market search/pagination specific files:

- `system/core/Admin/AdminController.php`
- `system/core/Market/HttpMarketClient.php`
- `system/core/Market/OfflineMarketClient.php`
- `system/core/Market/MarketItem.php`
- `system/core/Market/MarketPagedSearchInterface.php`
- `system/core/Market/MarketSearchResult.php`
- `tests/market_search_pagination.php`

Release metadata touched for the combined 1.2.67 release candidate:

- `CHANGELOG.md`
- `config/app.php`
- `config/app.example.php`
- `system/core-manifest.json`

## Market Query Contract

Core now supports a paged market query contract through:

- `Cms\Core\Market\MarketPagedSearchInterface`
- `Cms\Core\Market\MarketSearchResult`

The paged query accepts:

- `type`
- `query`
- `page`
- `per_page`
- `forceRefresh`

The result exposes:

- `items`
- `currentPage`
- `perPage`
- `totalItems`
- `totalPages`

`MarketSearchResult::fromItems()` normalizes invalid page/per-page values, clamps out-of-range pages, and guarantees `totalPages >= 1`.

## Search Contract

Search is server-side when the official market API supports pagination. The HTTP market client sends:

- `type`
- `q`
- `page`
- `per_page`
- `channel`
- `core_version`
- `php_version`

Search covers at least:

- Name
- Product/package/market/extension IDs
- Description/summary where present

The offline fallback search also checks name, market ID, extension ID, package ID, product ID, slug, developer name, description, and capabilities.

Search input is passed as a query parameter and rendered through existing escaping helpers. No file path or remote URL is accepted from the search parameter.

## Pagination Contract

Default per-page size is centralized in:

- `AdminController::MARKET_ITEMS_PER_PAGE = 20`
- Optional setting: `market.items_per_page`

The admin UI displays:

- Total items
- Current page
- Total pages
- Previous/next links
- A compact page window
- Search-preserving pagination URLs

Invalid page values such as `abc`, `-1`, and `0` normalize to page 1.

Out-of-range pages are clamped to the last valid page.

## Plugin And Theme Coverage

The existing market structure is preserved:

- Plugin browsing still combines `plugin` and `payment_provider`.
- Theme browsing queries `theme`.
- Existing installed/update state is still computed from `cms_extension_sources`.

The implementation does not force a repository merge between plugins and themes.

## UI Behavior

Admin market pages now include:

- A search field.
- Clear-search link when a query is active.
- Result summary.
- Empty state for no matches.
- Pagination navigation below the table.

Existing item state and actions remain in place:

- Details
- Install
- Update
- Incompatible state
- License-required action path
- Price/developer/review/capability display

## Base-Path Compatibility

In-scope market links and forms are generated through `AdminController::adminPath()`, which applies `Cms\Core\Routing\BasePath`:

- Search form action
- Pagination URLs
- Details link
- Install authorization form
- Refresh/diagnostics links
- Install completion/failure links

This preserves root deployment behavior while allowing market browsing under a base path such as `/daojia`.

Full arbitrary `/base/admin` migration for every legacy admin page remains outside this 1.2.67 scope.

## Error Handling

If the market API/registry is unavailable, the existing degraded behavior remains:

- The page renders an error message.
- Installed site/admin operation is not blocked.
- Search/pagination do not turn market API downtime into a backend crash.

## Tests

New focused test:

- `php tests/market_search_pagination.php` PASS

Covered cases:

1. Default first page.
2. Second page.
3. Last page.
4. Search hit.
5. Search by description.
6. Search no result.
7. Search plus page.
8. Invalid page.
9. Out-of-range page.
10. Theme browsing entry.
11. Plugin/payment provider type calls.
12. Installed/update state preserved after pagination.
13. Base-path market pagination URL and install action.

Additional regression tests run:

- `php tests/base_path_deployment.php` PASS
- `php tests/release_phase0_gate.php` PASS
- `php tests/release_gate_v1_contract.php` PASS
- Full tracked PHP syntax check PASS
- Full tracked PHP test suite PASS

Expected environment-dependent skips during full suite:

- `commerce_ai_deepseek_live.php` skipped: missing `DEEPSEEK_API_KEY`
- `cross_version_fixture_upgrade.php` skipped: missing local historical fixture artifacts
- `official_affiliate_hub_cj_live.php` skipped: missing CJ live environment

Quality checks:

- `git diff --check` PASS
- Core manifest parity PASS after regenerating `system/core-manifest.json`

## Git Diff Summary

This implementation adds two new Market classes, updates HTTP/offline market clients to expose paged search, updates the admin market page, and adds one focused regression test. The broader diff also contains previously completed base-path changes included in the same 1.2.67 release candidate.

## Known Limits

- The official market server must continue to support `/api/market/search`. If the server returns a full list without pagination metadata, Core falls back to local slicing.
- Multi-type plugin browsing preserves the current `plugin + payment_provider` model and does not attempt to globally sort across remote result sets beyond the current merged page behavior.
- Complete admin subpath migration outside market URLs remains a later base-path P1 task.

## Release Status

Implementation and tests are complete for review.

Status: STOPPED BEFORE RELEASE.

No Git tag, release package, update server publication, or production deployment has been performed.
