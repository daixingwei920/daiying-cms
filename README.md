# PHP CMS V1.2 First Release

This package is the first-release CMS main-chain build.

It focuses on the operational CMS path:

- install the site;
- log in to the admin area;
- create and publish Articles and Pages;
- edit structured content blocks;
- switch themes;
- upload and render media;
- configure Core Payment Providers;
- sell automatically delivered card-code products through content blocks;
- install, disable, uninstall and reinstall local ZIP plugins;
- browse/install official market extensions when Market API is configured;
- operate the Developer Center, Market Server review flow and AI Review evidence pipeline in V1.2 deployments;
- run signed Core updates;
- create restore points and recover the site;
- run production deployment readiness checks.

Market Server, Developer Center and AI Review are optional V1.2 platform services. They remain feature-gated and are not required for a customer CMS site to render content or operate installed extensions.

## Requirements

- PHP 8.3 or newer.
- PHP extensions: `pdo`, `json`, `openssl`, `fileinfo`, `zip`.
- PHP uploads enabled with `upload_max_filesize`, `post_max_size` and `memory_limit` sized for the configured media limit.
- SQLite for the simplest install path, or MySQL/MariaDB with `utf8mb4`.
- A web server that serves `public/index.php` as the public entry point.
- Writable runtime directories:
  - `storage/logs`
  - `storage/cache`
  - `storage/tmp`
  - `storage/database`
  - `storage/updates/incoming`
  - `storage/recovery`
  - `storage/plugin-installs/uploads`
  - `storage/plugin-installs/staging`
  - `content/uploads`

## Quick Start

1. Extract the package on the server.
2. Point the web server document root to `public`.
3. Ensure runtime directories are writable by the PHP process.
4. Open `/install` and complete the installer.
5. Log in at `/admin/login`.
6. Run the deployment readiness checker before exposing the site publicly.

```sh
php scripts/validate_production_readiness.php
php scripts/validate_production_readiness.php --json
php scripts/validate_production_readiness.php --strict
```

`--strict` exits non-zero when blocking production-readiness errors are present.

## Scheduled Publishing

Configure cron or an equivalent scheduler to publish due scheduled content:

```sh
* * * * * cd /path/to/php-cms && php scripts/publish_scheduled_content.php
```

The command emits JSON and is safe to run repeatedly.

## Security Notes

- Keep `config/app.php` outside public web access.
- Installed `config/app.php` and `storage/installed.lock` are written with owner-only permissions where the filesystem supports it.
- Prefer using `public` as the web document root; root-level Apache and Nginx guard examples are included for hosts that expose the project root.
- Use HTTPS in production, set `app.secure_cookies` to `true`, and enable `security.hsts_enabled` once the HTTPS host is stable.
- Keep `security.admin_mfa.runtime_enforcement` enabled and `implemented_methods` declaring `totp` and `recovery_codes`; `passkey` remains a reserved future method.
- Use a least-privilege MySQL/MariaDB user after installation.
- Use `charset=utf8mb4` for MySQL/MariaDB DSNs.
- Configure TLS for remote MySQL/MariaDB deployments when applicable.
- Configure a real Core update signing public key before enabling production updates; placeholder PEM examples are rejected by the production readiness checker.
- Generate a unique `security.encryption_key` for production; do not keep the `change-me` example because Core uses this key for encrypted secret storage.
- Set `media.max_file_bytes` intentionally for production and ensure PHP `upload_max_filesize` and `post_max_size` are not smaller.
- Enable PHP `fileinfo`; media uploads fail closed without it because MIME type detection is security-critical.
- Do not allow PHP, CGI or server-side script execution from `content/uploads`.
- Keep restore points, logs, databases and exports out of public web access.
- Do not place private keys, database passwords or full tokens in issue reports or screenshots.

The package includes upload-directory execution-denial examples:

- `content/uploads/.htaccess`
- `content/uploads/upload-security.nginx.conf`

The package includes project-root exposure guard examples:

- `.htaccess`
- `nginx-root-security.conf`

## Main Features

- Visual installer with database checks, install lock and rollback-oriented failure handling.
- Admin authentication with CSRF protection, session hardening, audit logging and Core TOTP/recovery-code MFA.
- Structured content editor using `blocks_json`.
- Article and Page routes, clean URLs, status handling, previews, sitemap, robots and SEO metadata.
- Theme discovery, compatibility checks, settings isolation and safe-theme fallback.
- Media library with MIME validation, randomized storage keys, deduplication, references, safe downloads and HTML5 audio/video rendering.
- Core Payment foundation with manual confirmation and hosted-redirect Provider settings managed from `/admin/payments/providers`.
- Read-only Payment Provider diagnostics via `php scripts/diagnose_payment_providers.php --json`; use explicit `--repair` only when old duplicate Provider rows or legacy payment-plugin fields need Repository-backed cleanup.
- Release audit count verification via `php scripts/verify_release_audit_counts.php --run`, which compares `CMS_COMPLETION_STATUS_AUDIT_V2.md` against the actual `[PASS]` output from each listed PHP test.
- Admin-only `/admin/diagnostics` reports Payment Provider readiness and legacy storage issue labels; public `/diagnostics` keeps Provider internals hidden.
- Automatic Card Delivery products, inventory import, payment-backed checkout and idempotent fulfillment.
- Safe content deletion for Articles and Pages with server-side permission checks and CSRF-protected POST actions.
- Compact admin backend density for desktop CMS workflows.
- Local ZIP plugin lifecycle with safe extraction, manifest validation, dependency checks, static scan, atomic install, data retention and reinstall recovery.
- Plugin migration recovery with reversible migration state and failed rollback evidence.
- Signed Core update flow with restore points, immutable release directories, atomic pointer switch, health checks and rollback.
- Recovery Mode, diagnostics and startup logging.

