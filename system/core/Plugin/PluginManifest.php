<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class PluginManifest
{
    /** @param list<string> $capabilities @param list<string> $permissions @param list<string> $dependencies @param list<string> $capabilityNamespaces @param list<string> $tablePrefixes */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly string $coreMin,
        public readonly string $phpMin,
        public readonly string $entry,
        public readonly string $trustLevel,
        public readonly array $capabilities,
        public readonly array $permissions,
        public readonly array $dependencies,
        public readonly string $type,
        public readonly bool $bundled,
        public readonly array $capabilityNamespaces,
        public readonly array $tablePrefixes,
        public readonly string $mediaReferenceProvider,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['plugin_id', 'name', 'version', 'author', 'core', 'php', 'entry'] as $key) {
            if (!isset($data[$key])) {
                throw new PluginException('Plugin manifest missing key: ' . $key);
            }
        }

        $id = (string) $data['plugin_id'];
        if (
            strlen($id) > 96
            || !preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $id)
        ) {
            throw new PluginException('Invalid plugin id.');
        }

        $entry = (string) $data['entry'];
        if ($entry === '' || str_contains($entry, '..') || str_starts_with($entry, '/')) {
            throw new PluginException('Invalid plugin entry path.');
        }

        $trustLevel = (string) ($data['trust_level'] ?? 'api');
        if (!in_array($trustLevel, ['api', 'trusted_php'], true)) {
            throw new PluginException('Invalid plugin trust level.');
        }

        $core = is_array($data['core']) ? $data['core'] : [];
        $capabilities = $data['capabilities'] ?? [];
        $permissions = $data['permissions'] ?? [];
        if (!is_array($permissions)) {
            $permissions = [];
        }
        $dependencies = $data['dependencies'] ?? [];
        $prefixes = $data['table_prefixes'] ?? ($data['database_prefixes'] ?? []);
        if (!is_array($prefixes) && isset($data['database_prefix'])) {
            $prefixes = [(string) $data['database_prefix']];
        }

        return new self(
            $id,
            (string) $data['name'],
            (string) $data['version'],
            (string) $data['author'],
            (string) ($core['min'] ?? '1.0.0'),
            (string) $data['php'],
            $entry,
            $trustLevel,
            self::cleanList($capabilities),
            self::cleanList($permissions),
            self::cleanList($dependencies),
            (string) ($data['type'] ?? 'plugin'),
            (bool) ($data['bundled'] ?? false),
            self::cleanList($data['capability_namespaces'] ?? []),
            self::cleanList($prefixes),
            (string) ($data['media_reference_provider'] ?? ''),
        );
    }

    /** @return list<string> */
    private static function cleanList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $items), static fn (string $item): bool => $item !== ''));
    }
}
