<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_14_000001_plugin_payment_bindings';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_plugin_payment_bindings (
            id ' . $id . ',
            plugin_id VARCHAR(96) NOT NULL,
            subject_type VARCHAR(96) NOT NULL,
            local_subject_id VARCHAR(191) NOT NULL,
            core_subject_id VARCHAR(64) NOT NULL,
            buyer_id INTEGER NOT NULL,
            provider_id VARCHAR(96) NOT NULL,
            external_key_hash VARCHAR(64) NOT NULL,
            internal_key VARCHAR(96) NOT NULL,
            quote_version VARCHAR(96) NOT NULL,
            amount_minor INTEGER NOT NULL,
            currency VARCHAR(3) NOT NULL,
            beneficiary_type VARCHAR(96) NOT NULL,
            beneficiary_id VARCHAR(191) NOT NULL,
            duration_seconds INTEGER NOT NULL,
            request_hash VARCHAR(64) NOT NULL,
            payment_ref VARCHAR(64) NOT NULL,
            state VARCHAR(24) NOT NULL,
            payment_id INTEGER NULL,
            trusted_paid_at VARCHAR(64) NULL,
            entitlement_id INTEGER NULL,
            expires_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        foreach ([
            'CREATE UNIQUE INDEX cms_plugin_payments_order_unique ON cms_plugin_payment_bindings (plugin_id, subject_type, local_subject_id)',
            'CREATE UNIQUE INDEX cms_plugin_payments_key_unique ON cms_plugin_payment_bindings (plugin_id, external_key_hash)',
            'CREATE UNIQUE INDEX cms_plugin_payments_internal_unique ON cms_plugin_payment_bindings (internal_key)',
            'CREATE UNIQUE INDEX cms_plugin_payments_ref_unique ON cms_plugin_payment_bindings (payment_ref)',
            'CREATE UNIQUE INDEX cms_plugin_payments_payment_unique ON cms_plugin_payment_bindings (payment_id)',
            'CREATE UNIQUE INDEX cms_plugin_payments_entitlement_unique ON cms_plugin_payment_bindings (entitlement_id)',
        ] as $sql) {
            $name = explode(' ', $sql)[3];
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name");
                $stmt->execute([':name' => $name]);
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
                $stmt->execute([':table' => 'cms_plugin_payment_bindings', ':name' => $name]);
            }
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }
    }
};
