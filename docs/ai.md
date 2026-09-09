# Global AI

Daiying CMS Core provides a site-level AI configuration and service layer that CMS features and installed plugins can reuse.

Core AI is infrastructure only. It stores configuration, creates Provider requests, normalizes responses, and returns safe errors. Business features such as product descriptions, article summaries, SEO suggestions, or distribution formatting should be implemented by Core features or plugins on top of this API.

## Current Built-In Uses

The current Core and bundled plugin source use the site AI service for these first-party workflows:

- Article/page editor drafting through the admin "AI write with me" action.
- Commerce product-description drafting from administrator-provided product name, price, category/context fields, specifications, selling points, and short notes.

Commerce may store an external product URL as administrator-provided context, but the first product-description workflow does not crawl or scrape that URL. Plugins should keep AI features optional and must catch `AiException` so ordinary content, media, payment, and commerce workflows continue to work when AI is disabled or unavailable.

## Admin Settings

Administrators can configure site AI under:

```text
Admin -> Site Settings -> AI Settings
```

Provider choices are user-friendly presets. They do not each map to a separate Core implementation.

| Provider | Adapter | Default Base URL | Default Model |
| --- | --- | --- | --- |
| `deepseek` | `openai_compatible` | `https://api.deepseek.com/v1` | `deepseek-chat` |
| `openai` | `openai_compatible` | `https://api.openai.com/v1` | `gpt-4.1-mini` |
| `xai` | `openai_compatible` | `https://api.x.ai/v1` | `grok-4.6` |
| `tencent_hunyuan` | `openai_compatible` | `https://api.hunyuan.cloud.tencent.com/v1` | `hunyuan-turbos-latest` |
| `gemini` | `gemini` | `https://generativelanguage.googleapis.com/v1beta` | `gemini-3.6-flash` |
| `openai_compatible` | `openai_compatible` | administrator-defined | administrator-defined |

When an administrator selects a preset, the form fills the recommended Base URL, model, and adapter protocol. Base URL and model remain editable, so compatible services can update endpoints or models without a Core code change.

The settings include:

- enabled switch
- Provider
- API Key
- Base URL
- Model
- timeout
- max tokens
- temperature
- connection test

API Keys are never rendered back into the HTML form. The admin page only shows whether a key is configured. Submitting the form with an empty API Key preserves the previous key unless the administrator explicitly chooses to clear it.

## Runtime API

Plugins should not read the AI configuration table directly. Use the stable Core API:

```php
use Cms\Core\Ai\AI;

$ai = AI::forSite(CMS_ROOT);

if ($ai->isEnabled()) {
    $result = $ai->chat([
        ['role' => 'user', 'content' => 'Summarize this content.'],
    ]);
}
```

Plugins loaded through the Core plugin runtime can also use:

```php
$ai = $context->ai();
```

Stable methods:

- `isEnabled(): bool`
- `getConfig(): array`
- `chat(array $messages, array $options = []): array`
- `testConnection(): array`
- `capabilities(): array`

`getConfig()` returns a masked/safe configuration. It does not expose the API Key.

## Failure Isolation

AI is optional. These states must not break normal CMS behavior:

- global AI disabled
- missing API Key
- invalid API Key
- missing or invalid model
- Provider timeout
- network error
- quota or rate-limit failure

AI calls throw `Cms\Core\Ai\AiException` with a stable `reason()` code and a safe human-readable message. Callers should catch the exception and show a feature-level error instead of letting the page fail.

Common failure reasons include:

- `disabled`
- `api_key_missing`
- `auth_failed`
- `model_missing`
- `model_not_found`
- `quota_or_rate_limited`
- `timeout`
- `network_error`
- `response_invalid`
- `response_empty`

## Adapter Architecture

Core keeps the Provider layer intentionally small:

- `OpenAiCompatibleProviderClient` handles OpenAI-style chat completions for DeepSeek, OpenAI, Grok / xAI, Tencent Hunyuan, and custom compatible endpoints.
- `GeminiProviderClient` handles Google's native Gemini `generateContent` protocol.
- `AiProviderPresets` maps friendly Provider names to adapter, Base URL, and model defaults.

New AI services that are compatible with OpenAI chat completions should normally be added as presets only. A new Adapter should be added only when the wire protocol is materially different.

## Storage And Upgrades

Site AI settings are stored in `cms_core_ai_settings`. The API Key is encrypted with the site's `security.encryption_key` using AES-256-GCM.

The migration `2026_09_07_000002_core_ai_settings` creates the table and inserts disabled safe defaults for older sites. Future schema changes must be handled by migrations or upgrade handlers, not by requiring users to re-enter keys.

The migration `2026_09_08_000001_core_ai_provider_presets` adds the `adapter` field for the preset architecture and backfills existing rows:

- existing `deepseek` settings remain `deepseek` and use the `openai_compatible` adapter
- existing `openai_compatible` settings remain custom OpenAI-compatible settings
- existing encrypted API Keys are not rewritten or cleared

Plugins must treat the Core AI API as the compatibility contract and avoid depending on table names, columns, or private implementation files.

## Update Server Isolation

The official update server and official market AI review system do not use this site AI configuration.

They must keep separate Provider settings, API Keys, database tables, and runtime logic. The site-level AI API must not be used to read or control `updates.daiyingcms.com` AI Review behavior.
