# 2026-11-11 sitemap SDK knowledge reconciliation

Scope: generic, unpublished `PluginContext::registerSitemap` candidate on clean official main `05d0f951bfce396deb2e19b0796a06bbe8484811`; source version 1.2.97. No release/version change or production operation.

Updated the SDK API/capability references, PublicApiRegistry (`seo.sitemaps`, experimental), documentation indexes, AGENTS work boundary and [candidate contract/evidence](../plugin-sitemap-registry.md). Plugin adaptation and its tests remain outside Core source/payload.

Existing governed V1 facts/exports are frozen at published Core1.2.96 / `56ec3de16b7dabec478f277d74b97f7df65ab138`. The validator refuses current working-source divergence at `config/app.example.php`, which already says 1.2.97 on the clean parent. Both `--write` and read-only commands fail closed. Do not label that global check PASS or insert candidate facts into the published customer-service export. Release-source reconciliation remains pending.

Local runtime/fixture results are recorded separately from provenance source review and formal release/native-install acceptance. No production knowledge import, consumer application, indexing or automatic-sync activation.
