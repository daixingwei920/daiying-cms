# Daiying CMS Baidu Storage Security Report

Date: 2026-09-05

## Secret Handling

- App Secret, access token, refresh token, and OAuth state are stored through `Cms\Core\Plugin\PluginSecretStore`.
- Secret Key is never echoed back into HTML; the admin field shows only a masked status.
- Access tokens are not placed in JavaScript, media picker JSON, or permanent `cms_media` URLs.
- Diagnostics show only status/configuration checks and intentionally hide Secret Key, Access Token, Refresh Token, and Authorization headers.

## OAuth

- Authorization starts only after App Key and Secret Key are configured.
- OAuth state is 64 hex characters from `random_bytes(32)`.
- State expires after 10 minutes and is consumed once.
- Token exchange reuses the same callback URL generated for authorization.
- Callback URL is derived from the current request host/scheme and now preserves a CMS subdirectory base path when `SCRIPT_NAME`/`PHP_SELF` includes it.
- Access-token refresh is guarded by a local file lock to reduce concurrent refresh races.
- Refresh failure marks the connection invalid and keeps App Key/Secret available for reauthorization.

## Network Boundary

- API transport requires HTTPS and allows only:
  - `openapi.baidu.com`
  - `pan.baidu.com`
  - `d.pcs.baidu.com`
- Download URL validation requires HTTPS, port 443, non-IP host, and either:
  - `d.pcs.baidu.com`
  - `pcs.baidu.com`
  - `pan.baidu.com`
  - a subdomain ending in `.baidupcs.com`
- The plugin does not proxy arbitrary user-provided URLs.
- Downloads are fetched only after looking up a CMS media record by id and matching `storage_provider = official.storage.baidu`.
- The public media URL is HMAC signed and expires after 5 minutes.

## Core Isolation

- Core changes are generic remote media provider UI changes.
- No Baidu-specific branch was added to Core media selection logic.
- PayPal, Stripe, Cloudreve, and other providers keep their own URL and security policies.
