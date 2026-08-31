<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000005_update_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_update_history (
                id ' . $idColumn . ',
                package_id VARCHAR(191) NOT NULL,
                from_version VARCHAR(64) NOT NULL,
                to_version VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                plan_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
    }
};
