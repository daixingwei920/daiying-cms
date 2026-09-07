<?php

declare(strict_types=1);

namespace Daiying\Commerce;

final class SystemShareDistributionProvider implements CommerceDistributionInterface
{
    public function providerId(): string
    {
        return 'official.commerce.distribution.system_share';
    }

    public function publishProduct(array $productSnapshot, array $pricingSnapshot, array $context = []): array
    {
        return [
            'status' => 'ready',
            'provider' => $this->providerId(),
            'external_listing_id' => null,
            'message' => '已生成系统级分享内容。',
            'payload' => [
                'product' => $productSnapshot,
                'pricing' => $pricingSnapshot,
                'share_card' => is_array($context['share_card'] ?? null) ? $context['share_card'] : [],
            ],
        ];
    }

    public function syncListing(string $externalListingId, array $context = []): array
    {
        return [
            'status' => 'ready',
            'provider' => $this->providerId(),
            'external_listing_id' => $externalListingId,
            'message' => '系统级分享无需远端同步。',
        ];
    }

    public function unpublishProduct(string $externalListingId, array $context = []): array
    {
        return [
            'status' => 'ready',
            'provider' => $this->providerId(),
            'external_listing_id' => $externalListingId,
            'message' => '系统级分享无需远端下架。',
        ];
    }
}
