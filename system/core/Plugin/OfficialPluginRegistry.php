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
        $configured = is_array($items) ? $items : [];
        $this->plugins = array_replace_recursive(self::builtInPlugins(), $configured);
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

    /** @return array<string,array<string,mixed>> */
    private static function builtInPlugins(): array
    {
        return [
            'official.friend-links' => [
                'directory' => 'official.friend-links',
                'package_type' => 'plugin',
                'type' => 'system-plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['friend_links'],
                'table_prefixes' => ['friend_links_'],
            ],
            'official.novel-collector' => [
                'directory' => 'official.novel-collector',
                'package_type' => 'plugin',
                'type' => 'plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['novel', 'novel_collector'],
                'table_prefixes' => ['novel_', 'novel_collector_'],
            ],
            'official.video-collector' => [
                'directory' => 'official.video-collector',
                'package_type' => 'plugin',
                'type' => 'plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['video', 'video_collector'],
                'table_prefixes' => ['video_', 'video_collector_'],
            ],
            'official.commerce' => [
                'directory' => 'official.commerce',
                'package_type' => 'plugin',
                'type' => 'plugin',
                'bundled' => true,
                'trust_level' => 'trusted_php',
                'capability_namespaces' => ['commerce'],
                'table_prefixes' => ['commerce_'],
            ],
        ];
    }
}
