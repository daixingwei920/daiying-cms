# Local plugin manifest scan compatibility candidate

Status: isolated maintenance candidate on Core 1.2.90 / base
`449f92a258f2d02f78cb19f590c308ec268a1d43`. Not committed, signed,
published or deployed. No Core version change.

The native local installer requires `plugin-id/plugin.json`, but PackageScanner
previously read capability declarations only from a flat `plugin.json`. This
could incorrectly flag declared network use as `capability_mismatch`.

PackageScanner now reads one unambiguous flat or plugin-ID-root manifest. For a
wrapped package, its manifest identity must match the directory and all entries
must remain within that root. Multiple manifests, malformed manifests, conflicting
identity or files outside the declared wrapper fail closed. A root manifest cannot
be added to spoof the declarations of the installed nested plugin. Existing code,
network, unsafe-path, protected-path and executable/nested-archive checks remain.
The public scanZip signature, installer review gate, trust rules and staging
fingerprint validation are unchanged. There are no plugin-specific IDs or rules.

Regression: `php tests/plugin_package_manifest_scan.php` checks flat/wrapped
manifests and relevant negative cases. Related trust/capability, runtime-enable and
market API enable checks were run separately. Isolated native HTTP acceptance
also covered authenticated multipart upload, zero-finding preview, installation
without allow_review, separate enable, payment Provider registration and unsigned
notification rejection. A genuine high-risk package was refused by default.

Release boundary: `tests/release_gate_v1_contract.php` invokes
`sodium_crypto_sign_keypair()`. Per AGENTS.md, signing test-key generation is not
allowed on the workstation. That test was not run, and no signing keys were
generated. Functional installation acceptance does not constitute a formal
Release Gate result or authorize a Core release.

Knowledge sync: this note documents an unpublished implementation correction to
the existing package contract. The published V1 product facts and their derived
USER-FACING knowledge remain unchanged; no new product capability or released
version is asserted. Knowledge package build/import/index/consumer application
was not performed. Production knowledge and service settings are unchanged.
