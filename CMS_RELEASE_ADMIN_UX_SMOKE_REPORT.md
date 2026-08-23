# CMS Release Admin UX Smoke Report

Date: 2026-08-22

Scope: first-release CMS admin UX smoke evidence.

The later production SEO indexing, admin MFA runtime readiness, Core public API registry, Plugin risk boundary policy, official Core update server client, Provider persistence, content deletion, V1.2 developer-mode gate, Market Developer Package Builder, isolated submissions, scan queue, freeze/sign/distribute, Review Admin evidence/actions, Developer Center review/sales boundary, Market AI Review/OpenAI structured adapter and delivery-manifest hardening passes keep the current artifact manifest audit count aligned at 1256.

Targeted Playwright smoke coverage was rerun on 2026-08-23 with local PHP loopback service enabled:

- `node tests/admin_density_browser_smoke.js`: passed and captured `/admin/content`, `/admin/content/new`, `/admin/card-delivery`, `/admin/payments` and `/admin/payments/providers` screenshots.
- `node tests/payment_provider_browser_smoke.js`: passed Provider browser persistence, PaymentService enabled-provider discovery, Card Delivery checkout, manual capture and fulfillment.
- `node tests/block_editor_card_delivery_browser_smoke.js`: passed Card Delivery block editor selection, product summary refresh, save and frontend checkout rendering.

Full browser regression coverage was also rerun on 2026-08-23:

- `node tests/browser_release_e2e.js`: passed install wizard, login, content blocks, page clean URL, media upload/playback, theme switching, plugin ZIP lifecycle, Core update and recovery diagnostics.
- `node tests/admin_ux_smoke.js`: passed dashboard navigation, content editor, navigation menu, media library, theme, plugin ZIP preflight, Core update controls, recovery diagnostics and sensitive-value checks.
- `node tests/theme_visual_smoke.js`: passed desktop/mobile default theme and safe fallback theme screenshots, non-empty rendering and mobile overflow checks.

Summary: Payment Provider persistence/Card Delivery capture, Card Delivery block editor checkout rendering, full browser release E2E, admin UX smoke and theme visual smoke all passed.

Screenshots are stored under `/Users/xingweidai/Documents/Codex/2026-08-20/du/outputs/admin-density-screenshots`.
