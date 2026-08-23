<?php

declare(strict_types=1);

use Cms\Core\Update\AtomicUpdateState;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function update_control_permission_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function update_control_permission_remove(string $path): void
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

$root = sys_get_temp_dir() . '/cms-update-control-perms-' . bin2hex(random_bytes(4));
update_control_permission_remove($root);
mkdir($root . '/storage/updates/history', 0755, true);

$state = new AtomicUpdateState($root);
$state->markPrepared(['operation_id' => 'perm-test']);
$prepared = $root . '/storage/updates/history/prepared.json';
update_control_permission_check(is_file($prepared), 'Atomic update state writes prepared history file');
update_control_permission_check((fileperms($prepared) & 0077) === 0, 'Atomic update state writes prepared history file with owner-only permissions');
$state->markRollback('perm-test', 'failure');
$rollback = $root . '/storage/updates/history/rollback-perm-test.json';
update_control_permission_check(is_file($rollback), 'Atomic update state writes rollback history file');
update_control_permission_check((fileperms($rollback) & 0077) === 0, 'Atomic update state writes rollback history file with owner-only permissions');
update_control_permission_remove($root);

$updateService = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core/Update/UpdateService.php');
update_control_permission_check(str_contains($updateService, 'private function writePrivateJsonFile'), 'UpdateService has a private JSON writer for control files');
update_control_permission_check(str_contains($updateService, '@chmod($file, 0600)'), 'UpdateService private JSON writer secures control files');
update_control_permission_check(str_contains($updateService, '@chmod($lock, 0600)'), 'UpdateService secures Core update lock file');
update_control_permission_check(str_contains($updateService, '$this->writePrivateJsonFile($file, [\'operation_id\' => $operationId'), 'UpdateService writes maintenance marker through private JSON writer');
update_control_permission_check(str_contains($updateService, '$this->writePrivateJsonFile($tmp, $payload)') && str_contains($updateService, '@chmod($file, 0600);'), 'UpdateService writes current release pointer atomically with owner-only permissions');
update_control_permission_check(str_contains($updateService, '$this->writePrivateJsonFile($this->rootPath . self::LOCK_FILE, $state)'), 'UpdateService heartbeat updates keep Core update lock owner-only');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production update control permission checks failed.\n");
    exit(1);
}

echo "Production update control permission tests passed.\n";
