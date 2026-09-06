<?php

declare(strict_types=1);

namespace Cms\Core\Market;

interface MarketApiClientInterface
{
    /** @return list<MarketItem> */
    public function search(string $type, string $query = '', bool $forceRefresh = false): array;

    public function authorizeInstall(string $marketId, string $siteId, string $licenseKey = ''): InstallAuthorization;

    /** @return array<string, mixed> */
    public function detail(string $marketId, string $version = '', bool $forceRefresh = false): array;

    /** @return array<string, mixed> */
    public function diagnostics(bool $forceRefresh = false): array;

    public function clearCache(): void;
}
