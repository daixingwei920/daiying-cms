# Daiying CMS AI Provider Spec

Status: Public AI API v1

Plugins should call `PluginContext::ai()` or `Cms\Core\Ai\AI::forSite()` and then use `AiService::chat()`, `AiService::request()`, or `AiGateway`. Plugins must not read `cms_core_ai_settings` directly and must not decrypt API keys.

## Provider Contract

Providers implement `Cms\Core\Ai\AiProviderInterface`.

Required methods:

- `getId(): string`
- `getLabel(): string`
- `getModels(): array`
- `getCapabilities(): array`
- `execute(AiRequest $request, array $config): AiResponse`
- `testConnection(array $config): array`

Provider IDs use namespaced lowercase IDs such as `vendor.provider`.

## Model Capabilities

Models declare capabilities through `AiModel`:

- `text_generation`
- `vision`
- `image_generation`
- `embedding`
- `structured_output`
- `tool_calling`
- `streaming`
- `reasoning`

Plugins should request capabilities, not hard-code model names.

## Built-In Presets

- `deepseek`: OpenAI-compatible, `https://api.deepseek.com/v1`, `deepseek-chat`
- `openai`: OpenAI-compatible, `https://api.openai.com/v1`, `gpt-4.1-mini`
- `xai`: OpenAI-compatible, `https://api.x.ai/v1`, `grok-4.6`
- `tencent_hunyuan`: OpenAI-compatible, `https://api.hunyuan.cloud.tencent.com/v1`, `hunyuan-turbos-latest`
- `gemini`: Gemini native, `https://generativelanguage.googleapis.com/v1beta`, `gemini-2.5-flash`
- `openai_compatible`: custom OpenAI-compatible endpoint

Admin-selected Base URL and model remain editable.

## Error Model

Provider and gateway errors use `AiException::reason()` values such as:

- `disabled`
- `api_key_missing`
- `auth_failed`
- `model_not_found`
- `quota_or_rate_limited`
- `quota_exceeded`
- `timeout`
- `network_error`
- `provider_error`
- `capability_unsupported`

Messages must be safe for admin display and must not include full API keys or tokens.
