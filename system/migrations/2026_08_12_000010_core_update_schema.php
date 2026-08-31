<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000010_core_update_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_update_operations (
                id ' . $idColumn . ',
                operation_id VARCHAR(64) NOT NULL UNIQUE,
                admin_id INTEGER NULL,
                release_id VARCHAR(191) NOT NULL,
                from_version VARCHAR(64) NOT NULL,
                to_version VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                current_step VARCHAR(64) NOT NULL,
                package_path VARCHAR(512) NULL,
                plan_json ' . $longText . ' NULL,
                restore_points_json ' . $longText . ' NULL,
                error_summary ' . $longText . ' NULL,
                started_at VARCHAR(64) NOT NULL,
                heartbeat_at VARCHAR(64) NOT NULL,
                completed_at VARCHAR(64) NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_update_migrations (
                id ' . $idColumn . ',
                operation_id VARCHAR(64) NOT NULL,
                migration_id VARCHAR(191) NOT NULL,
                version VARCHAR(64) NOT NULL,
                checksum VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                rollback_status VARCHAR(32) NULL,
                affected_objects_json ' . $longText . ' NULL,
                started_at VARCHAR(64) NULL,
                completed_at VARCHAR(64) NULL,
                rollback_at VARCHAR(64) NULL,
                error_summary ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
    }
};
