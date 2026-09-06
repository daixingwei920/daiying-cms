<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class MediaProviderItem
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly string $path,
        public readonly string $name,
        public readonly string $type,
        public readonly string $mimeType,
        public readonly int $byteSize,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $durationSeconds = null,
        public readonly ?string $checksum = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'path' => $this->path,
            'name' => $this->name,
            'type' => $this->type,
            'mime_type' => $this->mimeType,
            'byte_size' => $this->byteSize,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->durationSeconds,
            'checksum' => $this->checksum,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'metadata' => $this->metadata,
        ];
    }
}
