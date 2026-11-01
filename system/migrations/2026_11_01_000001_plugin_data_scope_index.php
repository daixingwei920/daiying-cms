<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_11_01_000001_plugin_data_scope_index';
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $name = 'idx_plugin_data_scope_id';
        if ($driver === 'sqlite') {
            $existing = $pdo->query("SELECT tbl_name FROM sqlite_master WHERE type='index' AND name='" . $name . "'")->fetchColumn();
            if ($existing === false) {
                $pdo->exec('CREATE INDEX ' . $name . ' ON cms_plugin_data(plugin_id, data_type, id DESC)');
            } elseif ($existing !== 'cms_plugin_data') {
                throw new RuntimeException('Plugin data scope index belongs to an unexpected table.');
            }
            $indexes = $pdo->query("PRAGMA index_list('cms_plugin_data')")->fetchAll(PDO::FETCH_ASSOC);
            $index = array_values(array_filter($indexes, static fn (array $row): bool => $row['name'] === $name))[0] ?? [];
            $keys = array_values(array_filter(
                $pdo->query("PRAGMA index_xinfo('" . $name . "')")->fetchAll(PDO::FETCH_ASSOC),
                static fn (array $row): bool => (int) $row['key'] === 1,
            ));
            if ((int) ($index['unique'] ?? 1) !== 0 || (int) ($index['partial'] ?? 1) !== 0
                || array_column($keys, 'name') !== ['plugin_id', 'data_type', 'id']
                || array_map('intval', array_column($keys, 'desc')) !== [0, 0, 1]
                || array_column($keys, 'coll') !== ['BINARY', 'BINARY', 'BINARY']) {
                throw new RuntimeException('Plugin data scope index definition does not match the Core migration.');
            }
            return;
        }
        if ($driver === 'mysql') {
            $keys = $pdo->query("SHOW INDEX FROM cms_plugin_data WHERE Key_name='" . $name . "'")->fetchAll(PDO::FETCH_ASSOC);
            if ($keys === []) {
                $pdo->exec('CREATE INDEX ' . $name . ' ON cms_plugin_data(plugin_id, data_type, id DESC)');
                $keys = $pdo->query("SHOW INDEX FROM cms_plugin_data WHERE Key_name='" . $name . "'")->fetchAll(PDO::FETCH_ASSOC);
            }
            usort($keys, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
            if (array_column($keys, 'Column_name') !== ['plugin_id', 'data_type', 'id']) {
                throw new RuntimeException('Plugin data scope index definition does not match the Core migration.');
            }
            foreach ($keys as $key) {
                // MySQL 5.7 stores DESC as ASC; equality on the first two columns permits a reverse index scan.
                if ((int) $key['Non_unique'] !== 1 || $key['Sub_part'] !== null || $key['Index_type'] !== 'BTREE'
                    || !in_array($key['Collation'], ['A', 'D'], true)
                    || ($key['Visible'] ?? 'YES') !== 'YES' || ($key['Ignored'] ?? 'NO') !== 'NO') {
                    throw new RuntimeException('Plugin data scope index is not a usable non-unique full-column index.');
                }
            }
            return;
        }
        throw new RuntimeException('Plugin data scope index requires a supported Core database driver.');
    }
};
