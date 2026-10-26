<?php

declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';
use Cms\Core\Config\Settings;
use Cms\Core\Seo\CrawlerLog;
use Cms\Core\Http\{Request,Response};
if (($argv[1] ?? '') === '--worker') {
    $root = $argv[2] ?? '';
    if (!is_file($root . '/.issue9-test')) { throw new RuntimeException('Disposable marked fixture required'); }
    $log = new CrawlerLog($root, Settings::fromArray(['seo' => ['crawler_log_enabled' => true]]));
    for ($i = 0; $i < 100; $i++) { $log->record(new Request('GET', '/articles/item', [], [], ['HTTP_USER_AGENT' => 'GPTBot']), Response::html('')); }
    exit;
}
$root = sys_get_temp_dir() . '/cms-crawler-concurrency-' . bin2hex(random_bytes(4)); mkdir($root, 0700); touch($root . '/.issue9-test');
mkdir($root . '/storage/logs', 0700, true);
file_put_contents($root . '/storage/logs/seo-crawlers.json', '[]');
$workers = [];
try {
    for ($i = 0; $i < 4; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Worker startup failed'); }
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException($out); }
    }
    $raw = file_get_contents($root . '/storage/logs/seo-crawlers.json'); $rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (count($rows) !== 1 || $rows[0]['hits'] < 1 || $rows[0]['hits'] > 400 || strlen($raw) > 524288) { throw new RuntimeException('Concurrency invariant failed'); }
    echo 'PASS: four real processes, valid bounded aggregate, hits=' . $rows[0]['hits'] . ' of 400 attempted; nonblocking contention intentionally drops samples.' . PHP_EOL;
} finally {
    foreach (glob($root . '/storage/logs/seo-crawler-observation-*.json') ?: [] as $observation) { unlink($observation); }
    unlink($root . '/storage/logs/seo-crawlers.json'); unlink($root . '/.issue9-test'); rmdir($root . '/storage/logs'); rmdir($root . '/storage'); rmdir($root);
}
