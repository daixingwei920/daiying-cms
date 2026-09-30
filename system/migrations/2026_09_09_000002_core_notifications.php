<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_09_000002_core_notifications';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_notifications (
            id ' . $idColumn . ',
            uuid VARCHAR(64) NOT NULL,
            site_id VARCHAR(96) NOT NULL DEFAULT "default",
            source_type VARCHAR(64) NOT NULL DEFAULT "system",
            source_owner VARCHAR(120) NOT NULL DEFAULT "core",
            source_id VARCHAR(191) NOT NULL DEFAULT "",
            severity VARCHAR(32) NOT NULL DEFAULT "info",
            title VARCHAR(191) NOT NULL,
            body ' . $longText . ' NOT NULL,
            action_url VARCHAR(500) NOT NULL DEFAULT "",
            status VARCHAR(32) NOT NULL DEFAULT "unread",
            dedupe_key VARCHAR(191) NOT NULL DEFAULT "",
            payload_json ' . $longText . ' NULL,
            created_at VARCHAR(64) NOT NULL,
            read_at VARCHAR(64) NULL,
            archived_at VARCHAR(64) NULL
        )');

        foreach ($this->columns($longText) as $column => $definition) {
            if (!in_array($column, $this->tableColumns($pdo, 'cms_notifications'), true)) {
                $pdo->exec('ALTER TABLE cms_notifications ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $this->createIndex($pdo, 'cms_notifications', 'idx_notifications_status_created', ['status', 'created_at']);
        $this->createIndex($pdo, 'cms_notifications', 'idx_notifications_source', ['source_owner', 'source_type']);
        $this->createIndex($pdo, 'cms_notifications', 'idx_notifications_dedupe', ['dedupe_key']);
        $this->createIndex($pdo, 'cms_notifications', 'idx_notifications_uuid', ['uuid'], true);
    }

    /** @return array<string,string> */
    private function columns(string $longText): array
    {
        return [
            'uuid' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'site_id' => 'VARCHAR(96) NOT NULL DEFAULT "default"',
            'source_type' => 'VARCHAR(64) NOT NULL DEFAULT "system"',
            'source_owner' => 'VARCHAR(120) NOT NULL DEFAULT "core"',
            'source_id' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'severity' => 'VARCHAR(32) NOT NULL DEFAULT "info"',
            'title' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'body' => $longText . ' NOT NULL DEFAULT ""',
            'action_url' => 'VARCHAR(500) NOT NULL DEFAULT ""',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "unread"',
            'dedupe_key' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'payload_json' => $longText . ' NULL',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'read_at' => 'VARCHAR(64) NULL',
            'archived_at' => 'VARCHAR(64) NULL',
        ];
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
    private function createIndex(PDO $pdo, string $table, string $name, array $columns, bool $unique = false): void
    {
        $columnList = implode(', ', $columns);
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $columnList . ')');
            return;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $name . ' ON ' . $table . ' (' . $columnList . ')');
        }
    }
};
