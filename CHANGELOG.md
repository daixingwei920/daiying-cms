# Changelog

This changelog summarizes public GitHub release information. Detailed engineering reports may exist in historical root-level `DAIYING_*.md` files.

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
