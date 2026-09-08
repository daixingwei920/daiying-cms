<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class StorageProviderHealth
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $metadata = [],
    ) {
    }

    /** @param array<string,mixed> $metadata */
    public static function ok(string $message = 'Storage provider is available.', array $metadata = []): self
    {
        return new self(true, 'ok', $message, $metadata);
    }

    /** @param array<string,mixed> $metadata */
    public static function failed(string $message, array $metadata = []): self
    {
        return new self(false, 'failed', $message, $metadata);
    }

    /** @return array{ok:bool,status:string,message:string,metadata:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'status' => $this->status,
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
