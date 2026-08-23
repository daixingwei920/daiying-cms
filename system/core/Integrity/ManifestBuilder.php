<?php

declare(strict_types=1);

namespace Cms\Core\Integrity;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ManifestBuilder
{
    /** @return array<string, string> */
    public static function build(string $corePath): array
    {
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($corePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getBasename() === '.DS_Store') {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($corePath) + 1));
            $manifest[$relative] = hash_file('sha256', $path);
        }

        ksort($manifest);

        return $manifest;
    }
}
