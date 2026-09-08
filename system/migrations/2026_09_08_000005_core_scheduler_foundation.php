<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_08_000005_core_scheduler_foundation';
    }

    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_core_scheduled_tasks (
            id ' . $idColumn . ',
            task_id VARCHAR(191) NOT NULL UNIQUE,
            owner VARCHAR(96) NOT NULL DEFAULT "core",
            interval_seconds INTEGER NOT NULL,
            payload_json ' . $longText . ' NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            next_run_at VARCHAR(64) NOT NULL,
            last_run_at VARCHAR(64) NULL,
            last_result VARCHAR(500) NULL,
            last_error ' . $longText . ' NULL,
            fail_count INTEGER NOT NULL DEFAULT 0,
            lock_until VARCHAR(64) NULL,
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL
        )');

        $this->createIndex($pdo, 'cms_core_scheduled_tasks', 'cms_core_scheduled_tasks_due_idx', '(enabled, next_run_at, lock_until)');
    }

    private function createIndex(PDO $pdo, string $table, string $index, string $columns): void
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . ' ' . $columns);
            return;
        }
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . ' ' . $columns);
    }
};
