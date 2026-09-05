# Daiying CMS Baidu Storage Implementation Report

Date: 2026-09-05

## Scope

Implemented release-candidate plugin `local.storage.baidu` as a local restricted API storage/media provider.

## Completed

- Added independent plugin under `content/plugins/local.storage.baidu`.
- Added admin settings page for App Key, Secret Key, OAuth callback display, connect, disconnect, connection test, and browser.
- Added callback URL copy action, reauthorization entry, recent-error display, and a safe diagnostics page.
- Added OAuth flow with generated state, one-time state validation, callback handling, token exchange, token refresh support, and encrypted token storage.
- Added Baidu API client for user info, directory list, search, file metadata, and download link resolution.
- Added refresh-token lock to avoid concurrent access-token refresh races on one server.
- Added fixed Baidu host allowlists for API/download URLs.
- Added remote media provider integration so Baidu files can be selected into the CMS media library as external references.
- Added generic Core remote media proxy support through `/media/{id}` so browser-facing URLs never expose Baidu access tokens.
- Converted the RC package from `official.storage.baidu` / `trusted_php` to local-installable `local.storage.baidu` / `api`.
- Switched remote download ingestion from `fopen()` to cURL streaming into a temporary file with TLS verification and byte limit enforcement.
- Genericized Core media admin/picker UI to enumerate all registered remote providers instead of only showing Cloudreve.
- Prepared `local.storage.baidu` as a local ZIP plugin package. It is intentionally not registered as an official bundled plugin.

## Deferred

- Upload, delete, and move are intentionally disabled in RC2 with friendly errors.
- Core still returns string responses, so the final CMS-to-browser response is not a true streaming response. Large video serving should wait for a Core `StreamResponse` style primitive; current RC path caps proxied downloads at 50 MB.
- Real OAuth and live Baidu file browsing require real App Key/Secret Key to be entered in admin and were not completed in this coding pass.
