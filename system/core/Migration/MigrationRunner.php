<?php

declare(strict_types=1);

namespace Cms\Core\Migration;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    /** @param list<MigrationInterface> $migrations */
    public function __construct(private readonly PDO $pdo, private readonly array $migrations)
    {
    }

    public function run(): int
    {
        $this->ensureTable();
        $this->ensureFormalHistoryTable();
        $applied = $this->appliedIds();
        $count = 0;
        $batch = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));

        foreach ($this->migrations as $migration) {
            if (isset($applied[$migration->id()])) {
                continue;
            }

            $historyId = $this->startHistory($migration->id(), $batch);
            $transactional = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($transactional) {
                $this->pdo->beginTransaction();
            }
            try {
                $migration->up($this->pdo);
                $stmt = $this->pdo->prepare('INSERT INTO cms_core_migrations (migration_id, applied_at) VALUES (:id, :applied_at)');
                $stmt->execute([
                    ':id' => $migration->id(),
                    ':applied_at' => gmdate('c'),
                ]);
                if ($transactional) {
                    $this->pdo->commit();
                }
                $this->finishHistory($historyId);
                $count++;
            } catch (\Throwable $exception) {
                if ($transactional && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->failHistory($historyId, $exception->getMessage());
                throw new RuntimeException('Migration failed: ' . $migration->id(), 0, $exception);
            }
        }

        return $count;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_core_migrations (
                migration_id VARCHAR(191) PRIMARY KEY,
                applied_at VARCHAR(64) NOT NULL
            )'
        );
    }

    private function ensureFormalHistoryTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $this->pdo->exec(
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
        $columns = $this->tableColumns('cms_migrations');
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
                $this->pdo->exec('ALTER TABLE cms_migrations ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

    private function startHistory(string $migrationId, string $batch): int
    {
        $now = gmdate('c');
        $checksum = substr(hash('sha256', $migrationId), 0, 64);
        $stmt = $this->pdo->prepare("INSERT INTO cms_migrations (migration_name, batch, checksum, started_at, status, created_at, updated_at) VALUES (:migration_name, :batch, :checksum, :started_at, 'running', :created_at, :updated_at)");
        $stmt->execute([
            ':migration_name' => $migrationId,
            ':batch' => $batch,
            ':checksum' => $checksum,
            ':started_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function finishHistory(int $historyId): void
    {
        $this->pdo->prepare("UPDATE cms_migrations SET status = 'applied', completed_at = :completed_at, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $historyId, ':completed_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
    }

    private function failHistory(int $historyId, string $error): void
    {
        $summary = function_exists('mb_substr') ? mb_substr($error, 0, 500) : substr($error, 0, 500);
        $this->pdo->prepare("UPDATE cms_migrations SET status = 'failed', error_message = :error, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $historyId, ':error' => $summary, ':updated_at' => gmdate('c')]);
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }

    /** @return array<string, true> */
    private function appliedIds(): array
    {
        $result = [];
        $stmt = $this->pdo->query('SELECT migration_id FROM cms_core_migrations');
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['migration_id']] = true;
        }

        return $result;
    }
}
