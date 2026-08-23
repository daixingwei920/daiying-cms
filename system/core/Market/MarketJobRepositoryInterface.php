<?php

declare(strict_types=1);

namespace Cms\Core\Market;

interface MarketJobRepositoryInterface
{
    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, array $payload): string;

    /** @return array<string, array<string, mixed>> */
    public function all(): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed> */
    public function summary(): array;

    /** @return array<string, mixed>|null */
    public function nextQueued(): ?array;

    /** @return array<string, mixed>|null */
    public function claimNext(): ?array;

    public function markRunning(string $id): void;

    /** @param array<string, mixed> $result */
    public function markComplete(string $id, array $result): void;

    public function markFailed(string $id, string $error, int $maxAttempts = 3): void;

    public function cancel(string $id, string $reason): void;

    public function retry(string $id): void;

    public function moveToFront(string $id): void;

    /** @param array<string, string> $filters @return array{items:array<int, array<string, mixed>>, total:int, page:int, per_page:int, pages:int} */
    public function paginate(array $filters, int $page, int $perPage): array;
}
