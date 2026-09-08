<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiToolRegistry
{
    /** @var array<string,AiToolDefinition> */
    private static array $definitions = [];
    /** @var array<string,callable(array<string,mixed>):array<string,mixed>> */
    private static array $handlers = [];

    /** @param callable(array<string,mixed>):array<string,mixed> $handler */
    public static function register(AiToolDefinition $definition, callable $handler): void
    {
        self::$definitions[$definition->id] = $definition;
        self::$handlers[$definition->id] = $handler;
    }

    public static function get(string $id): ?AiToolDefinition
    {
        return self::$definitions[$id] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        ksort(self::$definitions);

        return array_map(static fn (AiToolDefinition $definition): array => $definition->toArray(), self::$definitions);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function execute(string $id, array $payload): array
    {
        $handler = self::$handlers[$id] ?? null;
        if ($handler === null) {
            throw new AiException('AI tool is not registered.', 'tool_not_found');
        }
        $result = $handler($payload);
        if (!is_array($result)) {
            throw new AiException('AI tool returned an invalid result.', 'tool_invalid_result');
        }

        return $result;
    }

    public static function clear(): void
    {
        self::$definitions = [];
        self::$handlers = [];
    }
}
