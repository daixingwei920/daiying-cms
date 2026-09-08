<?php

declare(strict_types=1);

namespace Cms\Core\Queue;

final class QueueHandlerRegistry
{
    /** @var array<string,callable(array<string,mixed>): void> */
    private static array $handlers = [];

    /** @param callable(array<string,mixed>): void $handler */
    public static function register(string $type, callable $handler): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $type)) {
            throw new QueueException('Queue job type is invalid.');
        }

        self::$handlers[$type] = $handler;
    }

    /** @return callable(array<string,mixed>): void|null */
    public static function get(string $type): ?callable
    {
        return self::$handlers[$type] ?? null;
    }

    public static function clear(): void
    {
        self::$handlers = [];
    }
}
