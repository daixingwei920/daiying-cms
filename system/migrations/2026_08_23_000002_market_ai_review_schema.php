<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_23_000002_market_ai_review_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_ai_review_tasks (
            id ' . $idColumn . ',
            version_id INTEGER NOT NULL,
            review_type VARCHAR(32) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            model VARCHAR(128) NOT NULL,
            status VARCHAR(32) NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            request_id VARCHAR(191) NOT NULL,
            input_summary_json ' . $longText . ' NOT NULL,
            error_message VARCHAR(500) NOT NULL DEFAULT \'\',
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_market_ai_review_evidence (
            id ' . $idColumn . ',
            task_id INTEGER NOT NULL,
            version_id INTEGER NOT NULL,
            review_type VARCHAR(32) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            model VARCHAR(128) NOT NULL,
            request_id VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL,
            decision_suggestion VARCHAR(64) NOT NULL,
            risk_level VARCHAR(32) NOT NULL,
            confidence VARCHAR(32) NOT NULL,
            violations_json ' . $longText . ' NOT NULL,
            warnings_json ' . $longText . ' NOT NULL,
            manual_review_focus_json ' . $longText . ' NOT NULL,
            required_fixes_json ' . $longText . ' NOT NULL,
            output_json ' . $longText . ' NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cms_market_ai_review_evidence');
        $pdo->exec('DROP TABLE IF EXISTS cms_market_ai_review_tasks');
    }
};
