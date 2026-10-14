<?php
declare(strict_types=1);

// Opt-in only: a fresh cms_test_* database on the local MySQL service.
$configFile = getenv('CMS_MYSQL_TEST_CONFIG');
if (!is_string($configFile) || $configFile === '') {
    echo "[SKIP] CMS_MYSQL_TEST_CONFIG is not supplied.\n";
    exit(0);
}
$config = require $configFile;
if (!is_array($config) || ($config['host'] ?? null) !== '127.0.0.1'
    || !preg_match('/^cms_test_[a-z0-9_]+$/D', (string) ($config['database'] ?? ''))
    || !is_dir($config['root'] ?? '') || !is_string($config['user'] ?? null)
    || !is_string($config['password'] ?? null)) {
    throw new RuntimeException('A disposable loopback MySQL fixture is required.');
}

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\PaymentTransaction;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginPermissionDecisionService;
use Cms\Core\Plugin\PluginPermissionManifest;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $config['database'] . ';charset=utf8mb4',
    $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
$plugins = rtrim($config['root'], '/') . '/content/plugins';
$fixtureDir = $plugins . '/local.permission-fixture';
$raceDir = rtrim($config['root'], '/') . '/permission-race';
$mode = $argv[1] ?? 'main';
if (in_array($mode, ['revoke-worker', 'claim-worker'], true)) {
    $service = new PluginPermissionDecisionService($pdo, $plugins);
    $ready = $raceDir . '/' . $mode . '.ready';
    file_put_contents($ready, 'ready');
    $start = $raceDir . '/' . $mode . '.start';
    $deadline = microtime(true) + 5;
    while (!is_file($start) && microtime(true) < $deadline) { usleep(1000); }
    if (!is_file($start)) { throw new RuntimeException('Worker start barrier timed out.'); }
    try {
        if ($mode === 'revoke-worker') {
            $service->revoke('local.permission-fixture', '1.0.0', 2);
            file_put_contents($raceDir . '/revoke.result', 'revoked');
            exit(0);
        }
        PaymentTransaction::beginImmediate($pdo);
        $manifest = PluginManifest::fromArray(json_decode((string) file_get_contents($fixtureDir . '/plugin.json'), true, 16, JSON_THROW_ON_ERROR));
        $service->assertEffective($manifest, true);
        $pdo->exec("INSERT INTO test_claims (outcome) VALUES ('incorrect-after-revoke')");
        PaymentTransaction::commit($pdo);
        file_put_contents($raceDir . '/claim.result', 'incorrectly-accepted');
        exit(1);
    } catch (PluginException) {
        if (PaymentTransaction::isActive($pdo)) { PaymentTransaction::rollback($pdo); }
        file_put_contents($raceDir . '/claim.result', 'denied');
        exit(0);
    }
}
if ((int) $pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('MySQL permission test requires an empty database.');
}
if (is_file($fixtureDir . '/plugin.json') || is_dir($raceDir)) {
    throw new RuntimeException('Existing plugin fixture must not be overwritten.');
}
mkdir($fixtureDir, 0700, true);
mkdir($raceDir, 0700);
$manifestData = ['plugin_id' => 'local.permission-fixture', 'name' => 'MySQL permission fixture',
    'version' => '1.0.0', 'author' => 'Core test', 'core' => ['min' => '1.2.84'],
    'php' => '8.3.0', 'entry' => 'plugin.php', 'trust_level' => 'api',
    'permissions' => ['payment.access'],
    'capabilities' => ['payment.subject.register', 'payment.checkout']];
file_put_contents($fixtureDir . '/plugin.json', json_encode($manifestData, JSON_THROW_ON_ERROR));
$manifest = PluginManifest::fromArray($manifestData);

foreach (['2026_08_12_000003_plugin_schema.php',
    '2026_08_29_000004_differentiation_platform_schema.php'] as $file) {
    (require dirname(__DIR__) . '/system/migrations/' . $file)->up($pdo);
}
$pdo->exec('CREATE TABLE cms_audit_logs (id INTEGER PRIMARY KEY AUTO_INCREMENT, actor_type VARCHAR(32), actor_id INTEGER, action VARCHAR(128), context_json LONGTEXT, created_at VARCHAR(64))');
$pdo->prepare('INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([$manifest->id, $manifest->name, $manifest->version, $manifest->author,
        'Enabled', 'api', json_encode($manifest->capabilities), gmdate('c'), gmdate('c')]);
