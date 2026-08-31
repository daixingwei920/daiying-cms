<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class OfficialPluginRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $plugins;

    public function __construct(private readonly string $rootPath)
    {
        $path = $rootPath . '/system/official-plugins.php';
        $items = is_file($path) ? require $path : [];
        $this->plugins = is_array($items) ? $items : [];
    }

    public function isReservedOfficialId(string $pluginId): bool
    {
        return str_starts_with($pluginId, 'official.');
    }

    public function isTrustedBundled(string $pluginId, string $pluginRoot): bool
    {
        $record = $this->plugins[$pluginId] ?? null;
        if (!is_array($record) || ($record['bundled'] ?? false) !== true) {
            return false;
        }

        $expected = realpath($this->rootPath . '/content/plugins/' . (string) ($record['directory'] ?? $pluginId));
        $actual = realpath($pluginRoot);

        return $expected !== false && $actual !== false && $expected === $actual;
    }

    /** @return list<string> */
    public function capabilityNamespaces(string $pluginId): array
    {
        return array_values(array_filter(array_map('strval', $this->plugins[$pluginId]['capability_namespaces'] ?? [])));
    }

    /** @return list<string> */
    public function tablePrefixes(string $pluginId): array
    {
        return array_values(array_filter(array_map('strval', $this->plugins[$pluginId]['table_prefixes'] ?? [])));
    }

    /** @return list<string> */
    public function reservedTablePrefixes(): array
    {
        $prefixes = ['cms_', 'market_'];
        foreach ($this->plugins as $record) {
            foreach ((array) ($record['table_prefixes'] ?? []) as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }

        return array_values(array_unique(array_filter($prefixes)));
    }
}
