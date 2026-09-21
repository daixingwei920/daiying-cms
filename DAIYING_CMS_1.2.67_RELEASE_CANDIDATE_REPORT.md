# Daiying CMS 1.2.67 Release Candidate Report

Date: 2026-09-20

## Release Candidate Status

This is a release-candidate checkpoint only.

1.2.67 has not been published.

No Git tag, GitHub Release, public install package, official update-server package, or production deployment has been created in this step.

## Baseline

- Worktree: `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms-core-clean-1.2.63`
- Branch: `release/1.2.65`
- HEAD before release commit: `6131819ee21308b13cc708ca3177caf55d55548e`
- Pending Core version: `1.2.67`
- Previous published version in repository metadata: `1.2.66`

## Final 1.2.67 Scope

1.2.67 intentionally contains only two major Core changes:

### A. Generic Base Path Deployment

Adds generic `site.base_path` support for:

- Subdirectory deployment.
- Demo-platform URL prefixes.
- Reverse proxy/path-prefix deployments.

Root deployment remains backward compatible when `site.base_path` is empty.

Related report:

- `DAIYING_CMS_BASE_PATH_CORE_CHANGE_REPORT.md`
- `DAIYING_CMS_DEMO_COMPATIBILITY_GATE_V2.md`

### B. Official Market Search + Pagination

Adds official-market browsing scalability:

- Server-side market search.
- Data-layer pagination.
- Search-preserving pagination URLs.
- Plugin/payment-provider/theme coverage.
- Empty/out-of-range/invalid-page handling.
- Installed/update/incompatible state preservation.

Related report:

- `DAIYING_CMS_MARKET_SEARCH_PAGINATION_IMPLEMENTATION_REPORT.md`

## Version Metadata

Updated:

- `config/app.php`: `1.2.67`
- `config/app.example.php`: `1.2.67`
- `CHANGELOG.md`: pending 1.2.67 entry
- `system/core-manifest.json`: regenerated from full Core-owned tree

README public download references were not changed because 1.2.67 has not been formally published.

## Release Gate / Test Results

Commands completed successfully:

- `php tests/market_search_pagination.php`
- `php tests/base_path_deployment.php`
- `php tests/release_phase0_gate.php`
- `php tests/release_gate_v1_contract.php`
- Full tracked PHP syntax check
- Full tracked PHP tests: `for f in tests/*.php; do php "$f"; done`
- `git diff --check`
- Core manifest parity check

Expected environment-dependent skips in the full suite:

- `tests/commerce_ai_deepseek_live.php`
- `tests/cross_version_fixture_upgrade.php`
- `tests/official_affiliate_hub_cj_live.php`

These skips are unchanged from their environment requirements and are not 1.2.67 regressions.

## Base-Path Regression Result

`tests/base_path_deployment.php` PASS.

Verified coverage includes:

- Root deployment behavior.
- `/daojia` style base-path deployment.
- Frontend route stripping.
- Content permalinks.
- Theme assets.
- Plugin assets.
- Pagination/search/category/menu URL generation covered by the current base-path test scope.

Known limit preserved from Gate V2:

- Full arbitrary base-path support for every legacy admin URL remains a later P1 migration. This 1.2.67 work only adds targeted base-path-safe market URLs where this task touched admin market browsing.

## Market Regression Result

`tests/market_search_pagination.php` PASS.

Verified coverage includes:

- Default first page.
- Second page.
- Last/out-of-range page clamp.
- Search hits and no-result empty state.
- Search plus pagination.
- Invalid page normalization.
- Theme market entry.
- Plugin/payment provider filtering.
- Installed/update state preservation.
- Base-path market pagination/action URLs.

## Manifest Result

`system/core-manifest.json` was regenerated from the full Core-owned tree. The manifest parity check passed.

This release candidate does not use diff-only or changed-file-only packaging.

## Git Diff Summary

The current diff contains:

- Base-path Core implementation and tests.
- Market search/pagination implementation and tests.
- Version metadata update to `1.2.67`.
- Implementation/release candidate reports.

Unrelated generated formatting changes to `system/official-plugins.php` were explicitly removed from the diff and are not part of this release candidate.

## Release Artifacts

Not generated in this step.

Per user instruction, this checkpoint stops before:

- Git commit
- Git tag
- Full install package build
- Update package build
- Official update-server publication
- GitHub Release
- Production/test-site upgrade verification

## Known Limits

- Official market server pagination support is expected. If the API omits pagination metadata, Core falls back to safe local slicing for compatibility.
- Complete admin base-path migration remains out of scope.
- Demo Platform Phase 1 remains paused until 1.2.67 is confirmed, released, and verified.

## Stop Gate

Status: READY FOR HUMAN REVIEW.

Next action after approval:

1. Commit the 1.2.67 RC changes.
2. Build full Core-owned release artifacts through Release Gate.
3. Produce install/update packages and SHA-256.
4. Push Git/tag if approved.
5. Publish official update server package.
6. Verify update detection and production/test-site behavior.

No release action has been performed yet.
