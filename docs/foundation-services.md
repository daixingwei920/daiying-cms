# Foundation Services

Daiying CMS Foundation provides shared Core services so plugins do not build duplicate infrastructure.

## Role And Capability

Core defines role/capability foundations through:

- `Cms\Core\Auth\CapabilityRegistry`
- `Cms\Core\Auth\RoleCapabilityService`

Default roles:

- `super_admin`
- `admin`
- `editor`
- `author`

Default capabilities include content, media, plugin, theme, settings, and user management. Plugins may register their own capabilities and should check capabilities rather than hard-coding role names.

## Queue

Core queue classes:

- `Cms\Core\Queue\QueueService`
- `Cms\Core\Queue\QueueJob`
- `Cms\Core\Queue\QueueHandlerRegistry`

Jobs support:

- `pending`
- `running`
- `done`
- `retry`
- `dead`

The queue supports delayed jobs, retry counts, max attempts, owner/plugin tracking, and last error redaction.

## Scheduler

Core scheduler classes:

- `Cms\Core\Scheduler\SchedulerService`
- `Cms\Core\Scheduler\ScheduledTask`
- `Cms\Core\Scheduler\SchedulerTaskRegistry`

Scheduled tasks support:

- stable task ids
- owner/plugin tracking
- interval seconds
- payload JSON
- enabled/disabled state
- next run and last run timestamps
- lock window
- fail count
- redacted last error

Plugins can register scheduled tasks through
`PluginContext::registerScheduledTask()` when they declare
`scheduler.register` or the legacy-compatible `cron.register` capability.

## Cache

Core cache classes:

- `Cms\Core\Cache\CacheInterface`
- `Cms\Core\Cache\ArrayCache`
- `Cms\Core\Cache\FileCache`

The public API supports:

- `get`
- `set`
- `delete`
- `clear`
- TTL
- namespaces

Redis, APCu, or other cache adapters can be added later behind the same interface.

## Webhook

Core webhook classes:

- `Cms\Core\Webhook\WebhookService`
- `Cms\Core\Webhook\WebhookEndpointRepository`
- `Cms\Core\Webhook\WebhookEventRegistry`

Webhook events are versioned. Deliveries are recorded and can be queued through `QueueService`. Signatures use timestamped HMAC SHA-256.

Core default events include:

- `content.created`
- `content.updated`
- `content.published`
- `content.deleted`
- `media.created`
- `user.created`
- `plugin.activated`
- `plugin.deactivated`

Plugins can register new events through `PluginContext::registerWebhookEvent()` when they declare `webhook.register`.

## Secret Redaction

Core exposes:

```php
Cms\Core\Security\SecretRedactor
```

It is used by logging and queue failure handling to avoid leaking passwords, API keys, authorization headers, tokens, cookies, and signing secrets.

## PluginContext

Plugins should access Foundation services through:

```php
$context->queue();
$context->scheduler();
$context->cache();
$context->webhooks();
$context->registerQueueHandler(...);
$context->registerScheduledTask(...);
$context->registerWebhookEvent(...);
```

Plugins must declare the related capabilities:

- `queue.register`
- `scheduler.register`
- `webhook.register`
- `cache.use`
