# Daiying Plugin SDK V1

Daiying Plugin SDK V1 is the public plugin contract for Daiying CMS Core 1.2.70 and newer.

Third-party plugins must use only the classes, methods, events, capabilities, and data boundaries published in this SDK. If a Core class, method, event, table, or behavior is not listed in the API Reference or Event Registry, plugins must not assume it is stable public API.

## Documentation Set

- [Development Specification V1](../../DAIYING_PLUGIN_DEVELOPMENT_SPEC_V1.md): package shape, manifest rules, trust levels, capabilities, request/response handling, storage boundaries, commercial license boundaries, scheduler, blocks, content, auth, events, and forbidden private dependencies.
- [API Reference V1](../../DAIYING_PLUGIN_API_REFERENCE_V1.md): public SDK method signatures and service contracts including `rawBody()`, `content()`, `frontUsers()`, `license()`, `registerScheduledTask()`, and block renderers.
- [Event Registry V1](../../DAIYING_EVENT_REGISTRY_V1.md): real dispatched event classes that plugins may listen to.
- [Capability Registry V1](../../DAIYING_CAPABILITY_REGISTRY_V1.md): canonical capability names, risk levels, trust requirements, and example APIs.
- [Official Plugin Skeleton V1](../../DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/): minimal SDK-only plugin skeleton and contract test.

## Stable Boundary

Use the SDK services instead of private Core internals:

- `$request->rawBody()` for webhook/raw body access.
- `$context->content()` for CMS content operations.
- `$context->frontUsers()` for front-user identity, external identity, credential verification, and Core session login.
- `$context->license()` for context-scoped commercial license status and feature gates.
- `$context->registerScheduledTask(...)` with `scheduler.register` for interval scheduled work.
- `$context->registerBlockRenderer(...)` with `blocks.register` for frontend block rendering.
- `$context->listen(EventClass::class, ...)` for registered event classes.

Do not use raw PDO, direct `$_SESSION` mutation, private Core table layouts, guessed event strings, or unlisted Core methods from an `api` plugin. Trusted/bundled plugins may receive additional privileges only when Core grants them explicitly.

## Legacy Document

The older [Plugin API v1](../../PLUGIN_API_V1.md) page is preserved for historical links and broad compatibility notes, but it is superseded by this SDK V1 documentation set for Core 1.2.70+ plugin development.
