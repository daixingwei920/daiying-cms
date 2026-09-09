# Plugins

Daiying CMS supports plugin packages for extending Core capabilities.

## Current Bundled Plugins

The following plugin manifests are present in this repository:

| Plugin ID | Version | Type | Notes |
| --- | --- | --- | --- |
| `faq_block` | `1.0.0` | plugin | FAQ block registration example. |
| `local.storage.baidu` | `1.0.0-rc13.2` | `storage_provider` | Baidu Netdisk OAuth/media storage integration. |
| `official.commerce` | `0.1.0-alpha.15` | plugin | Commerce foundation with product, order, AI module, and alpha Distribution workflows. |
| `official.friend-links` | `1.0.0-alpha.1` | `system-plugin` | Friend links management. |
| `official.mail` | `0.1.0-alpha.2` | plugin | Official Mail Provider bridge for the Core Mail API, including SMTP, Gmail SMTP, and Outlook SMTP providers. |
| `official.novel-collector` | `0.4.19` | plugin | Novel collection and reading routes. |
| `official.video-collector` | `0.2.1` | plugin | Video collection and playback routes. |

## Manifest Basics

Plugin packages use `plugin.json`. Current manifests commonly include:

- `plugin_id`
- `name`
- `version`
- `author`
- `package_type`
- `type`
- `entry`
- `core`
- `php`
- `trust_level`
- `capabilities`
- `capability_namespaces`
- `public_routes`
- `migrations`
- `table_prefixes`
- `data_policy`

## Lifecycle

Core plugin infrastructure includes:

- Local ZIP package installation.
- Manifest validation.
- Safe extraction.
- Static scanning.
- Dependency checks.
- Atomic install.
- Migration handling.
- Data retention policy.
- Reinstall recovery.
- Runtime failure isolation.
- Permission grants and capability boundaries.

## Developer Notes

Use plugin-specific capabilities instead of changing Core directly when the extension can be implemented through existing routes, menus, migrations, storage, media, or content APIs.

When Core changes are unavoidable, document the missing extension point and coordinate the change separately from plugin packaging.
