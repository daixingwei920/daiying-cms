# Daiying CMS Documentation

This index collects the public-facing documentation currently available in the GitHub repository.

## Getting Started

- [Installation](installation.md)
- [Update System](update-system.md)
- [Security Policy](../SECURITY.md)

## User Guide

- Content management is available through the admin backend after installation.
- Media library capabilities are part of Core and can be extended by storage plugins.
- Scheduled publishing can be run with `php scripts/publish_scheduled_content.php`.

## Developer Guide

- [Developers](developers.md)
- [Plugins](plugins.md)
- [Themes](themes.md)
- [Examples](../examples/README.md)

## Commerce

- [Commerce](commerce.md)

## Distribution

- [Distribution](distribution.md)

Distribution is currently documented as Development / Preview because the public repository does not yet contain a stable Distribution Provider specification.

## Deployment

- Use `public/index.php` as the web entry point.
- Keep `config/`, `storage/`, `system/`, `tests/`, `scripts/`, `content/plugins/`, and `content/themes/` outside public execution.
- Run `php scripts/validate_production_readiness.php --strict` before public launch.

## Architecture Notes

Core namespaces currently visible in the repository include:

- `Cms\Core\Content`
- `Cms\Core\Media`
- `Cms\Core\Plugin`
- `Cms\Core\Theme`
- `Cms\Core\Market`
- `Cms\Core\Payment`
- `Cms\Core\Update`
- `Cms\Core\Recovery`
- `Cms\Core\ExternalMigration`

## Existing Root Reports

Several historical implementation reports still live at the repository root. They are preserved to avoid breaking references, but new public documentation should link from this index.
