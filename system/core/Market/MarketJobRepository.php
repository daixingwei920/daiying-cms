<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketJobRepository implements MarketJobRepositoryInterface
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, array $payload): string
    {
        $id = 'job-' . bin2hex(random_bytes(8));
        $jobs = $this->all();
        $jobs[$id] = [
            'id' => $id,
            'type' => $type,
            'status' => 'Queued',
            'attempts' => 0,
            'payload' => $payload,
            'result' => null,
            'error' => null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $this->write($jobs);

        return $id;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $decoded = json_decode(is_file($this->path) ? (string) file_get_contents($this->path) : '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $jobs = $this->all();

        return is_array($jobs[$id] ?? null) ? $jobs[$id] : null;
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
        foreach ($this->all() as $job) {
            if (($job['status'] ?? '') === 'Queued' || ($job['status'] ?? '') === 'Retry') {
                return $job;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function claimNext(): ?array
    {
        $job = $this->nextQueued();
        if ($job === null) {
            return null;
        }
        $this->markRunning((string) $job['id']);

        return $this->find((string) $job['id']);
    }

    /** @param array<string, mixed> $result */
    public function markRunning(string $id): void
    {
        $this->patch($id, ['status' => 'Running', 'updated_at' => gmdate('c')], true);
    }

    /** @param array<string, mixed> $result */
    public function markComplete(string $id, array $result): void
    {
        $this->patch($id, ['status' => 'Completed', 'result' => $result, 'error' => null, 'updated_at' => gmdate('c')]);
    }

    public function markFailed(string $id, string $error, int $maxAttempts = 3): void
    {
        $jobs = $this->all();
        if (!isset($jobs[$id])) {
            throw new MarketException('Market job was not found.');
        }
        $attempts = (int) ($jobs[$id]['attempts'] ?? 0);
        $jobs[$id]['status'] = $attempts < $maxAttempts ? 'Retry' : 'Failed';
        $jobs[$id]['error'] = $error;
        $jobs[$id]['updated_at'] = gmdate('c');
        $this->write($jobs);
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
        $this->patch($id, ['status' => 'Cancelled', 'error' => $reason, 'updated_at' => gmdate('c')]);
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
        $this->patch($id, ['status' => 'Queued', 'error' => null, 'updated_at' => gmdate('c')]);
    }

    public function moveToFront(string $id): void
    {
        $jobs = $this->all();
        if (!isset($jobs[$id])) {
            throw new MarketException('Market job was not found.');
        }
        if (!in_array((string) ($jobs[$id]['status'] ?? ''), ['Queued', 'Retry'], true)) {
            throw new MarketException('Only queued jobs can be moved.');
        }
        $jobs[$id]['created_at'] = gmdate('c', time() - 31536000);
        $jobs[$id]['updated_at'] = gmdate('c');
        $job = $jobs[$id];
        unset($jobs[$id]);
        $this->write([$id => $job] + $jobs);
    }

    /** @param array<string, string> $filters @return array{items:array<int, array<string, mixed>>, total:int, page:int, per_page:int, pages:int} */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $items = array_values($this->all());
        $items = array_values(array_filter($items, static function (array $job) use ($filters): bool {
            if (($filters['status'] ?? '') !== '' && (string) ($job['status'] ?? '') !== $filters['status']) {
                return false;
            }
            if (($filters['type'] ?? '') !== '' && (string) ($job['type'] ?? '') !== $filters['type']) {
                return false;
            }
            if (($filters['q'] ?? '') !== '') {
                $haystack = strtolower(implode(' ', [
                    (string) ($job['id'] ?? ''),
                    (string) ($job['type'] ?? ''),
                    (string) ($job['status'] ?? ''),
                    (string) ($job['error'] ?? ''),
                    json_encode($job['payload'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
                    json_encode($job['result'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
                ]));
                if (!str_contains($haystack, strtolower((string) $filters['q']))) {
                    return false;
                }
            }

            return true;
        }));
        $total = count($items);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $pages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** @param array<string, mixed> $fields */
    private function patch(string $id, array $fields, bool $incrementAttempts = false): void
    {
        $jobs = $this->all();
        if (!isset($jobs[$id])) {
            throw new MarketException('Market job was not found.');
        }
        if ($incrementAttempts) {
            $jobs[$id]['attempts'] = (int) ($jobs[$id]['attempts'] ?? 0) + 1;
        }
        $jobs[$id] = array_replace($jobs[$id], $fields);
        $this->write($jobs);
    }

    /** @param array<string, array<string, mixed>> $jobs */
    private function write(array $jobs): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->path, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
}
