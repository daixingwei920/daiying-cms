# Daiying CMS V1.2 Spec Gap Matrix

Source: `PHP_CMS_V1_2_Codex_Technical_Spec.docx`

Generated from a code-level audit of routes, migrations, services, controllers, UI and tests. This matrix is intentionally requirement-oriented; release ZIP/SHA validation is deferred until V1.2 scope is functionally closed.

Status legend: `DONE`, `PARTIAL`, `MISSING`, `IMPLEMENTED_BUT_UNTESTED`, `BLOCKED_BY_EXTERNAL_SERVICE`, `OUT_OF_SCOPE_BY_SPEC`.

| ID | Spec Area | Requirement | Status | Evidence / Gap |
| --- | --- | --- | --- | --- |
| V12-CORE-001 | Constitution | Core cannot be modified by third-party themes/plugins | DONE | `CoreBoundary`, package scanners, plugin installer ownership checks, update signing chain |
| V12-CORE-002 | Constitution | Public API stability and compatibility bias | DONE | `PublicApiRegistry` declares the V1.2 public API contract version, stable extension-facing service contracts and capability boundaries; regression coverage verifies public Core APIs are listed while internal admin controllers remain excluded |
| V12-CORE-003 | Constitution | Theme = UI, Plugin = Function | DONE | Theme runtime and plugin runtime separated under `Theme/*` and `Plugin/*` |
| V12-CORE-004 | Constitution | Plugin/theme failure must be recoverable | DONE | Safe Mode, Recovery Mode, fallback theme, plugin quarantine paths |
| V12-CORE-005 | Constitution | Extension data must not be lost when extension disappears | DONE | Block fallback, dormant plugin data/data retention behavior |
| V12-CORE-006 | Constitution | Market is not Core runtime dependency | DONE | Market/MarketServer are packaged as optional V1.2 platform services; runtime storage remains excluded and customer content rendering does not depend on Market |
| P0-001 | Phase 0 | Bootstrap/config/error/log/db/migration/router/Core boundary | DONE | `Bootstrap/Application.php`, `Settings`, `ErrorHandler`, `FileLogger`, `MigrationRunner`, `Router`, `CoreBoundary` |
| P1-001 | Phase 1 | Visual install full chain | DONE | `InstallController`, install E2E tests |
| P1-002 | Phase 1 | Admin auth/session/CSRF/rate-limit/audit | DONE | `AdminAuthenticator`, `SessionManager`, `CsrfToken`, `AuditLogger`, admin login tests |
| P1-003 | Phase 1 | TOTP/recovery-code MFA runtime | DONE | `AdminMfaService`, `/admin/mfa`, `/admin/security`, `tests/admin_login_security.php` |
| P2-001 | Phase 2 | Article/Page/Content Type API/status/slug/time | DONE | `ContentRepository`, `ContentTypeRegistry`, front controller tests |
| P2-002 | Phase 2 | Taxonomy/Terms/Metadata/Blocks | DONE | `TaxonomyRepository`, block sanitizer/renderer, content tests |
| P2-003 | Phase 2 | Editor blocks: text, media, video, audio, attachment, embed basics | DONE | Block sanitizer/renderer, media tests |
| P2-004 | Phase 2 | Unified Media/Storage abstraction and local media IDs | DONE | `MediaStorageProviderInterface` and `LocalMediaStorageProvider` keep stable `/media/{id}` local IDs while routing ingest, read/existence checks and hard delete through a pluggable storage boundary; media regression covers provider-backed writes, stable media URL resolution and provider-mediated delete |
| P2-005 | Phase 2 | WebP/AVIF, EXIF privacy, advanced thumbnails | DONE | WebP and AVIF are accepted for upload/import; supported images are re-encoded before storage to strip private metadata, WebP thumbnail/small derivatives are generated behind stable `/media/{id}?variant=...` URLs, and provider-backed hard delete removes generated derivatives |
| P3-001 | Phase 3 | Theme manifest/settings/fallback/safe theme | DONE | `ThemeManifest`, `ThemeManager`, `ThemeRuntime`, safe fallback tests |
| P3-002 | Phase 3 | Theme recommendation without silent install | DONE | Theme compatibility/recommended plugins handled as metadata/UI |
| P4-001 | Phase 4 | Plugin manifest/lifecycle/capability/API/block extension | DONE | `PluginManifest`, `PluginLifecycle`, `Capability`, `BlockRegistry`, plugin lifecycle tests |
| P4-002 | Phase 4 | Plugin data boundary and Core-table ownership | DONE | `PluginDataStore`, `PluginTableOwnership`, installer scan tests |
| P4-003 | Phase 4 | Restricted API vs trusted PHP risk boundary | DONE | `PluginRiskBoundaryPolicy` explicitly separates restricted API plugins from official reviewed trusted PHP plugins; local ZIP previews show the risk boundary, local trusted-PHP claims are blocked, and readiness tests document that CMS does not claim a hard PHP sandbox |
| P5-001 | Phase 5 | Safe Mode/Recovery Mode/Core integrity | DONE | `RunMode`, `RecoveryController`, `IntegrityChecker`, recovery tests |
| P5-002 | Phase 5 | Restore point/backup before updates | DONE | `RestorePointService`, update lifecycle tests |
| P5-003 | Phase 5 | Full site backup including market extensions | DONE | Restore points include config, content/plugins, content/themes, uploads, Core, migrations, database and an explicit `extensions/market-extensions.json` manifest with Market source/install-log/latest-installed metadata; runtime Market cache remains excluded by policy, and production readiness tests verify the backup boundary |
| P6-001 | Phase 6 | Official export package with manifest/content/media/url-map/checksums | DONE | `ExportPackageBuilder`, `ExportPackageReader`, import/export tests |
| P6-002 | Phase 6 | Export schema migration chain | DONE | `ExportSchemaMigrator` |
| P6-003 | Phase 6 | Z-BlogPHP and WordPress importers | DONE | `ZBlogImporter`, `WordPressImporter`, import tests |
| P6-004 | Phase 6 | Typecho/Ghost/Movable/Markdown/RSS/CSV future importers | OUT_OF_SCOPE_BY_SPEC | Spec says gradually support; V1.2 primary chain is Z-Blog/WordPress |
| P6-005 | Phase 6 | Remote image localization and SSRF safety | DONE | `RemoteImageLocalizer`, media tests |
| P6-006 | Phase 6 | URL Mapping and 301 safety | DONE | `UrlMappingRepository`, content tests |
| P7-001 | Phase 7 | Signed Core update, manual package, restore point, migration, health, atomic switch, rollback | DONE | `UpdateService`, `SignatureVerifier`, update lifecycle tests |
| P7-002 | Phase 7 | Official update server client | DONE | `UpdateServerClient` queries the official update manifest with current version/channel/site ID, requires HTTPS except loopback, downloads packages into incoming storage, verifies SHA-256 and leaves signed package execution to `UpdateService`; readiness tests cover manifest query, download, missing URL and hash mismatch |
| P8-001 | Phase 8 | Admin Theme/Plugin Market client | DONE | `Market/*` and admin routes exist; V1.2 package security now verifies Market client/server source inclusion |
| P8-002 | Phase 8 | Purchase-authorized install and source marking | DONE | `InstallAuthorization`, `MarketPackageInstaller`, extension source records and package inclusion tests |
| P8-003 | Phase 8 | Third-party ZIP upload rules and risk marking | DONE | Local plugin/theme installers, risk labels and scan tests |
| DEV-001 | V1.1 Dev Center | Developer mode, identity binding, my apps, projects, versions | DONE | Site settings persist `market.enabled` and `market.developer_mode`; admin dashboard and developer routes hide Developer Center unless developer mode is enabled; content tests cover both states |
| DEV-002 | V1.1 Dev Center | Local testing and release package generation | DONE | Developer Center project page can generate a standard Market Package from local plugin/theme source, verify manifest hashes, and submit the generated ZIP into the review queue; dedicated test covers symlink rejection |
| DEV-003 | V1.1 Dev Center | Submit review, review history, sales/settlement boundary | DONE | Project detail page exposes review history plus project-level paid order/settlement summary; raw payment/settlement administration remains admin-only; test covers generated package submission, manual review history and developer boundary |
| MKT9-001 | Phase 9 Market Server | Developer/project/version repositories and isolated submissions | DONE | Repository and controller tests cover developer/project/version creation, generated package submission, own-project permission and cross-project submission rejection |
| MKT9-002 | Phase 9 Market Server | Automatic scan queue and review state machine | DONE | `processScanQueue()` processes bounded Submitted versions in order, records pass/fail scan jobs, preserves findings, and Review Admin exposes a CSRF-protected queue action |
| MKT9-003 | Phase 9 Market Server | Freeze, sign and distribute Market Package | DONE | Publish now verifies the package hash still matches the submitted hash before signing; tests cover signed publish, duplicate publish rejection, authorized download audit and tampered package rejection |
| MKT10-001 | Phase 10 Review Admin | Queue, manifest, permission, scan report, sandbox report, diff, history, risk marks | DONE | Review queue has detail links; detail panel renders package manifest, file hashes, scan findings, sandbox execution policy, version diff, human review history and AI risk markers with regression coverage |
| MKT10-002 | Phase 10 Review Admin | Roles, audit, approve/return/reject/suspend/remove | DONE | Review Admin now exposes approve, return-to-fix, reject, publish, suspend/unpublish and remove/deprecate actions with admin role checks, CSRF guards, state transitions and review audit evidence regression coverage |
| AI-001 | V1.2 AI Review | AI Provider interface and Mock Provider | DONE | `AiReviewClientInterface`, `MockAiReviewClient`, module test |
| AI-002 | V1.2 AI Review | ChatGPT/OpenAI structured rule review adapter | DONE | `OpenAiStructuredReviewClient` builds OpenAI Responses API structured-output requests with strict JSON Schema, keeps API keys in Authorization headers only, and parses `output_text` evidence into Daiying's review schema; real credentials remain separately tracked in AI-011 |
| AI-003 | V1.2 AI Review | Fixed JSON Schema validation | DONE | `AiReviewOrchestrator::normalizeEvidence`, module test |
| AI-004 | V1.2 AI Review | Codex code review abstraction | DONE | `codex_code` review task path via same provider abstraction; high-risk test covers task creation |
| AI-005 | V1.2 AI Review | AI/Codex review state machine states | DONE | `ReviewState`, `ReviewStateMachine` updated with required states |
| AI-006 | V1.2 AI Review | Evidence storage, risk aggregation, request ID, model metadata | DONE | `cms_market_ai_review_tasks`, `cms_market_ai_review_evidence`, repository aggregate methods |
| AI-007 | V1.2 AI Review | Retry/failure/manual fallback | DONE | AI failed states and UI retry actions; failed model never approves |
| AI-008 | V1.2 AI Review | Review Admin AI evidence panel | DONE | `/admin/market-server/review?evidence_version_id=...` panel |
| AI-009 | V1.2 AI Review | Prompt-injection and secret isolation boundaries | DONE | Payload uses fixed system instructions and summaries; no platform secrets included in tasks/evidence |
| AI-010 | V1.2 AI Review | AI must not auto approve/publish | DONE | `AiReviewPolicy` is evidence-only; UI removed auto-approve; module test covers legacy auto_approve flag |
| AI-011 | V1.2 AI Review | Real OpenAI/Codex API credentials | BLOCKED_BY_EXTERNAL_SERVICE | Mark as `EXTERNAL_CREDENTIAL_PENDING`; architecture allows provider adapter without customer CMS secrets |

Current Summary:

- Total Spec Requirements Tracked: 53
- DONE: 51
- PARTIAL: 0
- MISSING: 0
- IMPLEMENTED_BUT_UNTESTED: 0
- BLOCKED_BY_EXTERNAL_SERVICE: 1
- OUT_OF_SCOPE_BY_SPEC: 1

Immediate Development Queue:

1. Keep AI-011 blocked until real external credentials are provided.
2. Run final release validation for the fully closed V1.2 code scope.
