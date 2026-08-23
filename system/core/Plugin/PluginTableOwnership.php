<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;

final class PluginTableOwnership
{
    private const RESERVED_PREFIXES = ['cms_', 'market_'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?OfficialPluginRegistry $officialRegistry = null,
    ) {
    }

    /** @param array<string,mixed> $manifest @return list<string> */
    public function prefixesFor(array $manifest, bool $trustedOfficial = false): array
    {
        $pluginId = (string) ($manifest['plugin_id'] ?? '');
        $declared = $manifest['table_prefixes'] ?? ($manifest['database_prefixes'] ?? null);
        if ($declared === null && isset($manifest['database_prefix'])) {
            $declared = [(string) $manifest['database_prefix']];
        }
        $prefixes = is_array($declared) ? array_values(array_map('strval', $declared)) : [];
        if ($prefixes === []) {
            $prefixes = ['plugin_' . preg_replace('/[^A-Za-z0-9_]/', '_', $pluginId) . '_'];
        }

        $allowedOfficial = $trustedOfficial && $this->officialRegistry !== null
            ? $this->officialRegistry->tablePrefixes($pluginId)
            : [];

        foreach ($prefixes as $prefix) {
            if (!preg_match('/^[a-z][a-z0-9_]*_$/', $prefix)) {
                throw new PluginException('Plugin table prefix is invalid.');
            }
            if (!$trustedOfficial && in_array($prefix, $this->reservedPrefixes(), true)) {
                throw new PluginException('Plugin table prefix is reserved.');
            }
            if ($trustedOfficial && $allowedOfficial !== [] && !in_array($prefix, $allowedOfficial, true)) {
                throw new PluginException('Official plugin table prefix is not in the bundled trust record.');
            }
        }

        return array_values(array_unique($prefixes));
    }

    /** @param list<string> $prefixes */
    public function assertAvailable(string $pluginId, array $prefixes): void
    {
        foreach ($this->installedPrefixes() as $owner => $installed) {
            if ($owner === $pluginId) {
                continue;
            }
            if (array_intersect($prefixes, $installed) !== []) {
                throw new PluginException('Plugin table prefix conflicts with an installed plugin.');
            }
        }
    }

    /** @param list<string> $objects @param list<string> $prefixes */
    public function assertOwnsObjects(array $objects, array $prefixes): void
    {
        foreach ($objects as $object) {
            $name = str_starts_with($object, 'table:') ? substr($object, 6) : $object;
            $logical = str_starts_with($name, 'cms_') ? substr($name, 4) : $name;
            if ($logical === '') {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($logical, $prefix)) {
                    continue 2;
                }
            }
            throw new PluginException('Plugin migration may only modify plugin-owned database objects.');
        }
    }

    /** @return list<string> */
    private function reservedPrefixes(): array
    {
        $prefixes = self::RESERVED_PREFIXES;
        if ($this->officialRegistry !== null) {
            $prefixes = array_merge($prefixes, $this->officialRegistry->reservedTablePrefixes());
        }

        return array_values(array_unique($prefixes));
    }

    /** @return array<string,list<string>> */
    private function installedPrefixes(): array
    {
        try {
            $rows = $this->pdo->query('SELECT plugin_id, table_prefixes_json FROM cms_plugins')->fetchAll();
        } catch (\Throwable) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['table_prefixes_json'] ?? '[]'), true);
            $items[(string) $row['plugin_id']] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        return $items;
    }
}
