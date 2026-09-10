# Daiying CMS Official Plugin Decoupling Audit

Date: 2026-09-10
Scope: audit only. No production changes, no Core code changes, no update server publishing.

## Baseline

- Repository: `https://github.com/daixingwei920/daiying-cms.git`
- Branch inspected: `fix/affiliate-official-registry`
- HEAD inspected: `41a03aba341311d43095cb0c28030184d9032294`
- Core version inspected: `1.2.48`
- Worktree before audit report: clean

## Question Being Answered

Why does adding a new official plugin currently require modifying CMS/Core files?

Short answer: Daiying CMS correctly protects trusted PHP plugins, official plugin IDs, reserved capability namespaces, and reserved database table prefixes. The problem is that the list of trusted official plugins and their granted namespaces/prefixes is still stored in Core PHP files and Core migrations instead of a signed, data-driven trust grant mechanism delivered by the official market/update authority.

As a result, every new official plugin that needs `trusted_php`, `official.*`, or an official table prefix such as `commerce_`, `affiliate_`, or `mail_` must be added to a Core-maintained registry before the installer can safely run migrations or boot it as trusted.

## Main Static Registries And Hard-Coded Trust Points

| Area | File | Current Behavior | Impact |
| --- | --- | --- | --- |
| Official plugin registry | `system/core/Plugin/OfficialPluginRegistry.php` | Built-in static records for `official.friend-links`, `official.novel-collector`, `official.video-collector`, `official.commerce`, `official.affiliate-hub`; also reads `system/official-plugins.php`. | New official plugins need Core changes to receive trusted table prefixes/capability namespaces. |
| Root official registry file | `system/official-plugins.php` | Runtime support file used as an overlay on built-in records. | Became release-sensitive; missing or stale entries block official plugin installs. |
| Registry-writing migrations | `system/migrations/2026_09_07_000001_official_plugins_registry.php`, `system/migrations/2026_09_08_000007_official_plugins_registry_sync.php` | Migrations write `system/official-plugins.php` with hard-coded plugin records. | Editing these migrations changes checksums and breaks update execution on installed sites. |
| Table prefix ownership | `system/core/Plugin/PluginTableOwnership.php` | Non-trusted plugins cannot use reserved prefixes; official prefixes come from `OfficialPluginRegistry`. | Correct security boundary, but tied to a Core hard-coded list. |
| Market installer trusted official decision | `system/core/Market/MarketPackageInstaller.php` | `isTrustedOfficialPackage()` only returns true when source/review are trusted and `OfficialPluginRegistry` has table prefixes for the extension. | Official market packages still need Core registry entries before migrations can use their own official prefix. |
| Local plugin installer | `system/core/Plugin/LocalPluginPackageInstaller.php` | Blocks local uploads that claim `official.*`, `system-plugin`, `bundled`, or `trusted_php`. | Correct security boundary; should remain strict. |
| Runtime trusted database access | `system/core/Plugin/PluginManager.php` | `trusted_php` access depends on bundled source or official market source plus registry-backed trust. | Official plugins without a registry grant cannot receive DB access. |
| Capability namespace validation | `system/core/Plugin/Capability.php` | Blocks reserved namespaces such as `core`, `admin`, `cms`, `system`, `market`; other plugin namespaces are allowed if declared/granted. | Mostly correct; official custom namespaces should be granted through signed metadata instead of Core edits. |

## Hard-Coded Routes, IDs, And UI Special Cases

| Area | File | Static Coupling Found | Recommendation |
| --- | --- | --- | --- |
| Admin sidebar sections | `system/core/Support/View.php` | Core sections and routes are hard-coded: content, media, themes, plugins, commerce/payment, settings, AI, update, etc. Plugin menus are appended dynamically, but section names come from plugin ID display text. | Keep Core sections static, but let plugin manifests declare admin menu section, icon, sort order, and breadcrumb metadata. |
| Admin breadcrumbs | `system/core/Support/View.php` | Breadcrumb top-level section is inferred from hard-coded route prefixes. | Add route/menu metadata lookup for plugin/admin extension routes. |
| Plugin display names/settings URLs | `system/core/Support/AdminUiText.php` | Static maps for `official.payment.stripe`, `official.payment-fixture`, `official.friend-links`; settings URL only maps `official.friend-links`. | Move plugin display name/settings URL to manifest/admin menu metadata. |
| Payment provider IDs | `system/core/Admin/AdminController.php` | `official.payment.stripe`, `official.payment.paypal`, `official.payment.wechatpay`, `official.payment.alipay` are hard-coded in provider lists, friendly names, help text, form schemas, public config parsing, secret parsing, and webhook URL hints. | Introduce provider configuration schema contract so payment plugins supply fields, validation, help text, and webhook metadata. |
| Stripe redirect exception | `system/core/Payment/PaymentService.php`, `system/core/Payment/PaidContentService.php`, `system/core/Payment/PaidDownloadService.php`, `system/core/CardDelivery/CardDeliveryService.php` | Special-cases `official.payment.stripe` for checkout redirect URL validation. | Replace provider-ID checks with a provider-level redirect URL policy interface. |
| Plugin runtime route reservations | `system/core/Plugin/PluginRuntimeRegistry.php` | Blocks `/admin/login`, `/recovery`, `/diagnostics`, `/health`, `/install`, `/admin/update`, `/api/market`, `/admin/market`, `/`, and `/{slug}`. | Keep these Core reservations; make them explicit public route policy. |
| Tests | `tests/*` | Many tests import exact official plugin IDs or assert exact registry entries. | Keep fixture tests, but add generic trust-grant tests so new official plugins do not require test rewrites unless public contracts change. |

