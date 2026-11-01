# Configurable payment gateway contract — isolated candidate

Base: merged PR77 `b3f7f74b7491ae28ebdbedf5d27762ab01de9f0d`. Runtime version 1.2.91 is a local, unpublished compatibility candidate, not an approved release.

A configurable payment Provider implements `PaymentProviderConfiguredRedirectPolicyInterface`. Core passes only its persisted public provider settings. Payment creation, encrypted checkout recovery, paid content, paid downloads and card delivery all use the same resolver. Missing/disabled settings, malformed JSON, provider exceptions and mismatched endpoints reject the redirect; configured policies never fall back to a broader HTTPS policy. Existing static Provider policies and old fallbacks retain their contracts. No schema migration, credential decryption, trust change or alternative payment ledger is introduced.

The independent Epay V1/MD5 plugin is not included in Core. It requires this contract (planned minimum Core 1.2.91). Its approved endpoint can change only through authenticated, CSRF-protected Core settings. A changed gateway, merchant or callback origin invalidates old pending checkout URLs. A gateway change does not rewrite historical payments or receipts. Existing verified callbacks still resolve through Core's original provider-scoped ledger and fulfillment checks.

Payment guidance now distinguishes Epay V1 MD5 from Core hosted-redirect HMAC. Stripe/PayPal/manual settings and payment behavior are retained. General card/wallet guidance does not claim that a merchant is approved or that a particular wallet has passed real payment acceptance.

Validation evidence is stored outside Core in `outputs/epay-generic-v1`. Run the configured-redirect, encrypted recovery, currency-filter, scanner and independent-plugin integration tests. Signing-related contract tests belong only in a private directory on the authorized update server. Formal Release Gate, production installation, commercial-license acceptance and real gateway transactions are separate, unverified gates. Do not merge, publish or deploy this candidate.
