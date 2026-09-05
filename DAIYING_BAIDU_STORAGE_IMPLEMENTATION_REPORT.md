# Daiying CMS Baidu Storage Implementation Report

Date: 2026-09-05

## Scope

Implemented first release-candidate plugin `official.storage.baidu` as an official trusted storage/media provider.

## Completed

- Added independent plugin under `content/plugins/official.storage.baidu`.
- Added admin settings page for App Key, Secret Key, OAuth callback display, connect, disconnect, connection test, and browser.
- Added OAuth flow with generated state, one-time state validation, callback handling, token exchange, token refresh support, and encrypted token storage.
- Added Baidu API client for user info, directory list, search, file metadata, and download link resolution.
- Added fixed Baidu host allowlists for API/download URLs.
- Added remote media provider integration so Baidu files can be selected into the CMS media library as external references.
- Added controlled CMS media route `/baidu-storage/media/{id}` so browser-facing URLs never expose Baidu access tokens.
- Genericized Core media admin/picker UI to enumerate all registered remote providers instead of only showing Cloudreve.
- Registered `official.storage.baidu` in the official bundled plugin registry.

## Deferred

- Upload, delete, and move are intentionally disabled in RC1 with friendly errors.
- Large file streaming should be improved before using Baidu Netdisk as a heavy video origin; current RC path caps proxied downloads at 50 MB.
- Real OAuth and live Baidu file browsing require real App Key/Secret Key to be entered in admin and were not completed in this coding pass.

