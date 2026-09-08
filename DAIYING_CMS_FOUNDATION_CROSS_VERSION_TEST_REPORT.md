# Daiying CMS Foundation Cross-Version Test Report

## Scope

This report records the cross-version checks completed during Foundation work and the remaining freeze blockers.

No production site was modified. No official update-server package was published.

## Baseline

- Current Core version: `1.2.29`
- Current implementation commit before this report: `a95e78d`
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

- `1.2.0` empty site -> current
- `1.2.0` site with articles/pages/media -> current
- `1.2.19` site with plugins/themes -> current
- `1.2.22` site with payment configuration -> current
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
