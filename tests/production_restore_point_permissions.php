<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Recovery\RestorePointService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function restore_permission_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function restore_permission_remove(string $path): void
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

function restore_permission_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/recovery/') || str_starts_with($relative, 'storage/updates/')) {
            continue;
        }
        $dest = $target . '/' . $relative;
        $item->isDir() ? (!is_dir($dest) && mkdir($dest, 0755, true)) : copy((string) $item->getPathname(), $dest);
    }
}

$root = sys_get_temp_dir() . '/cms-restore-perms-' . bin2hex(random_bytes(4));
restore_permission_remove($root);
restore_permission_copy(CMS_SOURCE_ROOT, $root);
foreach (['storage/recovery', 'storage/database', 'storage/logs', 'content/uploads'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        mkdir($root . '/' . $dir, 0755, true);
    }
}
$config = require $root . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/database/restore.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site']['secret'] = 'restore-secret';
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
@symlink($root . '/config/app.php', $root . '/content/uploads/config-link.php');
$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob($root . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
$restore = (new RestorePointService($root))->create('permission-test');
restore_permission_check(is_file($restore), 'restore point archive is created');
restore_permission_check((fileperms($restore) & 0077) === 0, 'restore point archive is owner-only');
restore_permission_check(count(glob($root . '/storage/recovery/db-backup-*') ?: []) === 0, 'temporary database backup is cleaned after archive creation');
$zip = new ZipArchive();
$zip->open($restore);
$manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
restore_permission_check($zip->locateName('content/uploads/config-link.php') === false, 'restore point archive skips symlink entries');
restore_permission_check(is_array($manifest) && !array_key_exists('content/uploads/config-link.php', $manifest['checksums'] ?? []), 'restore point manifest excludes skipped symlink entries');
$zip->close();
restore_permission_remove($root);

$source = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core/Recovery/RestorePointService.php');
restore_permission_check(substr_count($source, '@chmod($zipPath, 0600)') >= 2, 'restore point archive is secured before and after ZIP close');
restore_permission_check(str_contains($source, '@chmod($tmp, 0600);'), 'SQLite database restore temporary file is owner-only');
restore_permission_check(str_contains($source, 'Unable to create verified SQLite database backup.') && str_contains($source, '@chmod($tmp, 0600);'), 'SQLite database backup temporary file is owner-only');
restore_permission_check(str_contains($source, 'Unable to create verified MySQL/MariaDB database backup.') && str_contains($source, '@chmod($tmp, 0600);'), 'MySQL/MariaDB dump temporary file is owner-only');
restore_permission_check(str_contains($source, '$file->isLink()'), 'restore point creation explicitly skips symlinks');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production restore point permission checks failed.\n");
    exit(1);
}

echo "Production restore point permission tests passed.\n";
