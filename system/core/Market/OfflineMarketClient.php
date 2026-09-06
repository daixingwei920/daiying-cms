<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class OfflineMarketClient implements MarketApiClientInterface
{
    public function search(string $type, string $query = '', bool $forceRefresh = false): array
    {
        return [
            new MarketItem('official:faq_block', 'faq_block', 'plugin', 'FAQ Block', '1.0.0', 'Free', 'published', ['blocks.register']),
            new MarketItem('official:default_theme', 'default', 'theme', 'Default CMS Theme', '1.0.0', 'Included', 'published'),
        ];
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
