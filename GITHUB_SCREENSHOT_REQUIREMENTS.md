# GitHub Screenshot Requirements

Verified public screenshot assets are tracked under `.github/assets/screenshots/`.

Use real screenshots from the current Daiying CMS UI only. Do not create screenshots for UI that does not exist.

## Recommended Directory

Store future public screenshots under:

```text
.github/assets/screenshots/
```

## Needed Screenshots

| Area | Suggested File | Status | Notes |
| --- | --- | --- | --- |
| Admin dashboard | `.github/assets/screenshots/admin-dashboard.png` | Captured | Login name redacted. |
| Content editor | `.github/assets/screenshots/content-editor.png` | Captured | Uses a clean product overview article. |
| Media library | `.github/assets/screenshots/media-library.png` | Captured | Filtered to image assets to avoid music filenames in the README. |
| Baidu media | `.github/assets/screenshots/baidu-media.png` | Captured, limited | Cropped before personal file rows. |
| Plugin manager | `.github/assets/screenshots/plugin-manager.png` | Captured | Shows installed plugin lifecycle. |
| Theme manager | `.github/assets/screenshots/theme-manager.png` | Captured, not primary | Contains unavailable theme dependency notices. |
| Commerce | `.github/assets/screenshots/commerce.png` | Captured | Cropped to product/inventory area; order details excluded. |
| Distribution | `.github/assets/screenshots/distribution-preview.png` | Not captured | Current live backend did not expose a verified Distribution preview. |
| Online update | `.github/assets/screenshots/online-update.png` | Captured | Shows signed update entry points. |
| Marketplace | `.github/assets/screenshots/marketplace.png` | Captured | Shows plugin marketplace without actual license-code values. |
| Theme marketplace | `.github/assets/screenshots/theme-marketplace.png` | Captured | Shows theme marketplace. |

## Screenshot Rules

- Redact secrets, tokens, site keys, payment credentials, email verification secrets, OAuth credentials, sessions, and private URLs.
- Use a demo site or sanitized test instance.
- Keep browser chrome out unless it provides useful context.
- Prefer PNG for UI screenshots.
