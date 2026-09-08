# Daiying CMS Cross-Version Fixture Baselines

Date: 2026-09-08

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
- SHA-256: `06e84f28ed44f9ba05f0fa2b0ccce020831166cb9d928713e2528157efb70e1a`
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
- SHA-256: `6ba4da4ac4b19dcd0daa7244b0e78a5a3089bb4aae1a917b3389ffba1faeb41d`
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

The actual upgrade tests from these fixtures to the current latest Core should be run as the next step before marking the full matrix complete.
