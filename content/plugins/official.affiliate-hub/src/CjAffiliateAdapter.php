<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

final class CjAffiliateAdapter implements AffiliateAdapterInterface
{
    public function __construct(private readonly CjAffiliateClient $client = new CjAffiliateClient())
    {
    }

    public function id(): string
    {
        return 'affiliate.cj';
    }

    public function label(): string
    {
        return 'CJ Affiliate';
    }

    public function capabilities(): array
    {
        return ['product_search', 'advertiser_products', 'tracking_link', 'dedupe_sync', 'rate_limit_aware'];
    }

    public function validateCredentials(array $config): array
    {
        return $this->client->testConnection($config);
    }

    public function searchProducts(array $query): array
    {
        $config = is_array($query['config'] ?? null) ? $query['config'] : [];
        return $this->client->searchProducts($config, $query);
    }

    public function buildTrackingUrl(array $product, array $options = []): string
    {
        unset($options);
        $url = (string) ($product['affiliate_url'] ?? $product['click_url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('CJ product does not include a tracking URL.');
        }

        return $url;
    }
}
