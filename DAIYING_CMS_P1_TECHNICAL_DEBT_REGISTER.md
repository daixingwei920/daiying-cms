# Daiying CMS P1 Technical Debt Register

Date: 2026-09-22

Scope: Core closeout sprint. No production hotfix is used as closure evidence.

## P1-CORE-001

ID: P1-CORE-001

Title: Legacy Root Shell / Active Release integrity

Component: Core updater, root launchers, active release pointer

Root Cause: Runtime uses `storage/updates/current-release.json` and active release Core, while update preflight historically checked root shell Core integrity. The updater did not keep full root shell Core synchronized, so stale or corrupted root shell state could block future updates even when active runtime was healthy.

Risk: Future updates can fail or drift if root shell mutates independently; root-executed updates can make pointer unreadable by Web if written too restrictively.

Current Evidence: `DAIYING_CMS_LEGACY_ROOT_SHELL_ROOT_CAUSE_REPORT.md`; production 1.2.67 `/health`; active release pointer to `daiying-cms-core-update-1.2.67`.

Fix: `UpdateService::preflightChecks()` now validates the active release Core when a valid active release pointer exists, and root shell only when no pointer exists. Pointer paths are constrained to `storage/updates/releases`. Pointer writes are chmod `0644`. Web, CLI, and scheduled publishing launchers reject active release paths outside the release root. `public/index.php` and `cli.php` are now operational support files so launcher fixes can ship in update packages.

Regression Test: `tests/active_release_integrity_target.php`; `tests/active_release_bootstrap.php`; `tests/release_gate_v1_contract.php`; `tests/release_phase0_gate.php`. Full local PHP test suite completed with exit code 0; environment-dependent live/fixture tests reported SKIP.

Compatibility Risk: Low to medium. Valid installs continue to load active releases. Invalid or out-of-tree active release pointers now fail closed instead of loading arbitrary paths.

Closure Criteria: active release integrity target verified; pointer permission verified; launcher path boundary verified; update package allowlist includes launchers; release gate passes; upgrade/rollback/re-upgrade matrix passes, including stale root shell plus healthy active release fixture.

Status: CLOSED

## P1-CONTENT-001

ID: P1-CONTENT-001

Title: Paragraph multiline/newline semantics lost in Core rendered_blocks

Component: `Cms\Core\Content\BlockRenderer`

Root Cause: Paragraph block text from a textarea was escaped and inserted into one `<p>` without converting line breaks or blank lines. Browsers collapse whitespace, so visible paragraphs disappeared across all themes sharing `rendered_blocks`.

Risk: Published articles lose readability and differ from the admin editor preview/intent. All themes that use Core `rendered_blocks` inherit the bug.

Current Evidence: Frontend article output showed one large `<p>` containing raw newlines; `BlockRenderer::paragraph()` returned `<p>{$escapedText}</p>`.

Fix: Paragraph rendering now normalizes CRLF/CR to LF, splits blank-line-separated text into separate `<p>` elements, converts single newlines inside a paragraph to `<br>`, and escapes user text before inserting line break markup.

Regression Test: `tests/content_paragraph_multiline_rendering.php`. Full local PHP test suite completed with exit code 0; environment-dependent live/fixture tests reported SKIP.

Compatibility Risk: Low. Single-line paragraph output is unchanged. Multi-line paragraph output becomes more semantically correct. HTML in textarea remains escaped.

Closure Criteria: single line, LF, CRLF, blank line, multiple blank lines, Chinese/English mixed text, HTML special characters, script payload, and image-onerror payload tests pass; representative theme tests pass; no theme workaround required.

Status: FIXED_CLOSED

## P1-RELEASE-001

ID: P1-RELEASE-001

Title: Release/runtime/version consistency

Component: release builder, release parity gate, update metadata, runtime health

Root Cause: Legacy incidents showed that Git tag, package metadata, runtime health, root config, and active pointer can diverge if release source and runtime source of truth are not checked together.

Risk: Operators can misread the effective runtime version or publish a package whose metadata does not match the commit/runtime.

