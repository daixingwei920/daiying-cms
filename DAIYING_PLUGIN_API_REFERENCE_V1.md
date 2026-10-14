# Daiying Plugin API Reference V1

This reference includes the 1.2.70 Plugin SDK and explicitly marked unreleased maintenance APIs. An unreleased API is not available on the published Core until integration and release.

## PluginContext

Since Core 1.2.69 unless noted.

```php
hasCapability(string $capability): bool
listen(string $eventName, callable $listener): void
dispatch(object $event): void // Since Core 1.2.70; trusted/bundled only
registerBlock(string $type, string $label): void
registerBlockRenderer(string $type, callable $renderer): void // Since Core 1.2.70
data(): PluginDataStore
content(): Cms\Core\Content\PluginContentService // Since Core 1.2.70
advertising(): Cms\Core\Plugin\PluginAdvertisingService // Pending 1.2.84 integration
payments(): Cms\Core\Plugin\PluginPaymentService // Pending 1.2.84 integration
frontUsers(): Cms\Core\Auth\FrontUserService // Since Core 1.2.70
license(): Cms\Core\Plugin\PluginLicenseService // Since Core 1.2.70
pdo(): PDO
frontRoute(string $method, string $path, callable $handler, ?string $capability = null, bool $csrf = false): void
adminRoute(string $method, string $path, callable $handler, ?string $capability = null, bool $csrf = true): void
adminMenu(string $label, string $path, ?string $capability = null, array $metadata = []): void
assetUrl(string $relativePath): string
adminStyle(string $relativePath): string
adminScript(string $relativePath, bool $defer = true): string
frontendScript(string $relativePath, string $key = '', array $attributes = []): void
frontendStyle(string $relativePath, string $key = '', array $attributes = []): void
frontendBodyEnd(callable $callback, string $key = ''): void
secrets(): PluginSecretStore
ai(): AiService
mail(): MailService
queue(): QueueService
scheduler(): SchedulerService
cache(): CacheInterface
webhooks(): WebhookService
notifications(): NotificationService
registerMailProvider(MailProviderInterface $provider): void
registerAiProvider(AiProviderInterface $provider): void
registerPaymentProvider(PaymentProviderInterface $provider): void
registerAiTool(AiToolDefinition $definition, callable $handler): void
registerAiAgent(AiAgentDefinition $agent): void
registerAiPrompt(AiPromptTemplate $prompt): void
registerRemoteMediaProvider(RemoteMediaProviderInterface $provider): void
registerQueueHandler(string $type, callable $handler): void
registerScheduledTask(string $taskId, int $intervalSeconds, callable $handler, array $payload = []): void
registerWebhookEvent(string $eventId, string $label, string $version = '1.0'): void
registerContentType(string $id, string $name, array $fields, array $options = []): void
registerCustomField(string $contentType, CustomFieldDefinition $field): void
registerSearchResource(string $id, string $label): void
registerSeoJsonLd(string $id, callable $provider): void
registerSeoMeta(string $id, callable $provider): void
registerMailEvent(string $eventId, string $label, array $variables = []): void
```

## Advertising and Payment Facades (Unreleased GC03/04 Candidate)

These methods are isolated Core candidate APIs. They are not part of the published Core until a complete Release Gate and release. Ordinary `api` plugins do not receive PDO, payment secrets, Provider administration, capture, refund, or direct entitlement grants.

```php
$context->advertising()->renderSlots(['header', 'city_sidebar']);
$context->payments()->registerSubject('recommendation', $serverResolver);
$context->payments()->enabledProviders('USD');
$context->payments()->create('recommendation', $localOrderId, $providerId, $idempotencyKey);
$context->payments()->status($paymentRef);
$context->payments()->fulfill($paymentRef);
$context->payments()->isEntitled('recommendation', $localOrderId);
```

