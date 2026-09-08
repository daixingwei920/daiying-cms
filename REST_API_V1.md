# REST API v1

Daiying CMS exposes a versioned REST namespace at:

```text
/api/v1
```

## Current Status

REST API v1 currently provides public read endpoints and a protected settings endpoint. Write endpoints are reserved behind the v1 contract and return a clear not-implemented response until the permission and write-contract work is complete.

## Endpoints

- `GET /api/v1`
- `GET /api/v1/contents?type=article&page=1&per_page=10`
- `GET /api/v1/pages?page=1&per_page=10`
- `GET /api/v1/categories`
- `GET /api/v1/tags`
- `GET /api/v1/media?type=image&page=1&per_page=20`
- `GET /api/v1/settings`

## Authentication

Public content, taxonomy, and media list endpoints are anonymous read endpoints.

Admin endpoints require either:

- An active administrator session, or
- `Authorization: Bearer <token>` where `hash('sha256', token)` matches `api.admin_token_sha256` or `api.tokens.admin_sha256` in site configuration.

Raw API tokens must not be committed or logged.

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
