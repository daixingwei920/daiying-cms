# Core1.2.91 integrated isolated review candidate

This candidate combines the parked foundation API changes with the persisted-public-configuration payment redirect contract. Source baseline: actual merged PR77 b3f7f74, including PR75/76/77. Gateway implementation provenance: 7f6fa028e9950135fbd306433368546081770d04. No migrations, separate identity system or payment ledger are added. The independent Epay plugin is excluded from Core.

Foundation scope: resource/content-type discovery, protected multipart media upload, safe media details/metadata patch and real pagination. Reuse Core media validation, administrator capabilities/session revocation/CSRF or existing admin Bearer authentication. Do not expose storage keys, private configuration or custom content types by assumption. See rest-foundation-1.2.91.md and REST_API_V1.md.

Payment scope: exact configured HTTPS gateway and site-origin validation across payment creation/recovery, paid content, paid downloads and card delivery. Missing/disabled/invalid public settings fail closed. No decrypted secret is passed to redirect policies. Existing static Provider policies remain unchanged. The independent Epay V1/MD5 plugin uses this interface; Stripe/PayPal/manual modes remain available under their existing contracts. See payment-configured-redirect.md.

Knowledge facts pin the exact reviewed runtime source commit, with actual SHA256 and line/anchor evidence. Existing published1.2.90 evidence is preserved. The1.2.91 candidate stays UNPUBLISHED, and developer payment/API contracts must not become customer-facing promises or be imported into production consumers.

## Acceptance and hold

Run all formal preflight test components, Phase0 installer/update/rollback acceptance, exact source parity, related foundation/payment/UI/retirement regressions, strict knowledge/provenance and independent payment single-site authorization acceptance in private server isolation. Retain signature, integrity and commercial authorization checks. Candidate test keys stay on the authorized update server; production signing keys and customer license inventory are not copied or consumed.

The complete native formal preflight must be invoked unchanged. Because this PR is deliberately unmerged, its origin/main equality check is expected to block formal release approval; record the exact failure and run test components separately without mislabeling them as a successful formal preflight. No fake remote refs, no force flags, no public release/catalog mutation or production deployment.

Exact-commit evidence, safe logs and hashes accompany the PR, outside the frozen runtime artifacts. This document defines scope and acceptance boundaries; it is not itself a runtime PASS claim. The Owner can review the PR before separately deciding merge and later release work.
