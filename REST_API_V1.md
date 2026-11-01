# REST API v1

Daiying CMS exposes a versioned REST namespace at:

```text
/api/v1
```

## Current Status

REST API v1 provides public read endpoints plus protected administrator write
operations for Core content and taxonomy resources. Since Core 1.2.91, it also
provides protected multipart media upload, safe metadata PATCH, real media pagination,
single active-media reads and Core content-type discovery.

## Endpoints

- `GET /api/v1` (includes resource method/auth discovery)
- `GET /api/v1/content-types`
- `GET /api/v1/contents?type=article&page=1&per_page=10`
- `GET /api/v1/contents/{id}`
- `POST /api/v1/contents`
- `PATCH /api/v1/contents/{id}`
- `DELETE /api/v1/contents/{id}`
- `GET /api/v1/pages?page=1&per_page=10`
- `POST /api/v1/pages`
- `PATCH /api/v1/pages/{id}`
- `DELETE /api/v1/pages/{id}`
- `GET /api/v1/categories`
- `POST /api/v1/categories`
- `PATCH /api/v1/categories/{id}`
- `DELETE /api/v1/categories/{id}`
- `GET /api/v1/tags`
- `POST /api/v1/tags`
- `PATCH /api/v1/tags/{id}`
- `DELETE /api/v1/tags/{id}`
- `GET /api/v1/media?type=image&page=1&per_page=20`
- `GET /api/v1/media/{id}`
- `POST /api/v1/media` (multipart `file`, administrator authentication)
- `PATCH /api/v1/media/{id}` (metadata only)
- `GET /api/v1/comments`
- `PATCH /api/v1/comments/{id}`
- `DELETE /api/v1/comments/{id}`
- `GET /api/v1/users`
- `GET /api/v1/settings`

## Authentication

Public content, taxonomy, and media list endpoints are anonymous read endpoints.
Single content reads are anonymous only for published content.

Admin endpoints require either:

- An active administrator session, or
- `Authorization: Bearer <token>` where `hash('sha256', token)` matches `api.admin_token_sha256` or `api.tokens.admin_sha256` in site configuration.

Raw API tokens must not be committed or logged.

## Write Contract

Protected content writes use the existing Core `ContentRepository` validation and
therefore preserve slug checks, block sanitization, media reference validation,
scheduled publishing fields, revisions, and trash semantics.

Supported body fields:

- `type` or `content_type`
- `title`
- `slug`
- `status`
- `blocks`
- `meta`
- `categories`
- `tags`

`DELETE` moves content to trash by default. Explicit permanent deletion requires
`hard_delete=true`.

Protected taxonomy writes accept:

- `name`
- `slug`

Protected comment writes currently support status moderation and deletion.

`GET /api/v1/users` returns administrator and front-user identity summaries with
redacted email addresses and never includes password hashes.

## Response Shape

Successful responses:

```json
{
  "ok": true,
  "data": {}
}
```

Errors:

```json
{
  "ok": false,
  "error": {
    "code": "unauthorized",
    "message": "REST API admin token or admin session is required.",
    "status": 401
  }
}
```

## Compatibility

REST API v1 follows the Core public API compatibility policy. Minor releases may add fields and endpoints. Removing fields or changing existing response shapes requires a major API version.


## Issue #9 candidate compatibility

Unpublished, based on Core 1.2.88. See [SEO/GEO contract](docs/seo-geo-contract.md) and [member collaboration retirement](docs/member-editorial-retirement.md). Existing theme/SDK/REST signatures and SEO settings keys remain. Core may enhance full theme HTML heads when optional SEO settings are enabled; homepage providers bypassing ThemeRuntime own their output. The retired member workspace is not a machine publishing API. No changes to REST content/media write scope are included.

## Core 1.2.91 media/discovery contract

`GET /api/v1/content-types` lists only the built-in article/page definitions currently supported by the REST repository. It does not automatically publish plugin schemas or internal data. The index adds `resource_capabilities` without removing existing fields.

Media writes accept the existing administrator bearer token, or a non-revoked administrator session with `media.manage` (or superadmin) and a valid `_csrf` / `X-CSRF-Token`. Browser cookies alone are insufficient. This does not create a new account/token system or grant privileges to ordinary members.

```sh
curl -H 'Authorization: Bearer YOUR_ADMIN_TOKEN' -F 'file=@cover.png' https://YOUR_SITE/api/v1/media
curl -X PATCH -H 'Authorization: Bearer YOUR_ADMIN_TOKEN' -H 'Content-Type: application/json' \
  --data '{"alt_text":"Cover description"}' https://YOUR_SITE/api/v1/media/123
```

Only real PHP multipart uploads are accepted; local server paths and URL imports are unsupported. Uploads use MediaLibrary MIME/extension/size/quota/deduplication/storage protections and native audit records. PHP/scripts, SVG, mismatched extensions and invalid or oversized files remain rejected.

PATCH allows only text `title`, `description`, `alt_text` (up to 10000 bytes each); omitted values remain unchanged. Storage paths, provider/status changes, arbitrary metadata and deletion are not opened. Only Active records can be read/edited. Public responses contain the safe view model rather than storage paths or provider credentials.

Media pagination stays `page`/`per_page` (1–50); a one-record lookahead determines `has_more`, including a final exactly-full page. Existing MediaLibrary callers remain compatible; its optional third `list(..., int $offset = 0)` argument implements the offset.
