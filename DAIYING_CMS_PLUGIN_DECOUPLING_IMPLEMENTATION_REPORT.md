# Daiying CMS Plugin Decoupling Foundation P0 Implementation Report

Date: 2026-09-10
Baseline: Daiying CMS Core 1.2.48, commit 41a03ab
Branch: foundation/official-plugin-trust-grants

## Summary

Implemented the P0 decoupling layer for official plugin trust. New official market plugins can now be trusted by a signed, verified Trust Grant instead of requiring a Core PHP change for every plugin id, table prefix, or capability namespace.

The old static registry remains as a compatibility layer for already bundled/system plugins and older installed sites. Normal new official plugin onboarding should use signed Trust Grants.

## Main Changes

- Added `OfficialExtensionTrustGrant` value object.
- Added `OfficialExtensionTrustGrantVerifier` for signed grant validation.
- Added `OfficialExtensionTrustGrantRepository` for persisted active grants.
- Added migration `2026_09_10_000001_extension_trust_grants`.
- Refactored `OfficialPluginRegistry` to merge legacy records and database grants.
- Refactored `MarketPackageInstaller` to verify grants, compare manifest requests against grants, persist grants, run migrations as trusted official only when authorized, and roll back staged grants on failure.
- Refactored `PluginMigrationRunner` and local plugin installer code paths to use a PDO-aware registry.
- Preserved local upload restrictions for `official.*`, reserved table prefixes, and trusted PHP claims.
- Preserved old `system/official-plugins.php` as legacy input only.

## Market Install Behavior

Official market packages now support:

- Generic plugin migration execution.
- Official plugin reserved table prefix grants.
- Market upgrade migrations.
- Checksum drift rejection.
- Rollback on migration failure.
- Trust Grant rollback on installation failure.
- Enable-time migration backfill for market-installed plugins that were installed before this fix.

Covered official examples:

- `official.commerce` with `commerce_`.
- `official.affiliate-hub` with `affiliate_`.
- `official.mail` with `mail_`.

## Admin UI Decoupling

Plugin admin menu metadata can now come from plugin runtime registration instead of Core hard-coded plugin ids:

- section
- icon
- sort order
- breadcrumb parent
- badge

`AdminUiText::pluginName()` no longer carries product-specific plugin-id labels.

## Payment Provider Decoupling

Added public provider extension points:

- `PaymentProviderRedirectPolicyInterface`
- `PaymentProviderSettingsSchemaInterface`

Payment services now consult provider-owned redirect policy before Core generic redirect validation. Admin payment settings can render provider-declared public and secret fields. Existing Stripe compatibility remains as a legacy fallback.

## Frozen Migration Handling

The existing registry-writing migrations were not edited:

- `2026_09_07_000001_official_plugins_registry`
- `2026_09_08_000007_official_plugins_registry_sync`

Their checksums remain frozen. New official plugin trust should not be added by modifying these migrations.

## Security Boundaries

Preserved:

- Local uploads cannot claim `official.*`.
- Third-party packages cannot use official reserved table prefixes.
- `cms_` and `market_` remain Core-owned.
- Unsigned/tampered/revoked/expired Trust Grants are rejected.
- Manifest claims must be a subset of the signed grant.
- Migration affected objects must remain inside plugin-owned/granted prefixes.
- Failed installs roll back directory, plugin row, migration effects, and staged grant.

## What New Official Plugins Need

For a new official plugin after this change:

1. Package metadata remains reviewed by the official market.
2. The official market issues a signed Trust Grant with allowed namespaces/prefixes/routes/provider capabilities.
3. The site receives the grant with install authorization.
4. Core validates the package and grant.
5. The plugin installs without editing `OfficialPluginRegistry.php`.

If an official plugin needs a new Core-owned route, database prefix such as `cms_`, or a new public API surface, that is still a Core design change and must be reviewed separately.

## Remaining Follow-Up

- Official update/market server must emit signed Trust Grants in install authorizations.
- Existing legacy official plugins can migrate from static records to grants over time.
- Payment settings schema should be adopted by payment plugins gradually.
- Admin menu metadata should be adopted by official plugins gradually.

