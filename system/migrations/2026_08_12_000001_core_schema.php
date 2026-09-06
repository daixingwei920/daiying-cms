<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000001_core_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_settings (
                setting_key VARCHAR(191) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_extension_data (
                id ' . $idColumn . ',
                extension_id VARCHAR(191) NOT NULL,
                data_type VARCHAR(64) NOT NULL,
                data_key VARCHAR(191) NOT NULL,
                payload ' . $longText . ' NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "dormant",
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_admin_users (
                id ' . $idColumn . ',
                email VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_login_attempts (
                id ' . $idColumn . ',
                email VARCHAR(191) NOT NULL,
                ip_address VARCHAR(64) NOT NULL,
                success TINYINT(1) NOT NULL,
                attempted_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_audit_logs (
                id ' . $idColumn . ',
                actor_type VARCHAR(32) NOT NULL,
                actor_id INTEGER NULL,
                action VARCHAR(191) NOT NULL,
                context_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

    }
};
