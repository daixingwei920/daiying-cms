<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class Capability
{
    public const CORE_KNOWN = [
        'content.read',
        'content.write',
        'media.read',
        'media.write',
        'network.external',
        'cron.register',
        'settings.read',
        'settings.write',
        'storage.plugin',
        'blocks.register',
    ];

    public const KNOWN = self::CORE_KNOWN;

    private const RESERVED_NAMESPACES = ['core', 'admin', 'cms', 'system', 'market'];

    /** @param list<string> $capabilities */
    public static function assertKnown(array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            if (!self::isValidName($capability) || !in_array($capability, self::CORE_KNOWN, true)) {
                throw new PluginException('Unknown capability: ' . $capability);
            }
        }
    }

    /** @param list<string> $capabilities @param list<string> $allowedNamespaces */
    public static function assertPluginAllowed(string $pluginId, array $capabilities, array $allowedNamespaces = []): void
    {
        $namespaces = array_values(array_unique(array_merge(self::defaultNamespaces($pluginId), $allowedNamespaces)));
        foreach ($capabilities as $capability) {
            if (!self::isValidName($capability)) {
                throw new PluginException('Invalid capability: ' . $capability);
            }
            if (in_array($capability, self::CORE_KNOWN, true)) {
                continue;
            }
            $allowed = false;
            foreach ($namespaces as $namespace) {
                if (str_starts_with($capability, $namespace . '.')) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new PluginException('Plugin capability is outside the plugin namespace: ' . $capability);
            }
            $root = explode('.', $capability, 2)[0];
            if (in_array($root, self::RESERVED_NAMESPACES, true) && !in_array($root, $allowedNamespaces, true)) {
                throw new PluginException('Plugin capability namespace is reserved: ' . $root);
            }
        }
    }

    private static function isValidName(string $capability): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $capability) === 1;
    }

    /** @return list<string> */
    private static function defaultNamespaces(string $pluginId): array
    {
        $parts = explode('.', $pluginId);
        $namespaces = [$parts[0]];
        if (count($parts) > 1) {
            $namespaces[] = implode('.', array_slice($parts, 0, 2));
        }

        return array_values(array_unique($namespaces));
    }
}
