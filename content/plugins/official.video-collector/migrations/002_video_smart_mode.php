<?php

declare(strict_types=1);

$migration = static function (\PDO $pdo): void {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $auto = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';

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

    $addColumn = static function (string $table, string $column, string $definition) use ($pdo, $hasColumn): void {
        if (!$hasColumn($table, $column)) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    };

    $addColumn('video_resource_providers', 'slug', 'VARCHAR(191) NULL');
    $addColumn('video_resource_providers', 'auto_sync_enabled', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_resource_providers', 'health_status', "VARCHAR(32) NOT NULL DEFAULT 'unknown'");
    $addColumn('video_resource_providers', 'resource_count', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_resource_providers', 'type_summary_json', $json . ' NULL');
    $addColumn('video_resource_providers', 'category_summary_json', $json . ' NULL');
    $addColumn('video_resource_providers', 'detected_at', 'DATETIME NULL');

    $addColumn('videos', 'source_provider_id', 'BIGINT NULL');
    $addColumn('videos', 'source_external_id', 'VARCHAR(191) NULL');
    $addColumn('videos', 'source_url_hash', 'VARCHAR(64) NULL');
    $addColumn('videos', 'normalized_title', 'VARCHAR(191) NULL');
    $addColumn('videos', 'category_name', 'VARCHAR(191) NULL');

    $addColumn('video_collector_jobs', 'total_items', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_collector_jobs', 'processed_items', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_collector_jobs', 'success_count', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_collector_jobs', 'failed_count', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_collector_jobs', 'skipped_count', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_collector_jobs', 'cursor', 'INT NOT NULL DEFAULT 0');
    $addColumn('video_collector_jobs', 'batch_size', 'INT NOT NULL DEFAULT 20');
    $addColumn('video_collector_jobs', 'started_at', 'DATETIME NULL');
    $addColumn('video_collector_jobs', 'completed_at', 'DATETIME NULL');
    $addColumn('video_collector_jobs', 'cancelled_at', 'DATETIME NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_collector_job_items (
        id $auto,
        job_id BIGINT NOT NULL,
        resource_provider_id BIGINT NOT NULL,
        source_external_id VARCHAR(191) NULL,
        source_url_hash VARCHAR(64) NULL,
        item_index INT NOT NULL DEFAULT 0,
        title VARCHAR(191) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        payload_json $json NOT NULL,
        result_json $json NULL,
        last_error $text NULL,
        locked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(job_id, item_index)
    )");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_providers_api ON video_resource_providers(api_url)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_videos_source ON videos(source_provider_id, source_external_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_videos_normalized ON videos(normalized_title, year)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_collector_items_status ON video_collector_job_items(job_id, status, item_index)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_collector_items_provider ON video_collector_job_items(resource_provider_id, status, updated_at)');
};

$rollback = static function (\PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS video_collector_job_items');
};

return [
    'id' => '002_video_smart_mode',
    'up' => $migration,
    'apply' => $migration,
    'down' => $rollback,
    'rollback' => $rollback,
    'affected_objects' => [
        'video_resource_providers',
        'videos',
        'video_collector_jobs',
        'video_collector_job_items',
    ],
];
