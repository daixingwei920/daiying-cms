<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_08_000004_content_foundation_safety';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        foreach ([
            'deleted_at' => 'VARCHAR(64) NULL',
        ] as $column => $definition) {
            if (!$this->columnExists($pdo, 'cms_contents', $column)) {
                $pdo->exec('ALTER TABLE cms_contents ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_content_revisions (
            id ' . $idColumn . ',
            content_id INTEGER NOT NULL,
            content_type VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL,
            blocks_json ' . $longText . ' NOT NULL,
            meta_json ' . $longText . ' NULL,
            actor_id INTEGER NULL,
            reason VARCHAR(64) NOT NULL DEFAULT "manual",
            created_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_content_autosaves (
            id ' . $idColumn . ',
            content_id INTEGER NULL,
            actor_id INTEGER NOT NULL,
            content_type VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            blocks_json ' . $longText . ' NOT NULL,
            meta_json ' . $longText . ' NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        foreach ([
            'enabled' => 'INTEGER NOT NULL DEFAULT 1',
            'hit_count' => 'INTEGER NOT NULL DEFAULT 0',
            'last_hit_at' => 'VARCHAR(64) NULL',
        ] as $column => $definition) {
            if ($this->tableExists($pdo, 'cms_url_mappings') && !$this->columnExists($pdo, 'cms_url_mappings', $column)) {
                $pdo->exec('ALTER TABLE cms_url_mappings ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $this->createIndex($pdo, 'cms_content_revisions', 'cms_content_revisions_content_idx', 'content_id, id');
        $this->createIndex($pdo, 'cms_content_autosaves', 'cms_content_autosaves_lookup_idx', 'content_id, actor_id, id');
        if ($this->tableExists($pdo, 'cms_url_mappings')) {
            $this->createIndex($pdo, 'cms_url_mappings', 'cms_url_mappings_source_idx', 'source_url');
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name");
            $stmt->execute([':name' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function createIndex(PDO $pdo, string $table, string $name, string $columns): void
    {
        if ($this->indexExists($pdo, $table, $name)) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $columns . ')');
    }

    private function indexExists(PDO $pdo, string $table, string $name): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name");
            $stmt->execute([':name' => $name]);

            return (int) $stmt->fetchColumn() > 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :name');
        $stmt->execute([':table' => $table, ':name' => $name]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
