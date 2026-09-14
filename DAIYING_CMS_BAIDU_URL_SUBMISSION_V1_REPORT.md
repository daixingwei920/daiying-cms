# Daiying CMS Baidu URL Submission Provider V1 Report

## Scope

Implemented `official.seo.baidu-submit` as an official plugin for Baidu Search Resource Platform URL submission.

This work does not modify existing `sitemap.xml`, `robots.txt`, Google SEO, or Bing SEO behavior.

## Modified Core Boundary

Core only adds a generic content publication event:

- `Cms\Core\Content\ContentPublishedEvent`

`AdminController` dispatches this event after a content item becomes publicly published. The event carries only safe content metadata:

- content id
- content type
- title
- slug
- public path
- public URL
- published time
- trigger

No Baidu-specific logic was added to Core.

## Plugin Files

- `content/plugins/official.seo.baidu-submit/plugin.json`
- `content/plugins/official.seo.baidu-submit/plugin.php`
- `content/plugins/official.seo.baidu-submit/migrations/001_baidu_url_submission.php`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionController.php`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionHttpClient.php`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionRepository.php`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionResult.php`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionService.php`
- `content/plugins/official.seo.baidu-submit/src/BaiduUrlSubmissionTransportInterface.php`

## Database Changes

Plugin-owned tables:

- `baidu_url_submission_settings`
- `baidu_url_submission_logs`

Plugin migration id:

- `baidu_url_submission_001_core`

Affected objects:

- `table:baidu_url_submission_settings`
- `table:baidu_url_submission_logs`

The migration is idempotent and stays inside the plugin-owned `baidu_url_submission_` namespace.

## Secret Handling

The Baidu API token is saved through `PluginSecretStore` under the plugin id `official.seo.baidu-submit`.

Security behavior:

- token is encrypted at rest
- admin form only shows a masked placeholder
- blank token save preserves the existing token
- token is not written to logs
- token is not written to Git
- token is not rendered into frontend HTML
- submission result logs are redacted before persistence

## Admin UI

New admin route:

- `/admin/seo/baidu-submit`

Actions:

- save settings
- manually submit one or more same-site URLs
- show Baidu response fields
- show recent submission logs
- process pending queue jobs on ordinary PHP hosting when no worker is configured

Displayed Baidu response fields:

- `HTTP status`
- `success`
- `remain`
- `not_same_site`
- `not_valid`

## Submission Rules

Supported submission sources:

- manual admin submission
- content publish event -> Core Queue job

Validation:

- only same-site absolute `http` / `https` URLs are accepted
- external hosts are rejected before any POST
- URL fragments are stripped
- duplicate URLs are skipped inside the configured dedupe window

Default dedupe window:

- 1800 seconds

Queue job type:

- `baidu_url_submission.submit`

Queue owner:

- `official.seo.baidu-submit`

## Bot / Quota Behavior

This provider submits only server-side admin/publish events. It does not count or submit crawler page visits.

If Baidu returns `remain = 0`, the result is recorded as quota exhausted and does not create a retry loop.

## Verification Plan Results

1. Existing Baidu URL Submit feature audit: PASS, no existing URL submission feature found.
2. Token storage security: PASS, encrypted via `PluginSecretStore`.
3. Manual submit response field preservation: PASS with fake Baidu transport.
4. Same-site URL validation: PASS.
5. Dedupe window: PASS.
6. `not_same_site` / `not_valid` parsing: PASS.
7. Queue integration: PASS.
8. Content publish event integration: PASS.

Real Baidu POST test:

- PENDING.
- Waiting for the administrator to install/enable the plugin, enter the Baidu token in `/admin/seo/baidu-submit`, and submit exactly one already public Daiying CMS URL.

## Tests Run

- `php -l system/core/Content/ContentPublishedEvent.php`
- `php -l system/core/Admin/AdminController.php`
- `php -l system/core/Bootstrap/Application.php`
- `find content/plugins/official.seo.baidu-submit/src -name '*.php' -print -exec php -l {} \;`
- `php tests/official_baidu_url_submission.php`
- `php tests/plugin_decoupling_foundation.php`
- `php tests/market_plugin_migrations.php`
- `php tests/admin_market_trust_grant_authorization.php`
- `for f in tests/*.php; do php "$f" || exit 1; done`
- `git diff --check`

Full PHP test suite result:

- PASS, except the pre-existing cross-version fixture test remains SKIP because historical fixture artifacts are absent.

`git diff --check` result:

- PASS.

## Worktree Note

Before the final `git diff --check`, `system/official-plugins.php` had an unrelated generated-format change with trailing whitespace. Its registry content was semantically identical to the committed version, so it was restored to the repository format to avoid polluting this Baidu Submit implementation and future update package validation.

## Core Update Manifest

`system/core-manifest.json` was updated for:

- `Admin/AdminController.php`
- `Bootstrap/Application.php`
- `Content/ContentPublishedEvent.php`

## Production Status

Not deployed.

No Baidu token was entered or tested in this implementation pass.

## Next Manual Step

After this plugin is packaged and installed from the official market, open:

`/admin/seo/baidu-submit`

Then:

1. Enable Baidu URL submission.
2. Set the site URL.
3. Enter the Baidu API token.
4. Submit one public Daiying CMS URL.
5. Confirm the page shows Baidu `HTTP status`, `success`, `remain`, `not_same_site`, and `not_valid`.
