<?php

declare(strict_types=1);

namespace Cms\Core\Market;

interface ObjectStorageAdapterInterface
{
    public function put(string $key, string $localPath): string;

    public function get(string $key, string $targetPath): string;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function presignUpload(string $key, int $ttlSeconds = 300, array $options = []): array;
}
