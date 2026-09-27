<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class BlockRendererRegistry
{
    /** @var array<string,array{plugin_id:string,renderer:callable(array<string,mixed>,array<string,mixed>):string}> */
    private static array $renderers = [];

    /** @param callable(array<string,mixed>, array<string,mixed>): string $renderer */
    public static function register(string $pluginId, string $type, callable $renderer): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,63}$/', $type) !== 1) {
            throw new PluginException('Invalid block type.');
        }
        if (isset(self::$renderers[$type]) && self::$renderers[$type]['plugin_id'] !== $pluginId) {
            throw new PluginException('Block renderer already registered by another plugin.');
        }

        self::$renderers[$type] = ['plugin_id' => $pluginId, 'renderer' => $renderer];
    }

    /** @return callable(array<string,mixed>, array<string,mixed>): string|null */
    public static function renderer(string $type): ?callable
    {
        return self::$renderers[$type]['renderer'] ?? null;
    }

    public static function owner(string $type): ?string
    {
        return self::$renderers[$type]['plugin_id'] ?? null;
    }

    public static function reset(): void
    {
        self::$renderers = [];
    }
}
