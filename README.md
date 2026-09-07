# Daiying CMS

[简体中文](README.zh-CN.md)

A modular PHP CMS for building content websites, media workflows, commerce foundations, and in-development external distribution workflows.

Daiying CMS Core is a self-hosted PHP CMS with themes, plugins, media management, commerce foundations, signed online updates, and recovery tooling. The current public release is **1.2.24 stable**.

## Project Links

- Official Website: <https://www.daiyingcms.com>
- Documentation: [docs/README.md](docs/README.md)
- Download: <https://github.com/daixingwei920/daiying-cms/releases/latest>
- Releases: <https://github.com/daixingwei920/daiying-cms/releases>
- Plugins: [docs/plugins.md](docs/plugins.md)
- Themes: [docs/themes.md](docs/themes.md)

## Product Model

Daiying CMS is organized as a Core platform with optional product layers:

1. **Daiying CMS Core**: installer, admin backend, routing, content, media, themes, plugin lifecycle, recovery, and signed updates.
2. **Content / Media**: articles, pages, structured blocks, taxonomy, uploads, media rendering, and external media/storage integrations.
3. **Commerce**: products, pricing, orders, payments, paid access, card-code delivery, and commercial license storage.
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

- Tag: `v1.2.24`
- Package: `daiying-cms-1.2.24-stable.zip`
- SHA-256: `689ecb05b0a461bc5cdc5393589baf9eac8fdf2bb3c1b75e7e4b159e609b79ee`

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

This repository branch does not currently contain a dedicated stable `system/core/Distribution` module or final Distribution Provider/Connector specification. Detailed supported channels and workflows will be documented after the current implementation is merged.

### Commerce

**Status: Foundation / Beta**

Daiying CMS includes payment provider settings, payment attempts, paid content/download access, and automatic card-code delivery. The current Core ships a manual payment provider and hosted redirect provider foundation. External payment providers and storefront flows may be packaged as plugins.

See [Commerce](docs/commerce.md).

### Plugin Architecture

Plugins are installed from local ZIP packages or official market packages when the Market API is configured. The plugin lifecycle includes manifest validation, safe extraction, static scanning, dependency checks, atomic install, data-retention policy, migrations, reinstall recovery, and capability boundaries.

Bundled plugins currently visible in this repository include:

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

## Documentation

- [Documentation Index](docs/README.md)
- [Installation](docs/installation.md)
- [Developers](docs/developers.md)
- [Plugins](docs/plugins.md)
- [Themes](docs/themes.md)
- [Commerce](docs/commerce.md)
- [Distribution](docs/distribution.md)
- [Update System](docs/update-system.md)
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

No verified public screenshots are currently tracked in this repository. Screenshot requirements are documented in [GITHUB_SCREENSHOT_REQUIREMENTS.md](GITHUB_SCREENSHOT_REQUIREMENTS.md).

## Security

Do not publish configuration secrets, database dumps, OAuth secrets, payment keys, update signing private keys, sessions, or logs. See [SECURITY.md](SECURITY.md) for reporting and handling guidance.

## License

No project license file is currently present in this repository. Do not assume reuse rights until the project owner adds an explicit `LICENSE`.
