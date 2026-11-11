# Core1.2.98 joint release preparation

Owner authorizes one combined release containing the reviewed blue-white command-bridge admin UI and the generic plugin sitemap registration API. This authorization does not upgrade any production website or publish an independent plugin.

## Source and selected UI

The clean integration branch starts at actual main `05d0f951bfce396deb2e19b0796a06bbe8484811` (Core1.2.97). Published1.2.97 artifacts remain immutable. The two frozen runtime patches do not modify the same runtime files.

Selected typography is32px heading,14px body,15px navigation and16px content title, with responsive adjustments retained. Owner accepts the52-shot/35-page isolated gallery. The final source retains approved asset hashes:

- admin.css: `5712604ac88ba46fee56245037ee6bfcea4a9da3339da6bc13aebf08043ce3be`
- admin.js: `790f7365956dc6f375f206cdf0c140bbdae41edb3f83f8b51860e289d1a412e0`

Reuse existing paginated data and native operations. Static CSS only; no additional framework, network font, canvas, polling, timer loop, query or business endpoint. Existing routes, submitted names, capabilities, CSRF, confirmations and update integrity checks remain.

## Generic sitemap API

`PluginContext::registerSitemap(string $path): void` reuses `seo.extend` and a plugin-owned public GET XML route. `seo.sitemaps` remains explicitly experimental. Core publishes only healthy enabled owners, omits failed/disabled/revoked registrations, preserves Core-only map/robots behavior, honors noindex and prefixes subdirectories once. Child handlers are not fetched or executed by discovery. No migration, new permission, private URL or independent plugin payload is added.

The integration test adds explicit disposable-loopback MySQL configuration support with an empty-database guard and native prepared statements; SQLite remains the default. Simply setting an ignored environment variable is not MySQL acceptance. Initial MySQL5.7 execution stopped at the existing >=8.0.13 migration guard; retain that failure and use the existing isolated supported MySQL8 binaries without changing migrations.

## Evidence and pending release gates

The independent source chat verified frozen API runtime and approved UI assets, then ran73 SDK assertions plus13 independent novel adapter checks against combined source (SQLite fixture only). The adapter remains outside Core source/packages.

Server precommit validation uses PHP8.3/8.5 and disposable fixtures, including UI/layout/update regressions, SEO/GEO, SDK foundation and native admin HTTP. MySQL8 Sitemap behavior and server-only Release Gate V1 contract results are recorded in the server precommit report. Complete exact-main preflight/build, signing, package parity, native signed installation/upgrade/rollback/retry and publication verification remain required; this document alone is not a formal Gate PASS.

All key-generating tests and official signing run only on the authorized update server. Tests isolate external networking and mount production webroots read-only. No production site upgrade/freeze, paid model or real payment is performed. Back up distribution and protect existing products/licenses/codes/inventory before authorized publication.

## Knowledge provenance

Inherited main is configured1.2.97 while governed V1 remains pinned to published1.2.96. Reconcile V1 to a real immutable1.2.98 preparation commit before final merge; preserve prior published evidence, keep source review separate from runtime/production/consumer acceptance, and retain strict path/hash/line and eligibility checks. Publication evidence is updated separately after verified release.
