<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketJobAuditRepository
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $jobId, string $action, string $actor, array $context = []): void
    {
        $items = $this->all();
        $items[] = [
            'job_id' => $jobId,
            'action' => $action,
            'actor' => $actor,
            'context' => $context,
            'created_at' => gmdate('c'),
        ];
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    /** @return array<int, array<string, mixed>> */
    public function forJob(string $jobId): array
    {
        return array_values(array_filter($this->all(), static fn (array $item): bool => (string) ($item['job_id'] ?? '') === $jobId));
    }

    /** @param array<string, string> $filters @return array<int, array<string, mixed>> */
    public function search(array $filters): array
    {
        return array_values(array_filter($this->all(), static function (array $item) use ($filters): bool {
            if (($filters['job_id'] ?? '') !== '' && (string) ($item['job_id'] ?? '') !== $filters['job_id']) {
                return false;
            }
            if (($filters['action'] ?? '') !== '' && (string) ($item['action'] ?? '') !== $filters['action']) {
                return false;
            }
            if (($filters['actor'] ?? '') !== '' && !str_contains(strtolower((string) ($item['actor'] ?? '')), strtolower($filters['actor']))) {
                return false;
            }
            if (($filters['q'] ?? '') !== '') {
                $haystack = strtolower(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
                if (!str_contains($haystack, strtolower($filters['q']))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $decoded = json_decode(is_file($this->path) ? (string) file_get_contents($this->path) : '[]', true);

        return is_array($decoded) ? $decoded : [];
    }
}
