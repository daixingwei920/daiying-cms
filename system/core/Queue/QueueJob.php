<?php

declare(strict_types=1);

namespace Cms\Core\Queue;

final class QueueJob
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
        public readonly string $owner = 'core',
        public readonly int $maxAttempts = 3,
        public readonly ?string $notBefore = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $type)) {
            throw new QueueException('Queue job type is invalid.');
        }
        if ($owner === '' || strlen($owner) > 96) {
            throw new QueueException('Queue job owner is invalid.');
        }
    }
}
