<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_03_000002_seo_keyword_center_p2';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_seo_keywords (
            id ' . $idColumn . ',
            keyword VARCHAR(191) NOT NULL,
            primary_url VARCHAR(512) NOT NULL DEFAULT "",
            status VARCHAR(32) NOT NULL DEFAULT "draft",
            source VARCHAR(32) NOT NULL DEFAULT "manual",
            notes ' . $text . ' NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_seo_keyword_metrics (
            id ' . $idColumn . ',
            keyword VARCHAR(191) NOT NULL,
            search_engine VARCHAR(32) NOT NULL,
            impressions INTEGER NULL,
            clicks INTEGER NULL,
            ctr VARCHAR(32) NULL,
            average_position VARCHAR(32) NULL,
            period_start VARCHAR(64) NOT NULL,
            period_end VARCHAR(64) NOT NULL,
            source VARCHAR(32) NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $this->createIndex($pdo, 'cms_seo_keywords', 'cms_seo_keywords_keyword_unique', ['keyword'], true);
        $this->createIndex($pdo, 'cms_seo_keywords', 'cms_seo_keywords_status_idx', ['status'], false);
        $this->createIndex($pdo, 'cms_seo_keyword_metrics', 'cms_seo_keyword_metrics_lookup_idx', ['keyword', 'search_engine', 'period_start', 'period_end'], false);
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
};
