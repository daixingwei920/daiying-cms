# Security Policy

## Supported Version

The current public stable line is Daiying CMS `1.2.x`. The latest checked public release is `1.2.26`.

## Reporting A Vulnerability

Please do not open a public issue with exploit details, secrets, private keys, database dumps, or production logs.

Use the official website contact path or the public contact email when available:

- Website: <https://www.daiyingcms.com>
- Email: `daixingwei@126.com`

## Sensitive Data Rules

Never include the following in GitHub issues, pull requests, screenshots, or release packages:

- `.env` files.
- `config/app.php` from production.
- Database dumps or SQLite production databases.
- OAuth client secrets, access tokens, or refresh tokens.
- Payment API keys, webhook secrets, signing secrets, or Authorization headers.
- Core update signing private keys.
- Server SSH keys and credentials.
- Sessions, cookies, logs, and recovery archives.

## Production Hardening

- Serve only `public/` as the web document root.
- Keep `config/`, `storage/`, `system/`, `tests/`, `scripts/`, `content/plugins/`, and `content/themes/` out of public execution.
- Disable PHP or CGI execution in `content/uploads/`.
- Use HTTPS and secure cookies in production.
- Generate a unique `security.encryption_key`.
- Run `php scripts/validate_production_readiness.php --strict` before public launch.

## Update Security

Installed sites should use the official update server:

```text
https://updates.daiyingcms.com
```

Update packages must be verified by SHA-256, manifest, and signature before installation.
