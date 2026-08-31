<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class DownloadProgressRepository
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(string $downloadId, string $status, array $metadata = []): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $items = $this->all();
        $items[$downloadId] = [
            'download_id' => $downloadId,
            'status' => $status,
            'metadata' => $metadata,
            'updated_at' => gmdate('c'),
        ];
        file_put_contents($this->path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $decoded = json_decode(is_file($this->path) ? (string) file_get_contents($this->path) : '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $items = $this->all();
        if ($items === []) {
            return null;
        }

        return array_values($items)[array_key_last(array_values($items))];
    }
}
