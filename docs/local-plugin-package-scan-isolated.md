# Local plugin manifest scanning: review candidate

Status: unpublished Core maintenance PR. No version bump, official signing,
merge, release or production deployment is authorized by this evidence.

## Problem and behavior

The native local installer requires `plugin-id/plugin.json`, but PackageScanner
read capability declarations only from a flat `plugin.json`. A correctly declared
network capability was therefore reported as HIGH `capability_mismatch`, forcing
an unnecessary `needs_review` decision.

The scanner now reads one unambiguous flat or plugin-ID-root manifest. Wrapped
manifest identity must match its directory and all entries must stay within that
root. Multiple or corrupt manifests, conflicting identity and out-of-scope files
fail closed. A root manifest cannot spoof the nested manifest actually installed.
Dangerous-code, undeclared-network, binary, nested-ZIP, unsafe-path and protected
Core-path checks remain active. The public scanZip API, administrator review gate,
trust rules and staging-fingerprint verification are unchanged. No plugin-specific
ID or exception is added; no migration or data-schema change is required.

`system/core-manifest.json` is synchronized with the scanner change through the
existing Core ManifestBuilder. Its membership is unchanged; only the scanner hash
changes. The initial Phase 0 rejection of the stale manifest is retained in the
evidence rather than reported as a first-attempt success.

## Validation and provenance

The branch retains server-tested ancestor
`495829884f135e1f9a4f7d138f341a99cd9e1747`, based on frozen Core 1.2.90 commit
`449f92a258f2d02f78cb19f590c308ec268a1d43`. Subsequent PR changes only organize
this document and a structured evidence record. Current main was independently
checked; its changes since that frozen baseline are documentation only. The
scanner, Core manifest and regression-test bytes match the recorded server test.

See [structured acceptance evidence](local-plugin-package-scan-evidence.json):

- 17 scanner positive/negative cases and 9 + 1 + 4 related Core checks passed.
- The unchanged external dev.3 payment-provider ZIP passed the actual authenticated
  native HTTP upload/scan/install/enable flow: 13 checks, zero findings,
  `allow_review` omitted, Installed then Enabled. Its restricted API trust and
  local-unreviewed identity were preserved. Bad CSRF and genuinely risky code were
  refused. The plugin code/package is not part of this Core PR.
- External payment fixture regressions passed 72 integration and 11 local HTTP
  checks; no new real payment was performed for this PR.
- The authorized update server ran Release Gate V1 (4 checks) and Phase 0, including
  exact-commit installer/update parity, test signatures and tamper rejection.
  Seven Gate/build/preflight programs were byte-identical to the baseline.
- Test signing keys were generated and used only in isolated server test processes.
  No signing keys, raw logs, private server paths, databases, sessions, payment
  secrets or plugin runtime artifacts are committed here.

Run the non-signing scanner regression with:

```sh
php tests/plugin_package_manifest_scan.php
```

Signing tests must use the authorized update-server isolation workflow under
AGENTS.md; do not generate signing test keys on the workstation.

## Release boundary

The server's formal preflight correctly remained FAIL-CLOSED because the candidate
was not exact origin/main. Candidate test success is not formal release approval,
market review or production acceptance. A future exact merge/release source must
run its applicable gates again; this PR does not bypass protected-main or version
checks, reuse an existing release tag, or publish an update.

## Knowledge synchronization

This internal document and its structured evidence record are updated. Published
V1 product facts, released tutorials and USER-FACING knowledge are unchanged: the
patch corrects an unpublished implementation of the existing package contract.
Knowledge package build, import, indexing and consumer application were not run;
production knowledge and settings remain unchanged.
