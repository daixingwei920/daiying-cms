# Developers

This page collects developer entry points for Daiying CMS.

## Core Areas

- AI: `system/core/Ai`
- Mail: `system/core/Mail`
- Content: `system/core/Content`
- Media: `system/core/Media`
- Plugins: `system/core/Plugin`
- Themes: `system/core/Theme`
- Market: `system/core/Market`
- Payments: `system/core/Payment`
- Updates: `system/core/Update`
- Recovery: `system/core/Recovery`
- External migration: `system/core/ExternalMigration`

## Extension Guides

- [Plugin Development](plugins.md)
- [Theme Development](themes.md)
- [Theme API v1](../THEME_API_V1.md)
- [Global AI](ai.md)
- [Mail Infrastructure](mail-infrastructure.md)
- [Foundation Services](foundation-services.md)
- [Content Foundation](content-foundation.md)
- [Core API Stability](../CORE_API_STABILITY.md)
- [Storage Provider API v1](../STORAGE_PROVIDER_API_V1.md)
- [REST API v1](../REST_API_V1.md)
- [Commerce Extension](commerce.md)
- [Distribution In Development](distribution.md)

## Scripts

- `php scripts/validate_production_readiness.php`
- `php scripts/diagnose_payment_providers.php --json`
- `php scripts/publish_scheduled_content.php`
- `php scripts/build_full_install_package.php --version=1.2.29`
- `php scripts/verify_release_artifacts.php`

## Public API Versions

- Core API: `1.0`
- Plugin API: `1.0`
- Theme API: `1.0`
- Storage Provider API: `1.0`
- REST API: `1.0`
- Update Protocol: `1.0`

## Compatibility

Current Core runtime requirements are PHP 8.3.0+ with `pdo`, `json`, `openssl`, `fileinfo`, and `zip`.

## Safety Rules

- Do not commit production secrets or local credentials.
- Plugins should use `Cms\Core\Ai\AI::forSite()` or `PluginContext::ai()` instead of reading AI configuration tables directly.
- Plugins should use `PluginContext::mail()` instead of reading mail settings tables or implementing their own SMTP sender.
- Plugins should use `PluginContext::queue()`, `PluginContext::cache()`, and `PluginContext::webhooks()` for shared infrastructure.
- Plugins should register content types, custom fields, searchable resources, and SEO extensions through `PluginContext`.
- Prefer plugin/theme APIs before changing Core.
- Keep migrations reversible where possible and scoped to owned tables.
- Document new public routes, capabilities, permissions, and data-retention behavior in manifests.
