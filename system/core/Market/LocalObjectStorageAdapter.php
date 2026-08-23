<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class LocalObjectStorageAdapter implements ObjectStorageAdapterInterface
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function put(string $key, string $localPath): string
    {
        if (!is_file($localPath)) {
            throw new MarketException('Local object source file does not exist.');
        }
        $target = $this->rootPath . '/' . ltrim($key, '/');
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        copy($localPath, $target);

        return $target;
    }

    public function get(string $key, string $targetPath): string
    {
        $source = $this->rootPath . '/' . ltrim($key, '/');
        if (!is_file($source)) {
            throw new MarketException('Local object was not found.');
        }
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0755, true);
        }
        copy($source, $targetPath);

        return $targetPath;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function presignUpload(string $key, int $ttlSeconds = 300, array $options = []): array
    {
        $target = $this->rootPath . '/' . ltrim($key, '/');
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        return [
            'method' => 'PUT',
            'url' => 'file://' . $target,
            'headers' => [],
            'key' => ltrim($key, '/'),
            'expires_at' => gmdate('c', time() + max(1, $ttlSeconds)),
            'max_bytes' => (int) ($options['max_bytes'] ?? 0),
        ];
    }
}