Advertising requires `ads.read`. Slot keys are trimmed, lowercased, validated as `[a-z0-9_-]{1,48}`, and deduplicated. Empty input returns empty output. Results contain only requested active public slots as `slot_key => {label, enabled:true, html}`. HTML is sanitized and tracking is inserted by the existing `AdRenderer` exactly once. An unavailable advertising store raises `service_unavailable`, rather than looking like an empty set. Plugins should catch that error and hide the ad.

Payment requires formal manifest permission `payment.access`, a current administrator grant for the installed plugin version and declared payment-capability scope, and the relevant capability: `payment.subject.register`, `payment.checkout`, or `payment.entitlement.read`. Installation review and plugin enablement are not permission grants. Historical grants without a scope digest fail closed; upgrading or changing the declared scope requires a new explicit grant. Administrators use the plugin detail page to grant or revoke after `plugins.manage`, CSRF, exact plugin-ID confirmation, and reauthentication within 600 seconds. The persisted positive decision is `granted_scoped`, deliberately not the older `granted` value: a supported code rollback preserves authorization history but older Core does not treat this new decision as live payment access. Revocation denies new claims but does not undo already dispatched Provider operations or erase payment history. This grant flow remains an unreleased candidate and must not be substituted with direct database writes. Legacy undeclared plugins do not inherit this access. The resolver signature is `callable(string $localSubjectId, array $context): array`; Core supplies `plugin_id`, nullable `actor_id`, and `operation` (`create`, `status`, `fulfill`, `entitlement_read`). Return `authorized: true` for allowed access. On create, also return server-side `quote_version`, positive `amount_minor`, uppercase supported `currency`, `beneficiary:{type,id}`, and positive `duration_seconds`.

`create()` and `fulfill()` require a Core-dispatched plugin `POST` route with `csrf: true` and an authenticated active front user. `status()` requires that buyer's authenticated route context. `enabledProviders()` and `isEntitled()` do not need a buyer; the latter returns only a boolean for the plugin's own bound order. A plugin should register a distinct local business order ID for each purchase and never derive paid state from a form, redirect or cached response.

Core freezes the buyer and quote at first create. One plugin-local order can have only one payment binding; the same idempotency key and frozen request replay the same reference, while a changed key or quote fails. A Provider call is claimed once. If its outcome is uncertain and no local payment can be recovered with the same internal key, Core durably retains that key and returns `payment_reconciliation_required`; it does not issue a second charge. A database rollback cannot reverse a Provider operation. Only a Core-accepted paid payment for the exact amount and currency with no successful prior refund can receive its first entitlement. The expiry is the immutable first trusted paid UTC time plus the frozen duration. Partial refund after grant retains the original term; full refund and expiry make subsequent reads false. Permission revocation blocks new plugin facade calls, while Core retains payment and entitlement records for reconciliation. Existing `entitlement_ref` and expiry remain stable on replay.

Candidate facade errors are `capability_denied`, `service_unavailable`, `payment_reconciliation_required`, `invalid_request`, `not_found`, `idempotency_conflict`, and `payment_not_fulfillable`. Errors never return raw Provider payloads or secrets. The plugin should fail closed on availability and reconciliation errors.

## Request

Since Core 1.2.69 except `rawBody()`.

```php
__construct(string $method, string $path, array $query = [], array $body = [], array $server = [], string $rawBody = '')
static capture(): self
static normalizePath(string $path): string
static captureBody(string $method, array $post, string $contentType, string $rawBody): array
input(string $key, mixed $default = null): mixed
rawBody(): string // Since Core 1.2.70
withPath(string $path): self
```

Public readonly properties: `method`, `path`, `query`, `body`, `server`.

## Response

Since Core 1.2.69.

```php
__construct(string $body, int $status = 200, array $headers = [])
static text(string $body, int $status = 200): self
static html(string $body, int $status = 200): self
static json(array $data, int $status = 200): self
static redirect(string $location, int $status = 302): self
withHeaders(array $headers): self
send(): void
body(): string
status(): int
headers(): array
```

