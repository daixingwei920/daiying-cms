# Changelog

This changelog summarizes public GitHub release information. Detailed engineering reports may exist in historical root-level `DAIYING_*.md` files.

## 1.2.48 - 2026-09-10

- Published a legacy-verifier bridge update that delivers the collapsible admin sidebar runtime through `system/core/*` only.
- Avoided public asset and operational support file targets so sites still running older update validators can install the bridge cleanly.
- Kept Affiliate Hub trusted registry support in the built-in Core registry without requiring the root support registry file during the bridge update.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.48-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.48>.

## 1.2.47 - 2026-09-10

- Restored cross-version delivery of the admin sidebar collapse UI by ensuring admin CSS and JS are both treated as Core-owned operational support files.
- Repaired the release builder and parity gate so future exact Core update packages keep backend assets in lockstep with runtime update validation.
- Re-delivered the collapsible admin sidebar layout for sites that upgraded directly to 1.2.46 without receiving the 1.2.45 admin layout files.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.47-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.47>.

## 1.2.46 - 2026-09-10

- Added `official.affiliate-hub` to the trusted official plugin registry with the `affiliate_` table prefix and `affiliate` capability namespace.
- Restored official Marketplace install/upgrade compatibility for Affiliate Hub packages that declare `affiliate_` migrations.
- Added regression coverage proving registered Affiliate Hub packages can use `affiliate_` while unregistered official-like packages are still blocked from reserved prefixes.
- Preserved the official plugin database safety boundary and the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.46-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.46>.

## 1.2.45 - 2026-09-10

- Added collapsible admin sidebar sections for the main dashboard, content, appearance, extensions, commerce, platform, Marketplace, and plugin-provided menu groups.
- Kept the current page group expanded automatically while remembering administrator collapse preferences in the browser.
- Fixed the admin security sidebar label so it displays `后台安全` instead of the internal icon name.
- Preserved the existing admin sidebar icon-only mode and long-page scrolling behavior.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.45-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.45>.

## 1.2.44 - 2026-09-10

- Fixed official Marketplace plugin installs so trusted official plugins can run migrations against table prefixes registered in the built-in official plugin registry.
- Restored the `official.commerce` Marketplace upgrade path for packages declaring the `commerce_` table prefix without weakening reserved-prefix protection for ordinary plugins.
- Added regression coverage for official Marketplace plugin migrations using trusted official table prefixes.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.44-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.44>.

## 1.2.43 - 2026-09-09

- Published a bridge Core update for sites whose existing update verifier does not yet allow backend public asset paths.
- Kept the Core runtime, exact update builder, release parity gate, and update server aligned on `public/assets/admin/admin.css` as an operational support file for future updates.
- The signed online update package intentionally avoids targeting `public/assets/admin/admin.css` directly so older sites can install the bridge before public admin assets are delivered by later Core updates.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.43-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.43>.

## 1.2.42 - 2026-09-09

- Added the shared admin stylesheet to the Core update operational-support allow-list so signed online updates can deliver backend layout fixes.
- Kept the 1.2.41 long-page scrolling fix and extension/media/theme grid clipping fix.
- Added a release contract check that requires `public/assets/admin/admin.css` to be included in exact Core update packages.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.42-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.42>.

## 1.2.41 - 2026-09-09

- Fixed the shared admin layout so long backend pages can scroll to the final control on desktop and mobile.
- Added bottom safe-area padding to admin content to keep final fields and buttons away from the browser edge.
- Kept the sidebar independently scrollable while preserving the sticky topbar and mobile natural scrolling.
- Fixed extension/media/theme card grids so plugin management and similar pages do not clip cards horizontally.
- Added an admin layout scroll contract test.
- Public install package: `daiying-cms-1.2.41-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.41>.

## 1.2.40 - 2026-09-09

- Added Passkey passwordless admin login for administrator accounts with registered Passkeys while preserving the existing password login path.
- Added an article/page editor "AI write with me" action that uses the Core site-level AI service.
- Added Commerce product-description AI drafting from administrator-provided product fields, with external product URLs treated as context only.
- Bumped the bundled `official.commerce` source to `0.1.0-alpha.16` for the product-description drafting workflow.
- Kept AI Provider configuration optional, API Keys masked/encrypted, and official update-server AI Review isolated from site AI settings.
- Preserved payment, order, inventory, fulfillment, signed-update, and 1.2.0 cross-version upgrade boundaries.
- Public install package: `daiying-cms-1.2.40-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.40>.

