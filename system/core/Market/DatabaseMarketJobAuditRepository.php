<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use PDO;

final class DatabaseMarketJobAuditRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $jobId, string $action, string $actor, array $context = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_market_job_audits (job_id, action, actor, context_json, created_at)
             VALUES (:job_id, :action, :actor, :context, :created_at)'
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':action' => $action,
            ':actor' => $actor,
            ':context' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forJob(string $jobId): array
    {
        return $this->search(['job_id' => $jobId]);
    }

    /** @param array<string, string> $filters @return array<int, array<string, mixed>> */
    public function search(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['job_id'] ?? '') !== '') {
            $where[] = 'job_id = :job_id';
            $params[':job_id'] = $filters['job_id'];
        }
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (($filters['actor'] ?? '') !== '') {
            $where[] = 'actor LIKE :actor';
            $params[':actor'] = '%' . $filters['actor'] . '%';
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(job_id LIKE :q OR action LIKE :q OR actor LIKE :q OR context_json LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        $sql = 'SELECT * FROM cms_market_job_audits';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM cms_market_job_audits ORDER BY created_at ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $context = json_decode((string) ($row['context_json'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'job_id' => (string) ($row['job_id'] ?? ''),
            'action' => (string) ($row['action'] ?? ''),
            'actor' => (string) ($row['actor'] ?? ''),
            'context' => is_array($context) ? $context : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