## Current Official Plugin Inventory

| Plugin | Trust | Table Prefixes | Needs Core Registry Today? | Notes |
| --- | --- | --- | --- | --- |
| `official.friend-links` | `trusted_php`, bundled/system-plugin | `friend_links_` | Yes | Built-in system plugin. |
| `official.novel-collector` | `trusted_php`, official | `novel_`, `novel_collector_` | Yes | Official content plugin with migrations. |
| `official.video-collector` | `api` in manifest, but registry grants trusted data | `video_`, `video_collector_` | Yes if using official prefix grants | Current registry says trusted_php while manifest says api; this mismatch should be clarified. |
| `official.commerce` | `trusted_php`, official | `commerce_` | Yes | Recent failures came from missing trusted prefix grant. |
| `official.affiliate-hub` | `trusted_php`, official | `affiliate_` | Yes | Recent fix added this entry. |
| `official.mail` | `trusted_php`, official | `mail_` | Not currently in built-in registry in inspected file | This is a known future install risk for official market installs that need `mail_` trusted access. |
| `local.storage.baidu` | `api` | `baidu_storage_` | No | Local/API plugin with non-reserved prefix; does not require Core registry. |
| `faq_block` | `api` | none | No | Normal API plugin. |

## Why Updates Have Been Fragile

1. Official plugin trust is split across multiple places:
   - Built-in registry in `OfficialPluginRegistry`.
   - Root overlay file `system/official-plugins.php`.
   - Core migrations that rewrite that root file.
   - Tests that assert exact entries.
2. Installed sites freeze migration checksums. Any edit to a previously-applied migration causes errors such as `Core migration checksum changed`.
3. Old updaters used a narrower allowed-path list. Packages that included `system/official-plugins.php` or public admin assets could fail with `Update package may only target Core-owned paths`.
4. New official plugins are developed independently from Core, but their trust grants are not independent from Core.
5. Some operational support files are mutable runtime state but are delivered as Core update payload files. That mixes configuration, trust policy, and code deployment.

## Security Model That Should Be Preserved

The current strict checks are valuable and should not be removed:

- Local uploads must not claim `official.*`, `system-plugin`, `bundled`, or `trusted_php`.
- Ordinary plugins must not use Core/internal prefixes such as `cms_` or `market_`.
- Ordinary plugins must not use another official plugin's table prefix.
- Official plugin table prefixes must be explicitly granted.
- Plugin migrations must only touch plugin-owned database objects.
- Plugin route declarations must not override Core critical routes.
- Market packages must remain hash/signature/authorization checked.

The fix should move trust data out of static Core files, not weaken these rules.

## One-Time Decoupling Plan

### Phase 1: Introduce Signed Official Extension Trust Grants

Add a versioned trust grant data model, for example `cms_extension_trust_grants`.

Recommended fields:

- `extension_id`
- `extension_type`: `plugin`, `payment_provider`, `theme`
- `publisher`: expected `official` for official grants
- `source`: expected `official_market` or `bundled_official`
- `trust_level`: `api` or `trusted_php`
- `capability_namespaces_json`
- `table_prefixes_json`
- `route_prefixes_json`
- `admin_menu_json`
- `provider_capabilities_json`
- `status`: `active`, `revoked`, `superseded`
- `schema_version`
- `issued_at`
- `expires_at`
- `grant_fingerprint`
- `signature`

The signature should be verified with the existing official update/market public key. The CMS must not accept unsigned or locally forged grants.

### Phase 2: Make `OfficialPluginRegistry` Layered

Keep a tiny immutable Core baseline:

- Reserved IDs/prefixes that are always protected: `cms_`, `market_`, `core`, `admin`, `system`, `market`, `official`.
- Critical Core route reservations.
- Legacy built-in fallback for existing bundled plugins only.

Then layer:

1. Built-in legacy fallback records for currently shipped bundled plugins.
2. Signed DB trust grants synced from the official market/update server.
3. Optional emergency local override file, read-only/manual, never required for normal releases.

After this, adding `official.mail`, `official.affiliate-hub`, or a future `official.xxx` should require publishing a signed trust grant from the official market, not editing Core source.