## 1.2.39 - 2026-09-09

- Added the Core notification center foundation for reusable admin notifications.
- Added admin notification UI, unread counts, read/archive operations, plugin notification contracts, dedupe support, action URL validation, and sensitive payload redaction.
- Added official.mail integration points for new-mail notifications without storing OAuth tokens or full message bodies in Core notifications.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.39-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.39>.

## 1.2.38 - 2026-09-09

- Fixed official Marketplace plugin installs and upgrades to run plugin-declared migrations before leaving the package installed.
- Added migration checksum validation, plugin-owned table-prefix checks, and rollback preservation for Marketplace plugin migration failures.
- Added an enable-time migration backfill for trusted official Marketplace plugins so plugins cannot become Enabled while declared tables are missing.
- Added regression coverage for Marketplace migration install, upgrade, checksum mismatch, rollback, and official.mail table creation.
- Preserved Core/plugin data, official update isolation, and the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.38-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.38>.

## 1.2.37 - 2026-09-09

- Fixed post-switch Core update health checks to inspect the active release directory instead of stale root Core files.
- Fixed Site Health migration pending counts on upgraded sites whose migration table uses `migration_name`.
- Preserved rollback behavior, package integrity checks, and the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.37-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.37>.

## 1.2.36 - 2026-09-09

- Fixed direct cross-version Core updates for older sites that cannot write root-level operational registry files during migrations.
- Marked the historical official plugin registry migration as deprecated for update-package required migration manifests.
- Preserved the built-in official plugin registry fallback, rollback behavior, package integrity checks, and the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.36-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.36>.

## 1.2.35 - 2026-09-09

- Increased the Gemini test connection token budget so current Gemini models can return visible text instead of stopping at `MAX_TOKENS`.
- Kept the Gemini test connection deterministic with low temperature and safe diagnostics.
- Preserved the 1.2.0 cross-version upgrade floor and the existing AI public API.
- Public install package: `daiying-cms-1.2.35-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.35>.

## 1.2.34 - 2026-09-09

- Stabilized the Gemini test connection path with deterministic low-temperature test settings.
- Improved Gemini empty-response diagnostics by showing the safe Gemini finish reason when no text is returned.
- Preserved the 1.2.0 cross-version upgrade floor and the existing AI public API.
- Public install package: `daiying-cms-1.2.34-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.34>.

## 1.2.33 - 2026-09-09

- Updated the Google Gemini preset from the retired `gemini-2.5-flash` default to `gemini-3.6-flash`.
- Added a safe migration that updates only the obsolete Gemini default model while preserving existing encrypted API Keys and custom AI settings.
- Improved Gemini 404 diagnostics so administrators see the provider's safe model guidance instead of only a generic endpoint error.
- Accepted `models/...` Gemini model names without generating an invalid `models%2F...` request path.
- Removed PHP 8.5 `curl_close()` deprecation noise from AI provider HTTP clients.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.33-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.33>.

## 1.2.32 - 2026-09-09

- Restored the released `2026_09_07_000002_core_ai_settings` migration checksum so sites that already applied the 1.2.28/1.2.29 AI settings migration can continue direct Core updates.
- Added regression coverage for frozen migration checksums to prevent mutating already-published migrations.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.32-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.32>.

## 1.2.31 - 2026-09-08

