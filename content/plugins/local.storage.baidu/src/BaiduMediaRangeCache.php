<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

use Cms\Core\Media\MediaProviderItem;

final class BaiduMediaRangeCache
{
    public const HEAD_BYTES = 524288;
    public const TAIL_BYTES = 262144;

    private const MAX_TOTAL_BYTES = 2147483648;
    private const PRUNE_TARGET_BYTES = 1288490188;
    private const MAX_AGE_SECONDS = 2592000;

    public function __construct(private readonly string $basePath)
    {
    }

    public static function default(): self
    {
        $root = defined('CMS_ROOT') ? (string) CMS_ROOT : dirname(__DIR__, 4);

        return new self(rtrim($root, DIRECTORY_SEPARATOR) . '/storage/cache/local.storage.baidu/ranges');
    }

    /** @param array{0:int,1:int} $range */
    public function read(MediaProviderItem $item, array $range): ?string
    {
        [$start, $end] = $range;
        if ($start < 0 || $end < $start || $item->byteSize <= 0) {
            return null;
        }

        $head = $this->segmentPath($item, 'head');
        if ($start === 0 || $end < self::HEAD_BYTES) {
            $hit = $this->readFromFile($head, $start, $end);
            if ($hit !== null) {
                return $hit;
            }
        }

        $tailStart = $this->tailStart($item);
        if ($start >= $tailStart) {
            $hit = $this->readFromFile($this->segmentPath($item, 'tail'), $start - $tailStart, $end - $tailStart);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** @param array{0:int,1:int} $range */
    public function readAheadRange(MediaProviderItem $item, array $range): array
    {
        [$start, $end] = $range;
        $size = max(0, $item->byteSize);
        if ($size <= 0) {
            return $range;
        }

        if ($start === 0 || $end < self::HEAD_BYTES) {
            return [0, min($size - 1, max($end, self::HEAD_BYTES - 1))];
        }

        $tailStart = $this->tailStart($item);
        if ($end >= $size - 1 || $start >= $tailStart) {
            return [$tailStart, $size - 1];
        }

        return $range;
    }

    /** @param array{0:int,1:int} $range */
    public function store(MediaProviderItem $item, array $range, string $body): void
    {
        if ($body === '' || $item->byteSize <= 0) {
            return;
        }

        [$start, $end] = $range;
        if ($start === 0) {
            $this->writeFile($this->segmentPath($item, 'head'), substr($body, 0, self::HEAD_BYTES));
            $this->writeMeta($item);
        }

        $tailStart = $this->tailStart($item);
        if ($start === $tailStart && $end >= $item->byteSize - 1) {
            $this->writeFile($this->segmentPath($item, 'tail'), substr($body, 0, self::TAIL_BYTES));
            $this->writeMeta($item);
        }

        if (mt_rand(1, 100) === 1) {
            $this->prune();
        }
    }

    /** @return array{0:int,1:int} */
    public function headWarmRange(MediaProviderItem $item): array
    {
        return [0, min(max(0, $item->byteSize - 1), self::HEAD_BYTES - 1)];
    }

    /** @return array{0:int,1:int} */
    public function tailWarmRange(MediaProviderItem $item): array
    {
        return [$this->tailStart($item), max(0, $item->byteSize - 1)];
    }

    public function prune(): void
    {
        if (!is_dir($this->basePath)) {
            return;
        }

        $files = [];
        $total = 0;
        $now = time();
        foreach (glob($this->basePath . '/*/*.{bin,json}', GLOB_BRACE) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $mtime = (int) @filemtime($path);
            if ($mtime > 0 && $now - $mtime > self::MAX_AGE_SECONDS) {
                @unlink($path);
                continue;
            }
            $size = (int) @filesize($path);
            $files[] = ['path' => $path, 'mtime' => $mtime, 'size' => $size];
            $total += $size;
        }

        if ($total <= self::MAX_TOTAL_BYTES) {
            $this->removeEmptyDirs();
            return;
        }

        usort($files, static fn (array $a, array $b): int => ($a['mtime'] <=> $b['mtime']));
        foreach ($files as $file) {
            @unlink((string) $file['path']);
            $total -= (int) $file['size'];
            if ($total <= self::PRUNE_TARGET_BYTES) {
                break;
            }
        }
        $this->removeEmptyDirs();
    }

    private function tailStart(MediaProviderItem $item): int
    {
        return max(0, $item->byteSize - self::TAIL_BYTES);
    }

    private function segmentPath(MediaProviderItem $item, string $segment): string
    {
        $key = hash('sha256', implode(':', [
            BaiduTokenRepository::PLUGIN_ID,
            $item->id,
            (string) $item->byteSize,
            (string) ($item->updatedAt ?? ''),
            (string) ($item->checksum ?? ''),
        ]));

        return $this->basePath . '/' . substr($key, 0, 2) . '/' . $key . '-' . $segment . '.bin';
    }

    private function metaPath(MediaProviderItem $item): string
    {
        return preg_replace('/-(head|tail)\.bin$/', '-meta.json', $this->segmentPath($item, 'head')) ?: $this->segmentPath($item, 'head') . '.json';
    }

    private function readFromFile(string $path, int $start, int $end): ?string
    {
        if ($start < 0 || $end < $start || !is_file($path)) {
            return null;
        }
        $size = (int) @filesize($path);
        if ($size <= 0 || $end >= $size) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        if ($start > 0) {
            fseek($handle, $start);
        }
        $data = fread($handle, $end - $start + 1);
        fclose($handle);
        if (!is_string($data) || strlen($data) !== $end - $start + 1) {
            return null;
        }
        @touch($path);

        return $data;
    }

    private function writeFile(string $path, string $body): void
    {
        if ($body === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        @rename($tmp, $path);
        @chmod($path, 0664);
    }

    private function writeMeta(MediaProviderItem $item): void
    {
        $this->writeFile($this->metaPath($item), json_encode([
            'provider' => BaiduTokenRepository::PLUGIN_ID,
            'remote_id_hash' => hash('sha256', $item->id),
            'byte_size' => $item->byteSize,
            'updated_at' => $item->updatedAt,
            'checksum' => $item->checksum,
            'cached_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private function removeEmptyDirs(): void
    {
        foreach (glob($this->basePath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            @rmdir($dir);
        }
    }
}
