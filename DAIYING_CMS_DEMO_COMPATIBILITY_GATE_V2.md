# Daiying CMS Demo Compatibility Gate V2

Date: 2026-09-20

## Purpose

This gate checks whether Daiying CMS Core can support an official theme demo deployment under a path prefix such as:

```text
demo.daiyingcms.com/daojia/
```

without HTML rewriting, Apache URL hacks, theme-specific patches, or Demo Platform special cases in Core.

## Previous Blocker

Before this base-path change, Daiying CMS generated and routed many frontend URLs as root-absolute paths such as:

```text
/articles
/search
/content/themes/...
/extension-assets/...
```

That made a path-based demo site fragile because a theme demo mounted at `/daojia` could route the initial page but then generate links and assets that escaped back to site root.

## Implemented Core Capability

Core now has a generic `site.base_path` deployment-prefix capability.

The capability is not named after Demo Platform and contains no theme-specific or demo-specific branching.

Covered behavior:

- Request path stripping before normal routing.
- Local URL prefixing at Core output points.
- Root deployments remain unchanged when `site.base_path` is empty.
- Local redirects are prefixed centrally.
- Theme and plugin assets can be generated under the base path.
- Default Theme helpers now honor the active base path.

## Verified Scenarios

### Root Deployment

Expected:

- Existing root URLs unchanged.
- Existing root routing unchanged.
- Theme assets unchanged.
- Plugin assets unchanged.
- Menu/search/pagination/content links unchanged.

Result: PASS.

### `/daojia` Base Path Deployment

Expected:

- `/daojia/articles` routes as `/articles`.
- Content links become `/daojia/articles/{slug}`.
- Search forms point to `/daojia/search`.
- Pagination links keep `/daojia`.
- Theme assets point under `/daojia/content/themes/...`.
- Plugin assets point under `/daojia/extension-assets/...`.
- Menu links and active state work after stripping the base path.

Result: PASS.

## Tests Executed

```text
php tests/base_path_deployment.php
php tests/theme_api_v1.php
php tests/theme_asset_serving_contract.php
php tests/frontend_extension_api.php
php tests/release_gate_v1_contract.php
php tests/release_phase0_gate.php
for f in tests/*.php; do php "$f"; done
git diff --check
```

Result: PASS, except expected live credential/fixture skips in the full suite.

## Demo Gate Decision

```text
DEMO_CORE_CHANGE_REQUIRED = NO
DEMO_PUBLIC_BASE_PATH_GATE = PASS
DEMO_PLATFORM_PHASE_1_ALLOWED = YES_AFTER_MANUAL_CONFIRMATION
```

The original Core blocker for public path-based theme demos is resolved in the clean Core worktree.

## Remaining Boundary

Admin pages under a per-demo base path are not fully frozen because old controller-rendered admin body HTML still contains direct `/admin/...` actions and links. The shared admin shell and redirect headers are handled, but a full admin subpath migration should be a separate task if the Demo Manager itself must run under `/daojia/admin`.

Recommended Demo Platform architecture remains:

- Public demo pages under `demo.daiyingcms.com/{demo-slug}/`.
- Demo Manager/admin operations outside per-theme public slugs, or in a dedicated management instance/path.
- No per-theme Core hacks.

## Stop Point

Do not deploy Demo Platform yet.

Next recommended action:

1. Review this Gate V2 result.
2. Decide whether to turn this Core change into the next patch release.
3. Only after that, start Demo Platform Phase 1.
