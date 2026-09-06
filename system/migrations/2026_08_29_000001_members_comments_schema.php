<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_29_000001_members_comments_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_front_users (
                id ' . $idColumn . ',
                email VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                last_login_at VARCHAR(64) NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_front_login_attempts (
                id ' . $idColumn . ',
                email VARCHAR(191) NOT NULL,
                ip_address VARCHAR(64) NOT NULL,
                success TINYINT(1) NOT NULL,
                attempted_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_comments (
                id ' . $idColumn . ',
                content_id INTEGER NOT NULL,
                parent_id INTEGER NULL,
                user_id INTEGER NULL,
                author_name VARCHAR(191) NOT NULL,
                author_email VARCHAR(191) NULL,
                author_url VARCHAR(255) NULL,
                body ' . $longText . ' NOT NULL,
                status VARCHAR(32) NOT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $this->createIndex($pdo, 'cms_comments', 'cms_comments_content_status_idx', '(content_id, status, created_at)');
        $this->createIndex($pdo, 'cms_comments', 'cms_comments_status_idx', '(status, created_at)');
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
