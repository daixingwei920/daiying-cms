# Daiying CMS

[简体中文](README.zh-CN.md)

A modular PHP CMS for building content websites, media workflows, commerce foundations, and in-development external distribution workflows.

Daiying CMS Core is a self-hosted PHP CMS with themes, plugins, media management, commerce foundations, signed online updates, global AI settings, Passkey-capable admin login, and recovery tooling. The current public release is **1.2.40 stable**.

![Daiying CMS admin dashboard](.github/assets/screenshots/admin-dashboard.png)

## Project Links

- Official Website: <https://www.daiyingcms.com>
- Documentation: [docs/README.md](docs/README.md)
- Download: <https://github.com/daixingwei920/daiying-cms/releases/latest>
- Releases: <https://github.com/daixingwei920/daiying-cms/releases>
- Plugins: [docs/plugins.md](docs/plugins.md)
- Themes: [docs/themes.md](docs/themes.md)
- Global AI: [docs/ai.md](docs/ai.md)

## Product Model

Daiying CMS is organized as a Core platform with optional product layers:

1. **Daiying CMS Core**: installer, admin backend, routing, content, media, themes, plugin lifecycle, recovery, and signed updates.
2. **Content / Media**: articles, pages, structured blocks, taxonomy, uploads, media rendering, and external media/storage integrations.
3. **Commerce**: products, pricing, orders, payments, paid access, card-code delivery, commercial license storage, and optional AI-assisted product-description drafting.
4. **Distribution**: **In Development**. A distribution layer being developed for sending Daiying CMS content and commerce data to supported external channels.
5. **Plugins / Themes**: packaged extension points and marketplace ecosystem for official and third-party capabilities.
6. **Update System**: signed Core update flow with restore points, integrity checks, health checks, and rollback paths.

## Quick Start

### 1. Check Requirements

- PHP 8.3.0 or newer.
- PHP extensions: `pdo`, `json`, `openssl`, `fileinfo`, `zip`.
- SQLite for the simplest install path, or MySQL/MariaDB with `utf8mb4`.
- A web server that serves `public/index.php` as the document-root entry point.
- Writable runtime directories under `storage/` and `content/uploads/`.

### 2. Download

