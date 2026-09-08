<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_08_000006_ai_foundation_v1';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $decimal = $driver === 'sqlite' ? 'REAL' : 'DECIMAL(12,6)';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_usage_ledger (
            id ' . $idColumn . ',
            request_id VARCHAR(64) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            model VARCHAR(191) NOT NULL,
            plugin_id VARCHAR(96) NOT NULL DEFAULT "core",
            operation VARCHAR(96) NOT NULL DEFAULT "chat",
            input_tokens INTEGER NOT NULL DEFAULT 0,
            output_tokens INTEGER NOT NULL DEFAULT 0,
            request_count INTEGER NOT NULL DEFAULT 1,
            estimated_cost ' . $decimal . ' NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT "success",
            error_reason VARCHAR(191) NOT NULL DEFAULT "",
            created_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_quota_policies (
            id ' . $idColumn . ',
            scope_type VARCHAR(32) NOT NULL DEFAULT "global",
            scope_id VARCHAR(96) NOT NULL DEFAULT "",
            provider VARCHAR(64) NOT NULL DEFAULT "",
            model VARCHAR(191) NOT NULL DEFAULT "",
            operation VARCHAR(96) NOT NULL DEFAULT "",
            daily_request_limit INTEGER NOT NULL DEFAULT 0,
            monthly_request_limit INTEGER NOT NULL DEFAULT 0,
            daily_token_limit INTEGER NOT NULL DEFAULT 0,
            monthly_token_limit INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT "disabled",
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_jobs (
            id ' . $idColumn . ',
            job_id VARCHAR(64) NOT NULL,
            queue_job_id INTEGER NOT NULL DEFAULT 0,
            owner_plugin VARCHAR(96) NOT NULL DEFAULT "core",
            operation VARCHAR(96) NOT NULL DEFAULT "chat",
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            progress_percent INTEGER NOT NULL DEFAULT 0,
            last_error ' . $longText . ' NULL,
            completed_at VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_prompts (
            id ' . $idColumn . ',
            prompt_id VARCHAR(191) NOT NULL,
            version VARCHAR(32) NOT NULL DEFAULT "1.0",
            owner_plugin VARCHAR(96) NOT NULL DEFAULT "core",
            language VARCHAR(32) NOT NULL DEFAULT "default",
            variables_json ' . $longText . ' NOT NULL,
            template ' . $longText . ' NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "enabled",
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_ai_audit_events (
            id ' . $idColumn . ',
            request_id VARCHAR(64) NOT NULL DEFAULT "",
            agent_id VARCHAR(191) NOT NULL DEFAULT "",
            provider VARCHAR(64) NOT NULL DEFAULT "",
            model VARCHAR(191) NOT NULL DEFAULT "",
            tool_id VARCHAR(191) NOT NULL DEFAULT "",
            plugin_id VARCHAR(96) NOT NULL DEFAULT "core",
            action VARCHAR(191) NOT NULL,
            target_type VARCHAR(96) NOT NULL DEFAULT "",
            target_id VARCHAR(96) NOT NULL DEFAULT "",
            result VARCHAR(32) NOT NULL DEFAULT "",
            user_id INTEGER NULL,
            risk_level VARCHAR(32) NOT NULL DEFAULT "",
            before_json ' . $longText . ' NULL,
            after_json ' . $longText . ' NULL,
            created_at VARCHAR(64) NOT NULL
        )');

        $this->index($pdo, 'idx_ai_usage_request', 'cms_ai_usage_ledger', 'request_id');
        $this->index($pdo, 'idx_ai_usage_plugin_day', 'cms_ai_usage_ledger', 'plugin_id, created_at');
        $this->index($pdo, 'idx_ai_jobs_job_id', 'cms_ai_jobs', 'job_id');
        $this->index($pdo, 'idx_ai_prompts_lookup', 'cms_ai_prompts', 'prompt_id, version, language');
        $this->index($pdo, 'idx_ai_audit_request', 'cms_ai_audit_events', 'request_id');
    }

    private function index(PDO $pdo, string $name, string $table, string $columns): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $columns . ')');
    }
};
