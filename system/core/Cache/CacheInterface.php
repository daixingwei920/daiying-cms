<?php

declare(strict_types=1);

namespace Cms\Core\Cache;

interface CacheInterface
{
    public function get(string $namespace, string $key, mixed $default = null): mixed;

    public function set(string $namespace, string $key, mixed $value, int $ttlSeconds = 300): void;

    public function delete(string $namespace, string $key): void;

    public function clear(string $namespace = ''): int;
}
