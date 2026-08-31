<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000006_market_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_extension_sources (
                id ' . $idColumn . ',
                extension_id VARCHAR(191) NOT NULL,
                extension_type VARCHAR(32) NOT NULL,
                source VARCHAR(64) NOT NULL,
                market_id VARCHAR(191) NULL,
                version VARCHAR(64) NOT NULL,
                installed_at VARCHAR(64) NOT NULL,
                metadata_json ' . $longText . ' NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_market_install_logs (
                id ' . $idColumn . ',
                market_id VARCHAR(191) NOT NULL,
                extension_id VARCHAR(191) NOT NULL,
                extension_type VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                plan_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_market_jobs (
                id VARCHAR(64) PRIMARY KEY,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                payload_json ' . $longText . ' NULL,
                result_json ' . $longText . ' NULL,
                error_text ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_market_job_audits (
                id ' . $idColumn . ',
                job_id VARCHAR(64) NOT NULL,
                action VARCHAR(64) NOT NULL,
                actor VARCHAR(191) NOT NULL,
                context_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );
    }
};
