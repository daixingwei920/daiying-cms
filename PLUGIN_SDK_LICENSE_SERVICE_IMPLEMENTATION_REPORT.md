# Plugin SDK Foundation P1A License Service Implementation Report

Date: 2026-09-26

## Scope Completed

- Added Core public SDK service: `Cms\Core\Plugin\PluginLicenseService`.
- Added `$context->license(): PluginLicenseService`.
- Reused existing `cms_site_licenses` / `CommercialLicenseStore` as the Core-owned non-secret license state and activation cache.
- Added signed local license activation through `PluginLicenseService::activate()` for existing plugin admin flows.
- Added `PluginLicenseService::clear()` and `CommercialLicenseStore::clear()`.
- Added public key trust configuration:
  - `market.license_public_keys`
  - `market.license_public_key` legacy fallback
- Registered `plugin.license` in `PublicApiRegistry`.
- Updated SDK docs:
  - `DAIYING_PLUGIN_DEVELOPMENT_SPEC_V1.md`
  - `DAIYING_PLUGIN_API_REFERENCE_V1.md`
  - `DAIYING_CAPABILITY_REGISTRY_V1.md`
- Regenerated `system/core-manifest.json`; Core manifest integrity is PASS.

## Public API

```php
$context->license()->current(): array;
$context->license()->hasFeature(string $feature): bool;
$context->license()->requireFeature(string $feature): void;
$context->license()->activate(string $licenseCode): array;
$context->license()->clear(): void;
```

`current()` returns `plugin_id`, `status`, `tier`, `features`, `domain`, `expires_at`, `checked_at`, and `grace_until`.

Statuses implemented: `active`, `expired`, `invalid`, `missing`, `offline_grace`, `revoked`.

## Security Boundary

- The service is context-scoped. Plugins cannot pass an arbitrary `plugin_id` to read another plugin's license.
- No `license.read` capability was added because the context boundary is stricter and less error-prone.
- Feature gates use signed `features`; plugins should not rely on tier strings for paid behavior.
- Signed payload verification is done by Core with public keys from config.
- Private signing keys are not present in Core config, plugin code, PluginDataStore, or tests outside generated in-memory fixtures.
- Cache tampering fails closed because feature lists are derived from the signed payload.
- Offline grace requires a previously valid signed payload plus finite `grace_until`; expired grace fails closed.
- Site binding uses Core canonical domain comparison and ignores scheme, path, and leading `www.`.

## License Format

Accepted format:

```text
dylic_v1.base64url(payload_json).base64url(rsa2048_sha256_signature)
```

Payload minimum:

```json
{
  "license_id": "lic_...",
  "plugin_id": "official.wechat",
  "domain": "example.com",
  "tier": "pro",
  "features": ["article_sync", "template_messages"],
  "issued_at": "2026-09-26T00:00:00+00:00",
  "expires_at": "2027-09-26T00:00:00+00:00",
  "key_id": "2026-q4"
}
```

## official.wechat Dogfood

Updated dogfood source:

- `/Users/xingweidai/Documents/Codex/2026-09-25/files-mentioned-by-the-user-daiying-2/work/official-wechat-dogfood/official.wechat/src/LicenseManager.php`
- `/Users/xingweidai/Documents/Codex/2026-09-25/files-mentioned-by-the-user-daiying-2/work/official-wechat-dogfood/official.wechat/README.md`
- `/Users/xingweidai/Documents/Codex/2026-09-25/files-mentioned-by-the-user-daiying-2/work/official-wechat-dogfood/tests/official_wechat_sdk_dogfood.php`

Proof:

- `LicenseManager` no longer contains a public key constant.
- `LicenseManager` no longer calls `openssl_verify`.
- `LicenseManager` does not parse signed payloads.
- Lite mode works with missing Core license state.
- Pro gates use `$context->license()->hasFeature(...)`.
- Activation uses `$context->license()->activate(...)`.
- License clearing uses `$context->license()->clear()`.

No official plugin package was published or uploaded.

## Tests Run

- `php tests/plugin_license_service_v1.php`
- `php tests/plugin_sdk_foundation_v1.php`
- `php tests/foundation_public_api_storage_v1.php`
- `php tests/foundation_system_services.php`
- `php tests/content_foundation_safety.php`
- `php tests/commerce_v1_contract.php`
- `php /Users/xingweidai/Documents/Codex/2026-09-25/files-mentioned-by-the-user-daiying-2/work/official-wechat-dogfood/tests/official_wechat_sdk_dogfood.php`
- `find .../official.wechat -name '*.php' -print0 | xargs -0 -n1 php -l`
- `php scripts/validate_production_readiness.php .`

Results:

- All targeted PHP tests passed.
- All official.wechat PHP files passed lint.
- Core manifest integrity PASS.
- Production readiness remains `not_ready` because this local checkout has environment/config/storage/installed-lock issues unrelated to this SDK change.

## Explicitly Not Done

- Did not publish Core `1.2.70`.
- Did not upload to Market.
- Did not officially release official.wechat.
- Did not migrate AI Writer, Membership, or other commercial plugins.
- Did not implement P1/P2 Queue, Webhook, Recovery, Dependency, CLI, validator, refund events, or Commerce business changes.
