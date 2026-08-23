# CMS Release Final Sign-Off Checklist

Date: 2026-08-14

Scope: V1.2 CMS main chain including optional Market client, Developer Center, Market Server review flow and AI Review evidence pipeline.

## Release Artifact

- Package: `daiying-cms-1.2.0.zip`
- Package SHA-256 sidecar: `daiying-cms-1.2.0.zip.sha256`
- Package artifact manifest: `daiying-cms-1.2.0.manifest.json`
- Package source: canonical builder `scripts/build_release_package.php`
- Required runtime directories are preserved as empty package entries.
- Runtime logs, databases, exports, recovery archives, market runtime data, uploaded media and nested ZIP artifacts are excluded.
- Installed locks, transient install locks, local database files and local path traces are excluded from the installable package.
- Project-root Web exposure guards for Apache and Nginx are included for misconfigured-host mitigation.
- Upload execution-denial rules for Apache and Nginx are included.

## Main-Chain Acceptance

- Install wizard, database connection checks, install lock and rollback-oriented install behavior are covered.
- Admin login, CSRF, session cookie security and permission checks are covered.
- Article and Page creation, structured blocks, publishing, frontend access, SEO, sitemap and route behavior are covered.
- Scheduled content is covered for pre-time invisibility, due publish, stable cron execution and idempotent repeated scheduler runs.
- Theme switching is covered for compatibility, settings isolation, fallback and frontend rendering.
- Media upload, references, safe downloads, HTML5 audio/video and controlled Range support are covered.
- Local ZIP plugin install, dependency checks, enable/disable, uninstall-code data retention, purge confirmation and reinstall recovery are covered.
- Plugin migration failure rollback and retry safety are covered.
- Signed Core update, dry run, restore points, immutable release directories, atomic pointer switch, health checks, rollback and interruption handling are covered.
- Production readiness rejects missing or placeholder Core update signing public keys.
- Recovery Mode, diagnostics, startup logs, SQLite restore and MySQL/MariaDB logical backup/restore are covered.

## Final Validation Commands

All commands must be run with explicit timeouts.

```sh
perl -e 'alarm shift; exec @ARGV' 240 sh -c 'find system tests scripts -name "*.php" -print0 | xargs -0 -n 1 php -l'
perl -e 'alarm shift; exec @ARGV' 300 sh -c '/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/browser_release_e2e.js | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 300 sh -c 'php tests/mysql_release_acceptance.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 120 php scripts/build_release_package.php daiying-cms-1.2.0.zip
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/production_deployment_readiness.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/release_documentation_scope.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/release_artifact_sidecars.php | grep -c "^\[PASS\]"'
perl -e 'alarm shift; exec @ARGV' 60 sh -c 'php tests/release_final_artifact_verification.php | grep -c "^\[PASS\]"'
```

## Sign-Off Status

- Current audit: `CMS_COMPLETION_STATUS_AUDIT_V2.md`
- Current counted automated PASS checks after public read guard, URL Mapping source/repository safety, restore preflight validation, safe failure notices, runtime URL Mapping safety regression coverage, Core public API registry coverage, Plugin risk boundary policy coverage, official Core update server client coverage and Provider storage self-check and content delete preview-token/method guard and Provider write method guard and payment lifecycle method guard and half-built payment plugin Provider field normalization plus legacy is_enabled form compatibility coverage, Core admin MFA runtime coverage, Market AI Review/OpenAI structured adapter coverage, V1.2 developer-mode gate coverage, Market Developer Package Builder, isolated submission, scan queue, freeze/sign/distribute, Review Admin evidence/actions and Developer Center review/sales boundary coverage, diagnostic CLI help coverage and readiness CLI help coverage and scheduled publish CLI help coverage: 1256
- Release artifact sidecar verifier: passed, 12 checks.
- Final artifact verifier: passed, 56 checks.
- Release sign-off consistency verifier: passed, 11 checks.
- Production security headers verifier: passed, 9 checks.
- Production deployment readiness verifier: passed, 61 checks.
- Install E2E verifier: passed, 26 checks.
- Release recovery diagnostics verifier: passed, 33 checks.

## Deployment Notes

- Confirm production PHP version and required extensions before deployment.
- Confirm web server routes all public traffic through `public/index.php`.
- Confirm uploads are served only through the controlled media route or with the packaged execution-denial rules applied.
- Confirm production configuration enables secure cookies behind HTTPS.
- For MySQL/MariaDB, use a least-privilege CMS database user after installation and keep backup credentials out of logs and process arguments.
