<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketItem
{
    /** @param list<string> $capabilities */
    public function __construct(
        public readonly string $marketId,
        public readonly string $extensionId,
        public readonly string $type,
        public readonly string $name,
        public readonly string $version,
        public readonly string $priceLabel,
        public readonly string $reviewStatus,
        public readonly array $capabilities = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['market_id'] ?? ''),
            (string) ($data['extension_id'] ?? ''),
            (string) ($data['type'] ?? 'plugin'),
            (string) ($data['name'] ?? ''),
            (string) ($data['version'] ?? ''),
            (string) ($data['price_label'] ?? 'Free'),
            (string) ($data['review_status'] ?? 'unknown'),
            array_values(array_map('strval', is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [])),
        );
    }
}
