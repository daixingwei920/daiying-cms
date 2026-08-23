<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/scripts/build_release_package.php';

use Cms\Core\Config\Settings;
use Cms\Core\Install\InstallController;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Recovery\IntegrityChecker;

$failures = 0;

function pristine_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

function pristine_remove(string $path): void
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

$work = sys_get_temp_dir() . '/cms-pristine-package-' . bin2hex(random_bytes(4));
pristine_remove($work);
mkdir($work, 0755, true);
$zipPath = $work . '/release.zip';
$extractRoot = $work . '/extract';

cms_build_release_package(CMS_SOURCE_ROOT, $zipPath);

$zip = new ZipArchive();
$zip->open($zipPath);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$zipConfig = (string) $zip->getFromName('config/app.php') . "\n" . (string) $zip->getFromName('config/app.example.php');

pristine_check(is_file($zipPath) && filesize($zipPath) > 100000, 'pristine release ZIP builds');
pristine_check(!in_array('storage/installed.lock', $names, true), 'package does not contain installed lock');
pristine_check(!in_array('storage/installing.lock', $names, true), 'package does not contain transient installing lock');
pristine_check(!array_filter($names, static fn (string $name): bool => preg_match('/\.(sqlite|sqlite3|db)$/i', $name) === 1), 'package does not contain local database files');
pristine_check(!array_filter($names, static fn (string $name): bool => str_starts_with($name, 'storage/logs/') && $name !== 'storage/logs/'), 'package does not contain runtime log files');
pristine_check(!preg_match('#/(Users|private|var|tmp)/#', $zipConfig), 'package configuration does not contain local absolute paths');
$zip->extractTo($extractRoot);
$zip->close();

$config = require $extractRoot . '/config/app.php';
pristine_check(($config['database']['dsn'] ?? null) === '' && ($config['database']['username'] ?? null) === '' && ($config['database']['password'] ?? null) === '', 'extracted package database config is empty before install');
pristine_check(($config['site']['url'] ?? null) === '' && !isset($config['site']['secret']), 'extracted package site config is not pre-installed');
pristine_check(($config['payment']['fixture_provider_enabled'] ?? null) === false && ($config['payment']['paid_download_token_ttl_seconds'] ?? null) === 86400 && ($config['payment']['paid_download_token_max_uses'] ?? null) === 0 && ($config['payment']['paid_content_token_ttl_seconds'] ?? null) === 2592000, 'extracted package carries production-safe Core payment defaults');
pristine_check(is_dir($extractRoot . '/storage/logs') && is_dir($extractRoot . '/storage/database') && is_dir($extractRoot . '/storage/tmp'), 'extracted package preserves empty runtime directories');
pristine_check(is_dir($extractRoot . '/storage/plugin-installs/uploads') && is_dir($extractRoot . '/storage/plugin-installs/staging'), 'extracted package preserves local plugin ZIP install runtime directories');
pristine_check(!is_file($extractRoot . '/storage/installed.lock'), 'fresh extracted package is not marked installed');
pristine_check(count(glob($extractRoot . '/storage/database/*') ?: []) === 0, 'fresh extracted package has no database files');
$extractedIntegrity = (new IntegrityChecker())->check($extractRoot);
pristine_check(($extractedIntegrity['status'] ?? '') === 'ok', 'fresh extracted package Core manifest matches extracted Core files');

$controller = new InstallController($extractRoot, Settings::load($extractRoot), new FileLogger($extractRoot . '/storage/logs/app.log'));
$installPage = $controller->show();
pristine_check($installPage->status() === 200 && str_contains($installPage->body(), '安装 PHP CMS'), 'fresh extracted package opens install wizard');

pristine_remove($work);

if ($failures > 0) {
    fwrite(STDERR, $failures . " pristine install package checks failed.\n");
    exit(1);
}

echo "Pristine install package tests passed.\n";
