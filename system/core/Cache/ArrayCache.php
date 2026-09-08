<?php

declare(strict_types=1);

namespace Cms\Core\Cache;

final class ArrayCache implements CacheInterface
{
    /** @var array<string,array{expires:int,value:mixed}> */
    private array $items = [];

    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        $id = $this->id($namespace, $key);
        $item = $this->items[$id] ?? null;
        if (!is_array($item) || (int) $item['expires'] < time()) {
            unset($this->items[$id]);
            return $default;
        }

        return $item['value'];
    }

    public function set(string $namespace, string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $this->items[$this->id($namespace, $key)] = [
            'expires' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];
    }

    public function delete(string $namespace, string $key): void
    {
        unset($this->items[$this->id($namespace, $key)]);
    }

    public function clear(string $namespace = ''): int
    {
        $count = 0;
        foreach (array_keys($this->items) as $id) {
            if ($namespace === '' || str_starts_with($id, $namespace . ':')) {
                unset($this->items[$id]);
                $count++;
            }
        }

        return $count;
    }

    private function id(string $namespace, string $key): string
    {
        return $this->safe($namespace) . ':' . hash('sha256', $key);
    }

    private function safe(string $namespace): string
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,80}$/', $namespace)) {
            throw new CacheException('Cache namespace is invalid.');
        }

        return $namespace;
    }
}
