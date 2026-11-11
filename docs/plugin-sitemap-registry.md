# Plugin sitemap registration — isolated candidate

Owner request: provide one generic sitemap registration interface. This candidate starts from clean official main `05d0f951bfce396deb2e19b0796a06bbe8484811` (source configuration 1.2.97) on 2026-11-11. It is not a published version, signed package, marketplace update or production deployment.

## Interface and behavior

`PluginContext::registerSitemap(string $path): void` requires the existing `seo.extend` capability. Register an owned public GET XML route first, then announce it. See the complete SDK example and constraints in [API Reference V1](../DAIYING_PLUGIN_API_REFERENCE_V1.md#sitemap-discovery-registration-unreleased-candidate).

Core accepts only fixed application-relative ASCII `.xml` routes, without queries, fragments, percent encoding, templates, traversal or private prefixes. The registered GET route must belong to that plugin and must have no administrator, route capability or CSRF restriction. The route still runs through existing Core plugin guards when requested. Repeated announcements deduplicate; registration is bounded to 32 per owner and 512 overall. Root `/sitemap.xml` and `/robots.txt` are reserved against plugin route takeover.

When at least one healthy enabled plugin announces a map and global indexing is allowed:

- `/sitemap.xml` becomes a sitemap index: the original dynamic Core map at `/sitemap.xml?core=1`, then plugin maps sorted by path.
- The `core=1` child always stays a Core `urlset`, never a recursive index.
- `robots.txt` retains existing rules, including AI-agent policies, then appends each plugin map declaration once.
- URLs use configured site origin and base path, never the request Host. Subdirectory paths are prefixed once.

With no eligible maps, root discovery keeps the previous Core behavior. Global noindex returns an empty Core map and suppresses plugin announcements. Disabled/missing plugins, failed bootstrap registrations and revoked SEO capabilities are omitted. An unavailable lifecycle store hides announcements instead of guessing. No tables, migrations, new permissions, credentials, network requests, child-map fetches, writes to plugin data or model/payment calls are introduced.

This API announces locations only. A child handler is not executed during root discovery. The plugin remains responsible for a valid child urlset (not a nested sitemap index), canonical URLs, public-only data, site readiness and protocol limits (including splitting large child maps). Registration is not search-engine submission or proof of indexing. Changing global robots guidance does not replace authentication.

## Compatibility and rollout

Existing three-argument `ContentFrontController` construction and no-argument `sitemap()` calls remain supported. Existing plugins need not change. An adapter can declare optional `seo.extend` and use `method_exists` plus `hasCapability` to preserve older Core compatibility. There is no hard-coded business plugin identity in Core.

The independent novel adapter is based on its frozen 1.0.2 source `0c770a993d08312dc0aeab8545865c03b9a5d265`, outside this repository at `../novel-sitemap-registry`. It announces only canonical `/novels-sitemap.xml`; `/novels/sitemap.xml` remains an alias. Its SEO panel describes automatic discovery or manual fallback as applicable. The adapter is an unversioned, unsigned source candidate; the released 1.0.2 package remains unchanged. Native package upgrade, payment-scope reauthorization if required by native Core evaluation, and signed compatibility acceptance are separate release tasks, not implied by SDK fixture success.

The novel site's already deployed Apache fallback remains unchanged. Only a separately approved, backed-up Core/plugin upgrade may replace it. Keep that fallback until native discovery is verified, then retire conflicting rewrites/substitution in the same reviewed deployment; otherwise the static index may mask Core output or robots substitution may duplicate a declaration. This task does not touch production configuration.

## Local evidence and release boundary

- `php tests/plugin_sitemap_registry.php`: 73 checks covering capability/ownership, invalid inputs, private/admin/CSRF routes, limits, real PluginManager bootstrap failure/dependencies, route dispatch, index/Core XML, lifecycle changes, noindex, robots byte preservation, Host and subdirectory handling. The fixture uses synthetic SQLite data and real Core services; it is not a native installer/upgrade acceptance test.
- Existing sitemap, SEO/GEO, SDK foundation, trust/capabilities, frontend extension, homepage and scheduler lifecycle regressions passed. Raw logs and counted checks are in the workspace `outputs/core-sitemap-registry-20261111/` report, outside Core payloads.
- Independent novel adapter: real PluginManager + Library + Publication review/schedule + Core Router fixture; candidate Core and old-Core fallback tested. Confirms public-only child XML, original alias, SEO guidance, unchanged settings/5-yuan price and retained private draft. No model or payment requests.
- Changed PHP lint and `git diff --check` are required before handoff. Local runtime is PHP 8.5; PHP 8.3, MySQL, native signed installation/update/rollback and full formal Release Gate are not run in this task.
- `tests/release_gate_v1_contract.php` invokes `sodium_crypto_sign_keypair()` (line 29). Per Owner signing policy it is **NOT RUN on the workstation**. Formal key-generating tests run only on the authorized update server after approval; no test or security guard was bypassed.

Running existing migrations with a Core-source CMS_ROOT regenerated `system/official-plugins.php` with trailing spaces; its artifact was preserved outside the repository and exact HEAD bytes restored. The new Core/novel tests now define CMS_ROOT as their disposable fixture and copy the registry before migration; the source registry remains byte-identical. That unrelated generated file is excluded from this patch.

## Knowledge synchronization status

SDK method/capability documentation, public API catalog, SDK/docs indexes and this candidate evidence are updated. [Knowledge reconciliation](knowledge/2026-11-11-plugin-sitemap-registry.md) records the published/candidate distinction.

The governed V1 JSON and its derived exports remain pinned to published 1.2.96; this candidate does not promote an unreleased API into customer-service facts. After retrieving the exact existing tag, both `python3 scripts/product_knowledge.py --write` and the read-only check stop at `source drift: config/app.example.php`: current official main already reports 1.2.97, while those facts remain 1.2.96. This mismatch exists in the clean parent too; it is not treated as PASS or repaired by weakening provenance. Candidate development documentation is complete; a separately scoped release reconciliation must refresh governed facts from the eventual immutable release source. No knowledge consumer import, index rebuild, deployment or automatic sync occurred.
