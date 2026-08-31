<?php

declare(strict_types=1);

namespace Cms\Core\Integrity;

use RuntimeException;

final class CoreBoundary
{
    private const SYSTEM_DIRS = [
        '/system/core',
        '/system/admin',
        '/system/recovery',
        '/system/migrations',
    ];

    private const WRITABLE_DIRS = [
        '/content/uploads',
        '/content/themes',
        '/content/plugins',
        '/storage',
    ];

    public static function assertWritablePaths(string $rootPath): void
    {
        foreach (self::SYSTEM_DIRS as $dir) {
            $path = $rootPath . $dir;
            if (!is_dir($path)) {
                throw new RuntimeException('Missing protected core directory: ' . $dir);
            }
        }

        foreach (self::WRITABLE_DIRS as $dir) {
            $path = $rootPath . $dir;
            if (!is_dir($path) && !mkdir($path, 0755, true)) {
                throw new RuntimeException('Unable to create writable directory: ' . $dir);
            }
        }
    }

    public static function isProtectedPath(string $rootPath, string $candidate): bool
    {
        $root = rtrim(realpath($rootPath) ?: $rootPath, DIRECTORY_SEPARATOR);
        $path = realpath($candidate) ?: $candidate;

        foreach (self::SYSTEM_DIRS as $dir) {
            $protected = $root . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            if ($path === $protected || str_starts_with($path, $protected . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
