<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_26_000001_external_migration_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_migration_jobs (
                id ' . $idColumn . ',
                source_system VARCHAR(64) NOT NULL,
                source_site_id VARCHAR(191) NOT NULL,
                source_version VARCHAR(64) NULL,
                adapter_id VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                strategy VARCHAR(32) NOT NULL,
                source_filename VARCHAR(255) NULL,
                source_sha256 VARCHAR(64) NOT NULL,
                scan_json ' . $longText . ' NOT NULL,
                mapping_json ' . $longText . ' NOT NULL,
                dry_run_json ' . $longText . ' NULL,
                report_json ' . $longText . ' NULL,
                error_message VARCHAR(500) NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                started_at VARCHAR(64) NULL,
                completed_at VARCHAR(64) NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_migration_records (
                id ' . $idColumn . ',
                job_id INTEGER NOT NULL,
                source_system VARCHAR(64) NOT NULL,
                source_site_id VARCHAR(191) NOT NULL,
                source_type VARCHAR(64) NOT NULL,
                source_id VARCHAR(191) NOT NULL,
                target_type VARCHAR(64) NULL,
                target_id INTEGER NULL,
                status VARCHAR(32) NOT NULL,
                error_code VARCHAR(64) NULL,
                error_message VARCHAR(500) NULL,
                source_url VARCHAR(500) NULL,
                target_url VARCHAR(500) NULL,
                metadata_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $this->createIndexIfMissing($pdo, 'cms_migration_jobs', 'cms_migration_jobs_source_idx', 'CREATE INDEX cms_migration_jobs_source_idx ON cms_migration_jobs (source_system, source_site_id)');
        $this->createIndexIfMissing($pdo, 'cms_migration_records', 'cms_migration_records_job_idx', 'CREATE INDEX cms_migration_records_job_idx ON cms_migration_records (job_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_migration_records', 'cms_migration_records_source_unique', 'CREATE UNIQUE INDEX cms_migration_records_source_unique ON cms_migration_records (source_system, source_site_id, source_type, source_id)');
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
