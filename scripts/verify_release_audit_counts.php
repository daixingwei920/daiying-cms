<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auditPath = $root . '/CMS_COMPLETION_STATUS_AUDIT_V2.md';
$run = in_array('--run', $argv, true);
$json = in_array('--json', $argv, true);
$failures = 0;

if (!is_file($auditPath)) {
    fwrite(STDERR, "CMS_COMPLETION_STATUS_AUDIT_V2.md not found\n");
    exit(1);
}

$audit = (string) file_get_contents($auditPath);
$rows = [];
foreach (preg_split('/\R/u', $audit) ?: [] as $line) {
    if (!preg_match('/^\| (.+) \| `([^`]+)` \| ([0-9]+) \|$/u', $line, $match)) {
        continue;
    }

    if ($match[1] === 'Validation area') {
        continue;
    }

    $rows[] = [
        'area' => $match[1],
        'command' => $match[2],
        'expected_pass_count' => (int) $match[3],
    ];
}

if ($rows === []) {
    fwrite(STDERR, "No audit matrix rows found\n");
    exit(1);
}

$results = [];
foreach ($rows as $row) {
    $actual = null;
    $exitCode = null;
    $ok = true;
    $detail = 'not run';

    if ($run) {
        [$exitCode, $output] = cms_audit_counts_run_command($root, (string) $row['command']);
        $actual = preg_match_all('/^\[PASS\]/m', $output);
        $ok = $exitCode === 0 && $actual === $row['expected_pass_count'];
        $detail = 'exit=' . (string) $exitCode . '; actual=' . (string) $actual;
        if (!$ok) {
            $failures++;
        }
    }

    $results[] = $row + [
        'actual_pass_count' => $actual,
        'exit_code' => $exitCode,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

if ($json) {
    echo json_encode([
        'status' => $failures === 0 ? 'ok' : 'failed',
        'run' => $run,
        'rows' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    foreach ($results as $result) {
        echo '[' . (($result['ok'] ?? false) ? 'PASS' : 'FAIL') . '] '
            . $result['command']
            . ' expected=' . (string) $result['expected_pass_count']
            . ' actual=' . (($result['actual_pass_count'] ?? null) === null ? 'not-run' : (string) $result['actual_pass_count'])
            . PHP_EOL;
    }
}

exit($failures > 0 ? 1 : 0);

/**
 * @return array{0:int,1:string}
 */
function cms_audit_counts_run_command(string $root, string $command): array
{
    if (!preg_match('/^php tests\/[A-Za-z0-9_.-]+\.php$/', $command)) {
        return [1, '[FAIL] Unsupported audit command: ' . $command . PHP_EOL];
    }

    $script = substr($command, 4);
    $process = proc_open(
        PHP_BINARY . ' ' . escapeshellarg($root . '/' . $script),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root
    );

    if (!is_resource($process)) {
        return [1, '[FAIL] Could not start audit command' . PHP_EOL];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout . $stderr];
}
