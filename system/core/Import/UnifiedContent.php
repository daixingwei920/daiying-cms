<?php

declare(strict_types=1);

namespace Cms\Core\Import;

final class UnifiedContent
{
    /** @param list<array<string, mixed>> $blocks @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $status,
        public readonly array $blocks,
        public readonly array $meta = [],
        public readonly ?string $sourceUrl = null,
    ) {
    }
}
