# Daiying CMS Cross-Version Fixture Baselines

Date: 2026-09-08
Last refreshed: 2026-09-15

This report records the missing historical baselines needed to complete the Daiying CMS cross-version update matrix.

## v1.2.0

- Local tag: `v1.2.0`
- Tag commit: `deb813b9e1b5875f6797ff5775aebaa1187d9046`
- Source type: reconstructed fixture snapshot from a verified historical install package.
- Provenance package: `/Users/xingweidai/Documents/Codex/2026-08-20/du/outputs/daiying-cms-1.2.0.zip`
- Provenance SHA-256: `8cf6d330560b57c39c6a341f03ea742d24524f8bbb085a571b0ad8556cc9b172`
- SHA sidecar matched: yes
- ZIP integrity: PASS
- Package version field: `1.2.0`

Generated fixture package:

- ZIP: `outputs/cross-version-fixtures-20260908/daiying-cms-1.2.0-stable-exact-deb813b9e1b5.zip`
- SHA-256: `4a6eefa553fa21e6490c5e6785fa7165eaef7b68e51d105eacf064f33f455494`
- Manifest: `outputs/cross-version-fixtures-20260908/daiying-cms-1.2.0-stable-exact-deb813b9e1b5.manifest.json`
- ZIP integrity: PASS

The original provenance package was also copied into `outputs/cross-version-fixtures-20260908/` with its matching `.sha256` sidecar.

## v1.2.22

- Raw source commit: `3ce4c60aa27c78d09dd6dfe74514475a0bcf0903`
- Raw source version: `1.2.22`
- Raw provenance tag: `v1.2.22-raw`
- Fixture tag: `v1.2.22`
- Fixture commit: `d94b15b89b8152178ca9dfcc1193715ad7a05ac2`

The raw `1.2.22` commit had a Core manifest mismatch and could not pass the exact installer builder:

- `Admin/AdminController.php`
- `Media/MediaController.php`
- `Media/MediaLibrary.php`

The `v1.2.22` fixture commit is reconstructed from the raw source commit with only `system/core-manifest.json` regenerated to match the Core files. This keeps the version and source contents stable while making the fixture suitable for current-core integrity checks during cross-version update tests.

Generated fixture package:

- ZIP: `outputs/cross-version-fixtures-20260908/daiying-cms-1.2.22-stable-exact-d94b15b89b81.zip`
- SHA-256: `af66c9259825442558bf0c4fdaeefeb071c62efa17b7610182849e1f4799a9cd`
- Manifest: `outputs/cross-version-fixtures-20260908/daiying-cms-1.2.22-stable-exact-d94b15b89b81.manifest.json`
- ZIP integrity: PASS
- Package version field: `1.2.22`

## Tag Status

Local tags now present:

- `v1.2.0`
- `v1.2.22`
- `v1.2.22-raw`

Before this work, neither local nor origin had `v1.2.0` or `v1.2.22`.

## Cross-Version Matrix Impact

The matrix can now add real baselines for:

- `1.2.0` fresh install fixture
- `1.2.22` integrity-valid fixture
- `1.2.22-raw` provenance reference for the original manifest mismatch

## Local Upgrade Smoke to 1.2.62

A local RSA-signed update package was generated for old updater compatibility:

- ZIP: `outputs/cross-version-fixtures-20260908/update-1.2.62-rsa-local-test/daiying-cms-core-update-1.2.62-stable-legacy-updater-local-test-a7a76f98ff2a.zip`
- SHA-256: `d18c1c8b5ec6532194d8b3467c093e4bac551fce00b74cb41c6a0b2ebd2e70cb`
- Signature algorithm: `rsa-sha256`
- Scope: local smoke test only, not an official update server package
- Metadata floor fields: `min_upgrade_from=1.2.0`, `hard_min_version=1.2.0`, `migration_floor=1.2.0`
- Source commit: `a7a76f98ff2a4663f60e2c33b602f272fbfd1908`
- Files: 357
- Required migrations: 42

Important compatibility note: the current exact-commit Release Gate update package includes newer operational support paths such as `cli.php`, `public/assets/admin/admin.css`, `public/assets/admin/admin.js`, and `system/official-plugins.php`. The historical `1.2.0` and `1.2.22` `UpdatePackageManifest` classes reject those paths before the new Core can run. Therefore this local smoke package is explicitly marked `legacy-updater-local-test` and is used only to verify old `UpdateService` database/migration/Core switching compatibility. It is not an official release package and must not be promoted to the update server stable channel.

Test command:

```bash
php tests/cross_version_fixture_upgrade.php
```

Results:

- `1.2.0` fixture -> `1.2.62`: PASS through old `UpdateService::execute()`
- `1.2.22` fixture -> `1.2.62`: PASS through old `UpdateService::execute()`

The smoke verifies the old updater can complete the Core update, write the `1.2.62` release pointer, and create Foundation/AI tables including `cms_core_queue_jobs`, `cms_ai_jobs`, `cms_ai_usage_ledger`, and `cms_ai_prompts`.

The broader matrix still needs browser/UI verification and data-rich fixtures before the full cross-version checklist is marked complete.
