<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_29_000001_notification_recipients';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_notifications')) {
            return;
        }

        $columns = $this->tableColumns($pdo, 'cms_notifications');
        if (!in_array('recipient_type', $columns, true)) {
            $pdo->exec('ALTER TABLE cms_notifications ADD COLUMN recipient_type VARCHAR(32) NOT NULL DEFAULT "all"');
        }
        if (!in_array('recipient_id', $columns, true)) {
            $pdo->exec('ALTER TABLE cms_notifications ADD COLUMN recipient_id INTEGER NOT NULL DEFAULT 0');
        }

        $this->createIndex($pdo, 'cms_notifications', 'idx_notifications_recipient_status', ['recipient_type', 'recipient_id', 'status']);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute([':table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> */
    private function tableColumns(PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC));
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $columns */
    private function createIndex(PDO $pdo, string $table, string $name, array $columns): void
    {
        $columnList = implode(', ', $columns);
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $columnList . ')');
            return;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $columnList . ')');
        }
    }
};
