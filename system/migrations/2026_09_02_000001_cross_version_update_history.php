<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_09_02_000001_cross_version_update_history';
    }

    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_migrations (
                id ' . $idColumn . ',
                migration_name VARCHAR(191) NOT NULL,
                source_version VARCHAR(64) NOT NULL DEFAULT "",
                target_version VARCHAR(64) NOT NULL DEFAULT "",
                batch VARCHAR(64) NOT NULL DEFAULT "",
                checksum VARCHAR(64) NOT NULL DEFAULT "",
                started_at VARCHAR(64) NULL,
                completed_at VARCHAR(64) NULL,
                status VARCHAR(32) NOT NULL DEFAULT "pending",
                error_message ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $columns = $this->columns($pdo, 'cms_migrations');
        foreach ([
            'migration_name' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'source_version' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'target_version' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'batch' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'checksum' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'started_at' => 'VARCHAR(64) NULL',
            'completed_at' => 'VARCHAR(64) NULL',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "pending"',
            'error_message' => $longText . ' NULL',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'updated_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE cms_migrations ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $this->backfillFromCoreUpdateHistory($pdo);
        $this->recordSelfWhenAppliedByLegacyUpdater($pdo);
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }

    private function backfillFromCoreUpdateHistory(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_core_update_migrations')) {
            return;
        }

        $legacyColumns = $this->columns($pdo, 'cms_core_update_migrations');
        foreach (['migration_id', 'version', 'checksum', 'status', 'started_at'] as $requiredColumn) {
            if (!in_array($requiredColumn, $legacyColumns, true)) {
                return;
            }
        }

        $completedExpression = in_array('completed_at', $legacyColumns, true) ? 'completed_at' : 'NULL AS completed_at';
        $updatedExpression = in_array('updated_at', $legacyColumns, true) ? 'updated_at' : 'started_at AS updated_at';
        $errorExpression = in_array('error_summary', $legacyColumns, true) ? 'error_summary' : 'NULL AS error_summary';

        $rows = $pdo->query(
            'SELECT migration_id, version, checksum, status, started_at, '
            . $completedExpression . ', ' . $updatedExpression . ', ' . $errorExpression
            . ' FROM cms_core_update_migrations ORDER BY id ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $insert = $pdo->prepare(
            'INSERT INTO cms_migrations (
                migration_name, source_version, target_version, batch, checksum,
                started_at, completed_at, status, error_message, created_at, updated_at
            ) VALUES (
                :migration_name, "", :target_version, "core-update-backfill", :checksum,
                :started_at, :completed_at, :status, :error_message, :created_at, :updated_at
            )'
        );

        $now = gmdate('c');
        foreach ($rows as $row) {
            $migrationName = (string) ($row['migration_id'] ?? '');
            if ($migrationName === '' || $this->migrationRecorded($pdo, $migrationName)) {
                continue;
            }

            $startedAt = (string) ($row['started_at'] ?? '') ?: $now;
            $updatedAt = (string) ($row['updated_at'] ?? '') ?: $startedAt;
            $insert->execute([
                'migration_name' => $migrationName,
                'target_version' => (string) ($row['version'] ?? ''),
                'checksum' => (string) ($row['checksum'] ?? ''),
                'started_at' => $startedAt,
                'completed_at' => (string) ($row['completed_at'] ?? '') ?: null,
                'status' => (string) ($row['status'] ?? 'applied'),
                'error_message' => (string) ($row['error_summary'] ?? ''),
                'created_at' => $startedAt,
                'updated_at' => $updatedAt,
            ]);
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function migrationRecorded(\PDO $pdo, string $migrationName): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM cms_migrations WHERE migration_name = :migration_name');
        $stmt->execute(['migration_name' => $migrationName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function recordSelfWhenAppliedByLegacyUpdater(\PDO $pdo): void
    {
        $migrationName = $this->id();
        if ($this->migrationRecorded($pdo, $migrationName)) {
            $now = gmdate('c');
            $stmt = $pdo->prepare(
                'UPDATE cms_migrations
                    SET status = "applied", completed_at = COALESCE(NULLIF(completed_at, ""), :completed_at), updated_at = :updated_at
                    WHERE migration_name = :migration_name AND status = "running"'
            );
            $stmt->execute([
                'migration_name' => $migrationName,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO cms_migrations (
                migration_name, source_version, target_version, batch, checksum,
                started_at, completed_at, status, error_message, created_at, updated_at
            ) VALUES (
                :migration_name, "", "", "core-update-legacy-self", "",
                :started_at, :completed_at, "applied", "", :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'migration_name' => $migrationName,
            'started_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
