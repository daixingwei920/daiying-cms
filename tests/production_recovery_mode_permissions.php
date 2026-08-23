<?php

declare(strict_types=1);

use Cms\Core\Recovery\RecoveryActions;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function recovery_permission_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function recovery_permission_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/cms-recovery-perms-' . bin2hex(random_bytes(4));
recovery_permission_remove($root);
mkdir($root . '/storage', 0755, true);

$actions = new RecoveryActions($root);
$actions->enableRecoveryMode();
recovery_permission_check(is_file($root . '/storage/recovery.mode'), 'RecoveryActions creates recovery mode marker');
recovery_permission_check((fileperms($root . '/storage/recovery.mode') & 0077) === 0, 'RecoveryActions writes recovery mode marker with owner-only permissions');
$actions->enableSafeMode();
recovery_permission_check(is_file($root . '/storage/safe.mode'), 'RecoveryActions creates safe mode marker');
recovery_permission_check((fileperms($root . '/storage/safe.mode') & 0077) === 0, 'RecoveryActions writes safe mode marker with owner-only permissions');
recovery_permission_remove($root);

$launcher = (string) file_get_contents(CMS_SOURCE_ROOT . '/public/index.php');
recovery_permission_check(str_contains($launcher, "@chmod(CMS_ROOT . '/storage/recovery.mode', 0600)"), 'public launcher secures recovery marker created after damaged release pointer');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production recovery mode permission checks failed.\n");
    exit(1);
}

echo "Production recovery mode permission tests passed.\n";
