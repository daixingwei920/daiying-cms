<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface RemoteMediaProxyProviderInterface extends RemoteMediaProviderInterface
{
    /**
     * @param array<string,mixed> $media
     * @param array<string,mixed> $options
     * @return array{filename:string,mime_type:string,byte_size:int}
     */
    public function proxyDownload(array $media, string $targetPath, int $maxBytes, array $options = []): array;
}
