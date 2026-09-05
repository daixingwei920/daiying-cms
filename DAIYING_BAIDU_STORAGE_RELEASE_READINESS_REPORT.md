# Daiying CMS Baidu Storage Release Readiness Report

Date: 2026-09-05

## Version

- Plugin: `official.storage.baidu`
- Version: `1.0.0-rc2`
- Release status: RC, not final public release.

## Ready

- Plugin manifest and official registry entry exist.
- Admin configuration and OAuth entry points exist.
- Safe admin diagnostics and callback-copy UI exist.
- Encrypted secret/token storage exists.
- Access-token refresh lock exists.
- Generic media provider UI integration exists.
- Automated contract and selected regression tests pass.

## Not Ready For Final

- Real Baidu OAuth acceptance has not been run.
- Live Baidu directory browsing and media reference flow have not been verified with a real account.
- Large browser response streaming needs production hardening before video-heavy use.
- Upload/delete/move are intentionally disabled in this RC.

## Recommendation

Use RC2 for local/private acceptance testing only. Do not list it as a stable paid marketplace package until live OAuth, live browse/select, and media open tests pass on a real Daiying CMS site.
