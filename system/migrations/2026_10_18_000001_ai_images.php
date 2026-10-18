<?php

declare(strict_types=1);
use Cms\Core\Migration\MigrationInterface;
return new class implements MigrationInterface {
    public function id(): string { return '2026_10_18_000001_ai_images'; }
    public function up(PDO $pdo): void {
        $text = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_image_jobs (
            job_id VARCHAR(64) PRIMARY KEY, plugin_id VARCHAR(96) NOT NULL, user_id INTEGER NOT NULL,
            key_hash VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, request_json ' . $text . ' NOT NULL,
            provider VARCHAR(64) NOT NULL, model VARCHAR(191) NOT NULL, config_hash VARCHAR(64) NOT NULL,
            timeout_seconds INTEGER NOT NULL, status VARCHAR(32) NOT NULL, media_id INTEGER NULL,
            estimated_cost_micro BIGINT NULL, currency VARCHAR(3) NOT NULL DEFAULT "USD", usage_json ' . $text . ' NULL,
            error_reason VARCHAR(96) NOT NULL DEFAULT "", created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL,
            UNIQUE(plugin_id,user_id,key_hash))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_image_locks (lock_id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $stmt = $pdo->query('SELECT COUNT(*) FROM cms_ai_image_locks WHERE lock_id=1');
        if ((int)$stmt->fetchColumn() === 0) $pdo->exec('INSERT INTO cms_ai_image_locks (lock_id,marker) VALUES (1,0)');
    }
};
