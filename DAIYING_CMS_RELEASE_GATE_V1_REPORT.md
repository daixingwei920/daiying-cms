# Daiying CMS Release Gate V1 Report

Date: 2026-09-14

## Summary

Release Gate V1 is implemented as a fail-closed release and update safety layer for Daiying CMS Core. The main rule is now explicit: a stable Core release cannot be built from `git diff`, ad hoc changed files, or a manually selected subset. A formal Core update package must be built from an exact Git commit and must contain a complete Core-owned release snapshot.

No production site was modified during this task. No update server stable channel was published during this task.

## v1.2.58 Root Cause

The v1.2.58 failure class was caused by partial Core packages being allowed into the update flow.

Root causes:

- Core update packages could be assembled from a partial changed-file set.
- `UpdateService::prepareRelease()` copied the current root `system/core`, root migrations, and root `system/core-manifest.json` into the release directory first, then overlaid update package files.
- If the root shell was old and the package was partial, the prepared active release became a mixed Core tree: some files from the new version and some files from the old root.
- Web requests already used the active release pointer, but root CLI/cron entrypoints still loaded root `system/core/Bootstrap/autoload.php`, leaving CLI/Web on different Core trees.

Release Gate V1 prevents this failure by rejecting partial modern Core packages before publication and again before installation.

## Implementation Locations

- `scripts/release_parity_gate.php`
  - Exact commit release-source validation.
  - Installer/update parity validation.
  - Core-owned full snapshot validation.
  - PHP syntax checks.
  - Core class import resolution.
  - Migration order/uniqueness/up-callable validation.
  - Public Core interface compatibility check against previous stable.
  - Installer signature sidecar validation.
  - Update metadata and package SHA validation.

- `scripts/build_exact_commit_update_package.php`
  - Builds from an exact commit only.
  - Declares `snapshot_type=core-owned-full-snapshot`.
  - Declares `release_gate_v1`.
  - Includes the complete Core-owned update file set instead of a local diff.
  - Keeps `min_upgrade_from`, `hard_min_version`, and `migration_floor` at `1.2.0`.

- `scripts/build_exact_commit_installer.php`
  - Builds a full installer from an exact commit.
  - Verifies installer files and committed `system/core-manifest.json`.
  - Writes manifest, SHA256, and optional Ed25519 signature sidecar.

- `system/core/Update/UpdatePackageReader.php`
  - Rejects non-legacy update packages without `release_gate_v1`.
  - Requires `system/core-manifest.json`.
  - Verifies every Core manifest file exists in the package.
  - Rejects extra undeclared `system/core/*` files.
  - Verifies packaged Core hashes match `system/core-manifest.json`.

- `system/core/Update/UpdateService.php`
  - Stages releases from verified package contents only.
  - No longer seeds staging from the mutable root Core tree.
  - Runs release integrity preflight on the staged release.
  - Runs recursive PHP syntax preflight on staged Core PHP files.

- `system/core/Update/UpdatePackageManifest.php`
  - Allows controlled operational support files such as `cli.php`.

- `cli.php`
  - Loads active release autoload when `storage/updates/current-release.json` points to an active release.
  - Falls back to root autoload only when no valid active release exists.

- `scripts/publish_scheduled_content.php`
  - Uses the same active-release bootstrap behavior for cron-style scheduled publishing.

- `tests/release_gate_v1_contract.php`
  - Regression for the v1.2.58 partial package class.

- `tests/active_release_bootstrap.php`
  - Verifies CLI loads active release autoload when present.

## Core-Owned Snapshot Rule

A modern Core update package must:

1. Be built from a resolved exact Git commit.
2. Include `snapshot_type=core-owned-full-snapshot`.
3. Include `release_gate_v1` in `acceptance_gates`.
4. Include `system/core-manifest.json`.
5. Include every file declared by `system/core-manifest.json`.
6. Match every Core file hash declared by `system/core-manifest.json`.
7. Contain no extra undeclared `system/core/*` files.
8. Match package `update.json` files exactly.
9. Match update metadata `changed_files` and `required_migrations` exactly.
10. Keep cross-version floor fields at `1.2.0` unless a future major migration policy explicitly changes them.

If any of these fail, the package is rejected before stable publication and rejected again by the runtime update reader.

## Release Flow

Recommended stable release flow:

1. Commit the intended release source.
2. Build the installer with `scripts/build_exact_commit_installer.php`.
3. Build the Core update package with `scripts/build_exact_commit_update_package.php`.
4. Run `scripts/release_parity_gate.php` against:
   - target commit/tag
   - installer ZIP
   - installer manifest
   - update ZIP
   - update metadata
