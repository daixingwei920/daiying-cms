<?php

declare(strict_types=1);

namespace Cms\Core\Scheduler;

use Cms\Core\Security\SecretRedactor;
use PDO;

final class SchedulerService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(ScheduledTask $task): void
    {
        $now = gmdate('c');
        $existing = $this->find($task->taskId);
        if ($existing !== null) {
            $this->pdo->prepare('UPDATE cms_core_scheduled_tasks SET owner = :owner, interval_seconds = :interval_seconds, payload_json = :payload_json, enabled = :enabled, updated_at = :updated_at WHERE task_id = :task_id')
                ->execute([
                    ':task_id' => $task->taskId,
                    ':owner' => $task->owner,
                    ':interval_seconds' => $task->intervalSeconds,
                    ':payload_json' => $this->json($task->payload),
                    ':enabled' => $task->enabled ? 1 : 0,
                    ':updated_at' => $now,
                ]);
            return;
        }

        $this->pdo->prepare('INSERT INTO cms_core_scheduled_tasks (task_id, owner, interval_seconds, payload_json, enabled, next_run_at, created_at, updated_at) VALUES (:task_id, :owner, :interval_seconds, :payload_json, :enabled, :next_run_at, :created_at, :updated_at)')
            ->execute([
                ':task_id' => $task->taskId,
                ':owner' => $task->owner,
                ':interval_seconds' => $task->intervalSeconds,
                ':payload_json' => $this->json($task->payload),
                ':enabled' => $task->enabled ? 1 : 0,
                ':next_run_at' => gmdate('c', time() + $task->intervalSeconds),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $taskId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_core_scheduled_tasks WHERE task_id = :task_id LIMIT 1');
        $stmt->execute([':task_id' => $taskId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function due(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cms_core_scheduled_tasks WHERE enabled = 1 AND next_run_at <= :now AND (lock_until IS NULL OR lock_until <= :now) ORDER BY next_run_at ASC, id ASC LIMIT :limit");
        $stmt->bindValue(':now', gmdate('c'));
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array{processed:int,succeeded:int,failed:int,missing:int} */
    public function runDue(int $limit = 20): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $missing = 0;
        foreach ($this->due($limit) as $task) {
            $processed++;
            $taskId = (string) $task['task_id'];
            if (!$this->lock($taskId)) {
                continue;
            }
            $handler = SchedulerTaskRegistry::get($taskId);
            if ($handler === null) {
                $this->finish($task, false, 'No scheduled task handler registered.');
                $missing++;
                continue;
            }
            try {
                $payload = json_decode((string) $task['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $handler(is_array($payload) ? $payload : []);
                $this->finish($task, true, 'ok');
                $succeeded++;
            } catch (\Throwable $exception) {
                $this->finish($task, false, (string) SecretRedactor::redact($exception->getMessage()));
                $failed++;
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed, 'missing' => $missing];
    }

    private function lock(string $taskId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE cms_core_scheduled_tasks SET lock_until = :lock_until, updated_at = :updated_at WHERE task_id = :task_id AND enabled = 1 AND (lock_until IS NULL OR lock_until <= :now)");
        $stmt->execute([
            ':task_id' => $taskId,
            ':now' => gmdate('c'),
            ':lock_until' => gmdate('c', time() + 300),
            ':updated_at' => gmdate('c'),
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $task */
    private function finish(array $task, bool $success, string $result): void
    {
        $interval = max(60, (int) ($task['interval_seconds'] ?? 60));
        $next = gmdate('c', time() + $interval);
        $this->pdo->prepare('UPDATE cms_core_scheduled_tasks SET lock_until = NULL, next_run_at = :next_run_at, last_run_at = :last_run_at, last_result = :last_result, last_error = :last_error, fail_count = :fail_count, updated_at = :updated_at WHERE task_id = :task_id')
            ->execute([
                ':task_id' => (string) $task['task_id'],
                ':next_run_at' => $next,
                ':last_run_at' => gmdate('c'),
                ':last_result' => $success ? substr($result, 0, 500) : 'failed',
                ':last_error' => $success ? null : substr($result, 0, 1000),
                ':fail_count' => $success ? 0 : ((int) ($task['fail_count'] ?? 0) + 1),
                ':updated_at' => gmdate('c'),
            ]);
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SchedulerException('Scheduled task payload cannot be encoded.', 0, $exception);
        }
    }
}
