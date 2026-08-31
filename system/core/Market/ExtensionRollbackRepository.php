<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionRollbackRepository
{
    public function __construct(
        private readonly string $rollbackPath,
        private readonly string $restorePath,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $items = [];
        foreach (glob($this->rollbackPath . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            $decoded['id'] = basename($file, '.json');
            $decoded['path'] = $file;
            $items[] = $decoded;
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $id = $this->sanitizeId($id);
        $file = $this->rollbackPath . '/' . $id . '.json';
        if ($id === '' || !is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return null;
        }
        $decoded['id'] = $id;
        $decoded['path'] = $file;

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function requestRestore(string $id): array
    {
        $id = $this->sanitizeId($id);
        $artifact = $this->find($id);
        if ($artifact === null) {
            throw new MarketException('Rollback artifact was not found.');
        }
        if (!is_dir($this->restorePath)) {
            mkdir($this->restorePath, 0755, true);
        }
        $payload = [
            'id' => $id,
            'status' => 'Requested',
            'requested_at' => gmdate('c'),
            'rollback' => $artifact,
        ];
        file_put_contents($this->restorePath . '/' . $id . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

        return $payload;
    }

    /** @param array<string, mixed> $result */
    public function markRestore(string $id, string $status, array $result): void
    {
        $id = $this->sanitizeId($id);
        if ($id === '') {
            throw new MarketException('Rollback restore id is invalid.');
        }
        if (!is_dir($this->restorePath)) {
            mkdir($this->restorePath, 0755, true);
        }
        $existing = json_decode(is_file($this->restorePath . '/' . $id . '.json') ? (string) file_get_contents($this->restorePath . '/' . $id . '.json') : '{}', true);
        $payload = is_array($existing) ? $existing : ['id' => $id];
        $payload['status'] = $status;
        $payload['completed_at'] = gmdate('c');
        $payload['result'] = $result;
        file_put_contents($this->restorePath . '/' . $id . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private function sanitizeId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', $id) ?? '';
    }
}
