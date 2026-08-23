<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class MarketProject
{
    public function __construct(
        public readonly int $id,
        public readonly int $developerId,
        public readonly string $marketId,
        public readonly string $extensionType,
        public readonly string $name,
        public readonly string $status,
    ) {
    }
}
