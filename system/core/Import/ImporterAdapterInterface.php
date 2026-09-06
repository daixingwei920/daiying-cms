<?php

declare(strict_types=1);

namespace Cms\Core\Import;

interface ImporterAdapterInterface
{
    public function platformId(): string;

    public function supports(string $sourceType, string $sample): bool;

    /** @return list<UnifiedContent> */
    public function parse(string $payload): array;
}
