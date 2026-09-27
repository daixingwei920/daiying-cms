# Plugin SDK Foundation Implementation Report

Date: 2026-09-26

Status: Foundation candidate complete. Not released to production.

## Baseline

- Authoritative source: clean clone at `/Users/xingweidai/Documents/Codex/2026-09-25/files-mentioned-by-the-user-daiying-2/work/daiying-cms-sdk-foundation`
- Remote: `https://github.com/daixingwei920/daiying-cms.git`
- Baseline tag: `v1.2.69`
- Baseline commit: `14ed43d467ad98533e0bcf419a1386ce1b223be8`
- Baseline version: `1.2.69`
- Current candidate state: `v1.2.69-dirty` because this Foundation implementation is uncommitted
- Target internal candidate: Core `1.2.70 candidate`

## Core Changes

- `system/core/Http/Request.php`
  - Added immutable `rawBody(): string`.
  - Preserves existing form/json parsed body behavior.
  - Preserves raw body across `withPath()`.

- `system/core/Content/PluginContentService.php`
  - New public plugin content service.
  - Provides `createDraft`, `get`, `list`, `update`, `publish`.
  - Uses existing `ContentRepository`, sanitization, slugging, media validation, and revisions.

- `system/core/Auth/FrontUserService.php`
  - New public front-user service.
  - Provides current/read/credential verification/Core-controlled login/external identity binding.
  - `auth.login` requires both manifest capability and trusted/bundled status.

- `system/migrations/2026_09_26_000001_plugin_sdk_foundation_v1.php`
  - Adds `cms_front_user_external_identities`.
  - Enforces unique `(provider, subject)`.

- `system/core/Plugin/Capability.php`
  - Added `auth.read`, `auth.login`, `auth.external_identity`.

- `system/core/Plugin/PluginContext.php`
  - Added `content()`.
  - Added `frontUsers()`.
  - Added `registerBlockRenderer()`.
  - Added trusted/bundled-only `dispatch(object $event)` for official event producers.

- `system/core/Plugin/BlockRendererRegistry.php`
  - New renderer registry with duplicate ownership guard.

- `system/core/Content/BlockRenderer.php`
  - Invokes registered plugin block renderers.
  - Missing/failed renderers do not fatal.

- `system/core/Auth/FrontUserAuthenticator.php`
  - Accepts optional `EventDispatcher`.
  - Dispatches `FrontUserRegisteredEvent` after registration.

- `system/core/Auth/FrontUserRegisteredEvent.php`
  - New auth event.

- `system/core/Auth/FrontUserLoggedInEvent.php`
  - New auth event.

- `system/core/Comment/CommentRepository.php`
  - Accepts optional `EventDispatcher`.
  - Dispatches `CommentCreatedEvent` after persistence.

- `system/core/Comment/CommentCreatedEvent.php`
  - New comment event.

- `content/plugins/official.commerce/src/Events/OrderPaidEvent.php`
  - New official Commerce paid event.

- `content/plugins/official.commerce/src/CommerceRepository.php`
  - Dispatches `OrderPaidEvent` only after `pending_payment -> paid` transition.
  - Repeated `markOrderPaid()` on paid/fulfilled orders remains no-op and does not redispatch.

- `content/plugins/official.commerce/plugin.php`
  - Wires trusted context dispatch into Commerce repository.

- `system/core/Support/PublicApiRegistry.php`
  - Registers Plugin Content, Front User, Request, and Block Renderer contracts.

- `system/core-manifest.json`
  - Regenerated so Core manifest hashes match candidate Core files.

## Added Public APIs

```php
Cms\Core\Http\Request::rawBody(): string
```

```php
Cms\Core\Plugin\PluginContext::content(): Cms\Core\Content\PluginContentService
Cms\Core\Plugin\PluginContext::frontUsers(): Cms\Core\Auth\FrontUserService
Cms\Core\Plugin\PluginContext::registerBlockRenderer(string $type, callable $renderer): void
Cms\Core\Plugin\PluginContext::dispatch(object $event): void
```

```php
Cms\Core\Content\PluginContentService::createDraft(array $draft): array
Cms\Core\Content\PluginContentService::get(int $contentId): ?array
Cms\Core\Content\PluginContentService::list(array $query = []): array
Cms\Core\Content\PluginContentService::update(int $contentId, array $patch): array
Cms\Core\Content\PluginContentService::publish(int $contentId): array
```

```php
Cms\Core\Auth\FrontUserService::current(): ?array
Cms\Core\Auth\FrontUserService::find(int $userId): ?array
Cms\Core\Auth\FrontUserService::findByEmail(string $email): ?array
Cms\Core\Auth\FrontUserService::verifyCredentials(string $email, string $password, array $context = []): ?array
Cms\Core\Auth\FrontUserService::loginById(int $userId, array $context = []): void
Cms\Core\Auth\FrontUserService::findByExternalIdentity(string $provider, string $subject): ?array
Cms\Core\Auth\FrontUserService::bindExternalIdentity(int $userId, string $provider, string $subject, array $profile = []): void
Cms\Core\Auth\FrontUserService::unbindExternalIdentity(int $userId, string $provider, string $subject): void
```

