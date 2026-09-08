<?php

declare(strict_types=1);

namespace Cms\Core\Scheduler;

final class ScheduledTask
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $taskId,
        public readonly string $owner,
        public readonly int $intervalSeconds,
        public readonly array $payload = [],
        public readonly bool $enabled = true,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $taskId)) {
            throw new SchedulerException('Scheduled task id is invalid.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $owner)) {
            throw new SchedulerException('Scheduled task owner is invalid.');
        }
        if ($intervalSeconds < 60 || $intervalSeconds > 31536000) {
            throw new SchedulerException('Scheduled task interval must be between 60 seconds and 1 year.');
        }
    }
}
