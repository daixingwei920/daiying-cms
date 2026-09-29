<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
$script = $repo . '/scripts/preflight_updater_runtime_bridge.php';
$failures = 0;

caseSafeNoStale($repo, $script);
caseSafeOneStale($repo, $script);
caseMultipleStaleCleanup($repo, $script);
caseUnknownRunningFails($repo, $script);
caseBrokenPointerFails($repo, $script);
caseRunningDiffersFromActive($repo, $script);
caseRollbackSourceMissingFails($repo, $script);
caseTargetStagingExistsFails($repo, $script);
caseInterruptedCleanupIsIdempotent($repo, $script);

if ($failures > 0) {
    exit(1);
}

echo "Updater runtime bridge preflight tests passed.\n";

function caseSafeNoStale(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 100]);
    $result = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71']);
    check($result['code'] === 0 && ($result['json']['safe'] ?? false) === true, 'Case 1: normal 1.2.70 -> 1.2.71 is SAFE');
    cleanup($site);
}

function caseSafeOneStale(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 100, 'stale-a' => 90]);
    $result = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71']);
    check($result['code'] === 0 && ($result['json']['safe'] ?? false) === true, 'Case 2: one stale release is SAFE');
    cleanup($site);
}

function caseMultipleStaleCleanup(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 80, 'stale-a' => 100, 'stale-b' => 90]);
    $unsafe = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71']);
    check($unsafe['code'] !== 0 && ($unsafe['json']['safe'] ?? true) === false, 'Case 3a: old prune risk is detected as UNSAFE');
    check(in_array(realpath($site . '/storage/updates/releases/active'), $unsafe['json']['old_prune_delete'] ?? [], true), 'Case 3b: old 1.2.70 plan would delete running active release');
    $safe = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71', '--apply-cleanup']);
    check($safe['code'] === 0 && ($safe['json']['safe'] ?? false) === true, 'Case 3c: cleanup makes old prune SAFE');
    check(($safe['json']['cleanup_deleted'] ?? []) !== [], 'Case 3d: cleanup records deleted stale releases');
    cleanup($site);
}

function caseUnknownRunningFails(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 100]);
    $result = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71', '--running-release=' . $site . '/storage/updates/releases/missing']);
    check($result['code'] !== 0 && ($result['json']['reason'] ?? '') === 'invalid_running_release', 'Case 4: unknown running release fails closed');
    cleanup($site);
}

function caseBrokenPointerFails(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 100]);
    file_put_contents($site . '/storage/updates/current-release.json', '{bad json');
    $result = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71']);
    check($result['code'] !== 0 && ($result['json']['reason'] ?? '') === 'invalid_active_pointer', 'Case 5: broken active pointer fails closed');
    cleanup($site);
}

function caseRunningDiffersFromActive(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 80, 'running' => 70, 'stale-a' => 100]);
    $result = runPreflight($script, $site, [
        '--target-release-id=daiying-cms-core-update-1.2.71',
        '--running-release=' . $site . '/storage/updates/releases/running',
        '--apply-cleanup',
    ]);
    check($result['code'] === 0 && ($result['json']['safe'] ?? false) === true, 'Case 6: running != active is explicit and can be made SAFE');
    cleanup($site);
}

function caseRollbackSourceMissingFails(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 100]);
    rename($site . '/storage/updates/releases/active/system/core/Bootstrap/autoload.php', $site . '/storage/updates/releases/active/system/core/Bootstrap/autoload.php.missing');
    $result = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71']);
    check($result['code'] !== 0 && ($result['json']['reason'] ?? '') === 'invalid_active_release', 'Case 7: rollback source without bootstrap fails closed');
    cleanup($site);
}

function caseTargetStagingExistsFails(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 100, 'daiying-cms-core-update-1.2.71' => 110]);
    $result = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71']);
    check($result['code'] !== 0 && ($result['json']['reason'] ?? '') === 'target_staging_exists', 'Case 8: existing target staging fails closed');
    cleanup($site);
}

function caseInterruptedCleanupIsIdempotent(string $repo, string $script): void
{
    $site = fixture($repo, ['active' => 70, 'stale-a' => 100, 'stale-b' => 90, 'stale-c' => 80]);
    $first = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71', '--apply-cleanup']);
    $second = runPreflight($script, $site, ['--target-release-id=daiying-cms-core-update-1.2.71', '--apply-cleanup']);
    check(($first['json']['cleanup_deleted'] ?? []) !== [], 'Case 9a: first bridge run performs cleanup');
    check($second['code'] === 0 && ($second['json']['safe'] ?? false) === true, 'Case 9b: rerun after partial/previous cleanup remains SAFE');
    cleanup($site);
}

/** @param array<string,int> $releases */
function fixture(string $repo, array $releases): string
{
    $site = sys_get_temp_dir() . '/daiying-updater-bridge-' . bin2hex(random_bytes(4));
    mkdir($site . '/storage/updates/releases', 0755, true);
    foreach ($releases as $name => $offset) {
        $dir = $site . '/storage/updates/releases/' . $name;
        mkdir($dir . '/system/core/Bootstrap', 0755, true);
        file_put_contents($dir . '/system/core/Bootstrap/autoload.php', "<?php\nspl_autoload_register(static function (): void {});\n");
        touch($dir, time() + $offset);
    }
    $active = $site . '/storage/updates/releases/active';
    if (is_dir($active)) {
        file_put_contents($site . '/storage/updates/current-release.json', json_encode([
            'release_id' => 'active',
            'version' => '1.2.70',
            'build' => 'fixture',
            'path' => $active,
            'switched_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $site;
}

/** @return array{code:int,json:array<string,mixed>,raw:string} */
function runPreflight(string $script, string $site, array $args): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --json --root=' . escapeshellarg($site);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd . ' 2>&1', $output, $code);
    $raw = implode("\n", $output);
    $decoded = json_decode($raw, true);
    return ['code' => $code, 'json' => is_array($decoded) ? $decoded : [], 'raw' => $raw];
}

function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}

function cleanup(string $site): void
{
    if (!is_dir($site)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($site);
}