Download the latest stable package from [GitHub Releases](https://github.com/daixingwei920/daiying-cms/releases/latest).

Current checked release:

- Tag: `v1.2.40`
- Package: `daiying-cms-1.2.40-stable.zip`
- SHA-256: see the `.sha256` sidecar attached to the release.

### 3. Install

1. Upload and extract the ZIP on the server.
2. Point the web server document root to `public`.
3. Ensure `storage/` and `content/uploads/` are writable by the PHP process.
4. Open `/install`.
5. Choose SQLite or MySQL/MariaDB and test the database connection.
6. Create the site identity and first administrator account.
7. Log in at `/admin/login`.

See [Installation](docs/installation.md) for the full checklist.

## Major Capabilities

### Distribution

**Status: In Development**

Distribution is a layer being developed for sending Daiying CMS content and commerce data to supported external channels.

The intended workflow is:

```text
Content / product data
-> media assets
-> Distribution
-> choose or match external channels
-> AI or rule-based channel adaptation
-> Provider / Connector
-> delivery
-> success, failure, channel error, or AI error status
```

Distribution is separate from the plugin marketplace, commercial plugin licensing, authorization-code delivery, Core updates, Capability Packs, Site Vault, and Shadow Upgrade.

The current repository contains an alpha Commerce Distribution implementation under `content/plugins/official.commerce`. It is not a stable Core module, and the final Provider/Connector specification should continue to follow the merged implementation as it matures.

### Commerce

**Status: Foundation / Beta**

Daiying CMS includes payment provider settings, payment attempts, paid content/download access, and automatic card-code delivery. The bundled Commerce plugin provides product and order foundations, and its product editor can draft a product description from administrator-provided product fields through the Core site AI service. The first AI product-description workflow does not crawl external product URLs; a URL can be kept as administrator-provided context for later workflows.

See [Commerce](docs/commerce.md).

### Plugin Architecture

Plugins are installed from local ZIP packages or official market packages when the Market API is configured. The plugin lifecycle includes manifest validation, safe extraction, static scanning, dependency checks, atomic install, data-retention policy, migrations, reinstall recovery, and capability boundaries.

Bundled plugins currently visible in this repository include:

- `official.commerce`
- `official.friend-links`
- `official.novel-collector`
- `official.video-collector`
- `local.storage.baidu`
- `faq_block`

See [Plugins](docs/plugins.md).

### Themes

Daiying CMS themes are separate packages under `content/themes`, each with a `theme.json` manifest, templates, assets, compatibility constraints, supported content types, and optional settings schema.

Bundled themes currently visible in this repository include:

- `default`
- `daiying_media`
- `daiying_novel`
- `daiying-video`
- `safe`

See [Themes](docs/themes.md).

### Media And External Storage

Core media support includes MIME validation, randomized storage keys, deduplication, references, safe downloads, and HTML5 audio/video rendering. The repository also contains a Baidu storage plugin for OAuth-based Baidu Netdisk media access.

### Global AI Provider Settings

Core provides optional site-level AI Provider configuration and a reusable AI service API for CMS features and plugins. DeepSeek, OpenAI, Grok / xAI, Tencent Hunyuan, and custom compatible endpoints share one OpenAI-compatible adapter; Gemini uses a native Gemini adapter. The content editor can use this service for "AI write with me" drafting, and bundled plugins can inherit the same site AI settings instead of asking administrators to repeat API Keys. The AI layer handles configuration, Provider calls, and safe error handling only; business uses such as summaries, SEO, product text, or distribution adaptation belong to plugins or feature modules.

### Admin Security

Administrators can keep password login while also using registered Passkeys for passwordless admin login. Passkey credentials stay scoped to the administrator account and the site origin; the login page uses the existing Core authentication and CSRF boundaries.

See [Global AI](docs/ai.md).

## Documentation

- [Documentation Index](docs/README.md)
- [Installation](docs/installation.md)
- [Developers](docs/developers.md)
- [Plugins](docs/plugins.md)
- [Plugin API v1](PLUGIN_API_V1.md)
- [Themes](docs/themes.md)
- [Theme API v1](THEME_API_V1.md)
- [Storage Provider API v1](STORAGE_PROVIDER_API_V1.md)
- [REST API v1](REST_API_V1.md)
- [Global AI](docs/ai.md)
- [Commerce](docs/commerce.md)
- [Distribution](docs/distribution.md)
- [Update System](docs/update-system.md)
- [Foundation Freeze Checklist](CORE_FOUNDATION_FREEZE_CHECKLIST.md)
- [Security Policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)

## Release And Update Notes

Installed sites use the official update server configured as:

```text
https://updates.daiyingcms.com
```

Core update packages are signed and verified by the update flow. Production sites should run the readiness checker before public launch:

```sh
php scripts/validate_production_readiness.php
php scripts/validate_production_readiness.php --json
php scripts/validate_production_readiness.php --strict
```

Release history is summarized in [CHANGELOG.md](CHANGELOG.md).

## Screenshots

The screenshots below come from the current live Daiying CMS admin UI. Sensitive fields, private file lists, and order details were avoided, cropped, or redacted.

| Area | Screenshot |
| --- | --- |
| Content editor | ![Content editor](.github/assets/screenshots/content-editor.png) |
| Media library | ![Media library](.github/assets/screenshots/media-library.png) |
| Plugin marketplace | ![Plugin marketplace](.github/assets/screenshots/marketplace.png) |
| Card delivery commerce | ![Card delivery commerce](.github/assets/screenshots/commerce.png) |
| Theme marketplace | ![Theme marketplace](.github/assets/screenshots/theme-marketplace.png) |
| Online update | ![Online update](.github/assets/screenshots/online-update.png) |

Additional screenshot capture notes are documented in [DAIYING_CMS_SCREENSHOT_REPORT.md](DAIYING_CMS_SCREENSHOT_REPORT.md).

## Security

Do not publish configuration secrets, database dumps, OAuth secrets, payment keys, update signing private keys, sessions, or logs. See [SECURITY.md](SECURITY.md) for reporting and handling guidance.

## License

No project license file is currently present in this repository. Do not assume reuse rights until the project owner adds an explicit `LICENSE`.
