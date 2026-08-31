<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

use PDO;

final class MigrationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $scan @param array<string,mixed> $mapping */
    public function createJob(string $adapterId, string $sourceSystem, string $siteId, string $version, string $filename, string $sha256, array $scan, array $mapping = []): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_migration_jobs
                (source_system, source_site_id, source_version, adapter_id, status, strategy, source_filename, source_sha256, scan_json, mapping_json, created_at, updated_at)
             VALUES
                (:source_system, :source_site_id, :source_version, :adapter_id, :status, :strategy, :source_filename, :source_sha256, :scan_json, :mapping_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':source_system' => $sourceSystem,
            ':source_site_id' => $siteId,
            ':source_version' => $version,
            ':adapter_id' => $adapterId,
            ':status' => 'Scanned',
            ':strategy' => 'skip',
            ':source_filename' => $filename,
            ':source_sha256' => $sha256,
            ':scan_json' => $this->json($scan),
            ':mapping_json' => $this->json($mapping),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_migration_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['scan_json' => 'scan', 'mapping_json' => 'mapping', 'dry_run_json' => 'dry_run', 'report_json' => 'report'] as $column => $key) {
            $row[$key] = $this->decode((string) ($row[$column] ?? ''));
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function recentJobs(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_migration_jobs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['scan'] = $this->decode((string) ($row['scan_json'] ?? ''));
            $row['report'] = $this->decode((string) ($row['report_json'] ?? ''));
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string,mixed> $payload */
    public function updateJob(int $id, string $status, array $payload = []): void
    {
        $sets = ['status = :status', 'updated_at = :updated_at'];
        $params = [':id' => $id, ':status' => $status, ':updated_at' => gmdate('c')];
        foreach (['strategy', 'dry_run_json', 'report_json', 'error_message', 'started_at', 'completed_at'] as $column) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = is_array($payload[$column]) ? $this->json($payload[$column]) : $payload[$column];
        }
        $stmt = $this->pdo->prepare('UPDATE cms_migration_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** @return array<string,mixed>|null */
    public function findRecord(string $system, string $siteId, string $sourceType, string $sourceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_migration_records WHERE source_system = :system AND source_site_id = :site_id AND source_type = :type AND source_id = :source_id LIMIT 1');
        $stmt->execute([':system' => $system, ':site_id' => $siteId, ':type' => $sourceType, ':source_id' => $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $metadata */
    public function record(int $jobId, string $system, string $siteId, string $sourceType, string $sourceId, string $status, ?string $targetType = null, ?int $targetId = null, ?string $sourceUrl = null, ?string $targetUrl = null, array $metadata = [], string $errorCode = '', string $errorMessage = ''): void
    {
        $existing = $this->findRecord($system, $siteId, $sourceType, $sourceId);
        $now = gmdate('c');
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE cms_migration_records SET job_id = :job_id, status = :status, target_type = :target_type, target_id = :target_id,
                    error_code = :error_code, error_message = :error_message, source_url = :source_url, target_url = :target_url,
                    metadata_json = :metadata_json, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                ':id' => (int) $existing['id'],
                ':job_id' => $jobId,
                ':status' => $status,
                ':target_type' => $targetType,
                ':target_id' => $targetId,
                ':error_code' => $errorCode !== '' ? $errorCode : null,
                ':error_message' => $errorMessage !== '' ? mb_substr($errorMessage, 0, 500) : null,
                ':source_url' => $sourceUrl,
                ':target_url' => $targetUrl,
                ':metadata_json' => $this->json($metadata),
                ':updated_at' => $now,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_migration_records
                (job_id, source_system, source_site_id, source_type, source_id, target_type, target_id, status, error_code, error_message, source_url, target_url, metadata_json, created_at, updated_at)
             VALUES
                (:job_id, :source_system, :source_site_id, :source_type, :source_id, :target_type, :target_id, :status, :error_code, :error_message, :source_url, :target_url, :metadata_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':source_system' => $system,
            ':source_site_id' => $siteId,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':status' => $status,
            ':error_code' => $errorCode !== '' ? $errorCode : null,
            ':error_message' => $errorMessage !== '' ? mb_substr($errorMessage, 0, 500) : null,
            ':source_url' => $sourceUrl,
            ':target_url' => $targetUrl,
            ':metadata_json' => $this->json($metadata),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recordsForJob(int $jobId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_migration_records WHERE job_id = :job_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':job_id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min($limit, 300)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
