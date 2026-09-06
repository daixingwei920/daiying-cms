<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000004_export_import_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_url_mappings (
                id ' . $idColumn . ',
                source_url VARCHAR(512) NOT NULL,
                target_url VARCHAR(512) NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                source_platform VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_import_jobs (
                id ' . $idColumn . ',
                source_platform VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                source_manifest_json ' . $longText . ' NULL,
                counters_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
    }
};
