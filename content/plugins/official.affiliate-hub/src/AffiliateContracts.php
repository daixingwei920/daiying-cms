<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

interface AffiliateAdapterInterface
{
    public function id(): string;

    public function label(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** @param array<string,mixed> $config @return array{ok:bool,message:string} */
    public function validateCredentials(array $config): array;

    /** @param array<string,mixed> $query @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function searchProducts(array $query): array;

    /** @param array<string,mixed> $product @param array<string,mixed> $options */
    public function buildTrackingUrl(array $product, array $options = []): string;
}

final class AffiliateAdapterRegistry
{
    /** @var array<string,AffiliateAdapterInterface> */
    private static array $adapters = [];

    public static function register(AffiliateAdapterInterface $adapter): void
    {
        self::$adapters[$adapter->id()] = $adapter;
    }

    /** @return array<string,AffiliateAdapterInterface> */
    public static function all(): array
    {
        return self::$adapters;
    }

    public static function get(string $id): ?AffiliateAdapterInterface
    {
        return self::$adapters[$id] ?? null;
    }

    public static function clear(): void
    {
        self::$adapters = [];
    }
}

final class AffiliateProviderIsolation
{
    /** @return array{ok:bool,provider:string,operation:string,result:mixed,error:?string} */
    public static function capture(string $provider, string $operation, callable $callback): array
    {
        try {
            return [
                'ok' => true,
                'provider' => $provider,
                'operation' => $operation,
                'result' => $callback(),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'provider' => $provider,
                'operation' => $operation,
                'result' => null,
                'error' => substr($exception->getMessage(), 0, 500),
            ];
        }
    }
}

final class ManualAffiliateAdapter implements AffiliateAdapterInterface
{
    public function id(): string
    {
        return 'affiliate.manual';
    }

    public function label(): string
    {
        return '手工联盟商品';
    }

    public function capabilities(): array
    {
        return ['manual_product', 'deep_link'];
    }

    public function validateCredentials(array $config): array
    {
        return ['ok' => true, 'message' => '手工商品不需要平台凭据。'];
    }

    public function searchProducts(array $query): array
    {
        return ['items' => [], 'next_cursor' => null];
    }

    public function buildTrackingUrl(array $product, array $options = []): string
    {
        $url = (string) ($product['affiliate_url'] ?? $product['destination_url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Affiliate URL is required.');
        }

        return $url;
    }
}

final class FeedAffiliateAdapter implements AffiliateAdapterInterface
{
    public function id(): string
    {
        return 'affiliate.feed';
    }

    public function label(): string
    {
        return '通用 Feed';
    }

    public function capabilities(): array
    {
        return ['feed_csv', 'feed_xml', 'feed_json', 'field_mapping', 'full_feed', 'delta_feed'];
    }

    public function validateCredentials(array $config): array
    {
        return ['ok' => true, 'message' => 'Feed 连接将在具体映射和同步任务中校验。'];
    }

    public function searchProducts(array $query): array
    {
        return ['items' => [], 'next_cursor' => null];
    }

    public function buildTrackingUrl(array $product, array $options = []): string
    {
        $url = (string) ($product['affiliate_url'] ?? $product['destination_url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Feed product does not include a tracking URL.');
        }

        return $url;
    }
}
