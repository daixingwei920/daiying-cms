<?php

declare(strict_types=1);

namespace Cms\Core\Queue;

use Cms\Core\Security\SecretRedactor;
use PDO;

final class QueueService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(QueueJob $job): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "INSERT INTO cms_core_queue_jobs (job_type, owner, payload_json, status, attempts, max_attempts, not_before, created_at, updated_at)
             VALUES (:job_type, :owner, :payload_json, 'pending', 0, :max_attempts, :not_before, :created_at, :updated_at)"
        );
        $stmt->execute([
            ':job_type' => $job->type,
            ':owner' => $job->owner,
            ':payload_json' => $this->json($job->payload),
            ':max_attempts' => max(1, min(25, $job->maxAttempts)),
            ':not_before' => $job->notBefore ?? $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{processed:int,succeeded:int,failed:int,dead:int} */
    public function process(int $limit = 10): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $dead = 0;
        foreach ($this->claimable($limit) as $row) {
            $processed++;
            $id = (int) $row['id'];
            $type = (string) $row['job_type'];
            $handler = QueueHandlerRegistry::get($type);
            if ($handler === null) {
                $this->fail($id, 'No queue handler registered.', true);
                $dead++;
                continue;
            }
            $this->mark($id, 'running');
            try {
                $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $handler(is_array($payload) ? $payload : []);
                $this->markDone($id);
                $succeeded++;
            } catch (\Throwable $exception) {
                $attempts = (int) $row['attempts'] + 1;
                $isDead = $attempts >= (int) $row['max_attempts'];
                $this->fail($id, (string) SecretRedactor::redact($exception->getMessage()), $isDead);
                $failed++;
                if ($isDead) {
                    $dead++;
                }
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed, 'dead' => $dead];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_core_queue_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function claimable(int $limit): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cms_core_queue_jobs WHERE status IN ('pending', 'retry') AND not_before <= :now ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':now', gmdate('c'));
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function mark(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE cms_core_queue_jobs SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $id, ':status' => $status, ':updated_at' => gmdate('c')]);
    }

    private function markDone(int $id): void
    {
        $this->pdo->prepare("UPDATE cms_core_queue_jobs SET status = 'done', completed_at = :completed_at, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $id, ':completed_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
    }

    private function fail(int $id, string $error, bool $dead): void
    {
        $status = $dead ? 'dead' : 'retry';
        $next = gmdate('c', time() + 60);
        $this->pdo->prepare('UPDATE cms_core_queue_jobs SET status = :status, attempts = attempts + 1, last_error = :last_error, not_before = :not_before, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $id, ':status' => $status, ':last_error' => substr($error, 0, 1000), ':not_before' => $next, ':updated_at' => gmdate('c')]);
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new QueueException('Queue payload cannot be encoded.', 0, $exception);
        }
    }
}
