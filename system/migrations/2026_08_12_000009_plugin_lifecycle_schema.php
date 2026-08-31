<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000009_plugin_lifecycle_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';

        $columns = $this->columns($pdo, 'cms_plugins');
        foreach ([
            'source' => "VARCHAR(32) NOT NULL DEFAULT 'bundled'",
            'review_status' => "VARCHAR(32) NOT NULL DEFAULT 'unknown'",
            'dependencies_json' => $longText . ' NULL',
            'optional_dependencies_json' => $longText . ' NULL',
            'data_policy_json' => $longText . ' NULL',
            'data_schema_version' => 'VARCHAR(64) NULL',
            'dormant_data_json' => $longText . ' NULL',
            'removed_at' => 'VARCHAR(64) NULL',
            'last_error' => $longText . ' NULL',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE cms_plugins ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_install_previews (
                token VARCHAR(64) PRIMARY KEY,
                plugin_id VARCHAR(191) NOT NULL,
                version VARCHAR(64) NOT NULL,
                package_path VARCHAR(512) NOT NULL,
                staging_dir VARCHAR(512) NOT NULL,
                manifest_json ' . $longText . ' NOT NULL,
                scan_json ' . $longText . ' NOT NULL,
                source VARCHAR(32) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                expires_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_tasks (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL,
                task_name VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL,
                payload_json ' . $longText . ' NULL,
                cancel_requested_at VARCHAR(64) NULL,
                cancel_reason ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $taskColumns = $this->columns($pdo, 'cms_plugin_tasks');
        foreach ([
            'cancel_requested_at' => 'VARCHAR(64) NULL',
            'cancel_reason' => $longText . ' NULL',
        ] as $column => $definition) {
            if (!in_array($column, $taskColumns, true)) {
                $pdo->exec('ALTER TABLE cms_plugin_tasks ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_migrations (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL,
                plugin_version VARCHAR(64) NOT NULL,
                migration_id VARCHAR(191) NOT NULL,
                checksum VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                affected_objects_json ' . $longText . ' NULL,
                started_at VARCHAR(64) NULL,
                completed_at VARCHAR(64) NULL,
                rollback_at VARCHAR(64) NULL,
                error_code VARCHAR(64) NULL,
                error_summary ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }
};
