# Daiying CMS Plugin API v1

Daiying CMS Plugin API v1 is the stable public contract for site plugins.

Plugins should integrate through `Cms\Core\Plugin\PluginContext`, declared
capabilities, versioned provider interfaces, and public service classes. Plugins
must not depend on private Core table layouts, private controller methods, or
update-server internals.

## Version

- Plugin API: `1.0`
- Core API: `1.0`
- Storage Provider API: `1.0`
- Theme API: `1.0`
- REST API: `1.0`

Minor Core releases may add new methods, events, capabilities, and providers.
They must not remove or change published method signatures in the `1.x` line.

## Manifest

Plugins are installed under:

```text
content/plugins/{plugin_id}
```

The stable manifest fields are:

- `plugin_id`
- `name`
- `version`
- `description`
- `author`
- `type`
- `core.min`
- `core.max`
- `php.min`
- `capabilities`
- `dependencies`
- `optional_dependencies`
- `conflicts`

Official Marketplace packages may also include a top-level `manifest.json`.
Plugin identity must remain stable across `manifest.json` and `plugin.json`.

## Runtime Context

Stable plugin entry point:

```php
Cms\Core\Plugin\PluginContext
```

Plugins receive the context from the Core plugin runtime and use it to register
their routes, UI, providers, events, and shared Foundation services.

Stable context methods include:

- `hasCapability($capability)`
- `listen($eventName, $listener)`
- `registerBlock($type, $label)`
- `data()`
- `secrets()`
- `frontRoute($method, $path, $handler, $capability = null, $csrf = false)`
- `adminRoute($method, $path, $handler, $capability = null, $csrf = true)`
- `adminMenu($label, $path, $capability = null)`
- `assetUrl($relativePath)`
- `adminStyle($relativePath)`
- `adminScript($relativePath, $defer = true)`
- `ai()`
- `mail()`
- `queue()`
- `scheduler()`
- `cache()`
- `webhooks()`
- `registerMailProvider($provider)`
- `registerRemoteMediaProvider($provider)`
- `registerQueueHandler($type, $handler)`
- `registerScheduledTask($taskId, $intervalSeconds, $handler, $payload = [])`
- `registerWebhookEvent($eventId, $label, $version = '1.0')`
- `registerContentType($id, $name, $fields, $options = [])`
- `registerCustomField($contentType, $field)`
- `registerSearchResource($id, $label)`
- `registerSeoJsonLd($id, $provider)`
- `registerSeoMeta($id, $provider)`
- `registerMailEvent($eventId, $label, $variables = [])`

`PluginContext::pdo()` is intentionally restricted to trusted bundled plugins.
Marketplace and third-party plugins should use public repositories and services.

## Capabilities

Plugins must declare capabilities before using protected registration APIs.

Foundation capabilities include:

- `admin.menu`
- `blocks.register`
- `content.type`
- `content.field`
- `search.register`
- `seo.extend`
- `storage.plugin`
- `mail.provider`
- `mail.event`
- `queue.register`
- `scheduler.register`
- `cron.register`
- `webhook.register`
- `cache.use`
- `network.external`
- `settings.manage`

Core may add capabilities in minor releases. Removing or changing the behavior
of existing capabilities requires a major API version.

## Foundation Services

Plugins should reuse Core Foundation services:

```php
$context->ai();
$context->mail();
$context->queue();
$context->scheduler();
$context->cache();
$context->webhooks();
```

This keeps AI configuration, mail delivery, queue retry, cache namespace,
webhook signing, and secret redaction consistent across the site.

## Storage Providers

External media and storage plugins should register through:

```php
$context->registerRemoteMediaProvider($provider);
```

New providers should implement Storage Provider API v1. Legacy media provider
interfaces remain supported through Core compatibility adapters.

## Mail Providers

Delivery plugins should implement:

```php
Cms\Core\Mail\MailProviderInterface
```

and register through:

```php
$context->registerMailProvider($provider);
```

Plugins should not implement their own duplicate SMTP settings when site mail
configuration is sufficient.

## AI Usage

Plugins should call the site AI service:

```php
$ai = $context->ai();
```

Plugins must not read Core AI configuration tables directly and must not assume
that the configured provider is DeepSeek, OpenAI, Grok/xAI, Hunyuan, Gemini, or
another specific vendor. Provider-specific request logic belongs behind Core AI
adapters.

## Data And Secrets

Plugin-owned public data should use plugin data stores or plugin migrations.
Secrets should use `PluginContext::secrets()` or Core secret infrastructure.

Plugins must not log raw credentials, access tokens, authorization headers,
cookies, signing keys, payment secrets, SMTP passwords, or OAuth refresh tokens.

## Compatibility

Plugin API v1 follows the Core API stability policy:

- Additive changes are allowed in minor Core releases.
- Published method signatures remain stable in the `1.x` line.
- Deprecated APIs keep a compatibility wrapper until the next major API.
- Plugin migrations must be idempotent.
- Plugin activation state and plugin-owned data must survive Core upgrades.
