# Daiying CMS Legacy Root Shell Root Cause Report

Date: 2026-09-21

Scope: bounded post-1.2.67 production investigation. No production files, database rows, update packages, release metadata, or Core source files were modified by this investigation.

## Executive Summary

Production had a split-brain Core state before the 1.2.67 upgrade:

- Runtime active release was `daiying-cms-core-update-1.2.66`.
- `/health` reported `ok / NORMAL / 1.2.66`.
- Root shell `config/app.php` still contained `1.2.23`.
- Root shell `system/core` was not self-consistent with root `system/core-manifest.json`.

The direct reason updates failed before alignment was not simply that the root shell was old. It was that the root shell was old and internally drifted/corrupted relative to its own root manifest. Current `UpdateService` still runs a root-path integrity check before every update.

The underlying architectural fact is:

> Daiying CMS updater uses an active-release pointer model. It prepares a full Core release under `storage/updates/releases/{release_id}` and switches `storage/updates/current-release.json`. It does not normally copy the full active release back into the root `system/core` shell.

Therefore, the 1.2.23 root shell residue was not overwritten by normal upgrades to 1.2.66. The 1.2.67 production alignment manually restored root shell to a clean 1.2.66 state from the active 1.2.66 release, then the official updater successfully upgraded runtime to 1.2.67.

## Current Production State After Alignment And 1.2.67 Upgrade

Read-only production check after the successful upgrade:

```json
{
  "status": "ok",
  "mode": "NORMAL",
  "version": "1.2.67",
  "release_id": "daiying-cms-core-update-1.2.67",
  "maintenance": false,
  "installed": true
}
```

Pointer:

```json
{
  "release_id": "daiying-cms-core-update-1.2.67",
  "version": "1.2.67",
  "build": "20260921030933-core-update",
  "path": "/www/wwwroot/saas.daiyinggame.com/storage/updates/releases/daiying-cms-core-update-1.2.67",
  "switched_at": "2026-09-21T03:31:35+00:00"
}
```

Root shell `config/app.php` now reports `1.2.66`, because the root shell was aligned from active 1.2.66 before the 1.2.67 pointer switch. This is expected under the current pointer-based updater.

## Evidence

### Web Runtime Uses Active Release Pointer

File: `public/index.php`

Relevant behavior:

- Default autoload path is root `system/core/Bootstrap/autoload.php`.
- If `storage/updates/current-release.json` exists and points to a valid active release autoload, Web loads the active release instead.
- If the pointer is invalid, recovery mode is written.

Code location: `public/index.php:35-51`.

### CLI Runtime Also Uses Active Release Pointer

File: `cli.php`

Relevant behavior:

- `CMS_SOURCE_ROOT` remains the root shell path.
- `cms_cli_active_autoload()` reads `storage/updates/current-release.json`.
- If the active release autoload exists, CLI requires that autoload.

Code location: `cli.php:32-48`.

### Runtime Version Is Overridden From Active Release Pointer

Files:

- `system/core/Config/Settings.php`
- `system/core/Update/ActiveReleaseResolver.php`

Relevant behavior:

- Settings initially load root `config/app.php`.
- Then `ActiveReleaseResolver::version()` overrides `app.version` from `storage/updates/current-release.json` if present.

Code locations:

- `system/core/Config/Settings.php:16-34`
- `system/core/Update/ActiveReleaseResolver.php:10-50`

This explains why production could show 1.2.66/1.2.67 at runtime while root `config/app.php` had stale 1.2.23 before alignment.

### Update Execution Preflights Root Shell Integrity

File: `system/core/Update/UpdateService.php`

Relevant behavior:

- Before preparing a new release, `preflightChecks()` calls `IntegrityChecker` on `$this->rootPath`.
- Failure message: `Current Core integrity check failed.`

Code location: `system/core/Update/UpdateService.php:183-186`.

This is the guard that blocked updates when root shell was not self-consistent.

### Update Package Is Prepared Into A Release Directory

