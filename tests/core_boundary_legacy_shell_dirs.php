<?php

declare(strict_types=1);

use Cms\Core\Integrity\CoreBoundary;

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

$root = sys_get_temp_dir() . '/daiying-core-boundary-' . bin2hex(random_bytes(4));
mkdir($root . '/system/core', 0775, true);
mkdir($root . '/system/migrations', 0775, true);

try {
    CoreBoundary::assertWritablePaths($root);

    foreach (['system/admin', 'system/recovery', 'content/uploads', 'content/themes', 'content/plugins', 'storage'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            throw new RuntimeException('Expected directory was not created: ' . $dir);
        }
    }

    $missingCoreRoot = sys_get_temp_dir() . '/daiying-core-boundary-missing-core-' . bin2hex(random_bytes(4));
    mkdir($missingCoreRoot . '/system/migrations', 0775, true);
    $failed = false;
    try {
        CoreBoundary::assertWritablePaths($missingCoreRoot);
    } catch (RuntimeException $exception) {
        $failed = str_contains($exception->getMessage(), '/system/core');
    } finally {
        removeTree($missingCoreRoot);
    }
    if (!$failed) {
        throw new RuntimeException('Missing /system/core must remain a fatal protected boundary failure.');
    }
} finally {
    removeTree($root);
}

echo "Core boundary legacy shell directory tests passed.\n";

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
