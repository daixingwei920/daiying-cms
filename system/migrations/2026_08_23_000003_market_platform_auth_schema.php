<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_23_000003_market_platform_auth_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_platform_accounts (
            id ' . $idColumn . ',
            email VARCHAR(191) NOT NULL UNIQUE,
            display_name VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL,
            capabilities_json ' . $longText . ' NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_platform_audits (
            id ' . $idColumn . ',
            actor_id INTEGER NOT NULL,
            actor_role VARCHAR(32) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            subject_type VARCHAR(64) NOT NULL,
            subject_id VARCHAR(191) NOT NULL,
            before_state VARCHAR(64) NOT NULL DEFAULT \'\',
            after_state VARCHAR(64) NOT NULL DEFAULT \'\',
            reason ' . $longText . ' NULL,
            ip_address VARCHAR(64) NOT NULL DEFAULT \'\',
            user_agent VARCHAR(500) NOT NULL DEFAULT \'\',
            payload_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_platform_state (
            id ' . $idColumn . ',
            platform_initialized INTEGER NOT NULL DEFAULT 0,
            initialized_at VARCHAR(64) NULL,
            initialized_by VARCHAR(191) NOT NULL DEFAULT \'\',
            platform_instance_id VARCHAR(64) NOT NULL DEFAULT \'\',
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
    }
};
