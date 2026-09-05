# Daiying CMS Baidu Storage Architecture Audit

Date: 2026-09-05

## Scope

This audit covers the first release-candidate phase of the local Baidu Netdisk storage plugin, `local.storage.baidu`. The goal is to confirm which Daiying CMS extension points were reused and which small generic Core changes were required for OAuth, token storage, directory browsing, and external media references.

## Existing Core Capabilities

### Plugin Runtime

- `Cms\Core\Plugin\PluginContext` already supports plugin-owned admin routes, front routes, admin menu entries, plugin data storage, raw PDO access for trusted official plugins, and `PluginSecretStore`.
- `PluginRuntimeRegistry` handles route collision checks, admin route capability checks, and optional CSRF verification for plugin admin POST routes.
- Official plugin trust is enforced through `system/official-plugins.php` and `OfficialPluginRegistry`; this local RC must not claim that trust boundary.

Decision: Baidu storage can be implemented as an independent local plugin for this RC. No Baidu-specific Core boot logic is required.

### Secret Storage

- `Cms\Core\Plugin\PluginSecretStore` encrypts plugin secrets with the site `security.encryption_key` using AES-256-GCM.
- It stores values in `cms_plugin_secrets` keyed by `plugin_id` and `secret_key`.
- If the encryption key is missing, saving a non-empty secret throws a plugin exception.

Decision: App Secret, access token, refresh token, and OAuth one-time state secrets must use `PluginSecretStore`. Public configuration such as App Key and connection status can use `PluginDataStore`.

### Remote Media Provider

- `Cms\Core\Media\RemoteMediaProviderInterface` already defines the required provider surface: `list`, `search`, `get`, `resolveUrl`, `upload`, `delete`, `move`, `metadata`, and `downloadTo`.
- `Cms\Core\Media\RemoteMediaProxyProviderInterface` adds a generic proxy path for local/restricted providers that must keep provider tokens server-side.
- `RemoteMediaProviderRegistry` allows enabled plugins to register providers at boot.
- `MediaLibrary::registerRemoteReference()` persists remote media references in `cms_media` with `storage_provider`, `storage_key`, `relative_path`, and metadata including `remote_id`.
- `MediaController` already detects non-local `storage_provider` rows and calls the registered remote provider to resolve a fresh URL at request time.

Decision: Baidu media should save `storage_provider = local.storage.baidu` and `remote_id = fs_id`. The CMS should not persist short-lived Baidu download URLs as permanent media URLs.

### Media Picker and Admin Media Pages

- Core admin media already has generic JSON endpoints:
  - `/admin/media/provider/list`
  - `/admin/media/provider/select`
  - `/admin/media/provider/upload`
- These endpoints accept an arbitrary registered `provider` id.
- However, the visible media UI and editor picker currently hard-code `cloudreve` as the only remote source in labels, default paths, tabs, and JavaScript branching.

Decision: A small public Core improvement was implemented: render all registered remote providers from `RemoteMediaProviderRegistry::all()` instead of hard-coding Cloudreve. This is a generic provider UI extension and contains no Baidu-specific logic.

## Required Core Changes

Implemented public changes:

1. Add helper methods in `AdminController` to list registered remote media providers and return their id, label, and a provider-specific default path if available.
2. Update `/admin/media` source handling to accept any registered remote provider id.
3. Updated the media picker source buttons, labels, default path, JavaScript filtering, and fetch/select payloads to use the active remote provider dynamically.

No Core storage schema changes are required for Phase 1.

## Baidu Plugin Architecture

### Plugin ID

- `local.storage.baidu`

### Main Components

- `BaiduStoragePlugin`: route registration and provider registration.
- `BaiduTokenRepository`: public config, encrypted secret/token storage, OAuth state storage.
- `BaiduOAuthService`: authorization URL generation, state creation/verification, callback token exchange.
- `BaiduHttpTransport`: TLS-verified HTTP transport.
- `BaiduApiClient`: Baidu OAuth/API requests, token refresh, error mapping, download URL validation.
- `BaiduStorageProvider`: implements `RemoteMediaProxyProviderInterface`.
- `BaiduFileBrowser`: maps Baidu file entries to `MediaProviderItem`.

### First Implementation Boundary

P0/P1:

- Admin settings page.
- App Key and Secret Key storage.
- Site-derived OAuth callback URL.
- OAuth start/callback with state validation and one-time state consumption.
- Token refresh repository support.
- Directory browse and search through Baidu API.
- Remote media reference selection through existing CMS media endpoints.
- Fresh URL resolution on `/media/{id}`.
- Upload/delete/move throw friendly “not supported in this version” errors.

P1 upload will be implemented only after confirming Baidu's current official multipart upload protocol with real credentials.

## Security Notes

- Tokens and Secret Key must never be rendered into HTML, JavaScript, logs, or reports.
- OAuth callback must not treat the presence of `code` as success; it must validate state first and consume the state once.
- The provider must use fixed Baidu API hosts and must not proxy arbitrary user URLs.
- Baidu download URLs returned by the API must be validated before redirecting or downloading:
  - scheme must be `https`
  - host must match explicit Baidu Netdisk download host allowlist rules
  - localhost, loopback, private IP, link-local, and metadata IP destinations are rejected

## Readiness

Architecture is ready for live OAuth acceptance testing. Real Baidu OAuth acceptance cannot be claimed until the user enters real App Key and Secret Key in the admin UI and completes a live authorization callback.
