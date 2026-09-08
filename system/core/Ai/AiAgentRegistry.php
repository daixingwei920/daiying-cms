<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiAgentRegistry
{
    /** @var array<string,AiAgentDefinition> */
    private static array $agents = [];

    public static function register(AiAgentDefinition $agent): void
    {
        self::$agents[$agent->id] = $agent;
    }

    public static function get(string $id): ?AiAgentDefinition
    {
        return self::$agents[$id] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        ksort(self::$agents);

        return array_map(static fn (AiAgentDefinition $agent): array => $agent->toArray(), self::$agents);
    }

    public static function clear(): void
    {
        self::$agents = [];
    }
}
