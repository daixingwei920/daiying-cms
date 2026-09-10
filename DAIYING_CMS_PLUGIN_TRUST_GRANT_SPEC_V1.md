# Daiying CMS Official Extension Trust Grant v1

Date: 2026-09-10
Baseline: Daiying CMS Core 1.2.48, commit 41a03ab

## Purpose

Official extensions must be installable from the official market without editing Core PHP files for every new plugin. Trust is no longer inferred from an `official.*` extension id or from a hard-coded Core registry entry.

The v1 trust model is:

1. Core owns immutable reserved namespaces such as `cms_`, `market_`, critical admin routes, and update/recovery surfaces.
2. Legacy bundled records remain readable for old bundled/system plugins and old installed sites.
3. New official market plugins receive a signed Trust Grant from the official update/market server.
4. Market installation verifies the grant and checks that the plugin manifest asks only for the granted namespaces, table prefixes, route prefixes, and trust level.
5. Local uploads and third-party packages cannot gain official trust by naming themselves `official.*`.

## Database

Core migration `2026_09_10_000001_extension_trust_grants` creates:

`cms_extension_trust_grants`

Required trust fields:

- `extension_id`
- `extension_type`
- `publisher`
- `source`
- `trust_level`
- `capability_namespaces_json`
- `table_prefixes_json`
- `route_prefixes_json`
- `admin_menu_json`
- `provider_capabilities_json`
- `status`
- `schema_version`
- `issued_at`
- `expires_at`
- `grant_fingerprint`
- `signature`

Operational fields:

- `id`
- `key_id`
- `payload_json`
- `created_at`
- `updated_at`

Supported statuses:

- `active`
- `revoked`
- `superseded`

Only active, non-expired grants are used at runtime.

## Envelope

A market install authorization may include:

```json
{
  "trust_grant": {
    "payload": {
      "schema_version": 1,
      "extension_id": "official.mail",
      "extension_type": "plugin",
      "publisher": "official",
      "source": "official_market",
      "trust_level": "trusted_php",
      "capability_namespaces": ["mail", "notifications"],
      "table_prefixes": ["mail_"],
      "route_prefixes": ["/admin/mail"],
      "admin_menu": {},
      "provider_capabilities": {},
      "status": "active",
      "issued_at": "2026-09-10T00:00:00+00:00",
      "expires_at": ""
    },
    "signature": "base64-ed25519-signature",
    "grant_fingerprint": "sha256-canonical-payload",
    "key_id": "current-official-key"
  }
}
```

`official_trust_grant` is also accepted as a compatibility alias.

## Verification

Core verifies:

- Signed payload using `updates.public_key` and optional `updates.key_id`.
- Canonical payload fingerprint.
- `schema_version = 1`.
- `publisher = official`.
- `source` is `official_market` or `bundled_official`.
- `extension_type` is `plugin`, `payment_provider`, or `theme`.
- `extension_id` is a valid `official.*` id.
- `trust_level` is `api` or `trusted_php`.
- `status = active`.
- `expires_at` is empty or in the future.
- Table prefixes are syntactically valid and do not include `cms_` or `market_`.
- Route prefixes are safe and do not target critical Core routes.

Unsigned, forged, tampered, revoked, expired, or mismatched grants are rejected before plugin migrations run.

## Market Install Rules

For official market plugin and payment-provider packages:

1. Verify package hash against the install authorization.
2. Verify the market package manifest and file hashes.
3. Verify the signed Trust Grant, if present.
4. Reject any plugin that claims `official.*`, `bundled`, `system-plugin`, or `trusted_php` without a trusted official path.
5. Ensure plugin id, package type, trust level, capability namespaces, and table prefixes are within the grant.
6. Persist the grant.
7. Validate and run plugin migrations with trusted-official table-prefix ownership.
8. On failure, roll back the plugin directory, plugin row, and the staged Trust Grant.

Legacy built-in records remain accepted for old bundled/system plugins and old sites, but new official extensions should use signed Trust Grants.

## Non-Goals

This Foundation step does not:

- Move official market private source code into the public CMS package.
- Weaken local upload restrictions.
- Allow arbitrary `official.*` ids to become trusted.
- Hard-code CJ, Commerce, Mail, or payment-provider business logic into Core.
- Replace the official update server's own signing/review system.