## PluginContentService

Since Core 1.2.70.

```php
createDraft(array $draft): array
get(int $contentId): ?array
list(array $query = []): array
update(int $contentId, array $patch): array
publish(int $contentId): array
```

Capabilities: `content.read`, `content.write`.

Unreleased maintenance correction based on Core 1.2.83: custom blocks registered
through `registerBlock()` / `registerBlockRenderer()` retain their canonical
`plugin_id` across `createDraft()` -> `get()` -> `publish()` and title-only updates.
The owner is resolved from Core registration, not trusted from input. Supplying
another plugin's owner or an unknown type does not authorize execution and is
rejected on creation. Historical ownerless blocks are completed only when their
type has an unambiguous registered owner. Missing registrations preserve saved
data in a stable, nonexecuting `missing-extension` envelope; restoration of the
registration permits recovery on the next save. Renderer parameters remain
`(array $block, array $context)`; no manual owner repair is required.

## PluginLicenseService

Since Core 1.2.70.

Context-scoped commercial license service. Plugins can only read and enforce their own license state; there is no `pluginId` parameter.

```php
current(): array
hasFeature(string $feature): bool
requireFeature(string $feature): void
activate(string $licenseCode): array
clear(): void
```

`current()` returns:

```php
[
    'plugin_id' => 'official.wechat',
    'status' => 'active|expired|invalid|missing|offline_grace|revoked',
    'tier' => 'free|lite|pro|...',
    'features' => ['article_sync'],
    'domain' => 'example.com',
    'expires_at' => '2027-01-01T00:00:00+00:00',
    'checked_at' => '2026-09-26T00:00:00+00:00',
    'grace_until' => '2026-10-03T00:00:00+00:00',
]
```

Signed license codes use `dylic_v1.base64url(payload_json).base64url(rsa2048_sha256_signature)`. Payload must include at least `license_id`, `plugin_id`, `domain` or `site`, `tier`, `features`, `issued_at`, and `expires_at`; `key_id` selects a key from `market.license_public_keys`.

Plugins should gate paid behavior with `hasFeature()` / `requireFeature()`, not tier string checks. Core manages commercial license storage, activation state, public-key verification, and offline grace. Plugins must not implement official commercial license crypto or parse signed payloads themselves. Private signing keys never enter CMS config, the Core package, or plugin packages.

## FrontUserService

Since Core 1.2.70.

```php
current(): ?array
find(int $userId): ?array
findByEmail(string $email): ?array
verifyCredentials(string $email, string $password, array $context = []): ?array
loginById(int $userId, array $context = []): void
findByExternalIdentity(string $provider, string $subject): ?array
bindExternalIdentity(int $userId, string $provider, string $subject, array $profile = []): void
unbindExternalIdentity(int $userId, string $provider, string $subject): void
```

Capabilities: `auth.read`, `auth.login`, `auth.external_identity`.

`auth.login` also requires trusted/bundled status.

## PluginDataStore

Since Core 1.2.69.

```php
put(string $type, string $key, array $payload): void
all(string $type): array
```

Payload must be an array. Rows are append-only and include `data_key`, `payload_json`, `created_at`, `updated_at`.

## PluginSecretStore

Since Core 1.2.69.

```php
set(string $pluginId, string $key, string $value): void
masked(string $pluginId, string $key): ?string
get(string $pluginId, string $key): ?string
purgePluginSecrets(string $pluginId, string $confirmation): void
```

## Block Renderer

Since Core 1.2.70.

```php
registerBlockRenderer(string $type, callable $renderer): void
```

Renderer:

```php
function (array $block, array $context): string
```

## Scheduler

Since Core 1.2.69.

```php
registerScheduledTask(string $taskId, int $intervalSeconds, callable $handler, array $payload = []): void
```

Handler:

```php
function (array $payload): void
```
