<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketSearchResult
{
    /** @param list<MarketItem> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $totalItems,
        public readonly int $totalPages,
    ) {
    }

    /** @param list<MarketItem> $items */
    public static function fromItems(array $items, int $page, int $perPage, ?int $totalItems = null): self
    {
        $perPage = max(1, min(100, $perPage));
        $total = max(0, $totalItems ?? count($items));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        return new self(array_values($items), $page, $perPage, $total, $totalPages);
    }
}
