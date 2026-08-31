<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000008_media_release_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $columns = $this->columns($pdo, 'cms_media');
        foreach ([
            'storage_key' => 'VARCHAR(512) NULL',
            'extension' => 'VARCHAR(16) NULL',
            'width' => 'INTEGER NULL',
            'height' => 'INTEGER NULL',
            'duration_seconds' => 'REAL NULL',
            'title' => 'VARCHAR(255) NULL',
            'description' => $longText . ' NULL',
            'alt_text' => 'VARCHAR(255) NULL',
            'uploaded_by' => 'INTEGER NULL',
            'status' => "VARCHAR(32) NOT NULL DEFAULT 'Active'",
            'deleted_at' => 'VARCHAR(64) NULL',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE cms_media ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_media_references (
                id ' . $idColumn . ',
                media_id INTEGER NOT NULL,
                content_id INTEGER NOT NULL,
                block_type VARCHAR(64) NOT NULL,
                field_name VARCHAR(64) NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );
        if (!$this->indexExists($pdo, 'cms_media_references', 'cms_media_refs_media_idx')) {
            $pdo->exec('CREATE INDEX cms_media_refs_media_idx ON cms_media_references (media_id)');
        }
        if (!$this->indexExists($pdo, 'cms_media_references', 'cms_media_refs_content_idx')) {
            $pdo->exec('CREATE INDEX cms_media_refs_content_idx ON cms_media_references (content_id)');
        }
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
            return array_map(static fn (array $row): string => (string) $row['name'], $rows);
        }

        $rows = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll();
        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $rows);
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
