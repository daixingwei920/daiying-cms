# Daiying CMS Production Release SOP Closeout Report

Date: 2026-09-30

Baseline: Daiying CMS Core `1.2.75`

Exact commit at SOP creation: `c9f16e2c41ba84fe3bf211a6d75e2d5b698ced6c`

## Scope

This task did not publish a new Core version, did not bump Core, did not change Core runtime behavior, and did not publish marketplace packages.

The task converted the 1.2.71 -> 1.2.75 release incidents into permanent release infrastructure:

- long-term SOP in `DAIYING_INFRASTRUCTURE_AND_REPOSITORY_HANDOFF.md`
- release state machine
- hard release gates
- repeatable checklist
- initial `scripts/release/*` automation entry points
- incident review for 1.2.71, 1.2.72, 1.2.73, 1.2.74, and 1.2.75

## Current Complete Release Flow

The documented production release flow is now:

1. Development finishes on a scoped branch.
2. Worktree, remote refs, branch ahead/behind, exact commit, and report tracking are checked.
3. Candidate enters protected PR to `main`.
4. PR merges normally without bypassing branch protection.
5. Final `origin/main` commit is reread and becomes the only release baseline.
6. Preflight gates run.
7. Full Installer and full-snapshot update source are generated from exact commit.
8. Manifest and SHA256 are generated.
9. Core Update Package signing is performed only by official production signing infrastructure.
10. Signed update artifact, latest API metadata, SHA256, signature, commit, and version are verified.
11. Staging clean install and previous-stable -> candidate upgrade are verified.
12. Human approves READY_TO_PUBLISH.
13. GitHub Full Installer and Update Server stable/latest are published as applicable.
14. Production update check and production upgrade are verified.
15. Final commit/version/artifact/latest API/production health reconciliation is recorded.
16. Handoff is updated before closing the release.

## New Release Gates

The handoff now defines these hard gates:

- Clean Worktree Gate
- Protected Main Gate
- Exact Commit Gate
- Remote Ref Gate
- No Local Ahead Gate
- Tracked Closeout Report Gate
- Frozen Migration Gate
- Release Parity Gate
- Full Snapshot Update Gate
- Installer Parity Gate
- Manifest/SHA256 Gate
- Production Signature Gate
- Update Server Stable/Latest Parity Gate
- Migration Chain Gate
- Updater Runtime Compatibility Gate
- Recovery/Rollback Gate
- Staging Upgrade Gate
- Production Backup Gate
- Production Health Gate
- Final Commit/Version/Artifact Reconciliation Gate

Any gate failure is `FAIL-CLOSED`.

## Automated Steps Added

Added release script entry points:

- `scripts/release/preflight`
- `scripts/release/build`
- `scripts/release/sign`
- `scripts/release/verify`
- `scripts/release/publish`
- `scripts/release/postflight`
- `scripts/release/common.sh`

Current automation behavior:

- `preflight` verifies clean worktree, protected `origin/main`, exact commit, remote reachability, no existing version tag, frozen migrations, notification tests, Plugin SDK regression, Release Gate V1 contract, release phase gate, and release parity.
- `build` builds the exact-commit Full Installer and unsigned full-snapshot Core update source package. It also verifies installer parity and blocks update source packages containing `update.json`, `signature.bin`, or `config/app.php`.
- `sign` intentionally fail-closes because production signing is server-side only.
- `verify` validates a signed update package plus latest API JSON against the current exact commit.
- `publish` intentionally fail-closes because publication requires human approval and official infrastructure.
- `postflight` verifies production health and latest API version after publication.

## Steps Still Requiring Human Approval

- Promotion from `STAGING_VERIFIED` to `READY_TO_PUBLISH`.
- Production backup confirmation.
- Official Update Server production signing.
- stable/latest update.
- GitHub Release publication approval.
- Marketplace publication approval.
- Production upgrade execution.
- Decision to withdraw, revoke, or supersede a bad version number.

## Historical Incidents Covered

Covered in `DAIYING_INFRASTRUCTURE_AND_REPOSITORY_HANDOFF.md`:

- local baseline with no remote branch/tag reference
- branch ahead 1
- untracked closeout report
- old updater runtime after active pointer switch
- `pruneOldReleases` deleting running old release
- rollback depending on new-version-only `RecoveryActions`
- bundled official plugin trust grant missing
- reserved plugin table prefix mismatch
- unknown manifest capability causing plugin skip
- stable/latest/API/artifact commit/version mismatch
- updater support-file allowlist mismatch
- cross-version migration chain failure
- signature/SHA256/manifest/artifact mismatch
- delta update artifact published when client requires full Core snapshot

The 1.2.71 -> 1.2.75 release train is explicitly classified as a release infrastructure incident where applicable.

## Handoff Modifications

`DAIYING_INFRASTRUCTURE_AND_REPOSITORY_HANDOFF.md` now contains:

- `CURRENT STATE - 2026-09-30`
- `Production Release History and Incident Lessons`
- per-version incident review for `1.2.71` through `1.2.75`
- `Daiying CMS Production Release SOP`
- release state machine
- production release checklist
- hard release gates
- automation entry points
- `Known Release Failure Modes`

The current NEXT ACTION is no longer “publish another patch by memory”; it is to use the Production Release SOP before the next Core release.

## Readiness For Next Core Release

The next Core release can start from this SOP, but publication should not proceed until:

- `scripts/release/preflight` passes on final protected `origin/main`;
- `scripts/release/build` produces the only uploadable local artifacts;
- official production signing flow produces the signed update artifact;
- `scripts/release/verify` passes against latest API metadata;
- staging upgrade passes;
- a human explicitly approves `READY_TO_PUBLISH`.

Verdict: SOP foundation is ready for use on the next Core release, with signing and publication intentionally gated by official infrastructure and human approval.

