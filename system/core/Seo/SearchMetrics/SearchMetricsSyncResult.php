<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchMetrics;

final class SearchMetricsSyncResult
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly string $message,
        public readonly array $rows = [],
        public readonly array $raw = [],
    ) {
    }
}
