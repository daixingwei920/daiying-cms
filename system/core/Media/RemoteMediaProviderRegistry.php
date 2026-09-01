<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class RemoteMediaProviderRegistry
{
    /** @var array<string,RemoteMediaProviderInterface> */
    private static array $providers = [];

    public static function register(RemoteMediaProviderInterface $provider): void
    {
        self::$providers[$provider->id()] = $provider;
    }

    public static function get(string $id): ?RemoteMediaProviderInterface
    {
        return self::$providers[$id] ?? null;
    }

    /** @return array<string,RemoteMediaProviderInterface> */
    public static function all(): array
    {
        return self::$providers;
    }

    public static function clear(): void
    {
        self::$providers = [];
    }
}
