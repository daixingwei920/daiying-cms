<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_10_06_000001_historical_article_seo_backfill';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_historical_article_seo_jobs (
            id ' . $idColumn . ',
            content_id INTEGER NOT NULL,
            quality_grade VARCHAR(8) NOT NULL DEFAULT "C",
            word_count INTEGER NOT NULL DEFAULT 0,
            paragraph_count INTEGER NOT NULL DEFAULT 0,
            heading_count INTEGER NOT NULL DEFAULT 0,
            has_image INTEGER NOT NULL DEFAULT 0,
            possible_duplicate INTEGER NOT NULL DEFAULT 0,
            early_batch_candidate INTEGER NOT NULL DEFAULT 0,
            seo_status VARCHAR(32) NOT NULL DEFAULT "incomplete",
            recommendation VARCHAR(32) NOT NULL DEFAULT "seo_only",
            proposed_meta_json ' . $text . ' NULL,
            proposed_blocks_json ' . $text . ' NULL,
            suggestion_source VARCHAR(32) NOT NULL DEFAULT "rules",
            search_intent ' . $text . ' NULL,
            primary_keyword VARCHAR(191) NOT NULL DEFAULT "",
            auxiliary_keywords ' . $text . ' NULL,
            ai_provider VARCHAR(64) NOT NULL DEFAULT "",
            ai_model VARCHAR(191) NOT NULL DEFAULT "",
            ai_request_id VARCHAR(64) NOT NULL DEFAULT "",
            ai_usage_json ' . $text . ' NULL,
            ai_change_summary ' . $text . ' NULL,
            original_meta_hash VARCHAR(64) NOT NULL DEFAULT "",
            original_blocks_hash VARCHAR(64) NOT NULL DEFAULT "",
            status VARCHAR(32) NOT NULL DEFAULT "scanned",
            error_summary ' . $text . ' NULL,
            generated_at VARCHAR(64) NULL,
            applied_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_historical_article_seo_logs (
            id ' . $idColumn . ',
            content_id INTEGER NOT NULL,
            action VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            message ' . $text . ' NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $this->createIndex($pdo, 'cms_historical_article_seo_jobs', 'cms_hist_article_seo_content_unique', ['content_id'], true);
        $this->createIndex($pdo, 'cms_historical_article_seo_jobs', 'cms_hist_article_seo_status_idx', ['status'], false);
        $this->createIndex($pdo, 'cms_historical_article_seo_jobs', 'cms_hist_article_seo_quality_idx', ['quality_grade'], false);
        $this->createIndex($pdo, 'cms_historical_article_seo_logs', 'cms_hist_article_seo_logs_content_idx', ['content_id'], false);
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
