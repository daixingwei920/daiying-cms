<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000011_content_scheduler_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        if (!$this->columnExists($pdo, 'cms_contents', 'scheduled_at')) {
            $pdo->exec('ALTER TABLE cms_contents ADD COLUMN scheduled_at VARCHAR(64) NULL');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_content_events (
                id ' . $idColumn . ',
                event_type VARCHAR(191) NOT NULL,
                content_id INTEGER NOT NULL,
                payload_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );
        if (!$this->indexExists($pdo, 'cms_content_events', 'cms_content_events_type_content_unique')) {
            $pdo->exec('CREATE UNIQUE INDEX cms_content_events_type_content_unique ON cms_content_events (event_type, content_id)');
        }
        if (!$this->indexExists($pdo, 'cms_contents', 'cms_contents_scheduled_due_index')) {
            $pdo->exec('CREATE INDEX cms_contents_scheduled_due_index ON cms_contents (status, scheduled_at)');
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt->fetchAll() as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name");
            $stmt->execute([':name' => $index]);

            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :name'
        );
        $stmt->execute([':table' => $table, ':name' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
