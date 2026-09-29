<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/daiying-updater-runtime-boundary-' . bin2hex(random_bytes(4));
$failures = 0;

try {
    $site = $tmp . '/site';
    $running = $site . '/storage/updates/releases/daiying-cms-core-update-running';
    $active = $site . '/storage/updates/releases/daiying-cms-core-update-active';
    $keptStale = $site . '/storage/updates/releases/daiying-cms-core-update-kept-stale';
    $prunedStale = $site . '/storage/updates/releases/daiying-cms-core-update-pruned-stale';
    foreach ([$running, $active, $keptStale, $prunedStale, $site . '/storage/updates/history'] as $dir) {
        mkdir($dir, 0755, true);
    }
    copyDirectory($repo . '/system/core', $running . '/system/core');
    copyDirectory($repo . '/system/core', $active . '/system/core');
    copy($repo . '/system/core-manifest.json', $running . '/system/core-manifest.json');
    copy($repo . '/system/core-manifest.json', $active . '/system/core-manifest.json');
    file_put_contents($site . '/storage/updates/current-release.json', json_encode([
        'release_id' => 'daiying-cms-core-update-active',
        'version' => '9.9.9',
        'build' => 'test',
        'path' => $active,
        'switched_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    touch($active, time());
    touch($keptStale, time() - 10);
    touch($prunedStale, time() - 20);
    touch($running, time() - 30);

    $runner = $tmp . '/runner.php';
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);

$site = $argv[1];
$running = $argv[2];
require $running . '/system/core/Bootstrap/autoload.php';

$service = new Cms\Core\Update\UpdateService($site, '9.9.8', new Cms\Core\Update\SignatureVerifier(''));
$prune = new ReflectionMethod($service, 'pruneOldReleases');
$prune->invoke($service, 2);
if (!is_dir($running)) {
    throw new RuntimeException('Running updater release was pruned.');
}
if (!is_dir($site . '/storage/updates/releases/daiying-cms-core-update-active')) {
    throw new RuntimeException('Active release was pruned.');
}

$enable = new ReflectionMethod($service, 'enableRecoveryMode');
$disable = new ReflectionMethod($service, 'disableRecoveryMode');
$enable->invoke($service);
if (!is_file($site . '/storage/recovery.mode')) {
    throw new RuntimeException('Recovery mode file was not created by updater boundary.');
}
$disable->invoke($service);
if (is_file($site . '/storage/recovery.mode')) {
    throw new RuntimeException('Recovery mode file was not removed by updater boundary.');
}

echo "ok\n";
PHP);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($site) . ' ' . escapeshellarg($running) . ' 2>&1', $output, $code);
    check($code === 0, 'running updater release survives pruning: ' . implode("\n", $output));
    check(is_dir($keptStale), 'newest unprotected stale release is kept within retention count');
    check(!is_dir($prunedStale), 'old unprotected stale release is pruned');
} finally {
    removeDirectory($tmp);
}

if ($failures > 0) {
    exit(1);
}

echo "Updater runtime boundary tests passed.\n";

function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}

function copyDirectory(string $source, string $target): void
{
    mkdir($target, 0755, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $dest = $target . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            mkdir($dest, 0755, true);
        } else {
            copy($item->getPathname(), $dest);
        }
    }
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
