# Daiying CMS 1.2.70 -> 1.2.71 Updater Runtime Bridge Report

Date: 2026-09-27

Scope: prove that production sites currently running the unpatched 1.2.70 updater can safely enter the patched 1.2.71 updater without deleting their running runtime. No release, no Update Server publication, no production changes.

## Old Runtime Analysis

The 1.2.70 `UpdateService::execute()` still runs from the PHP runtime loaded before the release pointer changes. Its post-commit tail is:

1. `recordOperationStatus(..., Completed, completed)`
2. `pruneOldReleases(2)`
3. `exitMaintenance()`
4. `AuditLogger->record('core.updated', ...)`
5. return `Completed`

The old `pruneOldReleases(2)` behavior is purely:

- list all directories under `storage/updates/releases/*`
- sort by `filemtime` descending
- keep the first two
- delete the rest

It does not protect the active release pointer, the running PHP updater runtime, the rollback source, or the target release by identity.

## Production Layout Risk

The risky layout is:

- running/active release: `daiying-cms-core-update-1.2.70`
- target staging after prepare: `daiying-cms-core-update-1.2.71`
- one or more stale releases with mtimes newer than the running 1.2.70 directory

After target staging, old 1.2.70 prune keeps only the two newest directories. If the target and a stale directory are newer than the running 1.2.70 directory, old prune can delete the directory containing the still-running updater autoload/classes. Any later class load can then fail before the PHP process exits.

## Chosen Strategy

Chosen strategy: strict preflight with optional safe stale cleanup.

Implemented as:

- `scripts/preflight_updater_runtime_bridge.php`
- `tests/updater_runtime_bridge_preflight.php`

The bridge is standalone PHP. It does not require Core autoload, 1.2.71 classes, RecoveryActions, Plugin SDK, database access, migrations, maintenance mode, or the update package.

It uses the exact old 1.2.70 prune rule to simulate the release directory set after target staging. It returns:

- `SAFE` when old `pruneOldReleases(2)` cannot delete the running runtime or active release
- `UNSAFE` otherwise

With `--apply-cleanup`, it deletes only stale release directories that are all of:

- inside `storage/updates/releases`
- not active
- not running
- not target
- known by realpath

It does not modify the current release pointer, database, maintenance state, migrations, or update package.

## Compatibility Constraint

The bridge script must not be included as a new operational support file in the 1.2.71 update package, because old 1.2.70 `UpdatePackageManifest::isAllowedUpdatePath()` rejects paths it does not already know. A full local simulation initially reproduced this rejection:

`Update package may only target Core-owned paths.`

Therefore the bridge is a Release Gate / deployment preflight tool, not a file delivered through the 1.2.71 core update ZIP to old 1.2.70 sites.

## Safety Proof

For a given site root and target release id, the bridge computes:

- active release from `storage/updates/current-release.json`
- running release from `--running-release`, or active pointer when not supplied
- target release path from the same release-id sanitization used by updater
- old 1.2.70 keep/delete plan after target staging

SAFE requires:

- active pointer is valid
- running release is known and has Core bootstrap
- target staging directory does not already exist
- old 1.2.70 delete plan does not include running release
- old 1.2.70 delete plan does not include active release

If cleanup is requested, the bridge deletes stale candidates oldest first and recomputes the old prune plan after each deletion. It stops as soon as the old plan is safe.

## Failure Policy

Fail closed:

- missing/invalid target release id
- missing `storage/updates/releases`
- missing/invalid `current-release.json`
- active pointer outside releases root
- active release missing Core bootstrap
- running release cannot be proven
- target staging directory already exists
- old prune would still delete active/running after allowed cleanup

## Test Fixtures

Covered by `tests/updater_runtime_bridge_preflight.php`:

- Case 1: normal 1.2.70 -> 1.2.71, no stale releases: SAFE
- Case 2: one stale release: SAFE
- Case 3: multiple stale releases arranged so old prune would delete running active release: detected UNSAFE, then cleanup makes SAFE
- Case 4: cannot determine running release: UNSAFE
- Case 5: broken active pointer: UNSAFE
- Case 6: running != active: requires explicit `--running-release`, cleanup can make SAFE
- Case 7: rollback source/bootstrap missing: UNSAFE
- Case 8: target staging already exists: UNSAFE
- Case 9: interrupted/previous cleanup rerun: idempotently reaches SAFE

Full local simulation:

- built a temporary git commit from current 1.2.71 candidate
- built a local signed 1.2.71 update package with `min_upgrade_from=1.2.70`
- extracted the published 1.2.70 installer fixture
- set active/running to `daiying-cms-core-update-1.2.70`
- added stale releases with mtimes that make old prune dangerous
- ran bridge with `--apply-cleanup`
- executed old 1.2.70 `UpdateService::execute()` against the 1.2.71 package

Result:

- bridge: `safe=true`
- bridge deleted stale release: `stale-b`
- update result: `Completed`
- active pointer version: `1.2.71`
- release id: `daiying-cms-core-update-1.2.71`
- maintenance/recovery/update locks: none
- CLI exit: 0

## Target-Side Fix

The 1.2.71 target-side updater fix remains in place:

- protects running updater runtime from pruning
- protects active release from pruning
- treats `SWITCHED + POSTCHECK_OK` as the commit boundary
- records cleanup warnings instead of rolling back a committed healthy release
- keeps updater recovery file operations inside the stable updater boundary

## Permanent Release Gate

Add these gates before future Core releases:

- Previous stable -> candidate fixture upgrade, not only candidate self-tests.
- Running Runtime Preservation Gate: simulate old/runtime prune rules and prove the running updater release cannot be deleted.
- Commit Boundary Gate: force cleanup/audit failure after `SWITCHED + POSTCHECK_OK` and assert no rollback occurs.
- Exit Semantics Gate: successful committed upgrade exits 0; true pre-commit failure exits non-zero.
- Update Package Backward Allowlist Gate: candidate update ZIP must not include operational support paths rejected by the previous stable runtime.

## Production Action

Current production 1.2.70 does not need rollback or cleanup now.

Before a real 1.2.71 Release Gate/deployment, run the bridge preflight against production 1.2.70:

```bash
php scripts/preflight_updater_runtime_bridge.php \
  --root=/www/wwwroot/saas.daiyinggame.com \
  --target-release-id=daiying-cms-core-update-1.2.71 \
  --json
```

If it returns `UNSAFE`, review output and rerun with `--apply-cleanup` only if the listed deletions are inactive stale release directories. If any identity cannot be proven, fail closed.

STOP: no 1.2.71 release, no Update Server upload, no production modification, no official.wechat/AI Writer/Membership/SDK feature work.
