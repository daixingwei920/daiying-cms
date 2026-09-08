# Daiying CMS Documentation

This index collects the public-facing documentation currently available in the GitHub repository.

## Getting Started

- [Installation](installation.md)
- [Update System](update-system.md)
- [Security Policy](../SECURITY.md)

## User Guide

- Content management is available through the admin backend after installation.
- Media library capabilities are part of Core and can be extended by storage plugins.
- Site-level AI Provider settings are available through [Global AI](ai.md).
- Site-level mail delivery and notification foundations are available through [Mail Infrastructure](mail-infrastructure.md).
- Scheduled publishing can be run with `php scripts/publish_scheduled_content.php`.

## Developer Guide

- [Developers](developers.md)
- [Plugins](plugins.md)
- [Themes](themes.md)
- [Global AI](ai.md)
- [Mail Infrastructure](mail-infrastructure.md)
- [Foundation Services](foundation-services.md)
- [Core API Stability](../CORE_API_STABILITY.md)
- [Storage Provider API v1](../STORAGE_PROVIDER_API_V1.md)
- [REST API v1](../REST_API_V1.md)
- [Examples](../examples/README.md)

## Commerce

- [Commerce](commerce.md)

## Distribution

- [Distribution](distribution.md)

Distribution is documented as In Development. The current branch contains an alpha Commerce Distribution implementation under `content/plugins/official.commerce`, but it does not yet define a stable standalone Provider/Connector specification or final user workflow.

## Deployment

- Use `public/index.php` as the web entry point.
- Keep `config/`, `storage/`, `system/`, `tests/`, `scripts/`, `content/plugins/`, and `content/themes/` outside public execution.
- Run `php scripts/validate_production_readiness.php --strict` before public launch.

## Architecture Notes

Core namespaces currently visible in the repository include:

- `Cms\Core\Content`
- `Cms\Core\Ai`
- `Cms\Core\Mail`
- `Cms\Core\Media`
- `Cms\Core\Plugin`
- `Cms\Core\Theme`
- `Cms\Core\Market`
- `Cms\Core\Payment`
- `Cms\Core\Update`
- `Cms\Core\Recovery`
- `Cms\Core\ExternalMigration`

Current alpha Distribution work is implemented under the `official.commerce` plugin rather than a stable Core namespace.

## Existing Root Reports

Several historical implementation reports still live at the repository root. They are preserved to avoid breaking references, but new public documentation should link from this index.
