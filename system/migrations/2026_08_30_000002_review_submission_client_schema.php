<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_30_000002_review_submission_client_schema';
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_review_submissions (
            id ' . $idColumn . ',
            submission_id TEXT NOT NULL UNIQUE,
            product_id TEXT NOT NULL,
            package_type TEXT NOT NULL,
            version TEXT NOT NULL,
            developer_name TEXT NOT NULL,
            developer_email TEXT NOT NULL,
            developer_url TEXT NOT NULL DEFAULT "",
            purchase_url TEXT NOT NULL DEFAULT "",
            support_url TEXT NOT NULL DEFAULT "",
            description TEXT NOT NULL DEFAULT "",
            previous_submission_id TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL,
            remote_report_json TEXT NOT NULL DEFAULT "{}",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cms_review_submissions_status ON cms_review_submissions(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cms_review_submissions_product ON cms_review_submissions(product_id)');
    }
};
