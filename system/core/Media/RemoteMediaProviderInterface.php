<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface RemoteMediaProviderInterface
{
    public function id(): string;

    public function label(): string;

    /** @return array{items:list<MediaProviderItem>,pagination:array<string,mixed>,parent?:array<string,mixed>|null} */
    public function list(string $path = '', array $options = []): array;

    /** @return array{items:list<MediaProviderItem>,pagination:array<string,mixed>} */
    public function search(string $query, string $path = '', array $options = []): array;

    public function get(string $remoteId, string $path = ''): MediaProviderItem;

    /** @param array<string,mixed> $media @return array{url:string,expires:?string,headers?:array<string,string>} */
    public function resolveUrl(array $media, array $options = []): array;

    public function upload(string $sourcePath, string $filename, string $targetPath, string $mimeType): MediaProviderItem;

    public function delete(string $remoteId, string $path = ''): void;

    public function move(string $remoteId, string $path, string $destinationPath): MediaProviderItem;

    /** @return array<string,mixed> */
    public function metadata(string $remoteId, string $path = ''): array;

    /** @return array{filename:string,mime_type:string,byte_size:int} */
    public function downloadTo(string $remoteId, string $path, string $targetPath, int $maxBytes): array;
}
