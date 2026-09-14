<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $createIndex = static function (PDO $pdo, string $table, string $index, string $columns) use ($driver): void {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . $columns);
            return;
        }
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . $columns);
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS baidu_url_submission_settings (
        id $id,
        site_url VARCHAR(500) NOT NULL DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 0,
        dedupe_window_seconds INTEGER NOT NULL DEFAULT 1800,
        created_at VARCHAR(64) NOT NULL,
        updated_at VARCHAR(64) NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS baidu_url_submission_logs (
        id $id,
        url VARCHAR(1024) NOT NULL,
        url_hash VARCHAR(64) NOT NULL,
        trigger_type VARCHAR(32) NOT NULL,
        content_id BIGINT NULL,
        content_type VARCHAR(64) NULL,
        status VARCHAR(32) NOT NULL,
        http_status INTEGER NULL,
        baidu_success INTEGER NULL,
        baidu_remain INTEGER NULL,
        not_same_site_json $json NULL,
        not_valid_json $json NULL,
        response_json $text NULL,
        error_summary VARCHAR(500) NULL,
        created_at VARCHAR(64) NOT NULL
    )");
    $createIndex($pdo, 'baidu_url_submission_logs', 'idx_baidu_submit_logs_hash_created', '(url_hash, created_at)');
    $createIndex($pdo, 'baidu_url_submission_logs', 'idx_baidu_submit_logs_status_created', '(status, created_at)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS baidu_url_submission_logs');
    $pdo->exec('DROP TABLE IF EXISTS baidu_url_submission_settings');
};

return [
    'id' => 'baidu_url_submission_001_core',
    'affected_objects' => [
        'table:baidu_url_submission_settings',
        'table:baidu_url_submission_logs',
    ],
    'up' => $up,
    'down' => $rollback,
];
