<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiPromptRegistry
{
    /** @var array<string,AiPromptTemplate> */
    private static array $prompts = [];

    public static function register(AiPromptTemplate $prompt): void
    {
        self::$prompts[self::key($prompt->id, $prompt->version, $prompt->language)] = $prompt;
    }

    public static function get(string $id, string $version = '1.0', string $language = 'default'): ?AiPromptTemplate
    {
        return self::$prompts[self::key($id, $version, $language)] ?? self::$prompts[self::key($id, $version, 'default')] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        ksort(self::$prompts);

        return array_map(static fn (AiPromptTemplate $prompt): array => $prompt->toArray(), self::$prompts);
    }

    public static function clear(): void
    {
        self::$prompts = [];
    }

    private static function key(string $id, string $version, string $language): string
    {
        return $id . ':' . $version . ':' . $language;
    }
}
