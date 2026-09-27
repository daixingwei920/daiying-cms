# Daiying Plugin API Reference V1

Only APIs implemented in the Foundation candidate are listed.

## PluginContext

Since Core 1.2.69 unless noted.

```php
hasCapability(string $capability): bool
listen(string $eventName, callable $listener): void
dispatch(object $event): void // Since Core 1.2.70 candidate; trusted/bundled only
registerBlock(string $type, string $label): void
registerBlockRenderer(string $type, callable $renderer): void // Since Core 1.2.70 candidate
data(): PluginDataStore
content(): Cms\Core\Content\PluginContentService // Since Core 1.2.70 candidate
frontUsers(): Cms\Core\Auth\FrontUserService // Since Core 1.2.70 candidate
license(): Cms\Core\Plugin\PluginLicenseService // Since Core 1.2.70 candidate
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

## Request

Since Core 1.2.69 except `rawBody()`.

```php
__construct(string $method, string $path, array $query = [], array $body = [], array $server = [], string $rawBody = '')
static capture(): self
static normalizePath(string $path): string
static captureBody(string $method, array $post, string $contentType, string $rawBody): array
input(string $key, mixed $default = null): mixed
rawBody(): string // Since Core 1.2.70 candidate
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

Since Core 1.2.70 candidate.

## PluginLicenseService

Since Core 1.2.70 candidate.

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

Plugins should gate paid behavior with `hasFeature()` / `requireFeature()`, not tier string checks. Private signing keys never belong in CMS config or plugin packages.

```php
createDraft(array $draft): array
get(int $contentId): ?array
list(array $query = []): array
update(int $contentId, array $patch): array
publish(int $contentId): array
```

Capabilities: `content.read`, `content.write`.

## FrontUserService

Since Core 1.2.70 candidate.

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

Since Core 1.2.70 candidate.

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
