<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_30_000001_cms_site_license_client_schema';
    }

    public function description(): string
    {
        return 'Creates the customer CMS local license activation cache.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_site_licenses (
            product_id TEXT PRIMARY KEY,
            license_key_hash TEXT NOT NULL,
            license_key_mask TEXT NOT NULL,
            license_key_credential TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL,
            update_until TEXT NOT NULL DEFAULT '',
            activation_payload_json TEXT NOT NULL DEFAULT '{}',
            activated_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
    }
};
