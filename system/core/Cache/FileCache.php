<?php

declare(strict_types=1);

namespace Cms\Core\Cache;

final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $root)
    {
    }

    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        $path = $this->path($namespace, $key);
        if (!is_file($path)) {
            return $default;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || (int) ($decoded['expires'] ?? 0) < time()) {
            @unlink($path);
            return $default;
        }

        return $decoded['value'] ?? $default;
    }

    public function set(string $namespace, string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $path = $this->path($namespace, $key);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new CacheException('Cache directory cannot be created.');
        }
        $payload = json_encode(['expires' => time() + max(1, $ttlSeconds), 'value' => $value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($path, $payload, LOCK_EX);
    }

    public function delete(string $namespace, string $key): void
    {
        @unlink($this->path($namespace, $key));
    }

    public function clear(string $namespace = ''): int
    {
        $base = $namespace === '' ? $this->root : $this->root . '/' . $this->safe($namespace);
        if (!is_dir($base)) {
            return 0;
        }
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.json')) {
                @unlink($file->getPathname());
                $count++;
            }
        }

        return $count;
    }

    private function path(string $namespace, string $key): string
    {
        return rtrim($this->root, '/') . '/' . $this->safe($namespace) . '/' . hash('sha256', $key) . '.json';
    }

    private function safe(string $namespace): string
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,80}$/', $namespace)) {
            throw new CacheException('Cache namespace is invalid.');
        }

        return $namespace;
    }
}
