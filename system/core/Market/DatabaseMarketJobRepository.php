<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use PDO;

final class DatabaseMarketJobRepository implements MarketJobRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, array $payload): string
    {
        $id = 'job-' . bin2hex(random_bytes(8));
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_market_jobs (id, type, status, attempts, payload_json, result_json, error_text, created_at, updated_at)
             VALUES (:id, :type, :status, :attempts, :payload, :result, :error, :created, :updated)'
        );
        $stmt->execute([
            ':id' => $id,
            ':type' => $type,
            ':status' => 'Queued',
            ':attempts' => 0,
            ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':result' => null,
            ':error' => null,
            ':created' => $now,
            ':updated' => $now,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $job */
    public function insertExisting(array $job): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_market_jobs (id, type, status, attempts, payload_json, result_json, error_text, created_at, updated_at)
             VALUES (:id, :type, :status, :attempts, :payload, :result, :error, :created, :updated)'
        );
        $stmt->execute([
            ':id' => (string) $job['id'],
            ':type' => (string) $job['type'],
            ':status' => (string) $job['status'],
            ':attempts' => (int) ($job['attempts'] ?? 0),
            ':payload' => json_encode($job['payload'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':result' => json_encode($job['result'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':error' => $job['error'] ?? null,
            ':created' => (string) ($job['created_at'] ?? gmdate('c')),
            ':updated' => (string) ($job['updated_at'] ?? gmdate('c')),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM cms_market_jobs ORDER BY created_at ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $jobs = [];
        foreach ($rows as $row) {
            $job = $this->hydrate($row);
            $jobs[(string) $job['id']] = $job;
        }

        return $jobs;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $summary = ['total' => 0, 'queued' => 0, 'running' => 0, 'retry' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($this->all() as $job) {
            $summary['total']++;
            $key = strtolower((string) ($job['status'] ?? ''));
            if (array_key_exists($key, $summary)) {
                $summary[$key]++;
            }
        }

        return $summary;
    }

    /** @return array<string, mixed>|null */
    public function nextQueued(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM cms_market_jobs WHERE status IN ('Queued', 'Retry') ORDER BY created_at ASC, id ASC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $job = $this->nextQueuedForClaim();
            if ($job === null) {
                $this->pdo->commit();
                return null;
            }
            $stmt = $this->pdo->prepare("UPDATE cms_market_jobs SET status = 'Running', attempts = attempts + 1, updated_at = :updated WHERE id = :id AND status IN ('Queued', 'Retry')");
            $stmt->execute([':id' => (string) $job['id'], ':updated' => gmdate('c')]);
            if ($stmt->rowCount() < 1) {
                $this->pdo->rollBack();
                return null;
            }
            $claimed = $this->find((string) $job['id']);
            $this->pdo->commit();

            return $claimed;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markRunning(string $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE cms_market_jobs SET status = 'Running', attempts = attempts + 1, updated_at = :updated WHERE id = :id");
        $stmt->execute([':id' => $id, ':updated' => gmdate('c')]);
        $this->assertUpdated($stmt->rowCount());
    }

    /** @param array<string, mixed> $result */
    public function markComplete(string $id, array $result): void
    {
        $stmt = $this->pdo->prepare("UPDATE cms_market_jobs SET status = 'Completed', result_json = :result, error_text = NULL, updated_at = :updated WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':updated' => gmdate('c'),
        ]);
        $this->assertUpdated($stmt->rowCount());
    }

    public function markFailed(string $id, string $error, int $maxAttempts = 3): void
    {
        $job = $this->find($id);
        if ($job === null) {
            throw new MarketException('Market job was not found.');
        }
        $status = (int) ($job['attempts'] ?? 0) < $maxAttempts ? 'Retry' : 'Failed';
        $stmt = $this->pdo->prepare('UPDATE cms_market_jobs SET status = :status, error_text = :error, updated_at = :updated WHERE id = :id');
        $stmt->execute([':id' => $id, ':status' => $status, ':error' => $error, ':updated' => gmdate('c')]);
        $this->assertUpdated($stmt->rowCount());
    }

    public function cancel(string $id, string $reason): void
    {
        $job = $this->find($id);
        if ($job === null) {
            throw new MarketException('Market job was not found.');
        }
        if (in_array((string) ($job['status'] ?? ''), ['Completed', 'Cancelled'], true)) {
            throw new MarketException('Market job cannot be cancelled from its current state.');
        }
        $stmt = $this->pdo->prepare("UPDATE cms_market_jobs SET status = 'Cancelled', error_text = :reason, updated_at = :updated WHERE id = :id");
        $stmt->execute([':id' => $id, ':reason' => $reason, ':updated' => gmdate('c')]);
        $this->assertUpdated($stmt->rowCount());
    }

    public function retry(string $id): void
    {
        $job = $this->find($id);
        if ($job === null) {
            throw new MarketException('Market job was not found.');
        }
        if (!in_array((string) ($job['status'] ?? ''), ['Failed', 'Retry', 'Cancelled'], true)) {
            throw new MarketException('Market job cannot be retried from its current state.');
        }
        $stmt = $this->pdo->prepare("UPDATE cms_market_jobs SET status = 'Queued', error_text = NULL, updated_at = :updated WHERE id = :id");
        $stmt->execute([':id' => $id, ':updated' => gmdate('c')]);
        $this->assertUpdated($stmt->rowCount());
    }

    public function moveToFront(string $id): void
    {
        $job = $this->find($id);
        if ($job === null) {
            throw new MarketException('Market job was not found.');
        }
        if (!in_array((string) ($job['status'] ?? ''), ['Queued', 'Retry'], true)) {
            throw new MarketException('Only queued jobs can be moved.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_jobs SET created_at = :created, updated_at = :updated WHERE id = :id');
        $stmt->execute([':id' => $id, ':created' => gmdate('c', time() - 31536000), ':updated' => gmdate('c')]);
        $this->assertUpdated($stmt->rowCount());
    }

    /** @param array<string, string> $filters @return array{items:array<int, array<string, mixed>>, total:int, page:int, per_page:int, pages:int} */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        if (($filters['type'] ?? '') !== '') {
            $where[] = 'type = :type';
            $params[':type'] = $filters['type'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(id LIKE :q OR type LIKE :q OR status LIKE :q OR error_text LIKE :q OR payload_json LIKE :q OR result_json LIKE :q)';
            $params[':q'] = '%' . (string) $filters['q'] . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_jobs' . $clause);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_jobs' . $clause . ' ORDER BY created_at ASC, id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->hydrate($row);
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
    }

    /** @return array<string, mixed>|null */
    private function nextQueuedForClaim(): ?array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $sql = "SELECT * FROM cms_market_jobs WHERE status IN ('Queued', 'Retry') ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED";
            if ($driver === 'pgsql') {
                $sql = "SELECT * FROM cms_market_jobs WHERE status IN ('Queued', 'Retry') ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED";
            }
            $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $this->hydrate($row) : null;
        }

        return $this->nextQueued();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'payload' => $this->decode($row['payload_json'] === null ? '' : (string) $row['payload_json']),
            'result' => $this->decode($row['result_json'] === null ? '' : (string) $row['result_json']),
            'error' => $row['error_text'] === null ? null : (string) $row['error_text'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return mixed */
    private function decode(string $json): mixed
    {
        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function assertUpdated(int $count): void
    {
        if ($count < 1) {
            throw new MarketException('Market job was not found.');
        }
    }
}
