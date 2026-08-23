# CMS Release Environment Deployment Checklist

Date: 2026-08-14

Use this checklist before exposing the first-release CMS package to public traffic.

## Web Root

- Point the web server document root to `public`.
- If the host exposes the project root, install the bundled root guard:
  - Apache: `.htaccess`
  - Nginx: `nginx-root-security.conf`
- Confirm `config`, `storage`, `system`, `tests`, `scripts`, `content/plugins` and `content/themes` are not directly web-readable.

## HTTPS And Cookies

- Set `site.url` to the public HTTPS origin.
- Set `app.secure_cookies` to `true` for HTTPS production.
- Confirm admin login cookies are `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS.

## Core Secrets

- Generate a unique production `security.encryption_key` before public launch.
- Do not keep `change-me` or other placeholder encryption keys from `config/app.example.php`.
- Keep `security.encryption_key` out of screenshots, logs and tickets; it protects encrypted CMS secret storage such as Payment Provider secrets.

## Core Payment And Card Delivery

- Configure at least one real payment Provider in `/admin/payments/providers` before publishing paid blocks.
- For manual acceptance, configure `core.manual-payment`, set it to enabled, mark it as the default Provider and enter clear payment instructions.
- Confirm `/admin/payments/providers` reloads with `core.manual-payment` shown as configured, enabled and default before testing checkout.
- If a Provider still appears disabled or unconfigured after saving, treat it as a storage-chain failure: old half-finished payment plugin rows may still carry `enabled`, `is_default`, `config_json`, `public_config`, `name` or `title` while Core reads `status` and `public_config_json`.
- Open `/admin/payments/providers` and use **修复 Provider 存储** to migrate old plugin fields, clean duplicate settings rows, then re-save the Provider.
- If the admin page is unavailable, run `php scripts/diagnose_payment_providers.php --json --repair` and check `provider_settings.unique_provider_id`, `provider_settings.legacy_plugin_storage`, `enabled_checkout_provider` and `manual_payment_ready`.
- When checkout still cannot find an enabled Provider, open `/admin/diagnostics` and inspect `payment_providers.providers.*.legacy_storage_issues`; public `/diagnostics` intentionally omits Provider internals.
- Do not enable or present `core.fixture-payment` on production sites; it is for development and automated tests only.
- Test one Card Delivery purchase end to end: checkout creates a pending payment, administrator capture marks it trusted paid, one card is delivered, stock decreases and duplicate capture does not re-deliver.

## Database

- Use SQLite for simple single-site installs, or MySQL/MariaDB with `charset=utf8mb4`.
- For MySQL/MariaDB, use a least-privilege non-root CMS user.
- Configure TLS for remote MySQL/MariaDB when the database is not on the same trusted host or network.
- Keep database passwords out of screenshots, logs and tickets.

## Runtime Directories

Ensure these directories exist and are writable by the PHP process:

- `storage/logs`
- `storage/cache`
- `storage/tmp`
- `storage/database`
- `storage/updates/incoming`
- `storage/recovery`
- `storage/plugin-installs/uploads`
- `storage/plugin-installs/staging`
- `content/uploads`

Confirm installed-site state files are owner-only where the filesystem supports POSIX permissions:

- `config/app.php`
- `storage/installed.lock`

## Media Uploads

- Enable PHP `file_uploads`.
- Enable PHP `fileinfo`; it is required for secure MIME type detection during media uploads.
- Set `media.max_file_bytes` intentionally.
- Ensure PHP `upload_max_filesize` is not smaller than `media.max_file_bytes`.
- Ensure PHP `post_max_size` is not smaller than `upload_max_filesize`.
- Ensure `memory_limit` is compatible with request parsing, or explicitly unlimited.
- Install the upload execution-denial examples:
  - Apache: `content/uploads/.htaccess`
  - Nginx: `content/uploads/upload-security.nginx.conf`

## Scheduled Publishing

- Configure cron or an equivalent scheduler:

```sh
* * * * * cd /path/to/php-cms && php scripts/publish_scheduled_content.php
```

- Confirm the command emits JSON and is safe when run repeatedly.

## Core Updates

- Configure a real Core update signing public key in `updates.public_key`.
- Do not use placeholder PEM values.
- Confirm `storage/updates/incoming` is writable and not publicly web-readable.
- Keep update packages outside public web access until uploaded through the controlled admin flow.

## Recovery

- Confirm `/recovery`, `/diagnostics` and `/admin/diagnostics` are reachable.
- Create and verify a restore point before high-risk operations.
- Confirm restore points are stored under `storage/recovery` and are not web-readable.
- For MySQL/MariaDB, confirm `mysqldump` and `mysql` are available before relying on automatic logical backup/restore.

## Final Gate

Run:

```sh
php scripts/verify_release_audit_counts.php --run
php scripts/validate_production_readiness.php --strict
```

Do not expose the site publicly while blocking errors remain.
