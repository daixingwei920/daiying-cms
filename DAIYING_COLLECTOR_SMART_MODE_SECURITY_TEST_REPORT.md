# Daiying Collector Smart Mode Security Test Report

Date: 2026-09-03

## Result

Status: PASS for P0 video collector changes

## URL Safety

The video collector keeps SSRF protection enabled. It does not disable URL validation for provider API URLs or playback URLs.

Validated behavior:

- Only `http` and `https` URLs are allowed.
- URL userinfo is blocked.
- Localhost and `.localhost` hosts are blocked.
- Private, reserved, link-local, loopback, and metadata IPs are blocked after DNS resolution.
- Trailing-dot hosts are normalized before comparison.
- IDN hosts are normalized to ASCII when PHP intl is available.
- Redirect targets are revalidated before fetching.

Blocked samples:

- `ftp://example.com/a.m3u8`: PASS
- `http://localhost/a.m3u8`: PASS
- `http://169.254.169.254/latest/meta-data`: PASS
- `http://127.0.0.1/evil.m3u8` playback import: PASS, item skipped

## Provider Isolation

Only `official.video-collector` files changed. PayPal, Stripe, Cloudreve, and other providers were not modified.

## Player Safety

HLS and MP4 playback use native `<video controls>`. Embed playback uses an iframe with `sandbox="allow-same-origin allow-presentation"` and `referrerpolicy="no-referrer"`, without allowing untrusted script execution.

## Secret Hygiene

No `.env`, database dumps, production keys, OAuth secrets, payment keys, signing private keys, session files, or logs were added to the package.

## Package Security

- Package path: `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-plugins-market/outputs/official-video-collector-0.2.0-smart-mode.zip`
- SHA256: `6e7e9edaee2ead63d4c446f7469f22cfcd44bb11938f349ad8e8223a29c64ee0`
- Unsafe path scan: PASS
- Manifest ID/version check: PASS
