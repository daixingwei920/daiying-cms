<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_25_000001_payment_attempt_diagnostics_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_attempt_diagnostics (
                id ' . $idColumn . ',
                attempt_id VARCHAR(64) NOT NULL,
                subject_type VARCHAR(96) NOT NULL,
                subject_id VARCHAR(191) NOT NULL,
                provider_id VARCHAR(96) NOT NULL,
                amount_minor INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                stage VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                http_status INTEGER NULL,
                provider_error_type VARCHAR(96) NULL,
                provider_error_code VARCHAR(96) NULL,
                provider_request_id VARCHAR(191) NULL,
                safe_error_message VARCHAR(512) NOT NULL,
                metadata_json ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL
            )'
        );

        $this->createIndexIfMissing($pdo, 'cms_payment_attempt_diagnostics', 'cms_payment_attempt_diagnostics_created_idx', 'CREATE INDEX cms_payment_attempt_diagnostics_created_idx ON cms_payment_attempt_diagnostics (created_at)');
        $this->createIndexIfMissing($pdo, 'cms_payment_attempt_diagnostics', 'cms_payment_attempt_diagnostics_provider_idx', 'CREATE INDEX cms_payment_attempt_diagnostics_provider_idx ON cms_payment_attempt_diagnostics (provider_id, status, created_at)');
        $this->createIndexIfMissing($pdo, 'cms_payment_attempt_diagnostics', 'cms_payment_attempt_diagnostics_subject_idx', 'CREATE INDEX cms_payment_attempt_diagnostics_subject_idx ON cms_payment_attempt_diagnostics (subject_type, subject_id, created_at)');
    }

    private function createIndexIfMissing(\PDO $pdo, string $table, string $index, string $sql): void
    {
        if ($this->indexExists($pdo, $table, $index)) {
            return;
        }

        $pdo->exec($sql);
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name");
            $stmt->execute([':name' => $index]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