File: `system/core/Update/UpdateService.php`

Relevant behavior:

- `prepareRelease()` extracts package files into a release directory.
- It does not overwrite root `system/core`.
- `preflightRelease()` then checks the prepared release directory.

Code locations:

- `system/core/Update/UpdateService.php:234-260`
- `system/core/Update/UpdateService.php:275-288`

### Updater Applies Only Operational Support Files To Root

Files:

- `system/core/Update/UpdateService.php`
- `system/core/Update/UpdatePackageManifest.php`

Relevant behavior:

- `applyOperationalSupportFiles()` copies only files listed by `UpdatePackageManifest::operationalSupportPaths()` to the root.
- Operational support files include `cli.php`, admin CSS/JS, selected scripts, and `system/official-plugins.php`.
- Operational support files do not include full root `system/core` or `config/app.php`.

Code locations:

- `system/core/Update/UpdateService.php:408-430`
- `system/core/Update/UpdatePackageManifest.php:188-220`

### Active Release Switch Is Pointer-Only

File: `system/core/Update/UpdateService.php`

Relevant behavior:

- `switchPointer()` writes release id, version, build, path, and timestamp.
- It does not sync the active release tree back into root.

Code location: `system/core/Update/UpdateService.php:656-679`.

### Git History

Relevant commits observed:

- `a5e14ad` (`v1.2.4` / `v1.2.8`) introduced the early pointer-based updater baseline.
- `4f92c1c` (`v1.2.37`) fixed update health checks to use active release paths.
- `c9fc49b` introduced Release Gate V1 related update/package hardening.
- `6d03a96` (`v1.2.63`) introduced `ActiveReleaseResolver` and runtime version override.

This history supports that pointer-based active release is long-standing, while runtime version display was later corrected to follow the active release pointer.

## Answers To Required Questions

### 1. Active Release 更新机制与 root Core shell 的关系是什么？

Active Release is the runtime Core selected by `storage/updates/current-release.json`.

The root Core shell is the base installation shell. It provides:

- bootstrap files;
- root storage/config/database location;
- operational support files;
- a fallback Core if no active pointer exists;
- a root integrity target for update preflight.

Runtime Web and CLI prefer the active release autoload when a valid pointer exists.

### 2. 正常 updater 设计是否应该同步 root shell？

Current updater design does not synchronize the full root shell to the active release.

It performs:

1. package validation;
2. extraction into `storage/updates/releases/{release_id}`;
3. prepared release integrity checks;
4. migrations;
5. operational support file copy to root;
6. pointer switch;
7. post-switch health check.

It does not copy full `system/core` or `config/app.php` back to root during normal update execution.

### 3. 如果应该同步，为什么过去 1.2.23 → 1.2.66 没有发生？

Based on current code and available history, full root shell synchronization was not part of the updater design. Therefore 1.2.23 root shell content remained while active releases advanced through the pointer.

Production operation history also supports this: multiple successful update operations advanced active versions, while restore-point Core hashes for the root shell stayed unchanged across many updates until the manual 1.2.66 alignment.

### 4. 如果设计上本来就不同步，那么当前 integrity guard 为什么又要求 root shell 与 Active Release 一致？

The guard does not literally compare root shell to active release.

Current guard checks:

```php
(new IntegrityChecker())->check($this->rootPath)
```

That means root shell must be internally consistent with root `system/core-manifest.json`.

The 1.2.67 alignment strategy made root shell match active 1.2.66 because that was the safest known clean source. But the code-level requirement was root self-integrity, not root equals active release.

This creates a fragile mixed contract:

- Runtime uses active release.
- Update preflight requires root shell self-integrity.
- Root shell is not automatically kept current.

### 5. 是否存在历史升级流程、旧 updater、人工部署或旧版本架构遗留造成的状态分裂？

Yes. The most likely root cause is a historical state split caused by the combination of:

1. pointer-based active release updates;
2. no full root shell synchronization;
3. historical root shell left at 1.2.23;
4. later operational support/root mutations;
5. stricter current integrity checks.

