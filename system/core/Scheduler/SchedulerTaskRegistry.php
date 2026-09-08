<?php

declare(strict_types=1);

namespace Cms\Core\Scheduler;

final class SchedulerTaskRegistry
{
    /** @var array<string,callable(array<string,mixed>): void> */
    private static array $handlers = [];

    /** @param callable(array<string,mixed>): void $handler */
    public static function register(string $taskId, callable $handler): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $taskId)) {
            throw new SchedulerException('Scheduled task id is invalid.');
        }

        self::$handlers[$taskId] = $handler;
    }

    /** @return callable(array<string,mixed>): void|null */
    public static function get(string $taskId): ?callable
    {
        return self::$handlers[$taskId] ?? null;
    }

    public static function clear(): void
    {
        self::$handlers = [];
    }
}
