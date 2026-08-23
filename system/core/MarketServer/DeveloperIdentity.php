<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class DeveloperIdentity
{
    public function __construct(
        public readonly int $id,
        public readonly string $developerKey,
        public readonly string $displayName,
        public readonly string $email,
        public readonly string $status,
        public readonly string $role = 'Developer',
    ) {
    }
}
