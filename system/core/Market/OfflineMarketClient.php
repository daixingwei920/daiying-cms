<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class OfflineMarketClient implements MarketApiClientInterface, MarketPagedSearchInterface
{
    public function search(string $type, string $query = '', bool $forceRefresh = false): array
    {
        return $this->searchPage($type, $query, 1, 100, $forceRefresh)->items;
    }

    public function searchPage(string $type, string $query = '', int $page = 1, int $perPage = 20, bool $forceRefresh = false): MarketSearchResult
    {
        $items = [
            new MarketItem('official:faq_block', 'faq_block', 'plugin', 'FAQ Block', '1.0.0', 'Free', 'published', ['blocks.register']),
            new MarketItem('official:default_theme', 'default', 'theme', 'Default CMS Theme', '1.0.0', 'Included', 'published'),
        ];
        $query = strtolower(trim($query));
        $items = array_values(array_filter($items, static function (MarketItem $item) use ($type, $query): bool {
            if ($item->type !== $type) {
                return false;
            }
            if ($query === '') {
                return true;
            }
            $haystack = strtolower(implode(' ', [
                $item->name,
                $item->marketId,
                $item->extensionId,
                $item->packageId,
                $item->productId,
                $item->slug,
                $item->developerName,
                $item->description,
                implode(' ', $item->capabilities),
            ]));

            return str_contains($haystack, $query);
        }));

        $perPage = max(1, $perPage);
        $total = count($items);
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));

        return MarketSearchResult::fromItems(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            $page,
            $perPage,
            $total
        );
    }

    public function authorizeInstall(string $marketId, string $siteId, string $licenseKey = ''): InstallAuthorization
    {
        return new InstallAuthorization(
            'offline-demo-token',
            'offline://' . $marketId,
            gmdate('c', time() + 300),
            hash('sha256', $marketId . $siteId),
        );
    }

    public function detail(string $marketId, string $version = '', bool $forceRefresh = false): array
    {
        return ['error' => 'offline_market_has_no_remote_detail', 'market_id' => $marketId, 'version' => $version];
    }

    public function diagnostics(bool $forceRefresh = false): array
    {
        return [
            'mode' => 'offline',
            'api_status' => 'offline',
            'http_status' => 0,
            'last_sync_at' => '',
            'last_item_count' => 2,
            'cache_status' => 'offline',
            'api_version' => 'offline',
        ];
    }

    public function clearCache(): void
    {
    }

    public function activateLicense(string $productId, string $licenseKey, string $siteId, string $siteUrl = ''): array
    {
        return ['status' => 'ERROR', 'error' => 'offline_market_has_no_license_activation'];
    }

    public function authorizePaidUpdate(string $productId, string $version, string $installedVersion, string $siteId, string $licenseKey): array
    {
        return ['status' => 'ERROR', 'error' => 'offline_market_has_no_paid_update_authorization'];
    }
}
