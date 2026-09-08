# Global AI

Daiying CMS Core provides a site-level AI configuration and service layer that CMS features and installed plugins can reuse.

Core AI is infrastructure only. It stores configuration, creates Provider requests, normalizes responses, and returns safe errors. Business features such as product descriptions, article summaries, SEO suggestions, or distribution formatting should be implemented by Core features or plugins on top of this API.

## Admin Settings

Administrators can configure site AI under:

```text
Admin -> Site Settings -> AI Settings
```

The first supported Provider modes are:

- `deepseek`
- `openai_compatible`

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

## Storage And Upgrades

Site AI settings are stored in `cms_core_ai_settings`. The API Key is encrypted with the site's `security.encryption_key` using AES-256-GCM.

The migration `2026_09_07_000002_core_ai_settings` creates the table and inserts disabled safe defaults for older sites. Future schema changes must be handled by migrations or upgrade handlers, not by requiring users to re-enter keys.

Plugins must treat the Core AI API as the compatibility contract and avoid depending on table names, columns, or private implementation files.

## Update Server Isolation

The official update server and official market AI review system do not use this site AI configuration.

They must keep separate Provider settings, API Keys, database tables, and runtime logic. The site-level AI API must not be used to read or control `updates.daiyingcms.com` AI Review behavior.