- Restored the released `2026_09_07_000001_official_plugins_registry` migration checksum so sites that already applied the 1.2.25 registry migration can continue cross-version Core updates.
- Added a new `2026_09_08_000007_official_plugins_registry_sync` migration for best-effort official plugin registry synchronization without mutating the historical migration.
- Preserved the 1.2.0 cross-version upgrade floor.
- Public install package: `daiying-cms-1.2.31-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.31>.

## 1.2.30 - 2026-09-08

- Added AI Foundation V1 as a stable Core foundation layer for site-level AI reuse.
- Added AI Gateway, provider/model registry, usage ledger, quota policies, queue-backed AI jobs, AI Tool/Agent/Prompt registries, and AI audit records.
- Published AI API v1 contracts for plugins while preserving the existing global AI settings and encrypted API Key storage.
- Kept DeepSeek, OpenAI, Grok/xAI, Tencent Hunyuan, and custom providers on the shared OpenAI-compatible adapter; Gemini remains on the native Gemini adapter.
- Official update server and Marketplace AI Review remain isolated from site AI configuration.
- Added cross-version fixtures for `1.2.0` and `1.2.22`, plus RSA-compatible local update package support for old updater smoke tests.
- Public install package: `daiying-cms-1.2.30-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.30>.

## 1.2.29 - 2026-09-07

- Reissued the Global AI Core update with the migration manifest narrowed to the new AI settings migration.
- Keeps the complete Core file payload for cross-version file completeness while avoiding legacy migration replays on existing sites.
- Public install package: `daiying-cms-1.2.29-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.29>.

## 1.2.28 - 2026-09-07

- Added site-level Global AI Provider settings for CMS and plugin reuse.
- Added the `Cms\Core\Ai` service API and `PluginContext::ai()` runtime entry point.
- Added DeepSeek and OpenAI-compatible Provider support with masked API Key storage and safe test connection handling.
- Added cross-version AI settings migration defaults and regression coverage for upgrade/rollback compatibility.
- Public install package: `daiying-cms-1.2.28-stable.zip`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.28>.

## 1.2.27 - 2026-09-07

- Cleared stale, non-active Core release directories before preparing an update retry.
- Made the official plugin registry migration best-effort when the root `system/` directory is not writable by the PHP process; runtime trust still comes from the built-in registry fallback.
- Preserved the 1.2.26 Stripe Checkout URL compatibility fix.
- Public install package: `daiying-cms-1.2.27-stable.zip`.
- SHA-256: `bd70bcab0b6700c521835af319b31c34847a158fe0168b4cba965531ecbc0eb1`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.27>.

## 1.2.26 - 2026-09-07

- Allowed official Stripe Checkout Session URLs using `https://checkout.stripe.com/pay/cs_...` in addition to the existing `https://checkout.stripe.com/c/pay/cs_...` form.
- Preserved Stripe redirect safety checks for HTTPS, official host, default port, userinfo rejection, lookalike host rejection, and secret-looking query or fragment data.
- Public install package: `daiying-cms-1.2.26-stable.zip`.
- SHA-256: `3c4d5ef19f4b5658c7156c2b7f369054691714f3d84efcd8022dffa232c04ed4`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.26>.

## 1.2.25 - 2026-09-07

- Added `official.commerce` to the built-in official plugin registry fallback so trusted bundled Commerce installs are recognized even when older sites have an outdated root registry file.
- Allowed `system/official-plugins.php` as a signed Core operational support file for future Core update packages.
- Public install package: `daiying-cms-1.2.25-stable.zip`.
- SHA-256: `95d22a6c5636e3be63153789f41531abc81cb745bf8f5616c00cf122fd3b1986`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.25>.

## 1.2.24 - 2026-09-06

- Released `Daiying CMS 1.2.24` as the latest stable GitHub release.
- Public install package: `daiying-cms-1.2.24-stable.zip`.
- SHA-256: `689ecb05b0a461bc5cdc5393589baf9eac8fdf2bb3c1b75e7e4b159e609b79ee`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.24>.

## Previous Tags

The local repository also contains previous tags including:

- `v1.2.19`
- `v1.2.18`
- `v1.2.17`
- `v1.2.16`
- `v1.2.12`
- `v1.2.11`
- `v1.2.8`
- `v1.2.4`

Use GitHub Releases for public download artifacts.
