<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchEngine;

interface SearchEngineConnectorInterface
{
    public function engine(): string;

    /** @return array<string,bool> */
    public function capabilities(): array;

    /** @return array{ok:bool,status:string,message:string,raw:array<string,mixed>} */
    public function submitUrl(string $site, string $token, string $url): array;

    /** @return array{ok:bool,status:string,message:string,raw:array<string,mixed>} */
    public function syncMetrics(string $site, string $token, ?string $periodStart = null, ?string $periodEnd = null): array;
}
