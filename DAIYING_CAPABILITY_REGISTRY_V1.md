# Daiying Capability Registry V1

| Capability | Unlocks | Trust requirement | High risk? | Default grant? | Example API |
|---|---|---|---|---|---|
| `content.read` | Read/list CMS content through public SDK | `api` allowed | No | No | `$context->content()->get($id)` |
| `content.write` | Create/update/publish CMS content through public SDK | Review recommended | Medium | No | `$context->content()->createDraft($draft)` |
| `auth.read` | Read current/front-user identity and verify credentials | Review recommended | Medium | No | `$context->frontUsers()->find($id)` |
| `auth.login` | Establish front-user Core session | trusted/bundled required | Yes | No | `$context->frontUsers()->loginById($id)` |
| `auth.external_identity` | Lookup/bind/unbind provider identities | Review required | Yes | No | `$context->frontUsers()->bindExternalIdentity(...)` |
| `media.read` | Read media through approved APIs | `api` allowed | No | No | Media SDK APIs |
| `media.write` | Write media through approved APIs | Review recommended | Medium | No | Media SDK APIs |
| `network.external` | External HTTP/network calls | Review recommended | Medium | No | Provider clients |
| `scheduler.register` | Register interval scheduled tasks | `api` allowed | Medium | No | `$context->registerScheduledTask(...)` |
| `cron.register` | Deprecated scheduler alias | Existing compatibility only | Medium | No | Use `scheduler.register` |
| `settings.read` | Read plugin settings where supported | `api` allowed | Low | No | Settings APIs |
| `settings.write` | Write plugin settings where supported | Review recommended | Medium | No | Settings APIs |
| `storage.plugin` | Register remote media/storage provider | trusted/reviewed | High | No | `$context->registerRemoteMediaProvider(...)` |
| `mail.provider` | Register mail provider | trusted/reviewed | High | No | `$context->registerMailProvider(...)` |
| `payment.provider` | Register payment provider | trusted/reviewed | High | No | `$context->registerPaymentProvider(...)` |
| `mail.event` | Register mail template event | reviewed | Medium | No | `$context->registerMailEvent(...)` |
| `ai.provider` | Register AI provider | trusted/reviewed | High | No | `$context->registerAiProvider(...)` |
| `ai.tool` | Register AI tool | reviewed | High | No | `$context->registerAiTool(...)` |
| `ai.agent` | Register AI agent | reviewed | High | No | `$context->registerAiAgent(...)` |
| `ai.prompt` | Register AI prompt | `api` allowed | Medium | No | `$context->registerAiPrompt(...)` |
| `ai.use` | Use site AI service | reviewed | Medium | No | `$context->ai()` |
| `queue.register` | Register background queue handler | reviewed | Medium | No | `$context->registerQueueHandler(...)` |
| `webhook.register` | Register outbound webhook event | reviewed | Medium | No | `$context->registerWebhookEvent(...)` |
| `cache.use` | Use cache service | `api` allowed | Low | No | `$context->cache()` |
| `frontend.asset` | Enqueue frontend scripts/styles/body-end output | `api` allowed | Medium | No | `$context->frontendScript(...)` |
| `content.type` | Register content type | reviewed | Medium | No | `$context->registerContentType(...)` |
| `content.field` | Register custom content field | reviewed | Medium | No | `$context->registerCustomField(...)` |
| `search.register` | Register searchable resource | reviewed | Low | No | `$context->registerSearchResource(...)` |
| `seo.extend` | Extend SEO metadata/JSON-LD | `api` allowed | Low | No | `$context->registerSeoMeta(...)` |
| `blocks.register` | Register content block and renderer | `api` allowed | Medium | No | `$context->registerBlockRenderer(...)` |

`$context->license()` is context-scoped to the current plugin and does not require a separate `license.read` capability. Plugins cannot pass an arbitrary plugin ID to read another plugin's license. Paid behavior should be gated with `hasFeature()` or `requireFeature()`, not raw tier checks.

Capabilities are not user roles. They are plugin runtime grants. Unknown Core capability names fail manifest validation. `auth.login` is high risk: declaring the capability in `plugin.json` is not enough; Core also requires trusted or bundled status.
