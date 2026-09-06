<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class InstallAuthorization
{
    public function __construct(
        public readonly string $token,
        public readonly string $packageUrl,
        public readonly string $expiresAt,
        public readonly string $packageSha256,
        public readonly string $marketId = '',
    ) {
    }

    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) <= time();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['token'] ?? ''),
            (string) ($data['package_url'] ?? ''),
            (string) ($data['expires_at'] ?? ''),
            (string) ($data['package_sha256'] ?? ''),
            (string) ($data['market_id'] ?? ''),
        );
    }
}
