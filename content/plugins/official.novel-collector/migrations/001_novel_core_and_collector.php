<?php

declare(strict_types=1);

$migration = static function (\PDO $pdo): void {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $auto = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_authors (
        id $auto,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        slug VARCHAR(191) NOT NULL UNIQUE,
        bio $text NULL,
        avatar_media_id BIGINT NULL,
        source_url VARCHAR(1024) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novels (
        id $auto,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        title VARCHAR(191) NOT NULL,
        slug VARCHAR(191) NOT NULL UNIQUE,
        original_title VARCHAR(191) NULL,
        author_id BIGINT NULL,
        description $text NULL,
        cover_media_id BIGINT NULL,
        cover_url VARCHAR(1024) NULL,
        source_url VARCHAR(1024) NULL,
        source_url_hash VARCHAR(64) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        word_count BIGINT NOT NULL DEFAULT 0,
        chapter_count INT NOT NULL DEFAULT 0,
        latest_chapter_id BIGINT NULL,
        latest_chapter_title VARCHAR(191) NULL,
        latest_chapter_at DATETIME NULL,
        language VARCHAR(16) NOT NULL DEFAULT 'zh-CN',
        visibility VARCHAR(32) NOT NULL DEFAULT 'public',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        published_at DATETIME NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novels_author ON novels(author_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novels_latest ON novels(latest_chapter_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novels_status ON novels(status, visibility)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_volumes (
        id $auto,
        novel_id BIGINT NOT NULL,
        title VARCHAR(191) NOT NULL,
        description $text NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE(novel_id, sort_order)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novel_volumes_novel ON novel_volumes(novel_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_chapters (
        id $auto,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        novel_id BIGINT NOT NULL,
        volume_id BIGINT NOT NULL,
        title VARCHAR(191) NOT NULL,
        slug VARCHAR(191) NOT NULL,
        chapter_number INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        content $text NOT NULL,
        content_plaintext $text NOT NULL,
        source_url VARCHAR(1024) NULL,
        source_chapter_id VARCHAR(191) NULL,
        content_hash VARCHAR(64) NOT NULL,
        source_content_hash VARCHAR(64) NULL,
        word_count INT NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        collected_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(novel_id, slug),
        UNIQUE(novel_id, source_chapter_id)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novel_chapters_order ON novel_chapters(novel_id, volume_id, sort_order)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novel_chapters_hash ON novel_chapters(novel_id, source_content_hash)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_bookshelf (
        id $auto,
        user_id BIGINT NOT NULL,
        novel_id BIGINT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE(user_id, novel_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_reading_progress (
        id $auto,
        user_id BIGINT NOT NULL,
        novel_id BIGINT NOT NULL,
        chapter_id BIGINT NOT NULL,
        scroll_position INT NOT NULL DEFAULT 0,
        font_size INT NOT NULL DEFAULT 18,
        line_height DECIMAL(4,2) NOT NULL DEFAULT 1.80,
        reader_theme VARCHAR(32) NOT NULL DEFAULT 'paper',
        updated_at DATETIME NOT NULL,
        UNIQUE(user_id, novel_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_export_cache (
        id $auto,
        novel_id BIGINT NOT NULL,
        provider VARCHAR(32) NOT NULL,
        cache_key VARCHAR(191) NOT NULL,
        path VARCHAR(1024) NOT NULL,
        is_stale INT NOT NULL DEFAULT 1,
        generated_at DATETIME NULL,
        UNIQUE(novel_id, provider, cache_key)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_collector_site_rules (
        id $auto,
        host VARCHAR(191) NOT NULL UNIQUE,
        adapter VARCHAR(64) NULL,
        rule_json $json NOT NULL,
        confidence DECIMAL(4,3) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_collector_jobs (
        id $auto,
        novel_id BIGINT NULL,
        source_url VARCHAR(1024) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        mode VARCHAR(32) NOT NULL DEFAULT 'full',
        confidence DECIMAL(4,3) NOT NULL DEFAULT 0,
        checkpoint_json $json NULL,
        last_error $text NULL,
        attempts INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novel_collector_jobs_status ON novel_collector_jobs(status, updated_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS novel_collector_job_items (
        id $auto,
        job_id BIGINT NOT NULL,
        source_url VARCHAR(1024) NOT NULL,
        source_chapter_id VARCHAR(191) NULL,
        title VARCHAR(191) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        sort_order INT NOT NULL DEFAULT 0,
        attempts INT NOT NULL DEFAULT 0,
        content_hash VARCHAR(64) NULL,
        last_error $text NULL,
        locked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(job_id, source_url)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_novel_collector_items_status ON novel_collector_job_items(job_id, status, sort_order)");

    $hasColumn = static function (string $table, string $column) use ($pdo, $driver): bool {
        if ($driver === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')') ?: [] as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    if (!$hasColumn('novels', 'source_url')) {
        $pdo->exec('ALTER TABLE novels ADD COLUMN source_url VARCHAR(1024) NULL');
    }
    if (!$hasColumn('novels', 'source_url_hash')) {
        $pdo->exec('ALTER TABLE novels ADD COLUMN source_url_hash VARCHAR(64) NULL');
    }
    if (!$hasColumn('novels', 'cover_url')) {
        $pdo->exec('ALTER TABLE novels ADD COLUMN cover_url VARCHAR(1024) NULL');
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_novels_source_hash ON novels(source_url_hash)");
};


$rollback = static function (\PDO $pdo): void {
    foreach ([
        'novel_collector_job_items',
        'novel_collector_jobs',
        'novel_collector_site_rules',
        'novel_export_cache',
        'novel_reading_progress',
        'novel_bookshelf',
        'novel_chapters',
        'novel_volumes',
        'novels',
        'novel_authors',
    ] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
};

return ['up' => $migration, 'apply' => $migration, 'down' => $rollback, 'rollback' => $rollback];
