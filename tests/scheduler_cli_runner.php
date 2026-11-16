<?php

declare(strict_types=1);

$source = dirname(__DIR__);
require $source . '/system/core/Bootstrap/autoload.php';

$root = sys_get_temp_dir() . '/daiying-scheduler-cli-' . bin2hex(random_bytes(5));
$plugin = $root . '/content/plugins/fixture.scheduler';
mkdir($plugin, 0700, true);
mkdir($root . '/config', 0700, true);
mkdir($root . '/storage/logs', 0700, true);
$database = $root . '/site.sqlite';
$marker = $root . '/scheduled-runs.txt';
file_put_contents($root . '/config/app.php', '<?php return ' . var_export([
    'app' => ['version' => '1.2.99'],
    'database' => ['dsn' => 'sqlite:' . $database],
    'security' => ['encryption_key' => bin2hex(random_bytes(32))],
], true) . ';');
file_put_contents($plugin . '/plugin.json', json_encode([
    'plugin_id' => 'fixture.scheduler',
    'name' => 'Scheduler CLI fixture',
    'version' => '1.0.0',
    'author' => 'Daiying Test',
    'core' => ['min' => '1.2.99'],
    'php' => '8.3.0',
    'entry' => 'plugin.php',
    'capabilities' => ['scheduler.register'],
], JSON_THROW_ON_ERROR));
file_put_contents($plugin . '/plugin.php', <<<'PHP'
<?php
return static function (\Cms\Core\Plugin\PluginContext $context): void {
    $context->registerScheduledTask('fixture.scheduler.run', 60, static function (array $payload): void {
        file_put_contents(dirname(__DIR__, 3) . '/scheduled-runs.txt', ($payload['value'] ?? '') . "\n", FILE_APPEND);
    }, ['value' => 'ran']);
};
PHP);

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$run = static function (string $args) use ($root, $source): array {
    $lines = [];
    $code = 0;
    exec('CMS_ROOT_OVERRIDE=' . escapeshellarg($root) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($source . '/cli.php') . ' ' . $args . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
};

try {
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (require $source . '/system/migrations/2026_08_12_000003_plugin_schema.php')->up($pdo);
    (require $source . '/system/migrations/2026_09_08_000005_core_scheduler_foundation.php')->up($pdo);
    $pdo->exec("INSERT INTO cms_plugins(plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at) VALUES('fixture.scheduler','Scheduler CLI fixture','1.0.0','Daiying Test','Enabled','api','[\"scheduler.register\"]','before','before')");

    [$code, $output] = $run('run-scheduled-tasks 2');
    $first = json_decode($output, true);
    $check($code === 0 && is_array($first) && ($first['processed'] ?? -1) === 0, 'CLI boots the enabled plugin and waits for its interval');
    $registered = $pdo->query("SELECT owner, enabled FROM cms_core_scheduled_tasks WHERE task_id='fixture.scheduler.run'")->fetch(PDO::FETCH_ASSOC);
    $check(is_array($registered) && $registered['owner'] === 'fixture.scheduler' && (int) $registered['enabled'] === 1, 'Plugin task registered in Core scheduler');

    $pdo->exec("UPDATE cms_core_scheduled_tasks SET next_run_at='2000-01-01T00:00:00+00:00' WHERE task_id='fixture.scheduler.run'");
    $lock = fopen($root . '/storage/locks/scheduled-tasks.lock', 'c');
    $check($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Test process holds the site scheduler lock');
    [$code, $output] = $run('run-scheduled-tasks 2');
    $busy = json_decode($output, true);
    $check($code === 0 && ($busy['status'] ?? '') === 'already_running' && !is_file($marker), 'Concurrent CLI skips the due task while another runner holds the lock');
    flock($lock, LOCK_UN);
    fclose($lock);

    [$code, $output] = $run('run-scheduled-tasks 2');
    $result = json_decode($output, true);
    $check($code === 0 && is_array($result) && ($result['processed'] ?? -1) === 1 && ($result['succeeded'] ?? -1) === 1, 'CLI runs the due plugin task once');
    $check(is_file($marker) && file_get_contents($marker) === "ran\n", 'Registered handler received its payload');
    [$code, $output] = $run('run-scheduled-tasks 2');
    $again = json_decode($output, true);
    $check($code === 0 && is_array($again) && ($again['processed'] ?? -1) === 0 && file_get_contents($marker) === "ran\n", 'Immediate repeat does not rerun the interval task');

    [$code] = $run('run-scheduled-tasks 0');
    $check($code !== 0 && file_get_contents($marker) === "ran\n", 'Invalid limit is rejected before dispatch');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($root);
}

exit($failures ? 1 : 0);
