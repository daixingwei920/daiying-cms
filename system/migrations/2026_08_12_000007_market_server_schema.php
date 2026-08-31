<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000007_market_server_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_developers (
            id ' . $idColumn . ',
            developer_key VARCHAR(191) NOT NULL UNIQUE,
            display_name VARCHAR(191) NOT NULL,
            email VARCHAR(191) NOT NULL,
            password_hash VARCHAR(255) NULL,
            role VARCHAR(32) NOT NULL DEFAULT \'Developer\',
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_projects (
            id ' . $idColumn . ',
            developer_id INTEGER NOT NULL,
            market_id VARCHAR(191) NOT NULL UNIQUE,
            extension_type VARCHAR(32) NOT NULL,
            name VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_project_members (
            id ' . $idColumn . ',
            project_id INTEGER NOT NULL,
            developer_id INTEGER NOT NULL,
            role VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_versions (
            id ' . $idColumn . ',
            project_id INTEGER NOT NULL,
            version VARCHAR(64) NOT NULL,
            package_path VARCHAR(500) NOT NULL,
            package_sha256 VARCHAR(64) NOT NULL,
            changelog ' . $longText . ' NULL,
            status VARCHAR(32) NOT NULL,
            submitted_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_scan_jobs (
            id ' . $idColumn . ',
            version_id INTEGER NOT NULL,
            status VARCHAR(32) NOT NULL,
            findings_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            completed_at VARCHAR(64) NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_reviews (
            id ' . $idColumn . ',
            version_id INTEGER NOT NULL,
            reviewer_id INTEGER NOT NULL,
            decision VARCHAR(32) NOT NULL,
            notes ' . $longText . ' NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_package_signatures (
            id ' . $idColumn . ',
            version_id INTEGER NOT NULL,
            algorithm VARCHAR(64) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            signature ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_uploads (
            id ' . $idColumn . ',
            project_id INTEGER NOT NULL,
            object_key VARCHAR(500) NOT NULL,
            original_name VARCHAR(191) NOT NULL,
            byte_size INTEGER NOT NULL DEFAULT 0,
            sha256_hash VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            confirmed_at VARCHAR(64) NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_webhook_events (
            id ' . $idColumn . ',
            event_id VARCHAR(191) NOT NULL UNIQUE,
            payload_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_download_audits (
            id ' . $idColumn . ',
            market_id VARCHAR(191) NOT NULL,
            version VARCHAR(64) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            package_sha256 VARCHAR(64) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            user_agent VARCHAR(500) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_developer_notifications (
            id ' . $idColumn . ',
            developer_id INTEGER NOT NULL,
            project_id INTEGER NULL,
            type VARCHAR(64) NOT NULL,
            title VARCHAR(191) NOT NULL,
            body ' . $longText . ' NOT NULL,
            dispatch_status VARCHAR(32) NOT NULL DEFAULT \'Pending\',
            dispatch_attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at VARCHAR(64) NULL,
            read_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_notification_dispatch_attempts (
            id ' . $idColumn . ',
            notification_id INTEGER NOT NULL,
            channel VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            error_message VARCHAR(500) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_payments (
            id ' . $idColumn . ',
            market_id VARCHAR(191) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            plan_key VARCHAR(64) NOT NULL DEFAULT \'\',
            license_key VARCHAR(191) NOT NULL DEFAULT \'\',
            subscription_id VARCHAR(191) NOT NULL DEFAULT \'\',
            invoice_number VARCHAR(191) NOT NULL DEFAULT \'\',
            billing_email VARCHAR(191) NOT NULL DEFAULT \'\',
            billing_country VARCHAR(8) NOT NULL DEFAULT \'\',
            customer_tax_id VARCHAR(64) NOT NULL DEFAULT \'\',
            tax_amount_cents INTEGER NOT NULL DEFAULT 0,
            amount_cents INTEGER NOT NULL,
            currency VARCHAR(8) NOT NULL,
            status VARCHAR(32) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            provider_reference VARCHAR(191) NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'plan_key', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'license_key', 'VARCHAR(191) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'subscription_id', 'VARCHAR(191) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'invoice_number', 'VARCHAR(191) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'billing_email', 'VARCHAR(191) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'billing_country', 'VARCHAR(8) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'customer_tax_id', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_payments', 'tax_amount_cents', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_payment_webhook_events (
            id ' . $idColumn . ',
            event_id VARCHAR(191) NOT NULL UNIQUE,
            provider VARCHAR(64) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_webhook_dead_letters (
            id ' . $idColumn . ',
            event_id VARCHAR(191) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            error_message VARCHAR(500) NOT NULL,
            status VARCHAR(32) NOT NULL,
            retry_count INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_webhook_dead_letter_alerts (
            id ' . $idColumn . ',
            dead_letter_id INTEGER NOT NULL,
            channel VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_portal_tokens (
            id ' . $idColumn . ',
            license_key VARCHAR(191) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at VARCHAR(64) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_commercial_audits (
            id ' . $idColumn . ',
            event_type VARCHAR(64) NOT NULL,
            subject_key VARCHAR(191) NOT NULL,
            actor VARCHAR(191) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_commercial_audit_policies (
            id ' . $idColumn . ',
            retention_days INTEGER NOT NULL DEFAULT 365,
            audit_exports INTEGER NOT NULL DEFAULT 1,
            purge_limit INTEGER NOT NULL DEFAULT 500,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_settlements (
            id ' . $idColumn . ',
            developer_id INTEGER NOT NULL,
            amount_cents INTEGER NOT NULL,
            currency VARCHAR(8) NOT NULL,
            status VARCHAR(32) NOT NULL,
            period_start VARCHAR(32) NOT NULL,
            period_end VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            settled_at VARCHAR(64) NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_ai_settings (
            id ' . $idColumn . ',
            provider VARCHAR(64) NOT NULL,
            model VARCHAR(128) NOT NULL,
            api_key_ref VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_ai_policies (
            id ' . $idColumn . ',
            auto_approve INTEGER NOT NULL DEFAULT 0,
            max_findings INTEGER NOT NULL DEFAULT 0,
            reviewer_id INTEGER NOT NULL DEFAULT 1,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_licenses (
            id ' . $idColumn . ',
            market_id VARCHAR(191) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            license_key VARCHAR(191) NOT NULL UNIQUE,
            status VARCHAR(32) NOT NULL,
            seats INTEGER NOT NULL DEFAULT 1,
            plan_key VARCHAR(64) NOT NULL DEFAULT \'\',
            auto_renew INTEGER NOT NULL DEFAULT 0,
            expires_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NULL
        )');
        $this->addColumnIfMissing($pdo, 'cms_market_licenses', 'plan_key', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($pdo, 'cms_market_licenses', 'auto_renew', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($pdo, 'cms_market_licenses', 'updated_at', 'VARCHAR(64) NULL');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_license_audits (
            id ' . $idColumn . ',
            license_key VARCHAR(191) NOT NULL,
            action VARCHAR(64) NOT NULL,
            actor VARCHAR(191) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_notification_schedules (
            id ' . $idColumn . ',
            schedule_key VARCHAR(191) NOT NULL UNIQUE,
            channel VARCHAR(64) NOT NULL,
            interval_minutes INTEGER NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            last_run_at VARCHAR(64) NULL,
            next_run_at VARCHAR(64) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_plans (
            id ' . $idColumn . ',
            market_id VARCHAR(191) NOT NULL,
            plan_key VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            price_cents INTEGER NOT NULL,
            currency VARCHAR(8) NOT NULL,
            billing_period VARCHAR(32) NOT NULL,
            trial_days INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');
        $this->addColumnIfMissing($pdo, 'cms_market_plans', 'trial_days', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_tax_rules (
            id ' . $idColumn . ',
            country VARCHAR(8) NOT NULL,
            tax_rate_basis_points INTEGER NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_subscriptions (
            id ' . $idColumn . ',
            provider VARCHAR(64) NOT NULL,
            provider_subscription_id VARCHAR(191) NOT NULL UNIQUE,
            license_key VARCHAR(191) NOT NULL,
            market_id VARCHAR(191) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            plan_key VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            current_period_end VARCHAR(64) NULL,
            cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_subscription_action_jobs (
            id ' . $idColumn . ',
            provider_subscription_id VARCHAR(191) NOT NULL,
            action VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            provider_payload_json ' . $longText . ' NOT NULL,
            error_message VARCHAR(500) NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_license_usage (
            id ' . $idColumn . ',
            license_key VARCHAR(191) NOT NULL,
            site_id VARCHAR(191) NOT NULL,
            active_seats INTEGER NOT NULL DEFAULT 0,
            payload_json ' . $longText . ' NOT NULL,
            reported_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_commercial_policies (
            id ' . $idColumn . ',
            require_license_for_install INTEGER NOT NULL DEFAULT 0,
            require_license_for_download INTEGER NOT NULL DEFAULT 0,
            enforce_seat_limits INTEGER NOT NULL DEFAULT 1,
            updated_at VARCHAR(64) NOT NULL
        )');
    }

    private function addColumnIfMissing(\PDO $pdo, string $table, string $column, string $definition): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $columns = [];
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
                $columns[] = (string) ($row['name'] ?? '');
            }
        } else {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table);
            foreach ($stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
                $columns[] = (string) ($row['Field'] ?? '');
            }
        }

        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
};