## Added Events

- `Cms\Core\Auth\FrontUserRegisteredEvent`
- `Cms\Core\Auth\FrontUserLoggedInEvent`
- `Cms\Core\Comment\CommentCreatedEvent`
- `Daiying\Commerce\Events\OrderPaidEvent`

Existing documented event:

- `Cms\Core\Content\ContentPublishedEvent`

## Added Capabilities

- `auth.read`
- `auth.login`
- `auth.external_identity`

Canonical scheduler capability remains:

- `scheduler.register`

Backward-compatible deprecated alias remains:

- `cron.register`

## Docs

Completed five-piece SDK set:

- `DAIYING_PLUGIN_DEVELOPMENT_SPEC_V1.md`
- `DAIYING_PLUGIN_API_REFERENCE_V1.md`
- `DAIYING_EVENT_REGISTRY_V1.md`
- `DAIYING_CAPABILITY_REGISTRY_V1.md`
- `DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/`

Additional follow-up docs:

- `DEFERRED_PLUGIN_SDK_ROADMAP.md`
- `EVENT_EXCEPTION_ISOLATION_FOLLOWUP.md`

## Tests

Added:

- `tests/plugin_sdk_foundation_v1.php`
- `DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/tests/skeleton_contract.php`

Updated:

- `tests/foundation_public_api_storage_v1.php`

Final verification passed:

```text
php -l system/core/Http/Request.php
php -l system/core/Plugin/PluginContext.php
php -l system/core/Plugin/PluginManager.php
php -l system/core/Support/PublicApiRegistry.php
php -l system/core/Content/PluginContentService.php
php -l system/core/Auth/FrontUserService.php
php -l system/core/Comment/CommentRepository.php
php -l content/plugins/official.commerce/src/CommerceRepository.php
php tests/plugin_sdk_foundation_v1.php
php DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/tests/skeleton_contract.php
php tests/content_foundation_safety.php
php tests/foundation_system_services.php
php tests/foundation_public_api_storage_v1.php
php tests/commerce_v1_contract.php
```

Covered:

- Raw body for JSON/XML/form/empty/binary unknown content type.
- Parsed body and raw body available together.
- Content capability-negative and happy paths.
- Draft creation for article/page.
- Invalid type and invalid block rejection.
- Slug normalization.
- Content update/publish/get/list.
- Auth credential verification.
- `auth.login` trusted/bundled gate.
- External identity collision.
- External identity capability-negative.
- Core session establishment through FrontUserService.
- Block renderer registration, duplicate ownership guard, missing renderer safety.
- Comment created event.
- Auth login event.
- Commerce paid event exactly once on transition.
- Public API registry entries.
- Skeleton route/scheduler/block/raw-body contract.
- Existing content/system/storage/commerce regressions.

Readiness note:

- `php scripts/validate_production_readiness.php .` reports Core manifest integrity PASS.
- Overall readiness is `not_ready` in this local checkout due local config/storage/installed-lock conditions, not due SDK Foundation code.

## Backward Compatibility

- Existing `Request` constructor calls remain compatible because `rawBody` is appended with default `''`.
- Existing form/json parsing is unchanged.
- Existing route registration is unchanged.
- Existing scheduler API is unchanged.
- `cron.register` remains accepted as compatibility alias.
- Secret Store data format unchanged.
- PluginDataStore data format unchanged.
- Direct PDO restriction remains unchanged.
- Existing official Commerce constructors using two args remain compatible; third event-dispatch arg is optional.
- Existing `FrontUserAuthenticator` and `CommentRepository` constructors remain compatible; event dispatcher args are optional.

## Deferred

- License Service / `PluginContext::license()`.
- Queue SDK expansion.
- Outbound Webhook SDK expansion.
- Recovery Mode plugin APIs.
- Dependency Management.
- Developer CLI.
- Automated Validator.
- Refund events.
- Cancellation events.
- Order-created events.
- Event exception isolation hardening.
- PluginDataStore `latest()` / `getLatest()`.
- Structured table service for `api` trust plugins.

## Blockers

No P0 implementation blocker remains for this Foundation candidate.

Known deferred risk:

- Event listener exceptions still escape. This is documented in `EVENT_EXCEPTION_ISOLATION_FOLLOWUP.md` because changing it silently would be a behavior change.

## Stop Gate

This phase stops here.

Not done:

- No `official.wechat` changes.
- No AI Writer changes.
- No Membership changes.
- No business plugin adaptation.
- No production release.
- No `1.2.70` formal version bump.
- No commit or push.