### Phase 3: Extend Market Install Authorization

Current `InstallAuthorization` contains token, URL, expiry, package hash, and market ID. Extend it, or add a companion signed file, to include:

- Official trust grant fingerprint.
- Granted table prefixes.
- Granted capability namespaces.
- Granted route/admin menu metadata.
- Signed review status.
- Publisher identity.

The installer should:

1. Verify package hash/signature as it does now.
2. Verify the signed trust grant.
3. Compare plugin manifest requests against granted permissions.
4. Store/update the grant in DB.
5. Run migration validation with `trustedOfficial=true` only if the verified grant allows it.

No grant means no trusted official privilege, even if the plugin ID starts with `official.`.

### Phase 4: Stop Writing `system/official-plugins.php` From Migrations

Freeze these migrations permanently:

- `2026_09_07_000001_official_plugins_registry`
- `2026_09_08_000007_official_plugins_registry_sync`

Do not edit them again. Instead, add a new idempotent DB migration for trust grants and a sync service.

The root `system/official-plugins.php` can remain as a legacy compatibility overlay during transition, but it should no longer be the normal path for adding official plugins.

### Phase 5: Data-Drive Admin Menus And Display Text

Move plugin UI metadata into plugin manifests and runtime registration:

- Display name
- Admin menu section
- Admin menu label
- Icon
- Sort order
- Settings URL
- Breadcrumb parent
- Badge provider, if any

Core should render this metadata through `PluginRuntimeRegistry` instead of hard-coding individual official plugin IDs in `AdminUiText`.

### Phase 6: Data-Drive Payment Provider Settings

Add a provider configuration schema contract, for example:

- `PaymentProviderConfigurationInterface::settingsSchema()`
- `PaymentProviderConfigurationInterface::help()`
- `PaymentProviderConfigurationInterface::webhookMetadata()`
- `PaymentProviderRedirectPolicyInterface::isSafeRedirectUrl()`

Then Core Admin renders provider forms from schemas. Stripe/PayPal/WeChat Pay/Alipay can remain official plugins, but their form fields and redirect policies should not live in Core controller branches.

### Phase 7: Preserve Compatibility During Migration

Compatibility rules:

- Existing installed official plugins keep working.
- Existing `cms_plugins` rows retain status.
- Existing `cms_plugin_migrations` checksums are not rewritten.
- Existing `system/official-plugins.php` is read as legacy input.
- Future official packages prefer signed DB grants.
- Local upload remains strict.

## P0/P1/P2 Refactor Priority

### P0

- Add signed official extension trust grant storage and verifier.
- Update market installer to use signed grants instead of `OfficialPluginRegistry::tablePrefixes() !== []`.
- Freeze registry-writing migrations; do not edit old migration files again.
- Ensure `official.mail` and future official plugins can install with their own table prefixes without Core source changes.

### P1

- Move plugin display names/settings URLs/admin sections/breadcrumb metadata out of static Core maps.
- Introduce payment provider configuration schema and redirect policy interfaces.
- Add generic regression tests for signed official market grants and malicious lookalike packages.

### P2

- Reduce exact official plugin IDs in tests where the test is really about the generic plugin platform.
- Move official plugin recommendation lists into theme/plugin metadata instead of asserting them in Core-focused tests.

## Regression Tests Needed Before Refactor Is Accepted

- Official market package with valid signed grant can install and migrate a granted table prefix.
- Official market package without grant cannot claim `official.*` or `trusted_php`.
- Package with grant for `affiliate_` cannot migrate `commerce_` or `cms_`.
- Tampered grant signature is rejected.
- Revoked grant blocks install/upgrade.
- Local upload still rejects `official.*`, `trusted_php`, `system-plugin`, and reserved prefixes.
- Existing bundled official plugins still install through legacy registry fallback.
- Existing installed sites with `system/official-plugins.php` continue to boot.
- Market install rollback preserves previous plugin version and data when migration fails.
- Admin menu and breadcrumb render from plugin metadata.
- Payment provider settings render from provider schema without hard-coded provider ID branches.

## Proposed Acceptance Criteria

After the decoupling refactor, adding a new official plugin should require only:

1. Build the plugin package with a valid `plugin.json`.
2. Submit/review/publish it through the official market.
3. Publish or attach a signed official trust grant.
4. Install/upgrade through the market.

It should not require:

- Editing `OfficialPluginRegistry.php`.
- Editing `system/official-plugins.php`.
- Editing old Core migrations.
- Editing `AdminUiText.php` for plugin name/settings URL.
- Editing Core installer code for one plugin ID.
- Publishing a Core update unless the plugin needs a genuinely new public Core API.

## Final Recommendation

Do not solve the next official plugin by adding another static registry entry. The next safe step should be a planned Foundation refactor:

`Core Static Official Registry -> Signed Official Extension Trust Grants`

This keeps the existing security boundary while removing the release bottleneck that has repeatedly caused update failures.
