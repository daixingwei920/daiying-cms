<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class OfflineMarketClient implements MarketApiClientInterface
{
    public function search(string $type, string $query = ''): array
    {
        return [
            new MarketItem('official:faq_block', 'faq_block', 'plugin', 'FAQ Block', '1.0.0', 'Free', 'published', ['blocks.register']),
            new MarketItem('official:default_theme', 'default', 'theme', 'Default CMS Theme', '1.0.0', 'Included', 'published'),
        ];
    }

    public function authorizeInstall(string $marketId, string $siteId): InstallAuthorization
    {
        return new InstallAuthorization(
            'offline-demo-token',
            'offline://' . $marketId,
            gmdate('c', time() + 300),
            hash('sha256', $marketId . $siteId),
        );
    }
}
