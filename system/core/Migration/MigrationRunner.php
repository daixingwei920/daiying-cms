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
        $applied = $this->appliedIds();
        $count = 0;

        foreach ($this->migrations as $migration) {
            if (isset($applied[$migration->id()])) {
                continue;
            }

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
                $count++;
            } catch (\Throwable $exception) {
                if ($transactional && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
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
