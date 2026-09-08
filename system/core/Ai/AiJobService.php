<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use PDO;

final class AiJobService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(AiRequest $request, int $queueJobId): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "INSERT INTO cms_ai_jobs
             (job_id, queue_job_id, owner_plugin, operation, status, progress_percent, last_error, created_at, updated_at)
             VALUES (:job_id, :queue_job_id, :owner_plugin, :operation, 'pending', 0, '', :created_at, :updated_at)"
        );
        $stmt->execute([
            ':job_id' => $request->requestId,
            ':queue_job_id' => $queueJobId,
            ':owner_plugin' => $request->pluginId,
            ':operation' => $request->operation,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markProgress(string $jobId, string $status, int $progressPercent, string $lastError = ''): void
    {
        $status = in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true) ? $status : 'failed';
        $completedAt = in_array($status, ['completed', 'failed', 'cancelled'], true) ? gmdate('c') : null;
        $stmt = $this->pdo->prepare(
            'UPDATE cms_ai_jobs
             SET status = :status, progress_percent = :progress_percent, last_error = :last_error, completed_at = :completed_at, updated_at = :updated_at
             WHERE job_id = :job_id'
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':status' => $status,
            ':progress_percent' => max(0, min(100, $progressPercent)),
            ':last_error' => substr($lastError, 0, 1000),
            ':completed_at' => $completedAt,
            ':updated_at' => gmdate('c'),
        ]);
    }

    public function cancel(string $jobId): void
    {
        $this->markProgress($jobId, 'cancelled', 100);
    }

    /** @return array<string,mixed>|null */
    public function find(string $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_ai_jobs WHERE job_id = :job_id LIMIT 1');
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
