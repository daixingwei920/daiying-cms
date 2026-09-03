<?php

declare(strict_types=1);

namespace Cms\Core\Config;

final class Settings
{
    /** @param array<string, mixed> $items */
    private function __construct(private readonly array $items)
    {
    }

    public static function load(string $rootPath): self
    {
        $configFile = $rootPath . '/config/app.php';
        $exampleFile = $rootPath . '/config/app.example.php';
        clearstatcache(true, $configFile);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configFile, true);
        }
        $items = is_file($configFile) ? require $configFile : (is_file($exampleFile) ? require $exampleFile : []);

        return new self(is_array($items) ? $items : []);
    }

    /** @param array<string, mixed> $items */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
