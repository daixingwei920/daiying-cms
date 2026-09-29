<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$options = getopt('', [
    'root::',
    'target-release-id:',
    'running-release::',
    'apply-cleanup',
    'json',
]);

$root = rtrim((string) ($options['root'] ?? dirname(__DIR__)), '/');
$targetReleaseId = (string) ($options['target-release-id'] ?? '');
$runningRelease = (string) ($options['running-release'] ?? '');
$applyCleanup = array_key_exists('apply-cleanup', $options);
$json = array_key_exists('json', $options);

$result = cms_updater_bridge_preflight($root, $targetReleaseId, $runningRelease, $applyCleanup);
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo ($result['safe'] ? 'SAFE' : 'UNSAFE') . ': ' . $result['reason'] . PHP_EOL;
    foreach ($result['messages'] as $message) {
        echo '- ' . $message . PHP_EOL;
    }
}

exit(($result['safe'] ?? false) ? 0 : 1);

/** @return array{safe:bool,status:string,reason:string,root:string,active_release:string,running_release:string,target_release:string,old_prune_keep:list<string>,old_prune_delete:list<string>,cleanup_deleted:list<string>,messages:list<string>} */
function cms_updater_bridge_preflight(string $root, string $targetReleaseId, string $runningRelease, bool $applyCleanup): array
{
    $root = rtrim($root, '/');
    $messages = [];
    $deleted = [];
    $releasesRoot = realpath($root . '/storage/updates/releases');
    if ($targetReleaseId === '' || preg_match('/[^A-Za-z0-9_.:-]/', $targetReleaseId) === 1) {
        return cms_updater_bridge_result(false, 'invalid_target_release_id', $root, '', '', '', [], [], [], ['Target release id is missing or invalid.']);
    }
    if ($releasesRoot === false || !is_dir($releasesRoot)) {
        return cms_updater_bridge_result(false, 'missing_releases_root', $root, '', '', '', [], [], [], ['storage/updates/releases is missing.']);
    }

    $pointerFile = $root . '/storage/updates/current-release.json';
    $pointer = is_file($pointerFile) ? json_decode((string) file_get_contents($pointerFile), true) : null;
    if (!is_array($pointer)) {
        return cms_updater_bridge_result(false, 'invalid_active_pointer', $root, '', '', '', [], [], [], ['current-release.json is missing or invalid.']);
    }
    $active = cms_updater_bridge_safe_release_path($root, (string) ($pointer['path'] ?? ''));
    if ($active === '' || !is_file($active . '/system/core/Bootstrap/autoload.php')) {
        return cms_updater_bridge_result(false, 'invalid_active_release', $root, '', '', '', [], [], [], ['Active release path is invalid or missing Core bootstrap.']);
    }

    if ($runningRelease === '') {
        $runningRelease = $active;
        $messages[] = 'Running release inferred from active pointer. Use --running-release when invoking from a different runtime.';
    }
    $running = cms_updater_bridge_safe_release_path($root, $runningRelease);
    if ($running === '' || !is_file($running . '/system/core/Bootstrap/autoload.php')) {
        return cms_updater_bridge_result(false, 'invalid_running_release', $root, $active, '', '', [], [], [], ['Running release cannot be proven.']);
    }

    $target = $releasesRoot . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $targetReleaseId);
    if (is_dir($target)) {
        return cms_updater_bridge_result(false, 'target_staging_exists', $root, $active, $running, $target, [], [], [], ['Target staging/release directory already exists; fail closed before update transaction.']);
    }

    $plan = cms_updater_bridge_old_prune_plan($root, $target);
    if (in_array($running, $plan['delete'], true)) {
        $messages[] = 'Old 1.2.70 pruneOldReleases(2) would delete the running updater runtime after target staging.';
        if ($applyCleanup) {
            foreach (cms_updater_bridge_cleanup_candidates($root, $active, $running, $target) as $candidate) {
                cms_updater_bridge_remove_directory($candidate);
                $deleted[] = $candidate;
                $plan = cms_updater_bridge_old_prune_plan($root, $target);
                if (!in_array($running, $plan['delete'], true)) {
                    break;
                }
            }
        }
    }

    $safe = !in_array($running, $plan['delete'], true)
        && !in_array($active, $plan['delete'], true)
        && is_dir($running)
        && is_dir($active);
    $reason = $safe ? 'old_1.2.70_prune_will_preserve_running_runtime' : 'old_1.2.70_prune_would_delete_required_release';
    if (!$safe && !$applyCleanup) {
        $messages[] = 'Run with --apply-cleanup only after reviewing candidates, or fail closed.';
    }

    return cms_updater_bridge_result($safe, $reason, $root, $active, $running, $target, $plan['keep'], $plan['delete'], $deleted, $messages);
}

/** @return array{safe:bool,status:string,reason:string,root:string,active_release:string,running_release:string,target_release:string,old_prune_keep:list<string>,old_prune_delete:list<string>,cleanup_deleted:list<string>,messages:list<string>} */
function cms_updater_bridge_result(bool $safe, string $reason, string $root, string $active, string $running, string $target, array $keep, array $delete, array $deleted, array $messages): array
{
    return [
        'safe' => $safe,
        'status' => $safe ? 'SAFE' : 'UNSAFE',
        'reason' => $reason,
        'root' => $root,
        'active_release' => $active,
        'running_release' => $running,
        'target_release' => $target,
        'old_prune_keep' => array_values($keep),
        'old_prune_delete' => array_values($delete),
        'cleanup_deleted' => array_values($deleted),
        'messages' => array_values($messages),
    ];
}

function cms_updater_bridge_safe_release_path(string $root, string $path): string
{
    $real = realpath(rtrim($path, '/'));
    $releases = realpath(rtrim($root, '/') . '/storage/updates/releases');
    if ($real === false || $releases === false) {
        return '';
    }

    return ($real === $releases || str_starts_with($real, $releases . DIRECTORY_SEPARATOR)) ? $real : '';
}

/** @return array{keep:list<string>,delete:list<string>} */
function cms_updater_bridge_old_prune_plan(string $root, string $target): array
{
    $dirs = array_values(array_filter(glob(rtrim($root, '/') . '/storage/updates/releases/*') ?: [], 'is_dir'));
    if (!is_dir($target)) {
        $dirs[] = $target;
    }
    $unique = [];
    foreach ($dirs as $dir) {
        $real = realpath($dir) ?: $dir;
        $unique[$real] = $real;
    }
    $dirs = array_values($unique);
    usort($dirs, static function (string $a, string $b) use ($target): int {
        return cms_updater_bridge_mtime($b, $target) <=> cms_updater_bridge_mtime($a, $target);
    });

    return [
        'keep' => array_slice($dirs, 0, 2),
        'delete' => array_slice($dirs, 2),
    ];
}

function cms_updater_bridge_mtime(string $path, string $target): int
{
    if ($path === $target && !is_dir($path)) {
        return time() + 1;
    }

    return is_dir($path) ? (int) filemtime($path) : 0;
}

/** @return list<string> */
function cms_updater_bridge_cleanup_candidates(string $root, string $active, string $running, string $target): array
{
    $dirs = array_values(array_filter(glob(rtrim($root, '/') . '/storage/updates/releases/*') ?: [], 'is_dir'));
    $protected = array_filter([$active, $running, realpath($target) ?: $target]);
    $candidates = [];
    foreach ($dirs as $dir) {
        $real = realpath($dir);
        if ($real === false || in_array($real, $protected, true)) {
            continue;
        }
        $candidates[] = $real;
    }
    usort($candidates, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

    return $candidates;
}

function cms_updater_bridge_remove_directory(string $dir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
