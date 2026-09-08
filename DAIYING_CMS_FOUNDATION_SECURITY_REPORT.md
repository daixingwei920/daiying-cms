# Daiying CMS Foundation Security Report

## Scope

This report covers Foundation security controls added or verified during the
Core Foundation implementation pass.

## Controls

### Secrets

- AI API keys are stored as site secrets and shown masked in admin forms.
- Mail SMTP passwords are encrypted at rest.
- Plugin secrets remain behind plugin secret storage.
- Admin screens must not return full secret values after save.

### Redaction

- `Cms\Core\Security\SecretRedactor` redacts passwords, API keys, authorization
  headers, cookies, OAuth tokens, refresh tokens, signing keys, and secret-like
  values.
- Queue and log failure paths use redaction before persistence.
- File logger rotation keeps redaction in the write path.

### Plugin Boundaries

- `PluginContext::pdo()` is restricted to trusted bundled plugins.
- Provider registration requires declared plugin capabilities.
- Storage, mail, queue, scheduler, webhook, content field, search, and SEO
  registration methods enforce capabilities.
- Official update-server source directories are rejected by public package build
  guard tests.

### HTTP And Admin Safety

- Admin routes continue using the existing authentication and CSRF mechanisms.
- REST administrator writes require admin session or configured hashed bearer
  token.
- Theme asset helpers reject path traversal.
- Mail addresses, filenames, and subjects block header injection.
- Webhook signatures use timestamped HMAC SHA-256.

## Explicit Isolation

The official update server and market AI review system must remain separate from
site-level AI configuration. Public CMS packages must not include update-server
source code, private provider credentials, market review keys, `.env` files,
logs, database dumps, sessions, OAuth secrets, payment keys, signing private
keys, or server credentials.

## Residual Risk

- Full production-like rollback validation with existing encrypted AI/mail
  credentials is still required before Foundation Freeze.
- Older official plugins should be audited before RC to confirm they no longer
  duplicate SMTP, AI, or storage credential handling unnecessarily.

## Conclusion

Foundation security boundaries are documented and covered by local contract
tests. Final freeze requires cross-version rollback validation with real
configuration fixtures.
