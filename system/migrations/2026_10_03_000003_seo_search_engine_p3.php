<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_03_000003_seo_search_engine_p3';
    }

    public function up(PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'cms_seo_keyword_metrics') && !$this->columnExists($pdo, 'cms_seo_keyword_metrics', 'url_path')) {
            $pdo->exec('ALTER TABLE cms_seo_keyword_metrics ADD COLUMN url_path VARCHAR(512) NOT NULL DEFAULT ""');
        }

        if ($this->tableExists($pdo, 'cms_seo_keyword_metrics')) {
            $this->createIndex($pdo, 'cms_seo_keyword_metrics', 'cms_seo_keyword_metrics_p3_lookup_idx', ['keyword', 'url_path', 'search_engine', 'period_start', 'period_end', 'source'], false);
        }
    }

    /** @param list<string> $columns */
    private function createIndex(PDO $pdo, string $table, string $name, array $columns, bool $unique): void
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
