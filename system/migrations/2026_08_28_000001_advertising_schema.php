<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_28_000001_advertising_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_ad_slots (
                slot_key VARCHAR(48) PRIMARY KEY,
                label VARCHAR(120) NOT NULL,
                placement VARCHAR(64) NOT NULL,
                html ' . $longText . ' NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_ad_events (
                id ' . $idColumn . ',
                slot_key VARCHAR(48) NOT NULL,
                event_type VARCHAR(24) NOT NULL,
                request_path VARCHAR(512) NOT NULL,
                referrer VARCHAR(512) NOT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $this->createIndex($pdo, 'cms_ad_events', 'idx_cms_ad_events_slot_type', ['slot_key', 'event_type']);
        $this->createIndex($pdo, 'cms_ad_events', 'idx_cms_ad_events_created', ['created_at']);
    }

    /** @param list<string> $columns */
    private function createIndex(\PDO $pdo, string $table, string $name, array $columns): void
    {
        if ($this->indexExists($pdo, $table, $name)) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ')');
    }

    private function indexExists(\PDO $pdo, string $table, string $name): bool
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll() as $row) {
                if ((string) $row['name'] === $name) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
