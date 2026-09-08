<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface RemoteMediaProviderV1Interface extends RemoteMediaProviderInterface
{
    public function apiVersion(): string;

    /** @return list<string> */
    public function capabilities(): array;

    public function testConnection(): StorageProviderHealth;

    public function available(): bool;

    /** @return array{url:string,expires:?string,headers?:array<string,string>} */
    public function signedUrl(array $media, array $options = []): array;

    /** @return resource */
    public function readStream(string $remoteId, string $path = '', array $options = []): mixed;

    /** @return array<string,mixed> */
    public function refreshMetadata(string $remoteId, string $path = ''): array;
}
