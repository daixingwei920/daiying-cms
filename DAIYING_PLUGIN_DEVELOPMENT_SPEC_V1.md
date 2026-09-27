# Daiying Plugin Development Specification V1

Status: Published for Daiying CMS Core 1.2.70+.

Baseline: Core 1.2.70 Plugin SDK Foundation.

## Plugin Structure

Minimum plugin:

```text
content/plugins/vendor.example/
  plugin.json
  plugin.php
  README.md
```

`plugin.php` must return a callable:

```php
return static function (Cms\Core\Plugin\PluginContext $context): void {
    // register routes, tasks, blocks, listeners, providers
};
```

## Manifest

Required keys:

- `plugin_id`
- `name`
- `version`
- `author`
- `core.min`
- `php`
- `entry`

Important optional keys:

- `trust_level`: `api` or `trusted_php`.
- `capabilities`: declared Core capabilities.
- `type`: defaults to `plugin`.
- `bundled`: official bundled plugins only.
- `capability_namespaces`: custom plugin capability namespace grants.
- `table_prefixes`: trusted plugin-owned table prefixes.
- `media_reference_provider`: media integration identifier.

## Lifecycle

Core discovers `plugin.json`, validates the manifest, creates a `PluginContext`, and executes the plugin entry callable. Plugins register behavior during boot. Existing installed content and data must remain readable after a plugin is disabled.

## Trust Levels

- `api`: default. Uses public SDK services. No direct PDO/session mutation.
- `trusted_php`: reviewed trusted PHP. May receive direct PDO if the official trust grant permits it.

`auth.login` is high risk. Declaring it in a manifest is not enough; Core also requires trusted/bundled status.

## Capabilities

Capabilities fail closed. A plugin must declare the capability that unlocks the API it calls.

Common examples:

- `content.read`: `content()->get()`, `content()->list()`.
- `content.write`: `content()->createDraft()`, `content()->update()`, `content()->publish()`.
- `auth.read`: `frontUsers()->current()`, user lookup, credential verification.
- `auth.login`: `frontUsers()->loginById()`.
- `auth.external_identity`: identity lookup/bind/unbind.
- `scheduler.register`: `registerScheduledTask()`.
- `blocks.register`: `registerBlock()`, `registerBlockRenderer()`.
- Commercial license checks are context-scoped through `license()` and do not require a separate capability.

`cron.register` remains a backward-compatible alias for scheduler registration but is deprecated for new plugins.

## Routes And CSRF

Use:

```php
$context->frontRoute('GET', '/path', $handler, $capability = null, $csrf = false);
$context->adminRoute('POST', '/admin/path', $handler, $capability = null, $csrf = true);
```

Admin write routes should keep CSRF enabled. Forms should render `Cms\Core\Security\CsrfToken::field()`.

## Request / Response

Use `Request` properties:

- `$request->method`
- `$request->path`
- `$request->query`
- `$request->body`
- `$request->server`
- `$request->input($key, $default)`
- `$request->rawBody()`

Do not depend on non-existent helper methods such as `query()`, `post()`, `method()`, `host()`, or `csrfToken()`.

Return `Response::html()`, `Response::text()`, `Response::json()`, or `Response::redirect()`.

## Storage Boundary

- PluginDataStore: non-secret plugin KV/state, array payload only, append-only rows.
- Secret Store: tokens, AppSecret, webhook secrets, OAuth secrets. Do not put signed commercial license state in PluginDataStore.
- License Store: Core-owned non-secret signed license state and local activation cache.
- Cache: transient non-authoritative data.
- ContentService: CMS content authority.
- FrontUserService: CMS front-user/session/external identity authority.
- Trusted PDO: trusted/bundled plugins only.

Do not store the same authoritative record in both plugin tables and PluginDataStore.

## Commercial License Boundary

Paid plugins must use `$context->license()` for license status and feature gates. The service is scoped to the current plugin manifest, so plugins cannot query another plugin's license.

Required behavior:

- Use `hasFeature('feature_key')` or `requireFeature('feature_key')`; avoid tier-only checks.
- Signed payloads include `license_id`, `plugin_id`, `domain` or `site`, `tier`, `features`, `issued_at`, and `expires_at`.
- Core verifies signatures with configured public keys in `market.license_public_keys`; private signing keys stay only in the official signing system.
- Site binding compares Core's canonical site domain, ignoring scheme, path, and a leading `www.`.
- A previously valid signed license may enter finite `offline_grace`; after `grace_until`, paid features fail closed.
- Core owns commercial license storage, signature verification, activation state, and offline grace calculation.
- Plugins must not include their own public key constants, private keys, signature verification code, license payload parsers, or grace-period logic.

## Secrets

Secrets must not enter logs, normal exports, or PluginDataStore payloads. `masked()` is display-only. Secret Store fails closed when the master key is unavailable.

## Scheduler

Use:

```php
$context->registerScheduledTask('vendor.example.task', 3600, static function (array $payload): void {
});
```

The interval is seconds, not a cron expression. Minimum interval is 60 seconds.

## Blocks

Register editor availability:

```php
$context->registerBlock('example_card', 'Example Card');
```

Register front rendering:

```php
$context->registerBlockRenderer('example_card', static function (array $block, array $context): string {
    return '<section>' . htmlspecialchars((string) ($block['data']['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</section>';
});
```

Renderer output is an HTML fragment. Renderer code is responsible for escaping plugin-owned values. Missing renderers must not fatal.

## Content

Use `$context->content()` instead of `ContentRepository` or SQL. The service returns arrays/DTOs and applies Core validation/sanitization.

## Auth / Front Users

Use `$context->frontUsers()` instead of touching `$_SESSION` or front-user tables. Programmatic login must go through Core.

## Events

Listen by event class name:

```php
$context->listen(Cms\Core\Content\ContentPublishedEvent::class, static function (object $event): void {
});
```

The official registry lists only real dispatched events.

## Uninstall

Plugins must preserve content data owned by Core. Plugin-owned data cleanup must be explicit and must not purge shared Core user/content/payment records.

## Compatibility

Existing route registration, Secret Store, PluginDataStore, scheduler, provider registration, and official plugin loading remain backward compatible.

## Forbidden Private Dependencies

Plugins must not depend on:

- Core private repositories unless listed in the API reference.
- Raw PDO for `api` plugins.
- Direct `$_SESSION` mutation.
- Private table names for Core domains.
- `php://input` for route raw body after `Request::rawBody()`.
- Guessed event names not in the Event Registry.
