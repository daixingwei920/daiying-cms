# Daiying CMS Baidu Storage Real OAuth Acceptance

Date: 2026-09-05

## Status

Not run.

Plugin version under test: `1.0.0-rc2`.

This report intentionally does not claim a live Baidu OAuth pass. The current coding pass used a fake transport only for automated contract tests.

## Required Live Checklist

1. Install/enable `local.storage.baidu`.
2. Open `/admin/baidu-storage`.
3. Enter the real Baidu App Key and Secret Key.
4. Copy the displayed OAuth callback URL into Baidu Open Platform callback settings.
5. Click “连接百度网盘”.
6. Complete Baidu authorization.
7. Confirm the callback redirects back to `/admin/baidu-storage?connected=1`.
8. Click “测试连接” and confirm Baidu account info returns.
9. Open “浏览网盘” and confirm root listing.
10. Select a file into CMS media library and open `/media/{id}`.

## Acceptance Rule

Only after the above steps complete against the real Baidu API should this report be updated from “Not run” to “Passed”.
