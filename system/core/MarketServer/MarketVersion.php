<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class MarketVersion
{
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly string $version,
        public readonly string $packagePath,
        public readonly string $packageSha256,
        public readonly string $status,
    ) {
    }
}
