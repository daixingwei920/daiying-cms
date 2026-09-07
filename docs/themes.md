# Themes

Daiying CMS supports independent themes under `content/themes`.

## Current Bundled Themes

| Theme ID | Version | Content Types | Notes |
| --- | --- | --- | --- |
| `default` | `1.0.0` | `article`, `page` | Default CMS theme. |
| `daiying_media` | `1.0.5` | `article`, `page` | Media/article oriented theme. |
| `daiying_novel` | `1.0.2` | `novel`, `novel_author`, `novel_chapter` | Novel theme; requires `official.novel-collector`. |
| `daiying-video` | `1.0.0` | `video`, `video_episode`, `short_drama` | Video theme; requires `official.video-collector`. |
| `safe` | `1.0.0` | `article`, `page` | Recovery fallback theme. |

## Manifest Basics

Themes use `theme.json`. Current manifests may include:

- `theme_id`
- `product_id`
- `name`
- `version`
- `author`
- `description`
- `core`
- `content_types`
- `recommended_plugins`
- `required_plugins`
- `package_type`
- `market_release`
- `settings_schema`

## Theme Behavior

Core theme infrastructure includes:

- Theme discovery.
- Compatibility checks.
- Settings isolation.
- Safe-theme fallback.
- Template context rendering.
- Theme package installation.

## Installation

Use the admin theme management UI for packaged themes when available. Direct file replacement should be reserved for development or recovery scenarios.
