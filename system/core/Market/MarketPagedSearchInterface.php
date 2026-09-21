<?php

declare(strict_types=1);

namespace Cms\Core\Market;

interface MarketPagedSearchInterface
{
    public function searchPage(string $type, string $query = '', int $page = 1, int $perPage = 20, bool $forceRefresh = false): MarketSearchResult;
}
