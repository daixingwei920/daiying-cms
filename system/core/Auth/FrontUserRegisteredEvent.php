<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

final class FrontUserRegisteredEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $email,
        public readonly string $source,
        public readonly string $createdAt,
    ) {
    }
}
