# Storage Provider API v1

Daiying CMS Core defines Storage Provider API v1 so external storage plugins can integrate with the media library without modifying Core.

## Local Storage Providers

Local or primary storage providers implement:

```php
Cms\Core\Media\MediaStorageProviderV1Interface
```

The legacy `MediaStorageProviderInterface` remains supported for backward compatibility.

Provider methods cover:

- Provider ID and label
- API version
- Capability flags
- Connection test
- Upload / put
- Read and stream
- Delete
- Move
- Metadata and metadata refresh
- Public URL
- Signed URL

## Remote Media Providers

External media providers implement:

```php
Cms\Core\Media\RemoteMediaProviderV1Interface
```

The legacy `RemoteMediaProviderInterface` remains supported. The registry can describe legacy providers and infer capabilities so existing plugins keep working.

## Capabilities

Known capabilities are declared in:

```php
Cms\Core\Media\StorageProviderCapabilities
```

Current capabilities:

- `read`
- `upload`
- `delete`
- `move`
- `metadata`
- `metadata_refresh`
- `public_url`
- `signed_url`
- `proxy_url`
- `proxy_stream`
- `byte_range`
- `download`
- `read_only`
- `test_connection`

## Plugin Registration

Plugins should register remote media providers through:

```php
$context->registerRemoteMediaProvider($provider);
```

The plugin must declare:

```json
"capabilities": ["storage.plugin"]
```

Direct calls to `RemoteMediaProviderRegistry::register()` remain compatible for older plugins, but new plugins should use `PluginContext`.

## Boundary

Core provides the API and registry only. Provider-specific logic for Baidu Netdisk, Cloudreve, S3, R2, OSS, COS, FTP, SFTP, Qiniu, Upyun, and similar services belongs in plugins.
