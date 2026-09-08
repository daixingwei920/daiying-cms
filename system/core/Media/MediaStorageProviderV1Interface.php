<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface MediaStorageProviderV1Interface extends MediaStorageProviderInterface
{
    public function apiVersion(): string;

    public function label(): string;

    /** @return list<string> */
    public function capabilities(): array;

    public function testConnection(): StorageProviderHealth;

    /** @return resource */
    public function readStream(string $storageKey, array $options = []): mixed;

    /** @return array<string,mixed> */
    public function metadata(string $storageKey): array;

    /** @return array<string,mixed> */
    public function refreshMetadata(string $storageKey): array;

    public function publicUrl(string $storageKey): ?string;

    /** @param array<string,mixed> $options */
    public function signedUrl(string $storageKey, array $options = []): ?string;

    public function move(string $storageKey, string $destinationKey): void;
}
