<?php

declare(strict_types=1);

return [
    'id' => '2026_09_10_000001_extension_trust_grants',
    'description' => 'Add signed official extension trust grant registry.',
    'up' => function (\PDO $pdo): void {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS cms_extension_trust_grants (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    extension_id TEXT NOT NULL,
                    extension_type TEXT NOT NULL,
                    publisher TEXT NOT NULL,
                    source TEXT NOT NULL,
                    trust_level TEXT NOT NULL,
                    capability_namespaces_json TEXT NOT NULL DEFAULT '[]',
                    table_prefixes_json TEXT NOT NULL DEFAULT '[]',
                    route_prefixes_json TEXT NOT NULL DEFAULT '[]',
                    admin_menu_json TEXT NOT NULL DEFAULT '{}',
                    provider_capabilities_json TEXT NOT NULL DEFAULT '{}',
                    status TEXT NOT NULL DEFAULT 'active',
                    schema_version INTEGER NOT NULL DEFAULT 1,
                    issued_at TEXT NOT NULL DEFAULT '',
                    expires_at TEXT NOT NULL DEFAULT '',
                    grant_fingerprint TEXT NOT NULL,
                    signature TEXT NOT NULL,
                    key_id TEXT NOT NULL DEFAULT '',
                    payload_json TEXT NOT NULL DEFAULT '{}',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )"
            );
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_cms_extension_trust_grants_fingerprint ON cms_extension_trust_grants (grant_fingerprint)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cms_extension_trust_grants_extension ON cms_extension_trust_grants (extension_id, extension_type, status)');
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cms_extension_trust_grants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                extension_id VARCHAR(128) NOT NULL,
                extension_type VARCHAR(32) NOT NULL,
                publisher VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                trust_level VARCHAR(32) NOT NULL,
                capability_namespaces_json JSON NOT NULL,
                table_prefixes_json JSON NOT NULL,
                route_prefixes_json JSON NOT NULL,
                admin_menu_json JSON NOT NULL,
                provider_capabilities_json JSON NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                schema_version INT NOT NULL DEFAULT 1,
                issued_at VARCHAR(40) NOT NULL DEFAULT '',
                expires_at VARCHAR(40) NOT NULL DEFAULT '',
                grant_fingerprint CHAR(64) NOT NULL,
                signature TEXT NOT NULL,
                key_id VARCHAR(128) NOT NULL DEFAULT '',
                payload_json JSON NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY idx_cms_extension_trust_grants_fingerprint (grant_fingerprint),
                KEY idx_cms_extension_trust_grants_extension (extension_id, extension_type, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
    'down' => function (\PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS cms_extension_trust_grants');
    },
];
