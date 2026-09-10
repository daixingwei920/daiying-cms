# Daiying CMS Foundation Decoupling Final Report

Date: 2026-09-10

Branch: `foundation/official-plugin-trust-grants`

Baseline before this final pass:

- `947f3af foundation: add official plugin trust grants`
- `25fa91e foundation: decouple payment provider schema and redirects`

No production site or official update server changes were made in this pass.

## Working Tree Investigation

`system/official-plugins.php` was dirty before final staging. The file was semantically equal to `HEAD`, but it had been rewritten from short PHP array syntax to `var_export()` syntax with trailing whitespace after `=>`.

Root cause:

- The existing official plugin registry migrations write `system/official-plugins.php` with `var_export()`.
- Running migration-based tests from the source checkout can rewrite the tracked root registry file.
- This is a generated/test side effect, not a functional change required by the decoupling work.

Action taken:

- The file was restored to the committed canonical short-array format after confirming semantic equality.
- No historical migration was modified.
- No official plugin registry entry was added for the new test payment provider.

Residual note:

- Future test hardening should avoid running root-file-writing migrations against the source checkout path, or the registry writer should use a stable formatter in a new migration/helper without altering frozen historical migrations.

## Implementation Summary

This final pass completed the proof that new official plugins and new Payment Providers can be delivered through the official market without adding Core plugin ID special cases.

Changes:

- Added `payment.provider` as a known plugin capability.
- Added `PluginContext::registerPaymentProvider()` so provider plugins register through a stable public Core API.
- Added a long-term regression using a brand-new `official.decoupled-pay` provider unknown to Core's static registry.
- Documented the payment provider registration flow.
- Added `MigrationRunner` compatibility for existing array-style Core migrations, without modifying historical migration files.
- Updated `system/core-manifest.json` hashes for changed Core files.

## Hard-Code Scan Classification

Security Policy:

- `system/core/Plugin/OfficialPluginRegistry.php`
- `system/official-plugins.php`
- `system/core/Plugin/PluginManager.php`
- `system/core/Market/MarketPackageInstaller.php`

These files contain official-source or trusted-source policy logic. Existing built-in records are legacy/bundled fallbacks, while new official privileges are now supplied by signed Trust Grants.

Legacy Compatibility:

- `system/core/Admin/AdminController.php` still contains legacy settings UI fallback logic for Stripe, PayPal, WeChat Pay, and Alipay.
- `system/core/Payment/PaymentProviderRedirectPolicyResolver.php` still keeps the Stripe legacy redirect validator fallback.
- `system/core/Support/AdminUiText.php` classifies `official.payment.*` and `official.*` for display labels.
- `system/migrations/2026_08_27_000001_stripe_local_provider_migration.php` preserves historical Stripe provider migration compatibility.

These are intentionally retained for installed legacy providers. They are not required for new providers.

Test Fixtures:

- `tests/plugin_decoupling_foundation.php` includes `official.decoupled-pay` as the unknown-provider regression fixture.
- `tests/market_plugin_migrations.php` includes official package, grant, revoked, expired, and tampered fixtures.
- Commerce, Mail, Affiliate, theme, and payment tests reference real bundled or official plugin IDs as fixture subjects.

Documentation:

- README/docs/report files mention current known official plugins for product documentation, not runtime coupling.

Still-needs-removal Plugin-specific Coupling:

- None found that blocks adding a new `official.xxx` plugin through Trust Grant.
- None found that blocks adding a new Payment Provider through provider schema + redirect policy + `PluginContext::registerPaymentProvider()`.

## Unknown Official Plugin Proof

The market migration suite already proves unknown official-style packages can receive privileges through signed Trust Grants, and rejects unsigned/tampered/revoked/expired grants.

Important guarantees:

- A new official plugin does not need a Core static registry entry if the official market supplies a valid Trust Grant.
- Ordinary plugins and unregistered official-looking packages cannot claim reserved prefixes such as `affiliate_`, `commerce_`, `cms_`, or `market_`.
- Migration checksum drift is rejected.
- Failed installs roll back staged plugin/grant state.

## Unknown Payment Provider Proof

The final regression creates and installs `official.decoupled-pay`, which is intentionally absent from `OfficialPluginRegistry`.

Verified path:

1. Official market package install.
2. Signed Trust Grant import.
3. `payment_provider` package type persistence.
4. Plugin migration creation of `decoupled_pay_events`.
5. Migration checksum recorded in `cms_plugin_migrations`.
6. Plugin enable.
7. Plugin boot.
8. Provider registration through `PluginContext::registerPaymentProvider()`.
9. Admin payment settings form rendered from provider schema.
10. Secret field encrypted at rest and decrypted only server-side.
11. Payment creation through Core `PaymentService`.
12. Provider-owned redirect policy accepts only the provider checkout URL.
13. Provider-owned redirect policy rejects unsafe URLs.

No Core plugin ID registration was added for `official.decoupled-pay`.

## Migration Compatibility Finding

Running the full non-live PHP suite exposed that `MigrationRunner` accepted only `MigrationInterface`, while the existing trust grant migration is array-style.

Fix:

- `MigrationRunner` now accepts both `MigrationInterface` and array migrations with `id` and callable `up`.
- This protects fresh installs, fixture upgrades, and cross-version tests without editing frozen historical migrations.

## Regression Results

Full local PHP suite was run excluding live external-service tests:

- Skipped: `tests/commerce_ai_deepseek_live.php`
- Skipped: `tests/official_affiliate_hub_cj_live.php`

All other `tests/*.php` passed.

Additional checks:

- `php -l system/core/Migration/MigrationRunner.php` passed.
- `php -l system/core/Plugin/Capability.php` passed.
- `php -l system/core/Plugin/PluginContext.php` passed.
- `php -l tests/plugin_decoupling_foundation.php` passed.
- `system/core-manifest.json` hashes match the current Core files.
- `git diff --check` passed.

## Final Answers

新增 `official.xxx` 插件是否不再需要修改 Core：YES

新增 Payment Provider 是否不再需要修改 Core：YES

Foundation official plugin and payment provider decoupling can be treated as frozen. Future requests to register a new official plugin or payment provider inside Core should be considered an architecture regression unless they are explicitly for legacy compatibility or security policy changes.
