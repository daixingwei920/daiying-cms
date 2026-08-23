<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000003_plugin_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugins (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                version VARCHAR(64) NOT NULL,
                author VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL,
                trust_level VARCHAR(32) NOT NULL,
                capabilities_json ' . $longText . ' NOT NULL,
                installed_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_data (
                id ' . $idColumn . ',
                plugin_id VARCHAR(191) NOT NULL,
                data_type VARCHAR(64) NOT NULL,
                data_key VARCHAR(191) NOT NULL,
                payload_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
    }
};
