<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchMetrics;

final class SearchMetricsSyncRequest
{
    public function __construct(
        public readonly string $siteUrl,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $rowLimit = 25000,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) !== 1) {
            throw new SearchMetricsException('Search metrics sync dates are invalid.');
        }
        if ($siteUrl === '') {
            throw new SearchMetricsException('Search metrics site URL is required.');
        }
    }
}
