<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_26_000001_plugin_sdk_foundation_v1';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_front_user_external_identities (
            id ' . $idColumn . ',
            provider VARCHAR(96) NOT NULL,
            subject VARCHAR(191) NOT NULL,
            front_user_id INTEGER NOT NULL,
            profile_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $this->createIndex($pdo, 'cms_front_user_external_identities', 'cms_front_user_external_identity_unique', 'UNIQUE', '(provider, subject)');
        $this->createIndex($pdo, 'cms_front_user_external_identities', 'cms_front_user_external_identity_user_idx', '', '(front_user_id, provider)');
    }

    private function createIndex(PDO $pdo, string $table, string $index, string $kind, string $columns): void
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE ' . ($kind !== '' ? $kind . ' ' : '') . 'INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . ' ' . $columns);
            return;
        }
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE ' . ($kind !== '' ? $kind . ' ' : '') . 'INDEX ' . $index . ' ON ' . $table . ' ' . $columns);
    }
};
