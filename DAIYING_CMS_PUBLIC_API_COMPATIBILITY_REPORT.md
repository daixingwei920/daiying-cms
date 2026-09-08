# Daiying CMS Public API Compatibility Report

## Public Version Registry

Core exposes API versions through:

```php
Cms\Core\Foundation\FoundationVersions::all();
Cms\Core\Support\PublicApiRegistry::contracts();
```

Current versions:

- Core API: `1.0`
- Plugin API: `1.0`
- Theme API: `1.0`
- Storage Provider API: `1.0`
- REST API: `1.0`
- Update Protocol: `1.0`

## Stable Public Areas

- `PluginContext` for plugin integration
- `TemplateContext` and `ThemeViewModel` for themes
- `MediaStorageProviderV1Interface` and `RemoteMediaProviderV1Interface`
- `AiService` through `PluginContext::ai()`
- `MailService` and `MailProviderInterface`
- `QueueService`, `SchedulerService`, `CacheInterface`, `WebhookService`
- `RoleCapabilityService`
- REST API v1 response and error schema

## Compatibility Rules

- Minor releases may add methods and response fields.
- Minor releases must not remove public methods or change argument/result shape.
- Deprecated APIs require a compatibility wrapper until a major API release.
- Plugins should declare capabilities and use `PluginContext`.
- Plugins should avoid private tables, private controllers, update-server internals, and direct secret storage.

## Residual Risk

- Several older official plugins may still contain legacy direct registry calls.
  Those are currently tolerated, but new plugins should migrate to `PluginContext`.
- `PluginContext::pdo()` remains available only for trusted bundled plugins and
  should not become a general marketplace plugin pattern.
- REST media upload is reserved and must not be treated as stable until the
  binary upload contract is finalized.

## Conclusion

Public API v1 is documented and registered. Backward compatibility policy is now
explicit, but the final guarantee depends on completing the cross-version package
upgrade matrix.