There is not enough retained historical release directory data to attribute the split to a single past manual deployment or one exact updater version. However, the available evidence is sufficient to say the split is a legacy architecture/operations state, not a 1.2.67 package-specific issue.

### 6. 本次手工 alignment 后，升级到 1.2.68 时是否可能再次发生同样问题？

The exact 1.2.23 stale/corrupted root shell condition has been removed by the 1.2.66 alignment.

However, the broader structural risk still exists:

- After upgrading to 1.2.67, root shell remains aligned to 1.2.66.
- Active runtime is 1.2.67.
- Future updates still preflight root shell integrity.
- Future updates still do not fully sync root shell to the active release.

If root 1.2.66 remains self-consistent, a 1.2.68 update should not fail for the same reason. If future operational support changes, manual edits, permissions, or partial root mutations make root shell inconsistent again, the same class of failure can recur.

## Classification

### A. Historical issue permanently solved?

Partially, but not fully.

The production-specific 1.2.23 stale/corrupted root shell was repaired. The official 1.2.67 update then succeeded normally.

But current updater behavior still allows root shell and active release to diverge by design. The root shell is currently clean, but it is not automatically kept identical to active release.

### B. Current updater still has structural gap?

Yes.

The structural gap is:

> Runtime Core source of truth is the active release directory, but update preflight still gates on root shell integrity while the updater does not keep the root shell fully synchronized.

This is not an immediate production outage after the 1.2.67 alignment. It is a P1 technical debt that can block future updates if root shell drifts again.

## Recommended Fix Options

No fix was implemented in this investigation.

### Option 1: Make Active Release The Current Core Integrity Target

Smallest conceptual change:

- If a valid active release pointer exists, `UpdateService::preflightChecks()` should verify the active release Core integrity as the current runtime Core.
- If no pointer exists, keep the current root shell integrity check.
- Root shell operational support should be checked separately.

Required tests:

- root deployment with no pointer still checks root;
- active release healthy + root shell stale does not block update;
- active release corrupted blocks update;
- operational support file failures still block where appropriate;
- rollback still works.

Risk: medium-low if well-tested, but it changes a release safety guard and therefore must go through a dedicated Release Gate.

### Option 2: Sync Root Shell After Successful Active Release Switch

Alternative:

- After successful update, atomically copy active release `system/core`, `system/core-manifest.json`, and safe non-secret shell files back to root.

Risk: higher. This writes more root files, complicates rollback, and could repeat the historical partial-copy failure if not designed carefully.

### Option 3: Formalize Root Shell As Launcher Only

Longer-term architecture:

- Root shell is explicitly launcher/operational only.
- Active release is the only Core source of truth.
- Integrity, rollback, CLI, cron, and recovery contracts are documented around that model.

Risk: larger P1/P2 refactor. Not appropriate for this bounded investigation.

## Adjacent Observation: Pointer File Permissions

During the 1.2.67 production upgrade, running updater code under root wrote `storage/updates/current-release.json` as root-owned with mode `0600`, temporarily causing the Web process to fail reading the pointer and enter recovery behavior.

This was corrected operationally by restoring pointer ownership/mode to the web user-readable state.

This is adjacent to, but separate from, the stale root shell root cause. It should be tracked as a future operational hardening item if CLI/root-driven official updates remain supported.

## Current Risk Assessment

Immediate 1.2.67 production risk: low.

Next update risk: moderate unless root shell remains clean and update execution ownership is controlled.

Recommended status:

- Do not block Demo Platform on this investigation.
- Register the root/active integrity contract as P1 Technical Debt.
- Before 1.2.68 or the next official production update, choose whether to implement Option 1 or keep using the current model with explicit root-shell integrity checks.

## Stop Decision

No code changes were made.

No production changes were made.

No update package was generated.

CMS Core should be considered frozen again after the completed 1.2.67 production verification, with this report filed as a root-cause record and P1 follow-up candidate.

