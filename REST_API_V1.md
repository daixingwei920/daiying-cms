# REST API v1

Daiying CMS exposes a versioned REST namespace at:

```text
/api/v1
```

## Current Status

REST API v1 provides public read endpoints plus protected administrator write
operations for Core content and taxonomy resources. Media upload remains
explicitly reserved until the binary upload contract is finalized.

## Endpoints

- `GET /api/v1`
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
- `POST /api/v1/media` returns `not_implemented` until media upload v1 is finalized.
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
