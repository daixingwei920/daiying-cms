# Plugin SDK V1 Documentation Publication Report

Date: 2026-09-27

Branch: `docs/plugin-sdk-v1-publication`

Scope: docs-only publication for Daiying CMS Core 1.2.70 Plugin SDK Foundation.

## Source Of Truth

The publication was checked against the released Core 1.2.70 SDK boundary:

- `Cms\Core\Plugin\PluginContext`
- `Cms\Core\Http\Request`
- `Cms\Core\Http\Response`
- `Cms\Core\Content\PluginContentService`
- `Cms\Core\Auth\FrontUserService`
- `Cms\Core\Plugin\PluginLicenseService`
- V1 event classes and official.commerce `OrderPaidEvent`
- V1 capability checks implemented by `PluginContext`, content, auth, scheduler, and block renderer services

Reference implementation and gate reports were preserved as historical records. Current public entrypoints now point to the published SDK docs rather than implementation reports.

## Published Entry Point

New public SDK index:

- `docs/plugin-sdk/README.md`

The index defines the Core 1.2.70+ target and links the five-piece SDK documentation set:

- `DAIYING_PLUGIN_DEVELOPMENT_SPEC_V1.md`
- `DAIYING_PLUGIN_API_REFERENCE_V1.md`
- `DAIYING_EVENT_REGISTRY_V1.md`
- `DAIYING_CAPABILITY_REGISTRY_V1.md`
- `DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/`

It also states the stability rule: if a Core class, method, event, table, or behavior is not listed in the SDK V1 API Reference or Event Registry, third-party plugins must not assume it is stable public API.

## Documentation Updates

- Root `README.md` now lists Core 1.2.70 as the current public release and links Plugin SDK V1 from project links and documentation links.
- `docs/README.md` now links Plugin SDK V1 and keeps the older Plugin API page as legacy.
- `docs/developers.md` now leads extension developers to Plugin SDK V1 and repeats the private-boundary rule.
- `docs/plugins.md` now points Core 1.2.70+ plugin development to SDK V1 and warns against private Core code, raw PDO, direct session mutation, and guessed event names.
- `PLUGIN_API_V1.md` is preserved with a `LEGACY / SUPERSEDED` banner that points to the SDK V1 index.

## SDK Boundary Confirmed

The published docs teach the real SDK APIs:

- `Request::rawBody()`
- `PluginContext::content()`
- `PluginContext::frontUsers()`
- `PluginContext::license()`
- `PluginContext::registerScheduledTask(...)`
- `PluginContext::registerBlockRenderer(...)`
- class-name event listeners through `PluginContext::listen(...)`

The docs do not teach legacy cron registration helpers, guessed request helpers, raw `php://input`, raw PDO for `api` plugins, direct `$_SESSION` mutation, or string event IDs as stable plugin APIs.

## License Service Boundary

The License Service documentation now explicitly covers:

- `$context->license()->current()`
- `hasFeature(...)`
- `requireFeature(...)`
- `activate(...)`
- `clear()`
- context-scoped plugin license reads only
- feature gates over tier-only checks
- Core-owned commercial license storage, public-key signature verification, activation state, and offline grace
- private signing keys staying only in the official signing system, never in CMS config, Core packages, or plugin packages

## Event Registry

The V1 Event Registry publishes only real dispatched event classes:

- `Cms\Core\Content\ContentPublishedEvent`
- `Cms\Core\Comment\CommentCreatedEvent`
- `Cms\Core\Auth\FrontUserRegisteredEvent`
- `Cms\Core\Auth\FrontUserLoggedInEvent`
- `Daiying\Commerce\Events\OrderPaidEvent`

String-only event aliases remain documented only as excluded from V1.

## Capability Registry

The V1 Capability Registry includes the real gates used by the SDK, including:

- `content.read`
- `content.write`
- `auth.read`
- `auth.login`
- `auth.external_identity`
- `scheduler.register`
- `blocks.register`

It documents `cron.register` as a deprecated compatibility alias and states that `auth.login` requires trusted or bundled status in addition to the manifest capability.

## Legacy Handling

The older `PLUGIN_API_V1.md` document was not deleted. It is marked `LEGACY / SUPERSEDED` and points readers to `docs/plugin-sdk/README.md`.

## Non-Goals

No runtime code, updater code, manifests, release artifacts, production configuration, package versions, or marketplace publication state were changed.
