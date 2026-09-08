<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_08_000003_foundation_system_services';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_roles (
            role_id VARCHAR(64) PRIMARY KEY,
            label VARCHAR(191) NOT NULL,
            built_in INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_role_capabilities (
            id ' . $idColumn . ',
            role_id VARCHAR(64) NOT NULL,
            capability VARCHAR(191) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_core_queue_jobs (
            id ' . $idColumn . ',
            job_type VARCHAR(191) NOT NULL,
            owner VARCHAR(96) NOT NULL DEFAULT "core",
            payload_json ' . $longText . ' NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            not_before VARCHAR(64) NOT NULL,
            last_error ' . $longText . ' NULL,
            completed_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_webhook_endpoints (
            id ' . $idColumn . ',
            owner VARCHAR(96) NOT NULL DEFAULT "core",
            url VARCHAR(500) NOT NULL,
            secret_hash VARCHAR(64) NOT NULL DEFAULT "",
            events_json ' . $longText . ' NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "enabled",
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_webhook_deliveries (
            id ' . $idColumn . ',
            endpoint_id INTEGER NOT NULL,
            event_id VARCHAR(191) NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            last_error ' . $longText . ' NULL,
            response_status INTEGER NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $this->seedRoles($pdo);
    }

    private function seedRoles(PDO $pdo): void
    {
        $now = gmdate('c');
        foreach ([
            'super_admin' => 'Super Admin',
            'admin' => 'Admin',
            'editor' => 'Editor',
            'author' => 'Author',
        ] as $role => $label) {
            $stmt = $pdo->prepare('SELECT role_id FROM cms_roles WHERE role_id = :role_id LIMIT 1');
            $stmt->execute([':role_id' => $role]);
            if ($stmt->fetch()) {
                continue;
            }
            $pdo->prepare('INSERT INTO cms_roles (role_id, label, built_in, created_at, updated_at) VALUES (:role_id, :label, 1, :created_at, :updated_at)')
                ->execute([':role_id' => $role, ':label' => $label, ':created_at' => $now, ':updated_at' => $now]);
        }
    }
};
