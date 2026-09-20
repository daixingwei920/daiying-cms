# Daiying CMS Core Agent Rules

This file is long-term project context for Codex agents working on Daiying CMS Core.

Update this file whenever the official Core baseline, clean worktree, release process, or maintenance boundary changes.

## Current Core Baseline

- Product: Daiying CMS Core
- Baseline version: `1.2.66`
- Git tag: `v1.2.66`
- Baseline commit: local tag `v1.2.66`
- Current clean Core worktree:
  `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms-core-clean-1.2.63`
- Core closeout report:
  `/Users/xingweidai/Documents/Codex/2026-09-03/Daiying-CMS-WIP-Archive/core-closeout-1.2.63-20260915-231639/CORE_CLOSED_REPORT.md`

## Workspace Rules

Use the clean Core worktree above, or create a fresh clean clone/worktree from the current official Core branch, for all Core maintenance.

Do not use this legacy/WIP worktree as a Core release source:

`/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms`

That legacy directory may contain Baidu Netdisk WIP, AI Customer Service WIP, historical reports, old outputs, or other plugin/business work. It is not a clean Core release source.

## Core Maintenance Mode

Daiying CMS Core is in maintenance mode.

Allowed Core work:

- Security fixes.
- Critical bug fixes.
- Compatibility fixes.
- Upgrade/update reliability fixes.
- Release Gate fixes.
- Runtime/version consistency fixes.
- Minimal maintenance needed to keep existing Core public APIs stable.

Not allowed without an explicit new product decision:

- Large new Core features.
- New business modules.
- Plugin-specific special cases.
- Theme-specific behavior.
- Marketplace/business logic that belongs in plugins.
- Refactors unrelated to security, reliability, compatibility, or upgrade correctness.

## Plugin / Business Boundary

The following must not be mixed into Core commits, Core release packages, or Core update packages:

- Restaurant plugin/theme work.
- CJ / CJ Dropshipping plugin work.
- Baidu Netdisk plugin remediation.
- AI Customer Service plugin work.
- Commerce feature work that belongs inside `official.commerce`.
- Payment provider feature work that belongs inside payment plugins.
- Any WIP plugin directory or plugin-specific local test artifact.

If a plugin appears to require a Core change, first verify whether the missing capability is truly a generic public Core API. Do not add plugin ID hard-coding or one-off plugin special cases to Core.

## Release Source Rules

Never build a Core release package from:

- `git diff`
- only the files changed in the current task
- Codex's guess of "related files"
- stale local `outputs/`
- old update package directories
- dirty worktrees
- plugin-market worktrees
- legacy/WIP worktrees

Every official Core release must be built from a complete, deterministic, Core-owned release snapshot and must pass Release Gate checks before publication.

## Required Checks Before Core Commit or Release

For any Core modification, run at minimum:

- `git status --short`
- `git diff --check`
- PHP syntax checks for changed PHP files, or the relevant stable syntax target.
- `php tests/release_gate_v1_contract.php`
- Related regression tests for the modified subsystem.

For release or update-package work, also run the current Release Gate / release parity process and verify:

- Full Core-owned file manifest.
- `release_gate_v1` is declared for modern Core update packages.
- Package SHA256 matches metadata.
- Update package and GitHub Release come from the same release source.
- No future-version or WIP files are mixed in.
- Rollback and active-release bootstrap behavior remain intact.

## Forbidden Release Contents

Do not include any of the following in Core commits or release packages:

- Old `outputs/` artifacts.
- Local signing inputs.
- Test private keys.
- Local RSA/Ed25519 private keys.
- `.env` files.
- Production credentials.
- OAuth secrets.
- Payment secrets.
- Session files.
- Logs.
- Cache files.
- WIP plugin files.
- Historical archive directories.
- Temporary install smoke directories.

The known archived local test private key under the closeout archive is for local historical testing only and must never be committed or published.

## Version Updates

When the official Core version changes:

1. Update this `AGENTS.md` baseline section.
2. Update `config/app.php` and `config/app.example.php` only through the approved release process.
3. Update `CHANGELOG.md`.
4. Ensure `system/core-manifest.json` matches the released Core snapshot.
5. Ensure Git tag, GitHub Release, update server metadata, and website download/version displays agree.

## Stable Public API Rule

Core public APIs are long-lived. Do not break plugin/theme public APIs in a patch release.

If a Core API must evolve:

- Preserve backward compatibility when possible.
- Add compatibility layers for existing plugins/themes.
- Document deprecations before removal.
- Add regression tests.

## Default Agent Behavior

At the start of a Daiying CMS Core task:

1. Read this file.
2. Confirm the worktree is the clean Core worktree or a fresh clean clone.
3. Run `git status --short`.
4. Check the current Core version and Git commit.
5. Refuse to publish from a dirty or legacy/WIP worktree.
6. Keep plugin/business development in independent plugin threads.
