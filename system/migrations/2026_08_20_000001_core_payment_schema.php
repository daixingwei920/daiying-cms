<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_20_000001_core_payment_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payments (
                id ' . $idColumn . ',
                subject_type VARCHAR(96) NOT NULL,
                subject_id VARCHAR(191) NOT NULL,
                provider_id VARCHAR(96) NOT NULL,
                remote_id VARCHAR(191) NULL,
                reference VARCHAR(191) NULL,
                status VARCHAR(32) NOT NULL,
                amount_minor INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                metadata_json ' . $longText . ' NOT NULL,
                authorized_at VARCHAR(64) NULL,
                paid_at VARCHAR(64) NULL,
                failed_at VARCHAR(64) NULL,
                cancelled_at VARCHAR(64) NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_refunds (
                id ' . $idColumn . ',
                payment_id INTEGER NOT NULL,
                provider_id VARCHAR(96) NOT NULL,
                remote_id VARCHAR(191) NULL,
                status VARCHAR(32) NOT NULL,
                amount_minor INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                reason VARCHAR(512) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                metadata_json ' . $longText . ' NOT NULL,
                completed_at VARCHAR(64) NULL,
                failed_at VARCHAR(64) NULL,
                cancelled_at VARCHAR(64) NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_webhook_receipts (
                id ' . $idColumn . ',
                payment_id INTEGER NULL,
                provider_id VARCHAR(96) NOT NULL,
                external_event_id VARCHAR(191) NOT NULL,
                payload_hash VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                metadata_json ' . $longText . ' NOT NULL,
                received_at VARCHAR(64) NOT NULL,
                processed_at VARCHAR(64) NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_authorizations (
                id ' . $idColumn . ',
                payment_id INTEGER NOT NULL,
                subject_type VARCHAR(96) NOT NULL,
                subject_id VARCHAR(191) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                max_uses INTEGER NOT NULL,
                used_count INTEGER NOT NULL,
                expires_at VARCHAR(64) NOT NULL,
                revoked_at VARCHAR(64) NULL,
                last_used_at VARCHAR(64) NULL,
                metadata_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_authorization_events (
                id ' . $idColumn . ',
                authorization_id INTEGER NOT NULL,
                payment_id INTEGER NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                metadata_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_provider_settings (
                id ' . $idColumn . ',
                provider_id VARCHAR(96) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL,
                public_config_json ' . $longText . ' NOT NULL,
                secret_config_ciphertext ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_entitlements (
                id ' . $idColumn . ',
                principal_type VARCHAR(96) NOT NULL,
                principal_id VARCHAR(191) NOT NULL,
                subject_type VARCHAR(96) NOT NULL,
                subject_id VARCHAR(191) NOT NULL,
                source_payment_id INTEGER NOT NULL,
                source_authorization_id INTEGER NULL,
                status VARCHAR(32) NOT NULL,
                expires_at VARCHAR(64) NULL,
                revoked_at VARCHAR(64) NULL,
                metadata_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $this->createIndexIfMissing($pdo, 'cms_payments', 'cms_payments_idempotency_unique', 'CREATE UNIQUE INDEX cms_payments_idempotency_unique ON cms_payments (idempotency_key)');
        $this->createIndexIfMissing($pdo, 'cms_payments', 'cms_payments_provider_remote_unique', 'CREATE UNIQUE INDEX cms_payments_provider_remote_unique ON cms_payments (provider_id, remote_id)');
        $this->createIndexIfMissing($pdo, 'cms_payments', 'cms_payments_subject_status_idx', 'CREATE INDEX cms_payments_subject_status_idx ON cms_payments (subject_type, subject_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_payments', 'cms_payments_created_idx', 'CREATE INDEX cms_payments_created_idx ON cms_payments (created_at)');
        $this->createIndexIfMissing($pdo, 'cms_payments', 'cms_payments_currency_created_idx', 'CREATE INDEX cms_payments_currency_created_idx ON cms_payments (currency, created_at)');
        $this->createIndexIfMissing($pdo, 'cms_payment_refunds', 'cms_payment_refunds_idempotency_unique', 'CREATE UNIQUE INDEX cms_payment_refunds_idempotency_unique ON cms_payment_refunds (idempotency_key)');
        $this->createIndexIfMissing($pdo, 'cms_payment_refunds', 'cms_payment_refunds_provider_remote_unique', 'CREATE UNIQUE INDEX cms_payment_refunds_provider_remote_unique ON cms_payment_refunds (provider_id, remote_id)');
        $this->createIndexIfMissing($pdo, 'cms_payment_refunds', 'cms_payment_refunds_payment_status_idx', 'CREATE INDEX cms_payment_refunds_payment_status_idx ON cms_payment_refunds (payment_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_payment_webhook_receipts', 'cms_payment_webhook_event_unique', 'CREATE UNIQUE INDEX cms_payment_webhook_event_unique ON cms_payment_webhook_receipts (provider_id, external_event_id)');
        $this->createIndexIfMissing($pdo, 'cms_payment_webhook_receipts', 'cms_payment_webhook_receipts_payment_idx', 'CREATE INDEX cms_payment_webhook_receipts_payment_idx ON cms_payment_webhook_receipts (payment_id, status, received_at)');
        $this->createIndexIfMissing($pdo, 'cms_payment_authorizations', 'cms_payment_authorizations_token_unique', 'CREATE UNIQUE INDEX cms_payment_authorizations_token_unique ON cms_payment_authorizations (token_hash)');
        $this->createIndexIfMissing($pdo, 'cms_payment_authorizations', 'cms_payment_authorizations_subject_idx', 'CREATE INDEX cms_payment_authorizations_subject_idx ON cms_payment_authorizations (subject_type, subject_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_payment_authorizations', 'cms_payment_authorizations_payment_idx', 'CREATE INDEX cms_payment_authorizations_payment_idx ON cms_payment_authorizations (payment_id)');
        $this->createIndexIfMissing($pdo, 'cms_payment_authorization_events', 'cms_payment_authorization_events_payment_idx', 'CREATE INDEX cms_payment_authorization_events_payment_idx ON cms_payment_authorization_events (payment_id, authorization_id, created_at)');
        $this->createIndexIfMissing($pdo, 'cms_payment_provider_settings', 'cms_payment_provider_settings_provider_unique', 'CREATE UNIQUE INDEX cms_payment_provider_settings_provider_unique ON cms_payment_provider_settings (provider_id)');
        $this->createIndexIfMissing($pdo, 'cms_payment_provider_settings', 'cms_payment_provider_settings_status_idx', 'CREATE INDEX cms_payment_provider_settings_status_idx ON cms_payment_provider_settings (status)');
        $this->createIndexIfMissing($pdo, 'cms_payment_entitlements', 'cms_payment_entitlements_principal_subject_idx', 'CREATE INDEX cms_payment_entitlements_principal_subject_idx ON cms_payment_entitlements (principal_type, principal_id, subject_type, subject_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_payment_entitlements', 'cms_payment_entitlements_source_payment_idx', 'CREATE INDEX cms_payment_entitlements_source_payment_idx ON cms_payment_entitlements (source_payment_id, source_authorization_id, status)');
    }

    private function createIndexIfMissing(\PDO $pdo, string $table, string $index, string $sql): void
    {
        if ($this->indexExists($pdo, $table, $index)) {
            return;
        }

        $pdo->exec($sql);
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name");
            $stmt->execute([':name' => $index]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
