<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_29_000004_differentiation_platform_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $this->addPluginColumns($pdo, $longText);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_site_vault_packages (
                id ' . $idColumn . ',
                vault_id VARCHAR(64) NOT NULL UNIQUE,
                package_path VARCHAR(512) NOT NULL,
                package_format VARCHAR(32) NOT NULL,
                cms_version VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                manifest_json ' . $longText . ' NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                restored_at VARCHAR(64) NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_site_timeline (
                id ' . $idColumn . ',
                event_id VARCHAR(64) NOT NULL UNIQUE,
                actor_type VARCHAR(32) NOT NULL,
                actor_id INTEGER NULL,
                operation VARCHAR(96) NOT NULL,
                target_type VARCHAR(96) NOT NULL,
                target_id VARCHAR(191) NOT NULL,
                before_ref VARCHAR(191) NULL,
                after_ref VARCHAR(191) NULL,
                recoverability VARCHAR(32) NOT NULL,
                related_snapshot_id VARCHAR(64) NULL,
                metadata_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_reusable_blocks (
                id ' . $idColumn . ',
                block_id VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                block_type VARCHAR(96) NOT NULL,
                schema_version VARCHAR(32) NOT NULL,
                payload_json ' . $longText . ' NOT NULL,
                required_plugins_json ' . $longText . ' NOT NULL,
                integrity_sha256 VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_capability_packs (
                id ' . $idColumn . ',
                pack_id VARCHAR(96) NOT NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                version VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                manifest_json ' . $longText . ' NOT NULL,
                installed_at VARCHAR(64) NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_shadow_upgrade_runs (
                id ' . $idColumn . ',
                run_id VARCHAR(64) NOT NULL UNIQUE,
                package_path VARCHAR(512) NOT NULL,
                current_version VARCHAR(64) NOT NULL,
                target_version VARCHAR(64) NOT NULL,
                capability_level VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                report_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_runtime_failures (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL,
                plugin_version VARCHAR(64) NOT NULL,
                failure_kind VARCHAR(64) NOT NULL,
                affected_area VARCHAR(191) NOT NULL,
                error_summary ' . $longText . ' NOT NULL,
                failure_count INTEGER NOT NULL DEFAULT 1,
                isolation_status VARCHAR(32) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_permission_grants (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL,
                plugin_version VARCHAR(64) NOT NULL,
                permissions_json ' . $longText . ' NOT NULL,
                status VARCHAR(32) NOT NULL,
                granted_by INTEGER NULL,
                granted_at VARCHAR(64) NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
    }

    private function addPluginColumns(\PDO $pdo, string $longText): void
    {
        $columns = $this->columns($pdo, 'cms_plugins');
        foreach ([
            'runtime_status' => "VARCHAR(32) NOT NULL DEFAULT 'OK'",
            'runtime_failure_count' => 'INTEGER NOT NULL DEFAULT 0',
            'runtime_last_failure_at' => 'VARCHAR(64) NULL',
            'runtime_error_summary' => $longText . ' NULL',
            'declared_permissions_json' => $longText . ' NULL',
            'permission_grant_status' => "VARCHAR(32) NOT NULL DEFAULT 'legacy'",
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE cms_plugins ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }
};
