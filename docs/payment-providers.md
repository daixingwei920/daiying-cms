# Payment Provider Development

Daiying CMS Core exposes a stable payment provider foundation. Payment plugins should register a provider and use the public payment interfaces instead of changing Core admin forms or checkout redirect rules.

## Provider Settings Schema

Payment providers may implement `Cms\Core\Payment\PaymentProviderSettingsSchemaInterface` to let the Core admin settings page render provider-specific fields.

Supported field keys:

| Key | Purpose |
| --- | --- |
| `name` | Stable schema field name. Use ASCII letters, numbers, and underscores. |
| `input_name` | Optional admin form input name. Defaults to `provider_schema_{name}`. |
| `key`, `public_key`, `secret_key` | Stored config key. Defaults to `name`. |
| `label` | Admin-facing field label. |
| `type` | `text`, `url`, `email`, `number`, `password`, `textarea`, `select`, `checkbox`, or `hidden`. |
| `secret` | When true, the value is saved through encrypted payment secrets instead of public config. |
| `clearable` | When true for a secret field, Core renders a clear checkbox. |
| `default` | Default rendered value for public fields. |
| `placeholder` | Placeholder text. Secret fields with saved values are shown as masked placeholders. |
| `description` or `help` | Short admin help text below the field. |
| `options` | Select options as strings or `{value,label}` arrays. |
| `allowed_values` | Accepted values during save validation. |
| `min_length`, `max_length`, `pattern`, `required` | Save-time validation constraints. |
| `normalize` | String or list: `compact`, `uppercase`, `lowercase`. |
| `compact`, `uppercase`, `lowercase` | Boolean shortcuts for normalization. |
| `empty_removes` | Defaults to true. Empty public values remove the stored key. |

Example:

```php
public function settingsSchema(): array
{
    return [
        [
            'name' => 'merchant_id',
            'input_name' => 'acme_merchant_id',
            'key' => 'merchant_id',
            'label' => 'Merchant ID',
            'type' => 'text',
            'required' => true,
        ],
        [
            'name' => 'api_secret',
            'input_name' => 'acme_api_secret',
            'key' => 'api_secret',
            'label' => 'API Secret',
            'type' => 'password',
            'secret' => true,
            'clearable' => true,
            'compact' => true,
        ],
    ];
}
```

Core stores public fields in the provider public config and secret fields in the encrypted payment provider secret store. Existing secret values are preserved when the admin leaves a secret field blank.

## Redirect Policy

Providers that return checkout URLs should implement `Cms\Core\Payment\PaymentProviderRedirectPolicyInterface`.

```php
public function isSafeRedirectUrl(string $url): bool
{
    // Only allow the provider's official HTTPS checkout hosts and expected paths.
}
```

Core asks the provider policy before applying its generic hosted-redirect fallback. This lets each provider define precise official checkout hosts and path formats without adding provider IDs to Core.

Payment plugins must still reject unsafe URLs, including:

- non-HTTPS URLs;
- user/password URL components;
- lookalike hosts;
- unexpected ports;
- query or fragment values containing API keys, bearer tokens, authorization headers, or payment secrets.

## Backward Compatibility

The legacy built-in Stripe, PayPal, WeChat Pay, and Alipay admin forms remain as compatibility fallbacks for older provider packages. New or updated providers should declare settings schema and redirect policy in their provider class so new payment methods do not require Core patches.

## Boundaries

Payment plugins should not:

- read or write Core private payment settings tables directly;
- expose API keys to frontend code;
- store secrets in public config JSON;
- require Core to hardcode their provider ID for settings UI or redirect safety;
- bypass `PaymentProviderRegistry` or the encrypted provider settings repository.