(new PluginPermissionManifest($pdo))->grant($manifest->id, $manifest->version, ['payment.access'], 1);
$scopeMigration = require dirname(__DIR__) . '/system/migrations/2026_10_14_000002_plugin_permission_scope.php';
$scopeMigration->up($pdo);
$scopeMigration->up($pdo);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
    echo 'PASS ' . $label . PHP_EOL;
};
$check((int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants WHERE scope_sha256 IS NULL')->fetchColumn() === 1,
    'Repeated MySQL migration retains historical unscoped grant');
$service = new PluginPermissionDecisionService($pdo, $plugins);
try {
    $service->assertEffective($manifest);
    throw new RuntimeException('Historical grant unexpectedly authorized payment access.');
} catch (PluginException) {
    $check(true, 'Historical MySQL grant is not effective');
}

// This official call failed with HY093 before timestamps used distinct placeholders.
$check($service->grant($manifest->id, '1.0.0', 1)['changed'], 'Scoped grant succeeds with native MySQL prepares');
$row = $pdo->query('SELECT status, scope_sha256, granted_at, created_at, updated_at FROM cms_plugin_permission_grants ORDER BY id DESC LIMIT 1')->fetch();
$check(is_array($row) && $row['status'] === 'granted_scoped'
    && $row['scope_sha256'] === PluginPermissionDecisionService::scopeDigest($manifest)
    && $row['granted_at'] === $row['created_at'] && $row['created_at'] === $row['updated_at'],
    'Native MySQL insert binds each timestamp and preserves one time value');
$service->assertEffective($manifest);
$count = (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn();
$check(!$service->grant($manifest->id, '1.0.0', 1)['changed']
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn() === $count,
    'Repeated scoped grant does not duplicate history');
$check($service->revoke($manifest->id, '1.0.0', 1)['changed'], 'Scoped revoke succeeds with native MySQL prepares');
$count = (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn();
$check(!$service->revoke($manifest->id, '1.0.0', 1)['changed']
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn() === $count,
    'Repeated revoke does not duplicate history');
$scopeMigration->up($pdo);
$check((int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants WHERE scope_sha256 IS NULL')->fetchColumn() === 1,
    'Repeat migration still preserves historical NULL digest');
$service->grant($manifest->id, '1.0.0', 1);
$pdo->exec('CREATE TABLE test_claims (id INTEGER PRIMARY KEY AUTO_INCREMENT, outcome VARCHAR(64) NOT NULL)');
$startWorker = static function (string $name): array {
    $process = proc_open([PHP_BINARY, __FILE__, $name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start independent MySQL worker.'); }
    return [$process, $pipes];
};
$waitFor = static function (string $path): void {
    $deadline = microtime(true) + 5;
    while (!is_file($path) && microtime(true) < $deadline) { usleep(1000); }
    if (!is_file($path)) { throw new RuntimeException('MySQL worker readiness timed out.'); }
};
$finishWorker = static function (array $worker): int {
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 && $err !== '') { echo 'Worker failure: ' . $err . PHP_EOL; }
    return $exit;
};

$revokeWorker = $startWorker('revoke-worker');
$waitFor($raceDir . '/revoke-worker.ready');
PaymentTransaction::beginImmediate($pdo);
$service->assertEffective($manifest, true);
$pdo->exec("INSERT INTO test_claims (outcome) VALUES ('accepted-before-revoke')");
file_put_contents($raceDir . '/revoke-worker.start', 'start');
usleep(100000);
$check(!is_file($raceDir . '/revoke.result'), 'Revoke waits behind MySQL locked claim');
PaymentTransaction::commit($pdo);
$check($finishWorker($revokeWorker) === 0 && file_get_contents($raceDir . '/revoke.result') === 'revoked'
    && (int) $pdo->query('SELECT COUNT(*) FROM test_claims')->fetchColumn() === 1,
    'Claim-before-revoke preserves exactly one committed synthetic claim');
try {
    $service->assertEffective($manifest);
    throw new RuntimeException('Revoked plugin unexpectedly authorized another claim.');
} catch (PluginException) {
    $check(true, 'MySQL revocation denies later claims');
}

$service->grant($manifest->id, '1.0.0', 1);
PaymentTransaction::beginImmediate($pdo);
$service->revoke($manifest->id, '1.0.0', 1);
$claimWorker = $startWorker('claim-worker');
$waitFor($raceDir . '/claim-worker.ready');
file_put_contents($raceDir . '/claim-worker.start', 'start');
usleep(100000);
$check(!is_file($raceDir . '/claim.result'), 'Claim waits behind MySQL locked revocation');
PaymentTransaction::commit($pdo);
$check($finishWorker($claimWorker) === 0 && file_get_contents($raceDir . '/claim.result') === 'denied'
    && (int) $pdo->query('SELECT COUNT(*) FROM test_claims')->fetchColumn() === 1,
    'Revoke-before-claim rejects the second-process synthetic claim');
echo "MySQL permission regression: {$checks} checks passed.\n";
