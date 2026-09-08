# Daiying CMS AI Foundation Architecture

Status: Foundation V1

AI Foundation makes Daiying CMS AI-ready without turning Core into a business AI feature bundle. Core provides configuration, provider routing, model capability discovery, normalized requests, usage, quota, job mapping, prompt registration, tool registration, and audit boundaries.

Core does not implement article writing, SEO writing, customer service, image generation workflows, commerce assistants, or site-building agents. Those belong to CMS features or plugins.

## Current Baseline

- Site AI settings already existed through `Cms\Core\Ai\AiService`, `AI::forSite()`, `SiteAiSettingsRepository`, OpenAI-compatible client, Gemini client, and provider presets.
- Secret storage for AI API keys already used encrypted `cms_core_ai_settings.api_key_ciphertext` with masked admin display.
- Queue, scheduler, cache, webhook, audit log, role/capability, plugin context, public API registry, content, media, and settings services already existed and were reused.
- Official update server and Marketplace AI Review remain separate infrastructure and do not depend on this site AI runtime.

## V1 Components

- `AiGateway`: stable entrypoint for AI requests, connection tests, usage/quota checks, queue handoff, and normalized errors.
- `AiProviderInterface`: public provider contract for Core and plugin providers.
- `AiProviderRegistry`: runtime registry for built-in and plugin-registered providers.
- `AiModel`: model metadata and capability declarations.
- `AiRequest` / `AiResponse`: stable DTOs for plugin and Core callers.
- `AiUsageLedger`: records provider/model/plugin/operation/token usage.
- `AiQuotaService`: enforces global or plugin scoped request limits.
- `AiJobService`: maps AI work to the existing Core queue instead of creating a second queue.
- `AiContextService`: safe read-only context boundary for site/content/media/public capabilities.
- `AiToolRegistry` / `AiToolService`: permissioned AI actions with risk levels.
- `AiAgentRegistry`: plugin-owned agent definitions.
- `AiPromptRegistry` / `AiPromptRepository`: versioned prompt templates.
- `AiAuditLogger`: AI-specific audit table for request/tool traceability.

## Provider Architecture

Daiying CMS keeps one OpenAI-compatible adapter and one Gemini adapter.

- DeepSeek: OpenAI-compatible preset
- OpenAI: OpenAI-compatible preset
- Grok / xAI: OpenAI-compatible preset
- Tencent Hunyuan: OpenAI-compatible preset
- Custom OpenAI-compatible: OpenAI-compatible preset
- Google Gemini: Gemini adapter

New OpenAI-compatible vendors should be added as presets or plugin-registered providers before adding new Core provider classes.

## Cross-Version Policy

AI Foundation public APIs are versioned through `FoundationVersions::AI_API = 1.0`. Minor releases may add methods or contracts, but must not remove or change published signatures without a major API version and compatibility layer.

Existing DeepSeek/OpenAI-compatible settings migrate forward without rewriting encrypted API keys. New tables are additive and idempotent.
