# Daiying CMS Storage API v1 Report

## Goal

Storage Provider API v1 lets third-party plugins implement media storage and
external media access without modifying Core.

## Implemented Contracts

- `Cms\Core\Media\MediaStorageProviderInterface`
- `Cms\Core\Media\MediaStorageProviderV1Interface`
- `Cms\Core\Media\RemoteMediaProviderInterface`
- `Cms\Core\Media\RemoteMediaProviderV1Interface`
- `Cms\Core\Media\StorageProviderCapabilities`
- `Cms\Core\Media\RemoteMediaProviderRegistry`
- `Cms\Core\Media\MediaLibrary`

## Supported Provider Capabilities

- upload
- read
- delete
- move
- metadata
- metadata refresh
- public URL
- signed URL
- proxy URL
- stream/proxy stream
- byte range
- download
- read-only
- connection test

## Plugin Registration

Plugins register storage integrations through:

```php
$context->registerRemoteMediaProvider($provider);
```

The plugin must declare:

```json
"capabilities": ["storage.plugin"]
```

## Existing Provider Boundary

Baidu Netdisk and Cloudreve logic should remain provider/plugin logic. Core owns
the interface, registry, media records, and safe access boundary. Provider
authentication, API calls, token refresh, folder browsing, and remote service
quirks must not be hard-coded into Core.

## Remaining Work

- Finalize REST binary media upload contract.
- Add more package-level fixture tests for third-party storage provider install,
  enable, upload/read/delete, and upgrade persistence.
- Add documentation examples for a minimal read-only provider and a full upload
  provider.

## Conclusion

Storage Provider API v1 is present and documented. It is ready for official
storage plugins to consume, subject to the final cross-version package matrix.
