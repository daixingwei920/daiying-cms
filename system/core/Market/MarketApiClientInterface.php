<?php

declare(strict_types=1);

namespace Cms\Core\Market;

interface MarketApiClientInterface
{
    /** @return list<MarketItem> */
    public function search(string $type, string $query = ''): array;

    public function authorizeInstall(string $marketId, string $siteId): InstallAuthorization;
}
