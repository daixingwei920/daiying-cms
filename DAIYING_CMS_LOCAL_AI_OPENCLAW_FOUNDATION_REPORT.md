# Daiying CMS Local AI / OpenClaw Foundation Report

Date: 2026-09-13

## Scope

This Foundation extension adds Local Model and OpenClaw support to the existing Daiying CMS unified AI provider architecture. It does not add a separate chat product, does not modify Commerce/payment/order flows, and does not connect the official update server or marketplace AI review to site-level AI settings.

## Modified Files

- `system/core/Admin/AdminController.php`
- `system/core/Ai/AiGateway.php`
- `system/core/Ai/AiProviderPresets.php`
- `system/core/Ai/AiProviderRegistry.php`
- `system/core/Ai/AiService.php`
- `system/core/Ai/GeminiProvider.php`
- `system/core/Ai/OpenAiCompatibleProvider.php`
- `system/core/Ai/OpenAiCompatibleProviderClient.php`
- `system/core/Ai/SiteAiSettingsRepository.php`
- `system/core/Bootstrap/Application.php`
- `system/core/Support/PublicApiRegistry.php`
- `system/core-manifest.json`
- `tests/ai_foundation_v1.php`
- `tests/core_ai_settings.php`

## New Files

- `system/core/Ai/AiModelDiscoveryInterface.php`
- `system/core/Ai/AiModelDiscoveryClientInterface.php`
- `system/core/Ai/OpenClawProvider.php`
- `system/core/Ai/OpenClawProviderClient.php`
- `system/migrations/2026_09_13_000001_core_ai_local_openclaw.php`
- `tests/core_ai_local_openclaw.php`
- `DAIYING_CMS_LOCAL_AI_OPENCLAW_FOUNDATION_REPORT.md`

## Database Changes

The migration `2026_09_13_000001_core_ai_local_openclaw` extends `cms_core_ai_settings` idempotently with:

- `provider_name`
- `local_api_type`
- `context_window`
- `openclaw_agent`
- `allow_cloud_fallback`
- `fallback_provider`

Existing provider, base URL, model, timeout, max tokens, temperature, enabled state, and encrypted API key values are preserved. The previous AI settings migration files were not changed, avoiding released migration checksum drift.

## Provider Interface

Daiying CMS now keeps a small provider architecture:

- `OpenAI-compatible Adapter`
  - DeepSeek
  - OpenAI
  - Grok / xAI
  - Tencent Hunyuan
  - Qwen
  - Local Model
  - Custom OpenAI-compatible endpoints
- `Gemini Adapter`
  - Google Gemini native API
- `OpenClaw Adapter`
  - OpenClaw gateway / agent endpoint

New optional model discovery contracts were added:

- `Cms\Core\Ai\AiModelDiscoveryInterface`
- `Cms\Core\Ai\AiModelDiscoveryClientInterface`

Older providers continue to work without implementing model discovery.

## Provider Presets

The AI settings page now exposes these user-facing presets:

- DeepSeek
- OpenAI
- Grok / xAI
- Tencent Hunyuan
- Qwen
- Google Gemini
- Local Model
- Custom OpenAI-compatible
- OpenClaw

Presets fill recommended defaults, but Base URL and Model remain editable. Local Model defaults to an OpenAI-compatible adapter and can point to Ollama, LM Studio, llama.cpp server, vLLM, or another compatible server.

## Routing Design

Business features continue to call Core AI through the unified service layer. The provider decision is centralized in:

- `AiService`
- `AiGateway`
- `AiProviderRegistry`
- provider preset metadata

Local Model and OpenClaw are not hardcoded into articles, Commerce, editor, payment, or plugin business code.

Cloud fallback support was added but is default off. If enabled, fallback only targets configured cloud presets and does not silently call a paid provider unless the administrator explicitly allows it.

## Local Model Test Result

Status: PARTIAL PASS

Automated protocol tests passed with a mocked OpenAI-compatible transport:

- Local Model settings save shape
- optional API key behavior
- bare Ollama-style URL normalization to `/v1`
- chat completion request construction
- no empty Authorization header
- model discovery parsing
- timeout/error boundary behavior

Real endpoint status: NOT TESTED - ENDPOINT UNAVAILABLE

No local Ollama, LM Studio, llama.cpp, or vLLM endpoint was available in this environment, so no real model generation was claimed.

## OpenClaw Test Result

Status: PARTIAL PASS

Automated protocol tests passed with a mocked OpenClaw transport:

- OpenClaw settings save shape
- token kept server-side
- agent chat endpoint routing
- model discovery parsing
- friendly 404 error handling
- redacted provider errors

Real endpoint status: NOT TESTED - ENDPOINT UNAVAILABLE

No real OpenClaw gateway URL/token was available in this environment, so no real OpenClaw request was claimed.

## Security Checks

- API keys and OpenClaw tokens remain encrypted in the existing AI settings secret field.
- Admin screens show masked credentials and preserve the existing credential when the field is left blank.
- The model detection route is admin-only and CSRF-protected.
- Frontend/browser JavaScript does not receive complete API keys or tokens.
- Provider errors are normalized to avoid leaking secrets.
- Local/private endpoints are allowed only from saved administrator configuration; ordinary request parameters cannot choose arbitrary endpoints.
- URLs with username/password, query strings, or fragments are rejected for provider Base URLs.
- Official update server and official marketplace AI review are not connected to site-level AI settings.

## Regression Tests

Passed:

- `php tests/core_ai_local_openclaw.php`
- `php tests/core_ai_settings.php`
- `php tests/ai_foundation_v1.php`
- `php tests/gemini_provider_client.php`
- `php tests/foundation_boundary_contract.php`
- `php tests/foundation_public_api_storage_v1.php`
- `php tests/core_mail_infrastructure.php`

Skipped because no credential was provided:

- `php tests/commerce_ai_deepseek_live.php`

## Diff Check

The AI Foundation files pass whitespace checks when scoped to this task.

Full `git diff --check` currently reports pre-existing trailing whitespace in `system/official-plugins.php`. That file is outside this task and was not included in the AI Provider commit to avoid overwriting another thread's state.

## Cross-Version Compatibility

Passed in automated coverage:

- The new migration is idempotent.
- Existing DeepSeek/OpenAI-compatible settings are preserved.
- Existing encrypted API key values are not overwritten by empty form submissions.
- New fields receive safe defaults on old installations.
- Public AI API contracts remain stable and additive.
- Older provider code paths do not need model discovery support.

Manual production update was not performed in this task.

## Not Completed

- Real Local Model endpoint test.
- Real OpenClaw endpoint test.
- UI browser screenshot verification of the new model detection button.
- Feature-specific routing UI, such as article AI using one provider and developer assistant using another provider.
- Commerce-specific Local/OpenClaw integration changes. Commerce can now consume the unified Core AI service without adding provider-specific code.

## Commerce Integration Conclusion

Commerce can start integrating with CMS global AI through the unified Core AI API. It should not read AI settings tables directly and should not implement separate DeepSeek/OpenAI/Gemini/Local/OpenClaw HTTP clients.
