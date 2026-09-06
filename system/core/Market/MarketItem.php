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
        public readonly string $slug = '',
        public readonly string $vendor = '',
        public readonly string $channel = 'stable',
        public readonly string $packageId = '',
        public readonly string $packageSha256 = '',
        public readonly bool $compatible = true,
        public readonly string $compatibilityMessage = '',
        public readonly string $productId = '',
        public readonly string $developerName = '',
        public readonly ?int $developerId = null,
        public readonly bool $licenseRequired = false,
        public readonly string $licenseMode = 'FREE',
        public readonly string $purchaseUrl = '',
        public readonly string $renewUrl = '',
        public readonly string $supportUrl = '',
        public readonly string $developerUrl = '',
        public readonly string $pricingText = '',
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
            (string) ($data['slug'] ?? $data['plugin_id'] ?? $data['extension_id'] ?? ''),
            (string) ($data['vendor'] ?? ''),
            (string) ($data['channel'] ?? 'stable'),
            (string) ($data['package_id'] ?? $data['market_id'] ?? ''),
            (string) ($data['package_sha256'] ?? ''),
            (bool) (($data['compatibility']['compatible'] ?? $data['compatible'] ?? true)),
            implode('; ', array_values(array_map('strval', is_array($data['compatibility']['messages'] ?? null) ? $data['compatibility']['messages'] : []))),
            (string) ($data['product_id'] ?? $data['market_id'] ?? ''),
            (string) ($data['developer_name'] ?? ''),
            isset($data['developer_id']) ? (int) $data['developer_id'] : null,
            (bool) ($data['license_required'] ?? false),
            (string) ($data['license_mode'] ?? 'FREE'),
            (string) ($data['purchase_url'] ?? ''),
            (string) ($data['renew_url'] ?? ($data['purchase_url'] ?? '')),
            (string) ($data['support_url'] ?? ''),
            (string) ($data['developer_url'] ?? ''),
            (string) ($data['pricing_text'] ?? $data['price_label'] ?? ''),
        );
    }
}
