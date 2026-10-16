<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\PaymentTransaction;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginPermissionDecisionService;
use Cms\Core\Plugin\PluginPermissionManifest;

$checks = 0;
function checkDecision(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function deniedDecision(callable $action, string $message): void
{
    try {
        $action();
    } catch (PluginException) {
        checkDecision(true, $message);
        return;
    }
    throw new RuntimeException('Expected denial: ' . $message);
}

function testPdo(string $file): PDO
{
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
    return $pdo;
}

function testManifest(string $root, string $version, array $permissions, array $capabilities): PluginManifest
{
    $data = [
        'plugin_id' => 'local.permission-fixture', 'name' => 'Permission fixture',
        'version' => $version, 'author' => 'Core test', 'core' => ['min' => '1.2.84'],
        'php' => '8.3.0', 'entry' => 'plugin.php', 'trust_level' => 'api',
        'permissions' => $permissions, 'capabilities' => $capabilities,
    ];
    file_put_contents($root . '/local.permission-fixture/plugin.json', json_encode($data, JSON_THROW_ON_ERROR));
    return PluginManifest::fromArray($data);
}

$root = sys_get_temp_dir() . '/daiying-payment-permission-' . getmypid();
mkdir($root . '/local.permission-fixture', 0700, true);
$dbFile = $root . '/test.sqlite';
$ownerPid = getmypid();
register_shutdown_function(static function () use ($root, $ownerPid): void {
    if (getmypid() !== $ownerPid) {
        return;
    }
    foreach (glob($root . '/*') ?: [] as $entry) {
        if (is_dir($entry)) {
            foreach (glob($entry . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($entry);
        } else {
            unlink($entry);
        }
    }
    rmdir($root);
});
$pdo = testPdo($dbFile);
foreach ([
    '2026_08_12_000003_plugin_schema.php',
    '2026_08_29_000004_differentiation_platform_schema.php',
    '2026_10_14_000002_plugin_permission_scope.php',
] as $migration) {
    (require dirname(__DIR__) . '/system/migrations/' . $migration)->up($pdo);
}
(require dirname(__DIR__) . '/system/migrations/2026_10_14_000002_plugin_permission_scope.php')->up($pdo);
checkDecision(in_array('scope_sha256', array_column($pdo->query('PRAGMA table_info(cms_plugin_permission_grants)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true), 'Additive scope migration is repeatable');
$pdo->exec('CREATE TABLE cms_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER, action TEXT, context_json TEXT, created_at TEXT)');
$manifest = testManifest($root, '1.0.0', ['payment.access'], ['payment.subject.register', 'payment.checkout']);
$pdo->prepare('INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([$manifest->id, $manifest->name, $manifest->version, $manifest->author, PluginLifecycle::ENABLED, 'api', json_encode($manifest->capabilities), gmdate('c'), gmdate('c')]);
$service = new PluginPermissionDecisionService($pdo, $root);
deniedDecision(static fn () => $service->assertEffective($manifest), 'Declaration and installation do not grant payment access');
(new PluginPermissionManifest($pdo))->grant($manifest->id, $manifest->version, ['payment.access'], 1);
deniedDecision(static fn () => $service->assertEffective($manifest), 'Historical grant with empty scope digest fails closed');
$first = $service->grant($manifest->id, '1.0.0', 1);
checkDecision($first['changed'] && $first['state'] === 'granted', 'Explicit decision grants current scope');
$service->assertEffective($manifest);
checkDecision($pdo->query("SELECT permission_grant_status FROM cms_plugins WHERE plugin_id = 'local.permission-fixture'")->fetchColumn() === 'granted_scoped'
    && $pdo->query("SELECT status FROM cms_plugin_permission_grants ORDER BY id DESC LIMIT 1")->fetchColumn() === 'granted_scoped',
    'New scoped grant is not recognized as an old-Core unscoped grant after code rollback');
$count = (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn();
checkDecision(!$service->grant($manifest->id, '1.0.0', 1)['changed']
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn() === $count,
    'Duplicate grant is no_change and creates no decision row');
checkDecision($service->revoke($manifest->id, '1.0.0', 1)['changed'], 'Revocation is a real transition');
deniedDecision(static fn () => $service->assertEffective($manifest), 'Revocation never falls back to older granted row');
$count = (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn();
checkDecision(!$service->revoke($manifest->id, '1.0.0', 1)['changed']
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn() === $count,
    'Duplicate revoke is no_change and creates no decision row');
checkDecision((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE context_json LIKE '%no_change%'")->fetchColumn() === 2,
    'Both duplicate attempts have no_change audit');
checkDecision($service->grant($manifest->id, '1.0.0', 1)['changed'], 'Explicit new approval can follow revocation');

$changedScope = testManifest($root, '1.0.0', ['payment.access'], ['payment.subject.register', 'payment.checkout', 'payment.entitlement.read']);
deniedDecision(static fn () => $service->assertEffective($changedScope), 'Same-version expanded payment capabilities invalidate old scope');
checkDecision($service->grant($manifest->id, '1.0.0', 1)['changed'], 'Changed declared scope needs fresh approval');
$service->assertEffective($changedScope);
deniedDecision(static fn () => $service->grant($manifest->id, '0.9.0', 1), 'Wrong expected installed version rejected');
$newVersion = testManifest($root, '1.0.1', ['payment.access'], $changedScope->capabilities);
$pdo->exec("UPDATE cms_plugins SET version = '1.0.1', permission_grant_status = 'declared' WHERE plugin_id = 'local.permission-fixture'");
deniedDecision(static fn () => $service->assertEffective($manifest), 'Old-version runtime manifest rejected after upgrade');
deniedDecision(static fn () => $service->assertEffective($newVersion), 'New version does not inherit old-version approval');
checkDecision($service->grant($manifest->id, '1.0.1', 1)['changed'], 'New version requires its own grant');
$service->assertEffective($newVersion);
$pdo->exec("UPDATE cms_plugins SET status = 'Disabled' WHERE plugin_id = 'local.permission-fixture'");
deniedDecision(static fn () => $service->assertEffective($newVersion), 'Disabled plugin cannot use payment access');
$pdo->exec("UPDATE cms_plugins SET status = 'Enabled' WHERE plugin_id = 'local.permission-fixture'");
$service->assertEffective($newVersion);
$undeclared = testManifest($root, '1.0.1', [], $newVersion->capabilities);
deniedDecision(static fn () => $service->grant($manifest->id, '1.0.1', 1), 'Undeclared payment permission cannot be granted');
deniedDecision(static fn () => $service->assertEffective($undeclared), 'Removing permission declaration denies old grant');
deniedDecision(static fn () => $service->grant('local.other', '1.0.1', 1), 'Another plugin ID cannot inherit grant');
deniedDecision(static fn () => $service->grant('../outside', '1.0.1', 1), 'Traversal identity rejected');
testManifest($root, '1.0.1', ['payment.access'], $newVersion->capabilities);

if (!function_exists('pcntl_fork')) {
    echo 'NOT RUN: two-process SQLite decision race (pcntl unavailable)' . PHP_EOL;
} else {
    $service->revoke($manifest->id, '1.0.1', 1);
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork permission test');
        }
        if ($pid === 0) {
            $childPdo = testPdo($dbFile);
            file_put_contents($root . '/ready-' . $i, 'ready');
            while (!is_file($root . '/start')) {
                usleep(1000);
            }
            try {
                $result = (new PluginPermissionDecisionService($childPdo, $root))->grant($manifest->id, '1.0.1', $i + 1);
                file_put_contents($root . '/result-' . $i, $result['changed'] ? 'changed' : 'no_change');
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($root . '/result-' . $i, 'error:' . $exception->getMessage());
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $deadline = microtime(true) + 5;
    while ((!is_file($root . '/ready-0') || !is_file($root . '/ready-1')) && microtime(true) < $deadline) {
        usleep(1000);
    }
    checkDecision(is_file($root . '/ready-0') && is_file($root . '/ready-1'), 'Two independent SQLite workers reached grant barrier');
    file_put_contents($root . '/start', 'go');
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        checkDecision(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrent grant worker completed');
    }
    $results = [file_get_contents($root . '/result-0'), file_get_contents($root . '/result-1')];
    sort($results);
    checkDecision($results === ['changed', 'no_change'], 'Concurrent duplicate grants serialize to one transition and one no_change');
    $service->assertEffective($newVersion);

    $pdo->exec('CREATE TABLE test_claims (id INTEGER PRIMARY KEY AUTOINCREMENT, outcome TEXT NOT NULL)');
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork revoke worker');
    }
    if ($pid === 0) {
        $childPdo = testPdo($dbFile);
        file_put_contents($root . '/revoke-ready', 'ready');
        while (!is_file($root . '/revoke-start')) {
            usleep(1000);
        }
        try {
            (new PluginPermissionDecisionService($childPdo, $root))->revoke($manifest->id, '1.0.1', 2);
            file_put_contents($root . '/revoke-result', 'revoked');
            exit(0);
        } catch (Throwable $exception) {
            file_put_contents($root . '/revoke-result', 'error:' . $exception->getMessage());
            exit(1);
        }
    }
    while (!is_file($root . '/revoke-ready')) {
        usleep(1000);
    }
    PaymentTransaction::beginImmediate($pdo);
    $service->assertEffective($newVersion, true);
    $pdo->exec("INSERT INTO test_claims (outcome) VALUES ('accepted-before-revoke')");
    file_put_contents($root . '/revoke-start', 'go');
    usleep(50000);
    checkDecision(!is_file($root . '/revoke-result'), 'Revoke waits while earlier authorized claim holds plugin decision lock');
    PaymentTransaction::commit($pdo);
    pcntl_waitpid($pid, $status);
    checkDecision(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0
        && file_get_contents($root . '/revoke-result') === 'revoked'
        && (int) $pdo->query('SELECT COUNT(*) FROM test_claims')->fetchColumn() === 1,
        'Claim-before-revoke keeps the already committed synthetic claim');
    deniedDecision(static fn () => $service->assertEffective($newVersion), 'Revoke denies subsequent claims');

    $service->grant($manifest->id, '1.0.1', 1);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork claim worker');
    }
    if ($pid === 0) {
        $childPdo = testPdo($dbFile);
        file_put_contents($root . '/claim-ready', 'ready');
        while (!is_file($root . '/claim-start')) {
            usleep(1000);
        }
        try {
            PaymentTransaction::beginImmediate($childPdo);
            (new PluginPermissionDecisionService($childPdo, $root))->assertEffective($newVersion, true);
            $childPdo->exec("INSERT INTO test_claims (outcome) VALUES ('incorrectly-accepted-after-revoke')");
            PaymentTransaction::commit($childPdo);
            file_put_contents($root . '/claim-result', 'unexpected-grant');
            exit(1);
        } catch (PluginException) {
            if (PaymentTransaction::isActive($childPdo)) {
                PaymentTransaction::rollback($childPdo);
            }
            file_put_contents($root . '/claim-result', 'denied');
            exit(0);
        }
    }
    while (!is_file($root . '/claim-ready')) {
        usleep(1000);
    }
    $service->revoke($manifest->id, '1.0.1', 1);
    file_put_contents($root . '/claim-start', 'go');
    pcntl_waitpid($pid, $status);
    checkDecision(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0
        && file_get_contents($root . '/claim-result') === 'denied'
        && (int) $pdo->query('SELECT COUNT(*) FROM test_claims')->fetchColumn() === 1,
        'Revoke-before-claim denies a second-process synthetic claim');
}

$historyBeforeRepeat = (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn();
(require dirname(__DIR__) . '/system/migrations/2026_10_14_000002_plugin_permission_scope.php')->up($pdo);
checkDecision((int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants')->fetchColumn() === $historyBeforeRepeat
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_permission_grants WHERE scope_sha256 IS NULL')->fetchColumn() >= 1,
    'Repeated additive migration preserves historical null-digest and all decision rows');

echo 'Plugin payment permission decision contract: ' . $checks . ' checks passed.' . PHP_EOL;
