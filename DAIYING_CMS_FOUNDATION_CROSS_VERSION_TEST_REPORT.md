# Daiying CMS Foundation Cross-Version Test Report

## Scope

This report records the cross-version checks completed during Foundation work and the remaining freeze blockers.

No production site was modified. No official update-server package was published.

## Baseline

- Current Core version: `1.2.29`
- Current implementation commit: `ef8562d6396df28655cd7fbe2fe62ad12cda9c52`
- Required migration floor for official packages: `1.2.0`
- Required hard minimum version for official packages: `1.2.0`
- PHP minimum: `8.3.0`

## Completed Automated Checks

The following local tests passed during Foundation implementation:

- `php tests/release_phase0_gate.php`
- `php tests/foundation_public_api_storage_v1.php`
- `php tests/core_mail_infrastructure.php`
- `php tests/core_ai_settings.php`
- `php tests/foundation_system_services.php`
- `php tests/theme_api_v1.php`
- `php tests/content_foundation_safety.php`
- `php tests/rest_api_v1.php`
- `php tests/system_health_foundation.php`
- `php tests/foundation_boundary_contract.php`
- `php tests/commerce_v1_contract.php`
- `php tests/baidu_storage_provider_contract.php`

## Local Package Verification

Built local-only Foundation RC artifacts from exact commit
`ef8562d6396df28655cd7fbe2fe62ad12cda9c52`:

- Installer ZIP SHA-256: `43e317e748da9d71eee5d539e1961fbd775e7baac85511ddf4d4af6f7778e194`
- Update ZIP SHA-256: `f04f8a41f4c3c5ddaf3d073454a44a8ebeb8ffd57ec7cfc09f14a68777fb1ea4`
- `php scripts/release_parity_gate.php --commit=HEAD --installer-zip=... --update-zip=... --update-metadata=...`: PASS

The update metadata retained:

- `min_upgrade_from`: `1.2.0`
- `hard_min_version`: `1.2.0`
- `migration_floor`: `1.2.0`

## Local Fresh Install Smoke

The exact-commit installer ZIP was extracted into a temporary directory and
tested through PHP's built-in web server using the real HTTP install flow:

- `GET /install`: PASS
- CSRF token extraction: PASS
- `POST /install` with SQLite database settings: PASS
- Redirect to `/admin/login`: PASS
- `GET /health`: PASS, version `1.2.29`
- `GET /admin/login`: PASS
- `php scripts/validate_production_readiness.php --json`: completed and returned 68 checks

Temporary test data was deleted after the smoke test.

## Local Tagged Upgrade Smoke

Available local and remote tags were checked. The repository currently exposes
`v1.2.19` and `v1.2.24` tags for the requested historical range. No `1.2.0` or
`1.2.22` tag was found in the local or origin tag list during this pass.

Two old-version temporary sites were created from exact-commit installer
packages, installed through the real HTTP `/install` flow, overlaid with the
local Foundation RC Core update files, and then migrated with the current
MigrationRunner:

| Source | Commit | Old Install | Migrations Applied | Pointer Health | Foundation Tables |
| --- | --- | --- | --- | --- | --- |
| `v1.2.19` | `b9e1bb9fc873fb064e9b9fb6cae5ff5c7f444a07` | PASS | 7 | `1.2.29` PASS | PASS |
| `v1.2.24` | `02eb142c8dd1e4100b5f15f1784e1a9319c4ff34` | PASS | 7 | `1.2.29` PASS | PASS |

Verified Foundation tables after migration:

- `cms_core_ai_settings`
- `cms_core_mail_settings`
- `cms_core_queue_jobs`
- `cms_core_scheduled_tasks`
- `cms_content_revisions`

These were local smoke tests. They did not publish to the official update server
and did not modify production.

## Local Tagged Data Upgrade Smoke

The same available historical tags were also tested with seeded legacy data
before applying current Foundation migrations:

- published article
- published page
- category and tag relationships
- local media record and uploaded file
- enabled bundled plugin record
- manual payment provider settings

| Source | Content | Taxonomy | Media | Plugin State | Payment Config | AI/Mail Defaults | Queue/Scheduler Tables |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `v1.2.19` | PASS | PASS | PASS | PASS | PASS | PASS | PASS |
| `v1.2.24` | PASS | PASS | PASS | PASS | PASS | PASS | PASS |

Both temporary upgraded sites returned `/health` version `1.2.29` after the
local current-release pointer was written. These tests validate migration/data
persistence behavior, but they are still not a substitute for the final official
updater UI/browser matrix.

## Upgrade Compatibility Coverage

Implemented migrations are idempotent and provide safe defaults when older sites
do not have Foundation tables or settings:

- AI settings and provider presets
- Mail settings, templates, queue, and events
- Queue, cache, webhook, role/capability, and scheduler tables
- Content revisions, autosave, trash fields, custom field metadata, and search/SEO registries
- Official plugin registry migration

Foundation services are exposed through public APIs instead of requiring plugins
to read new private tables directly.

## Required Full Matrix Before Foundation Freeze

The following end-to-end matrix still needs to be run against packaged upgrade
artifacts before declaring Core Foundation Freeze:

- `1.2.0` empty site -> current; fixture/tag not available in this pass
- `1.2.0` site with articles/pages/media -> current
- `1.2.19` empty-site tagged upgrade smoke -> current: PASS
- `1.2.19` site with content/media/plugin/payment fixture -> current: PASS
- `1.2.19` site with plugin/theme browser workflow -> current
- `1.2.22` site with payment configuration -> current; fixture/tag not available in this pass
- `1.2.24` empty-site tagged upgrade smoke -> current: PASS
- `1.2.24` site with content/media/plugin/payment fixture -> current: PASS
- `1.2.24` site with Commerce data -> current
- current-minus-one site with AI and mail configuration -> current
- direct multi-version upgrade through the official updater UI
- failed update rollback with existing AI/mail/payment secrets preserved

Validation points:

- content and media references intact
- active plugin/theme state intact
- Commerce product/order/card delivery records intact
- payment provider settings intact
- AI provider/API key/base URL/model retained
- mail provider/password/from settings retained
- administrator login works
- frontend routes work
- update history records the expected package, SHA, migrations, and rollback state

## Current Conclusion

Foundation migrations and local contract tests pass. Full cross-version package
and browser upgrade verification is not complete yet, so Foundation Freeze should
remain pending until that matrix is executed.
