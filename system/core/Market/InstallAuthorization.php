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
        /** @var array<string,mixed> */
        public readonly array $trustGrant = [],
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
            is_array($data['trust_grant'] ?? null)
                ? $data['trust_grant']
                : (is_array($data['official_trust_grant'] ?? null) ? $data['official_trust_grant'] : []),
        );
    }
}
