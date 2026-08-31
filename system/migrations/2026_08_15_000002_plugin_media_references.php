<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_15_000002_plugin_media_references';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_plugin_media_references (
                id ' . $idColumn . ',
                plugin_id VARCHAR(96) NOT NULL,
                media_id INTEGER NOT NULL,
                reference_type VARCHAR(96) NOT NULL,
                reference_id VARCHAR(191) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $this->createIndex($pdo, 'cms_plugin_media_refs_media_idx', 'cms_plugin_media_references', ['media_id']);
        $this->createIndex($pdo, 'cms_plugin_media_refs_plugin_idx', 'cms_plugin_media_references', ['plugin_id']);
        $this->createUnique($pdo, 'cms_plugin_media_refs_unique', 'cms_plugin_media_references', ['plugin_id', 'media_id', 'reference_type', 'reference_id']);
    }

    /** @param list<string> $columns */
    private function createIndex(\PDO $pdo, string $name, string $table, array $columns): void
    {
        if ($this->indexExists($pdo, $table, $name)) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ')');
    }

    /** @param list<string> $columns */
    private function createUnique(\PDO $pdo, string $name, string $table, array $columns): void
    {
        if ($this->indexExists($pdo, $table, $name)) {
            return;
        }
        $pdo->exec('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ')');
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
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
