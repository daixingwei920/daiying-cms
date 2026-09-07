# Developers

This page collects developer entry points for Daiying CMS.

## Core Areas

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
- [Commerce Extension](commerce.md)
- [Distribution Preview](distribution.md)

## Scripts

- `php scripts/validate_production_readiness.php`
- `php scripts/diagnose_payment_providers.php --json`
- `php scripts/publish_scheduled_content.php`
- `php scripts/build_full_install_package.php --version=1.2.24`
- `php scripts/verify_release_artifacts.php`

## Compatibility

Current Core runtime requirements are PHP 8.3.0+ with `pdo`, `json`, `openssl`, `fileinfo`, and `zip`.

## Safety Rules

- Do not commit production secrets or local credentials.
- Prefer plugin/theme APIs before changing Core.
- Keep migrations reversible where possible and scoped to owned tables.
- Document new public routes, capabilities, permissions, and data-retention behavior in manifests.