Current Evidence: `scripts/release_parity_gate.php` verifies exact commit, config versions, package metadata, update metadata, and package SHA. Runtime version is resolved from active release pointer via `ActiveReleaseResolver`. Final local matrix verified `/health` reports `1.2.68` after upgrade and re-upgrade, and `1.2.67` after rollback.

Fix: Existing Release Gate V1 covers exact-commit release source and metadata parity. This sprint added active-release integrity target reporting through update compatibility checks.

Regression Test: `tests/release_phase0_gate.php`; `tests/release_gate_v1_contract.php`; `tests/active_release_integrity_target.php`. Final release parity gate PASS. Full local PHP test suite completed with exit code 0; environment-dependent live/fixture tests reported SKIP.

Compatibility Risk: Low.

Closure Criteria: release gate exact commit PASS; update metadata PASS; runtime health version PASS after upgrade; rollback version PASS.

Status: VERIFIED_CLOSED

## P1-UPDATE-001

ID: P1-UPDATE-001

Title: Update package integrity and pollution detection

Component: exact commit update builder, package reader, release parity gate

Root Cause: Historical partial packages could mix new package files with stale root files. Separately, operational support allowlists were not perfectly aligned between runtime manifest, builder, and parity gate.

Risk: update packages can omit launcher fixes or include unexpected files if allowlists drift.

Current Evidence: Release Gate V1 rejects partial Core snapshots and validates package file hashes. This sprint found and fixed allowlist drift for `cli.php` and added `public/index.php` as operational support. Final artifact verification confirmed installer and update ZIP metadata, checksums, expected file set, `public/index.php`, `cli.php`, and absence of legacy root shell pollution.

Fix: `UpdatePackageManifest`, `build_exact_commit_update_package.php`, and `release_parity_gate.php` now agree that `public/index.php` and `cli.php` are operational support update paths.

Regression Test: `tests/active_release_integrity_target.php`; `tests/release_gate_v1_contract.php`; `tests/release_phase0_gate.php`; final `scripts/release_parity_gate.php` against built artifacts. Full local PHP test suite completed with exit code 0; environment-dependent live/fixture tests reported SKIP.

Compatibility Risk: Low. Update packages may now carry launcher files needed for root shell closeout.

Closure Criteria: exact commit update package includes expected operational support files; no unexpected files; checksums match; release parity gate PASS.

Status: CLOSED

## P1-TEST-001

ID: P1-TEST-001

Title: Critical Core regression coverage

Component: Core regression suite

Root Cause: Legacy root shell drift and paragraph newline semantics were not covered by permanent regression tests.

Risk: Future changes can reintroduce stale root shell behavior, pointer permission regressions, or paragraph rendering loss.

Current Evidence: New tests added for active release integrity target and paragraph multiline rendering.

Fix: Added `tests/active_release_integrity_target.php` and `tests/content_paragraph_multiline_rendering.php`; updated `tests/active_release_bootstrap.php` to enforce the active release path boundary.

Regression Test: same as fix. Full local PHP test suite completed with exit code 0; environment-dependent live/fixture tests reported SKIP.

Compatibility Risk: Low.

Closure Criteria: targeted tests PASS; related release/theme tests PASS; full suite or documented skips reviewed.

Status: FIXED_CLOSED

## Read-Only Production Evidence

Checked without modifying production:

- `/health`: `ok`, `NORMAL`, version `1.2.67`, release id `daiying-cms-core-update-1.2.67`.
- Root shell integrity: `ok`.
- Active release integrity: `ok`.
- `storage/updates/current-release.json` mode: `0644`.

## P2/P3 Newly Observed

P2: Full historical upgrade matrix remains partially blocked because true local 1.2.0 and 1.2.22 fixture artifacts are still missing, as previously recorded by Release Gate V1.

P2: Health/admin version display still reads pointer metadata directly in some locations; this is acceptable today but should eventually centralize on `ActiveReleaseResolver` for consistency.

P3: Root launcher active release path validation is implemented separately in Web, CLI, and scheduled publishing scripts; future cleanup could consolidate this helper if a safe shared bootstrap utility exists.
