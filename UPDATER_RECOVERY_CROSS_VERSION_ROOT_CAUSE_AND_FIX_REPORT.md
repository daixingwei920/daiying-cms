# Daiying CMS Updater / Recovery Cross-Version Root Cause and Fix Report

Date: 2026-09-27

Scope: investigate and patch the updater/recovery backward-compatibility fatal observed during the production 1.2.69 -> 1.2.70 upgrade. No production changes, no Update Server upload, no official.wechat/market work.

## Exact Root Cause

The production upgrade reached a successful commit point before the fatal:

1. `UpdateService::execute()` prepared the release.
2. It applied migrations.
3. It applied operational support files.
4. It switched `storage/updates/current-release.json` to `daiying-cms-core-update-1.2.70`.
5. `postSwitchHealth()` returned `ok`.
6. `recordOperationStatus(..., Completed, completed)` succeeded.
7. A later post-commit action failed while the still-running PHP process was using the old 1.2.69 updater runtime.
8. The generic catch block treated that late failure as a failed upgrade and called `rollback()`.
9. `rollback()` tried to instantiate `Cms\Core\Recovery\RecoveryActions`.
10. The old running runtime could not autoload that class, producing the observed fatal at `UpdateService.php:482`.

Production evidence:

- `/health` after the event: `ok / NORMAL / 1.2.70`
- `current-release.json`: points to `daiying-cms-core-update-1.2.70`
- `cms_core_update_operations` row `bf6ef3d6472fd7d994157def`: `status=Completed`, `current_step=completed`
- no `maintenance.mode`, `recovery.mode`, or `core-update.lock`
- no `core.updated` audit row for 1.2.70, which places the triggering exception after `recordOperationStatus(Completed)` and before/during the final audit/cleanup tail

The most likely concrete trigger is the old release pruning step: `pruneOldReleases(2)` could delete the active running updater release when another stale release directory was newer than the old runtime directory. Any later class autoload, such as `AuditLogger`, then depends on a runtime path that no longer exists.

## Runtime Version Analysis

- Running updater runtime: old 1.2.69 code loaded at PHP process start.
- Old release: `daiying-cms-core-update-1.2.69`.
- New release: `daiying-cms-core-update-1.2.70`.
- Active release pointer after switch: `daiying-cms-core-update-1.2.70`.

The PHP process did not and cannot automatically swap its already-registered autoloader to the new release after pointer switch. The next web/CLI request uses the new pointer, but the in-flight updater transaction continues with the runtime it booted from.

## Why Existing Tests Missed It

- Existing cross-version fixture coverage only validates clean fixture layouts; it did not include an active old release plus another stale release directory that makes pruning delete the running updater runtime.
- Tests did not distinguish the commit point (`SWITCHED + POSTCHECK_OK + Completed`) from best-effort post-commit cleanup.
- Tests did not assert CLI exit code semantics for successful upgrades with cleanup/audit warnings.
- Tests did not model class autoload after pruning the directory that contains the running updater runtime.

## Fix

Files changed:

- `system/core/Update/UpdateService.php`
- `tests/updater_runtime_boundary.php`
- `config/app.php`
- `config/app.example.php`
- `system/core-manifest.json`
- `CHANGELOG.md`

Behavioral changes:

- After pointer switch and successful post-switch health, late cleanup/audit exceptions are treated as `cleanup_warnings`, not as reasons to rollback a healthy new release.
- `pruneOldReleases()` now protects both the active release pointer and the release directory containing the currently running updater runtime.
- Recovery mode file operations used by updater rollback/recovery are internal stable updater-boundary operations and no longer require `Cms\Core\Recovery\RecoveryActions` to be autoloadable.
- Version advanced to 1.2.71 so the already-published 1.2.70 artifact is not overwritten.

## Why The Fix Is Version-Safe

The updater no longer assumes the running PHP runtime changes when `current-release.json` changes. It treats these as separate concepts:

- running updater runtime
- old release
- new release
- active release pointer

The commit boundary is now explicit: once the pointer targets the new release and post-switch health is ok, the upgrade is successful. Cleanup/audit/pruning are post-commit work and cannot convert that success into rollback.

Rollback and interrupted-update recovery now use stable file writes for `storage/recovery.mode`, avoiding a dependency on newer Recovery classes during old-runtime failure paths.

## Cross-Version Tests

Implemented and passing:

- `tests/updater_runtime_boundary.php`
  - verifies the running updater release is not pruned even when it is older than retained stale directories
  - verifies active release is protected
  - verifies unprotected stale release is still pruned
  - verifies updater recovery mode file operations do not require `RecoveryActions`

Important limitation:

An already-published, unpatched 1.2.69 or 1.2.70 runtime cannot be retroactively fixed by code that only exists inside a target 1.2.71 release package. During 1.2.69 -> 1.2.71 or 1.2.70 -> 1.2.71, the transaction starts in the old runtime. Therefore the 1.2.71 Release Gate must include either:

- a stable updater-runtime bridge/preflight that executes the transaction with patched updater code, or
- a strict preflight that guarantees the old runtime release will not be pruned before the old updater returns.

This is a release strategy requirement, not a plugin or SDK issue.

## Exit Semantics

Patched behavior:

- successful switch + post-switch health ok => `status=Completed`, exit code should be 0 even if cleanup warnings are recorded
- pointer not switched, migration failed, or post-switch health failed => rollback/recovery path, non-zero on failure
- post-commit cleanup warnings are written to `storage/updates/history/post-commit-warning-<operation>.json`

## Recovery Semantics

Rollback/recovery should run only before the commit boundary or when post-switch health fails.

It should not run merely because post-commit audit/prune/cleanup failed after the new release is already active and healthy.

Recovery state remains data-oriented: operation id, pointer state, package/release metadata, phase, restore points, and timestamps. The updater must not require serialized objects, callables, or new-version-only Recovery classes to decide what happened.

## Regression Results

Passed:

- `php -l system/core/Update/UpdateService.php`
- `php tests/updater_runtime_boundary.php`
- `php tests/active_release_integrity_target.php`
- `php tests/release_gate_v1_contract.php`
- `php tests/plugin_sdk_foundation_v1.php`
- `php tests/plugin_license_service_v1.php`
- `php tests/foundation_public_api_storage_v1.php`
- `php tests/foundation_system_services.php`
- `php tests/commerce_v1_contract.php`

Local readiness note:

- `scripts/validate_production_readiness.php` reports `not_ready` in this local dev checkout because local `config/app.php` intentionally lacks production DSN/secret/installed lock. Core manifest integrity itself passes.

## Production Recommendation

- Keep production on 1.2.70. Do not rollback.
- Do not overwrite or republish the existing 1.2.70 artifact.
- Prepare this as 1.2.71 candidate.
- Before 1.2.71 Release Gate, add an explicit bridge/preflight step for sites currently on unpatched 1.2.70:
  - inspect `storage/updates/releases`
  - confirm pruning by old runtime cannot delete the running active release, or run the transaction through a patched stable updater runtime
  - fail-closed if the release directory state is unsafe
- No extra production cleanup is required for the completed 1.2.70 upgrade based on current health, pointer, lock, maintenance, recovery, migration, and integrity checks.

STOP: no release, no Update Server upload, no production modification, no official.wechat/market work.
