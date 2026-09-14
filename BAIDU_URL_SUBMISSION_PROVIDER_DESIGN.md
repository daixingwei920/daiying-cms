# Baidu URL Submission Provider V1 Design

## Current Status

Daiying CMS currently provides dynamic `sitemap.xml`, `robots.txt`, URL mappings, and SEO metadata extension points. No Baidu Search Resource Platform active URL submission feature was found in Core, installed official-site plugins, or the production database.

The existing Baidu integration is `local.storage.baidu`, which is a Baidu Netdisk storage/media provider and is unrelated to Baidu Search Resource Platform URL submission.

## Scope

This V1 should be an official website/CMS extension or a small SEO provider layer, not a replacement for sitemap generation.

Goals:

- submit newly published article/page URLs to Baidu
- allow administrators to manually submit one URL
- allow administrators to batch submit selected or pasted URLs
- record Baidu API results
- rate-limit duplicate submissions
- store the Baidu token as an encrypted secret
- keep Google/Bing sitemap behavior unchanged

Out of scope:

- crawling or indexing guarantees
- complex SEO scoring
- Baidu account management
- search ranking reports
- keyword tracking
- copying tokens into Git, logs, HTML, or public docs

## Provider Contract

Suggested provider id:

`official.seo.baidu-submit`

Suggested public API:

```php
interface UrlSubmissionProviderInterface
{
    public function id(): string;

    public function label(): string;

    /** @param list<string> $urls */
    public function submit(array $urls, UrlSubmissionContext $context): UrlSubmissionResult;

    public function testConnection(): UrlSubmissionResult;
}
```

The Baidu provider should call:

`POST https://data.zz.baidu.com/urls?site={site_url}&token={token}`

with body:

```text
https://www.daiyingcms.com/articles/example
https://www.daiyingcms.com/page-example
```

Direct browser GET returning `400 empty content` is expected and must not be treated as a CMS failure.

## Secret Handling

The Baidu token must be stored through the existing encrypted secret infrastructure:

- use `PluginSecretStore` or the current Foundation credential API
- never persist the token in `cms_settings`
- never render the token back to the browser
- show only masked status in admin, for example `已配置（********abcd）`
- leave existing token unchanged when the token field is empty on save
- redact token values from exceptions and logs

Suggested secret key:

`official.seo.baidu-submit:baidu_url_submit_token`

## Database Schema

Suggested tables:

```sql
CREATE TABLE IF NOT EXISTS baidu_url_submission_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_url TEXT NOT NULL,
    enabled INT NOT NULL DEFAULT 0,
    dedupe_window_seconds INT NOT NULL DEFAULT 1800,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

```sql
CREATE TABLE IF NOT EXISTS baidu_url_submission_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL,
    url_hash TEXT NOT NULL,
    content_id INTEGER NULL,
    content_type TEXT NULL,
    trigger_type TEXT NOT NULL,
    status TEXT NOT NULL,
    http_status INTEGER NULL,
    success_count INTEGER NOT NULL DEFAULT 0,
    remain_count INTEGER NULL,
    not_same_site_count INTEGER NOT NULL DEFAULT 0,
    response_json TEXT NULL,
    error_message TEXT NULL,
    submitted_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

Recommended indexes:

```sql
CREATE INDEX IF NOT EXISTS idx_baidu_url_submission_logs_hash_time
ON baidu_url_submission_logs (url_hash, submitted_at);
```

```sql
CREATE INDEX IF NOT EXISTS idx_baidu_url_submission_logs_status_time
ON baidu_url_submission_logs (status, submitted_at);
```

## Admin UI

Add an admin page such as:

`/admin/seo/baidu-submit`

Fields:

- enable Baidu URL submission
- site URL
- Baidu API token
- dedupe window seconds
- manual URL textarea
- batch submit button
- test connection button

Dashboard sections:

- latest submission result
- success count
- remaining Baidu quota
- recent submitted URLs
- failures grouped by Baidu error code

Admin-only access and CSRF protection are required.

## Publish Hook

When article/page status changes from non-public to `published`, enqueue a submission job.

Do not block the content save request on Baidu API latency.

If a generic content event exists, use:

`content.published`

If not, add a small stable event dispatch point around successful content create/update. The event should contain only:

- content id
- content type
- slug
- public URL
- published_at

Do not include drafts, private content, preview tokens, API keys, session values, or full editor body in the event payload.

## Queue Behavior

Use Core queue if available:

- job type: `baidu_url_submission.submit`
- owner: `official.seo.baidu-submit`
- max attempts: 3
- retry delay: standard queue retry delay

If Baidu returns `remain = 0`, do not retry until the next day or administrator action.

If Baidu returns `not_same_site`, mark as failed and do not retry automatically.

Network errors may retry with backoff.

## Dedupe Rules

Before submitting a URL:

- normalize URL
- require HTTPS
- require host matches configured site
- hash normalized URL
- check latest log for the same `url_hash`
- if within `dedupe_window_seconds`, skip and record `deduped`

Default dedupe window:

`1800` seconds.

## Bot and Sitemap Isolation

This provider submits only URLs selected by the CMS/admin workflow.

It must not:

- count crawler visits
- submit on every public page view
- modify sitemap XML
- ping Google/Bing
- expose a public endpoint that accepts arbitrary URLs

## Baidu Response Mapping

Expected success-like response:

```json
{
  "remain": 99999,
  "success": 1,
  "not_same_site": [],
  "not_valid": []
}
```

Store:

- `success`
- `remain`
- `not_same_site`
- `not_valid`
- HTTP status

If `remain` is `0`, display:

`百度当天 API 提交额度已用完，本次不会继续自动重试。`

If `not_same_site` is non-empty, display:

`百度返回 not_same_site，请确认站点 URL 与百度搜索资源平台绑定站点一致。`

## Verification Plan

1. Save settings with token and confirm token is encrypted.
2. Reopen settings and confirm only masked token is shown.
3. Submit one real article URL and confirm Baidu returns parsed JSON.
4. Submit the same URL again within the dedupe window and confirm it is not counted as a new API call.
5. Publish a new article and confirm a queue job is created.
6. Process queue and confirm the result log is recorded.
7. Test with `remain = 0` and confirm no retry loop.
8. Confirm `sitemap.xml` and `robots.txt` output remains unchanged.

## Production Notes

The Baidu API quota cannot be checked without the real Baidu submission token and a valid POST request body. Once the token is provided through the admin settings page, the provider should run a single test submission against a known public URL and record Baidu's `success` and `remain` values.

Do not paste the token into GitHub issues, public docs, screenshots, or chat logs.
