# Core API Stability

Daiying CMS Foundation publishes versioned public API contracts for plugin and theme authors.

## Current Versions

- Core API: `1.0`
- Plugin API: `1.0`
- Theme API: `1.0`
- Storage Provider API: `1.0`
- REST API: `1.0`
- Update Protocol: `1.0`

## Compatibility Rules

- Minor releases may add public classes, methods, capabilities, fields, and events.
- Minor releases must not remove published public methods or change their argument/result shape.
- Deprecated APIs must keep a compatibility wrapper until the next major API version.
- Breaking public API changes require a major API version.
- Plugins and themes must depend on documented public APIs, not Core private tables or internal services.
- New schema must be introduced by idempotent migrations.
- Core updates must not overwrite site configuration or plugin-owned data.

## Public Registry

The machine-readable contract list is exposed by:

```php
Cms\Core\Support\PublicApiRegistry::contracts();
Cms\Core\Support\PublicApiRegistry::versions();
```
