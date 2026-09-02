<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_29_000005_commercial_ecosystem_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_products (
            id ' . $idColumn . ',
            product_id VARCHAR(191) NOT NULL UNIQUE,
            owner_type VARCHAR(32) NOT NULL,
            owner_id VARCHAR(191) NOT NULL DEFAULT \'\',
            developer_id INTEGER NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            product_type VARCHAR(32) NOT NULL,
            license_mode VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            license_required_from_version VARCHAR(64) NOT NULL DEFAULT \'\',
            pricing_text VARCHAR(255) NOT NULL DEFAULT \'\',
            purchase_url VARCHAR(500) NOT NULL DEFAULT \'\',
            renew_url VARCHAR(500) NOT NULL DEFAULT \'\',
            support_url VARCHAR(500) NOT NULL DEFAULT \'\',
            developer_url VARCHAR(500) NOT NULL DEFAULT \'\',
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_license_batches (
            id ' . $idColumn . ',
            batch_id VARCHAR(191) NOT NULL UNIQUE,
            product_id VARCHAR(191) NOT NULL,
            developer_id INTEGER NULL,
            quantity INTEGER NOT NULL,
            site_limit INTEGER NOT NULL,
            update_duration_days INTEGER NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            created_by VARCHAR(191) NOT NULL,
            export_count INTEGER NOT NULL DEFAULT 0,
            last_exported_at VARCHAR(64) NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_licenses (
            id ' . $idColumn . ',
            license_key_hash VARCHAR(64) NOT NULL UNIQUE,
            license_key_mask VARCHAR(64) NOT NULL,
            license_key_plain VARCHAR(191) NOT NULL,
            product_id VARCHAR(191) NOT NULL,
            developer_id INTEGER NULL,
            batch_id VARCHAR(191) NOT NULL,
            site_limit INTEGER NOT NULL,
            update_duration_days INTEGER NOT NULL,
            status VARCHAR(32) NOT NULL,
            activated_at VARCHAR(64) NULL,
            update_until VARCHAR(64) NULL,
            disabled_at VARCHAR(64) NULL,
            revoked_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_license_sites (
            id ' . $idColumn . ',
            license_key_hash VARCHAR(64) NOT NULL,
            product_id VARCHAR(191) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            domain VARCHAR(191) NOT NULL DEFAULT \'\',
            site_url VARCHAR(500) NOT NULL DEFAULT \'\',
            status VARCHAR(32) NOT NULL,
            first_seen_at VARCHAR(64) NOT NULL,
            last_seen_at VARCHAR(64) NOT NULL,
            deactivated_at VARCHAR(64) NULL,
            deactivate_count INTEGER NOT NULL DEFAULT 0,
            last_deactivation_at VARCHAR(64) NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_download_tokens (
            id ' . $idColumn . ',
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            product_id VARCHAR(191) NOT NULL,
            version VARCHAR(64) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            license_key_hash VARCHAR(64) NOT NULL,
            scopes_json ' . $longText . ' NOT NULL,
            expires_at VARCHAR(64) NOT NULL,
            downloaded_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_audit_events (
            id ' . $idColumn . ',
            event_type VARCHAR(64) NOT NULL,
            actor_type VARCHAR(64) NOT NULL,
            actor_id VARCHAR(191) NOT NULL,
            subject_type VARCHAR(64) NOT NULL,
            subject_id VARCHAR(191) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_commercial_developer_plans (
            id ' . $idColumn . ',
            developer_id INTEGER NOT NULL,
            developer_plan VARCHAR(64) NOT NULL DEFAULT \'Unlimited\',
            license_generation_limit INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT \'Active\',
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_site_licenses (
            id ' . $idColumn . ',
            product_id VARCHAR(191) NOT NULL UNIQUE,
            license_key_hash VARCHAR(64) NOT NULL,
            license_key_mask VARCHAR(64) NOT NULL,
            license_key_credential VARCHAR(191) NOT NULL DEFAULT \'\',
            status VARCHAR(32) NOT NULL,
            update_until VARCHAR(64) NULL,
            activation_payload_json ' . $longText . ' NOT NULL,
            activated_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
    }
};
