<?php

declare(strict_types=1);

namespace Cms\Core\Media;

use Throwable;

final class LocalMediaStorageProvider implements MediaStorageProviderV1Interface
{
    public function __construct(private readonly string $root)
    {
    }

    public function id(): string
    {
        return 'local';
    }

    public function apiVersion(): string
    {
        return '1.0';
    }

    public function label(): string
    {
        return 'Local filesystem';
    }

    public function capabilities(): array
    {
        return [
            StorageProviderCapabilities::READ,
            StorageProviderCapabilities::UPLOAD,
            StorageProviderCapabilities::DELETE,
            StorageProviderCapabilities::MOVE,
            StorageProviderCapabilities::METADATA,
            StorageProviderCapabilities::PUBLIC_URL,
            StorageProviderCapabilities::BYTE_RANGE,
            StorageProviderCapabilities::TEST_CONNECTION,
        ];
    }

    public function testConnection(): StorageProviderHealth
    {
        try {
            if (!is_dir($this->root) && !mkdir($this->root, 0755, true) && !is_dir($this->root)) {
                return StorageProviderHealth::failed('Local media storage directory cannot be created.');
            }
            if (!is_writable($this->root)) {
                return StorageProviderHealth::failed('Local media storage directory is not writable.');
            }

            return StorageProviderHealth::ok('Local media storage is writable.');
        } catch (Throwable $exception) {
            return StorageProviderHealth::failed($exception->getMessage());
        }
    }

    public function put(string $sourcePath, string $storageKey, bool $move): void
    {
        $target = $this->path($storageKey);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new MediaException('Unable to create media storage directory.');
        }
        $staged = $target . '.part';

        try {
            if ($move) {
                if (!rename($sourcePath, $staged)) {
                    throw new MediaException('Unable to stage upload.');
                }
            } elseif (!copy($sourcePath, $staged)) {
                throw new MediaException('Unable to stage media file.');
            }
            if (!rename($staged, $target)) {
                throw new MediaException('Unable to store upload.');
            }
        } catch (Throwable $exception) {
            if (is_file($staged)) {
                unlink($staged);
            }
            if ($move && is_file($sourcePath)) {
                unlink($sourcePath);
            }
            throw $exception;
        }
    }

    public function delete(string $storageKey): void
    {
        $path = $this->path($storageKey);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $storageKey): bool
    {
        return is_file($this->path($storageKey));
    }

    public function readStream(string $storageKey, array $options = []): mixed
    {
        $path = $this->path($storageKey);
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new MediaException('Media file is missing.');
        }

        return $stream;
    }

    public function metadata(string $storageKey): array
    {
        $path = $this->path($storageKey);
        if (!is_file($path)) {
            throw new MediaException('Media file is missing.');
        }

        return [
            'storage_key' => $storageKey,
            'byte_size' => (int) filesize($path),
            'sha256' => hash_file('sha256', $path) ?: '',
            'updated_at' => gmdate('c', (int) filemtime($path)),
        ];
    }

    public function refreshMetadata(string $storageKey): array
    {
        return $this->metadata($storageKey);
    }

    public function publicUrl(string $storageKey): ?string
    {
        return null;
    }

    public function signedUrl(string $storageKey, array $options = []): ?string
    {
        return null;
    }

    public function move(string $storageKey, string $destinationKey): void
    {
        $source = $this->path($storageKey);
        $destination = $this->path($destinationKey);
        if (!is_file($source)) {
            throw new MediaException('Media file is missing.');
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new MediaException('Unable to create media storage directory.');
        }
        if (!rename($source, $destination)) {
            throw new MediaException('Unable to move media file.');
        }
    }

    public function path(string $storageKey): string
    {
        $key = str_replace('\\', '/', ltrim($storageKey, '/'));
        if ($key === '' || str_contains($key, '../') || str_starts_with($key, '..')) {
            throw new MediaException('Media storage key is invalid.');
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key;
    }
}
