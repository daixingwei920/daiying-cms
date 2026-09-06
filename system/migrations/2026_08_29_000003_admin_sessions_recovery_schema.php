<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_29_000003_admin_sessions_recovery_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_admin_sessions (
                id ' . $idColumn . ',
                admin_id INTEGER NOT NULL,
                session_hash VARCHAR(128) NOT NULL UNIQUE,
                ip_address VARCHAR(64) NOT NULL DEFAULT "",
                user_agent VARCHAR(255) NOT NULL DEFAULT "",
                mfa_method VARCHAR(32) NOT NULL DEFAULT "",
                created_at VARCHAR(64) NOT NULL,
                last_seen_at VARCHAR(64) NOT NULL,
                revoked_at VARCHAR(64) NOT NULL DEFAULT ""
            )'
        );
        $this->createIndex($pdo, 'cms_admin_sessions', 'idx_cms_admin_sessions_admin_id', '(admin_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_admin_password_resets (
                id ' . $idColumn . ',
                admin_id INTEGER NOT NULL,
                token_hash VARCHAR(128) NOT NULL UNIQUE,
                requested_ip VARCHAR(64) NOT NULL DEFAULT "",
                used_at VARCHAR(64) NOT NULL DEFAULT "",
                expires_at VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );
        $this->createIndex($pdo, 'cms_admin_password_resets', 'idx_cms_admin_password_resets_admin_id', '(admin_id)');
    }

    private function createIndex(\PDO $pdo, string $table, string $index, string $columns): void
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . ' ' . $columns);
            return;
        }

        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . ' ' . $columns);
    }
};
