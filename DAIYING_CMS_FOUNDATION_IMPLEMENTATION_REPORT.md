# Daiying CMS Foundation Implementation Report

## Baseline

- Core version: `1.2.29`
- Current implementation commit: `ef8562d6396df28655cd7fbe2fe62ad12cda9c52`
- PHP minimum: `8.3.0`
- Database targets: SQLite and MySQL-compatible PDO deployments
- Public API versions: Core `1.0`, Plugin `1.0`, Theme `1.0`, Storage `1.0`, REST `1.0`, Update Protocol `1.0`
- Production site and official update server were not modified in this Foundation implementation pass.

## Implemented Foundation Areas

### Release Parity

Implemented exact-commit installer/update package parity checks and release gate tests. The gate verifies package SHA-256, manifest parity, signature sidecars, update package metadata, and tamper rejection.

### Public API v1

Implemented a machine-readable public contract registry:

- `Cms\Core\Support\PublicApiRegistry`
- `Cms\Core\Foundation\FoundationVersions`
- `CORE_API_STABILITY.md`
- `PLUGIN_API_V1.md`

Plugins now have documented stable access boundaries for Core services.

### Storage Provider API v1

Implemented versioned local and remote media provider contracts and capability metadata for upload, read, delete, metadata, URLs, stream/proxy, byte range, and connection testing.

### AI Foundation

Implemented site-level AI settings and public service access. Core uses a shared OpenAI-compatible adapter for compatible providers and a separate Gemini adapter for Gemini native API. Official update-server AI review remains isolated.

### Mail Foundation

Implemented outbound mail infrastructure:

- mail service
- provider interface and registry
- SMTP and PHP mail baseline providers
- templates
- queue records
- events
- encrypted SMTP password persistence
- admin settings and test connection

Core intentionally does not implement webmail, IMAP inbox, Gmail UI, Outlook UI, or AI mail assistant features.

### Role, Queue, Cache, Webhook, Scheduler, Logging, Health

Implemented shared Foundation services so plugins can stop duplicating common infrastructure:

- role/capability registry and service
- retryable queue service
- scheduler service
- file and array cache providers
- webhook events, endpoints, signatures, and queued deliveries
- secret redaction for logs/errors
- file logger rotation and cleanup
- system health checks for foundation tables, sitemap/robots routes, queue, scheduler, writable paths, and update prerequisites

### Content And Theme Safety

Implemented content revisions, autosave, trash semantics, content type registration, custom fields, searchable resources, SEO extension registries, Theme API v1 helpers, template view models, and theme asset path traversal protection.

### REST API v1

Expanded REST API v1 to provide public reads and protected administrator write operations for content, pages, categories, tags, comments, settings, users, and media listing. Binary media upload remains intentionally reserved until the upload contract is finalized.

## Boundary Decisions

- Distribution remains a content/product external distribution layer and is not the plugin marketplace, license delivery, update system, or capability-pack system.
- Commerce remains responsible for product, price, order, payment, and fulfillment flows.
- Marketplace/license/card delivery/update infrastructure remains separate from Distribution.
- Official update server internals remain private and must not enter public Core packages.

## Modified Foundation Documents

- `CORE_API_STABILITY.md`
- `PLUGIN_API_V1.md`
- `REST_API_V1.md`
- `STORAGE_PROVIDER_API_V1.md`
- `THEME_API_V1.md`
- `docs/ai.md`
- `docs/content-foundation.md`
- `docs/foundation-services.md`
- `docs/mail-infrastructure.md`
- `DAIYING_CMS_FOUNDATION_BOUNDARY_REPORT.md`

## Current Foundation Status

Foundation is substantially implemented, but final Foundation Freeze should wait
for the remaining full cross-version package/browser upgrade matrix.

## Local RC Artifacts

Local-only Foundation RC artifacts were built from the exact commit above. They
were not published to the official update server.

- Installer ZIP: `outputs/foundation-rc-ef8562d6396d/installer/daiying-cms-1.2.29-foundation-rc-exact-ef8562d6396d.zip`
- Installer SHA-256: `43e317e748da9d71eee5d539e1961fbd775e7baac85511ddf4d4af6f7778e194`
- Update ZIP: `outputs/foundation-rc-ef8562d6396d/update/daiying-cms-core-update-1.2.29-foundation-rc-exact-ef8562d6396d.zip`
- Update SHA-256: `f04f8a41f4c3c5ddaf3d073454a44a8ebeb8ffd57ec7cfc09f14a68777fb1ea4`
- Release parity gate: PASS

Latest local old-updater execute package:

- Commit: `5fe4b65653f7f3a6554ab13712027088995accb5`
- Update ZIP: `outputs/foundation-exec-compat-5fe4b65653f7/update/daiying-cms-core-update-1.2.29-foundation-exec-exact-5fe4b65653f7.zip`
- Update SHA-256: `a471365a59e21d8a6b368ad269194ae28ec514d1e46d3982566af1f6c355c5aa`
- Changed files: 313
- Required migrations: 37
- `v1.2.19` old-updater execute: PASS
- `v1.2.24` old-updater execute: PASS
