# Daiying CMS AI Database Migration

Status: Additive and idempotent

## Existing Tables

`cms_core_ai_settings` remains the source of site AI configuration. Existing encrypted API keys are preserved. Provider aliases and missing adapter values are normalized by the provider preset migration.

## New Migration

Migration: `2026_09_08_000006_ai_foundation_v1`

New tables:

- `cms_ai_usage_ledger`
- `cms_ai_quota_policies`
- `cms_ai_jobs`
- `cms_ai_prompts`
- `cms_ai_audit_events`

All tables are additive. The migration uses `CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS`, so it can be safely re-run by cross-version update flows.

## Upgrade Compatibility

- Old sites without AI receive disabled safe defaults.
- Existing AI API keys are not rewritten.
- New fields are additive and receive safe defaults.
- AI jobs reuse the Core queue, preserving one queue system.
- AI prompts are versioned by `prompt_id`, `version`, and `language`.
- Rollback restores the previous database snapshot through the existing update recovery path; the migration does not delete user data.

## Secret Handling

The migration does not copy or export API keys. Runtime decryption remains inside `SiteAiSettingsRepository` and is only used server-side by the AI service.
