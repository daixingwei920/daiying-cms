<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchMetrics;

interface SearchMetricsProviderInterface
{
    public function engine(): string;

    /** @return array<string,bool> */
    public function capabilities(): array;

    /** @return array{ok:bool,status:string,message:string} */
    public function connectionStatus(): array;

    public function sync(SearchMetricsSyncRequest $request): SearchMetricsSyncResult;
}