## Validation Evidence

The release audit is in:

- `CMS_COMPLETION_STATUS_AUDIT_V2.md`
- `CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md`

The current audit records 1256 counted automated PASS checks across install, admin security, content/SEO/import/export/public API registry, media, plugins/risk-boundary policy, Core update/recovery/update-server client, payment/Card Delivery, admin density, Developer Center package/isolated submission/scan queue/freeze-sign-distribute/review-admin evidence/actions/review/sales boundary, Market AI Review/OpenAI structured adapter, production package safety, deployment readiness, final artifact verification and final sign-off consistency.

Useful verification commands:

Browser and Playwright smoke commands require a local environment that can bind `127.0.0.1` for the PHP built-in server. The 2026-08-23 browser pass covered full release E2E, admin UX smoke, theme visual smoke, admin density screenshots, Payment Provider persistence/Card Delivery capture, and Card Delivery block editor checkout rendering.

```sh
perl -e 'alarm shift; exec @ARGV' 300 sh -c '/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/browser_release_e2e.js | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 300 sh -c '/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/admin_ux_smoke.js | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 300 sh -c '/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/theme_visual_smoke.js | grep -c "^\[PASS\]"'
/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/admin_density_browser_smoke.js
/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/payment_provider_browser_smoke.js
/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/block_editor_card_delivery_browser_smoke.js
perl -e 'alarm shift; exec @ARGV' 300 sh -c 'php tests/mysql_release_acceptance.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/production_deployment_readiness.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/admin_login_security.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 120 php scripts/verify_release_audit_counts.php --run
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/release_environment_checklist.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/release_final_artifact_verification.php | grep -c "^\[PASS\]"'
```

## Release Package

Build the distributable ZIP with:

```sh
php scripts/build_release_package.php daiying-cms-1.2.0.zip
```

The package builder excludes runtime logs, databases, exports, recovery archives, market runtime artifacts, uploaded media and nested ZIP files while preserving required empty runtime directories.

The builder also writes package sidecars:

- `daiying-cms-1.2.0.zip.sha256`
- `daiying-cms-1.2.0.manifest.json`

## Recovery

Recovery entry points:

- `/recovery`
- `/diagnostics`
- `/admin/diagnostics`

Recovery and diagnostics output is redacted to avoid exposing passwords, tokens, sessions, private keys, DSNs and absolute server paths.

## More Reports

Detailed implementation and validation reports:

- `CMS_RELEASE_BATCH1_INSTALL_REPORT.md`
- `CMS_RELEASE_BATCH2_THEME_REPORT.md`
- `CMS_RELEASE_BATCH3_CONTENT_EDITOR_SEO_REPORT.md`
- `CMS_RELEASE_BATCH4_MEDIA_REPORT.md`
- `CMS_RELEASE_BATCH5_PLUGIN_LIFECYCLE_REPORT.md`
- `CMS_RELEASE_BATCH5A_MIGRATION_RECOVERY_REPORT.md`
- `CMS_RELEASE_BATCH6_CORE_UPDATE_REPORT.md`
- `CMS_RELEASE_BATCH7_PRODUCTION_RECOVERY_REPORT.md`
- `CMS_RELEASE_MEDIA_UPLOAD_READINESS_REPORT.md`
- `CMS_RELEASE_MYSQL_BACKUP_TOOL_READINESS_REPORT.md`
- `CMS_RELEASE_ARTIFACT_EXPOSURE_GUARD_READINESS_REPORT.md`
- `CMS_RELEASE_HSTS_SECURITY_HEADERS_REPORT.md`
- `CMS_RELEASE_DIAGNOSTICS_LOG_REDACTION_REPORT.md`
- `CMS_RELEASE_RESTORE_POINT_PATH_SAFETY_REPORT.md`
- `CMS_RELEASE_INSTALL_FILE_PERMISSION_REPORT.md`
- `CMS_RELEASE_THEME_VISUAL_SMOKE_REPORT.md`
- `CMS_RELEASE_MOBILE_VISUAL_SMOKE_REPORT.md`
- `CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md`
- `CMS_RELEASE_ENVIRONMENT_CHECKLIST_REPORT.md`
- `CMS_RELEASE_ENVIRONMENT_CHECKLIST_TEST_REPORT.md`
- `CMS_RELEASE_FINAL_SIGNOFF_REPORT.md`
- `CMS_RELEASE_DEPLOYMENT_READINESS_REPORT.md`
