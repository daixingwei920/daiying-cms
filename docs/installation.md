# Installation

This page summarizes the installer behavior confirmed from the current Daiying CMS repository.

## Requirements

- PHP 8.3.0 or newer.
- PHP extensions: `pdo`, `json`, `openssl`, `fileinfo`, `zip`.
- SQLite, or MySQL/MariaDB using `utf8mb4`.
- A web server configured to serve `public/index.php`.
- Writable runtime paths for the PHP process:
  - `storage/`
  - `storage/logs/`
  - `storage/cache/`
  - `storage/tmp/`
  - `storage/database/`
  - `storage/updates/incoming/`
  - `storage/recovery/`
  - `storage/plugin-installs/uploads/`
  - `storage/plugin-installs/staging/`
  - `content/uploads/`

## Install Flow

1. Download the latest stable ZIP from GitHub Releases.
2. Upload and extract it on the server.
3. Point the web server document root to `public`.
4. Open `/install`.
5. Review the environment checks.
6. Choose SQLite or MySQL/MariaDB.
7. Use the installer database test before installing.
8. Enter the site name, optional site URL, administrator email, display name, and administrator password.
9. Submit the installer.
10. Log in at `/admin/login`.

The installer runs Core migrations, writes `config/app.php`, creates runtime directories, and writes `storage/installed.lock`.

## Production Readiness

Run the readiness checker before public launch:

```sh
php scripts/validate_production_readiness.php
php scripts/validate_production_readiness.php --json
php scripts/validate_production_readiness.php --strict
```

`--strict` exits with a non-zero status when blocking readiness errors are present.

## Security Checklist

- Use HTTPS for public production sites.
- Enable secure cookies in production.
- Use a unique `security.encryption_key`.
- Keep `config/app.php`, databases, logs, recovery files, and update packages outside public web access.
- Do not allow server-side script execution from `content/uploads/`.
- Use a least-privilege MySQL/MariaDB account when not using SQLite.
