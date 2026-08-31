<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_29_000002_admin_passkeys_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_admin_passkeys (
                id ' . $idColumn . ',
                admin_id INTEGER NOT NULL,
                credential_id VARCHAR(512) NOT NULL UNIQUE,
                public_key_cose ' . $text . ' NOT NULL,
                sign_count INTEGER NOT NULL DEFAULT 0,
                label VARCHAR(191) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                last_used_at VARCHAR(64) NULL
            )'
        );
        $this->createIndex($pdo, 'cms_admin_passkeys', 'cms_admin_passkeys_admin_idx', '(admin_id)');
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