5. If any P0 check fails, stop. Do not create a GitHub Release and do not publish stable.
6. Only after Release Gate PASS, upload the GitHub Release assets and update the official update server stable channel.

## Update Flow

The runtime update flow is now:

1. Download package.
2. Verify package signature/hash/path safety.
3. Verify modern package is a full Core-owned snapshot.
4. Extract to staging.
5. Run release integrity preflight.
6. Run PHP syntax preflight.
7. Run migration preflight and checksum checks.
8. Create rollback point.
9. Atomically switch active release pointer.
10. Run health checks.
11. Keep the new release on success.
12. Restore the previous release on failure.

The updater no longer builds staging by copying mutable root Core files before overlaying the package.

## Rollback Flow

Rollback continues to rely on the active release pointer and restore point model. The important Release Gate V1 change is that staged releases are no longer mixed from old root files and new package files. This makes rollback targets cleaner and reduces the chance of switching to a polluted Core tree.

## CLI/Web Bootstrap

Read-only production audit found:

- Web entrypoint already uses the active release pointer.
- Production root `cli.php` still loads root `system/core/Bootstrap/autoload.php` until the next Core update containing the new `cli.php` is installed.
- Production `scripts/publish_scheduled_content.php` also still loads root autoload until updated.

Local implementation now makes CLI and scheduled publishing load the same active release Core as Web. If no valid active release exists, they safely fall back to root autoload for fresh installs and recovery.

## Candidate Artifact Validation

Final validated local candidate before this report commit:

- Installer ZIP: `outputs/release-gate-v1-1.2.61/installer/daiying-cms-1.2.61-stable-exact-27b0f20ad3d4.zip`
- Installer SHA256: `a69edca960f778f4f49bcc5db50c8d9118d4663cb603e51d9eb68fffa9b947e8`
- Installer files: 522
- Update ZIP: `outputs/release-gate-v1-1.2.61/update/daiying-cms-core-update-1.2.61-stable-exact-27b0f20ad3d4.zip`
- Update SHA256: `7f1c0244b06430c67551d3a30d1c3935c35a25c3180f2a9b556f7346a5397f51`
- Update changed files: 359
- Required migrations: 42
- `min_upgrade_from`: `1.2.0`
- `hard_min_version`: `1.2.0`
- `migration_floor`: `1.2.0`

Note: these were local validation artifacts only. They were not published to stable.

## Test Results

Passed:

- `php -l scripts/release_parity_gate.php`
- `php -l scripts/build_exact_commit_installer.php`
- `php tests/release_gate_v1_contract.php`
- `php tests/active_release_bootstrap.php`
- `php tests/release_phase0_gate.php`
- `php scripts/verify_release_artifacts.php outputs/release-gate-v1-1.2.61/installer/*.zip`
- Full local PHP test suite: `for f in tests/*.php; do php "$f" || exit 1; done`
- Full Release Parity Gate on local installer/update candidate: PASS

Expected skips in the full local suite:

- `tests/commerce_ai_deepseek_live.php`: skipped because `DEEPSEEK_API_KEY` is not set.
- `tests/official_affiliate_hub_cj_live.php`: skipped because CJ live credentials are not set.
- `tests/cross_version_fixture_upgrade.php`: skipped because true local 1.2.0 and 1.2.22 fixture artifacts are still missing.

## Regression Coverage

`tests/release_gate_v1_contract.php` builds a simulated partial modern update package containing only selected Core files, including the same failure class as v1.2.58 where new `Application.php`/`AdminController.php` could be combined with old missing dependencies.

The test verifies:

- `UpdatePackageReader` rejects the partial package.
- `scripts/release_parity_gate.php` rejects the partial package before publication.
- The failure message points to update package parity or full snapshot violation.

## Remaining Risks

- The true 1.2.0 and 1.2.22 cross-version fixture packages are still absent locally, so the full historical upgrade matrix cannot yet be honestly marked complete.
- Production root CLI/cron will remain on the old bootstrap until a future Release Gate V1-approved Core update is installed.
- The Core class reference check is intentionally conservative. It validates `Cms\Core` imports and public interface compatibility, but it is not a full PHP static analyzer for every dynamic call.
- Release Gate V1 validates package integrity and release parity. It does not replace production smoke tests after deployment.

## Freeze Decision

Release Gate V1 can be frozen for the specific goal of preventing incomplete Core release packages and mixed root/active-release pollution.

The broader cross-version update program is not fully frozen until true 1.2.0 and 1.2.22 fixture artifacts are added and the complete historical matrix can run without SKIP.
