<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

final class FrontUserLoggedInEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $email,
        public readonly string $provider,
        public readonly ?string $externalSubject,
        public readonly string $loggedInAt,
    ) {
    }
}
