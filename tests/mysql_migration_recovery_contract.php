<?php

declare(strict_types=1);

$configFile = getenv('CMS_MYSQL_RECOVERY_CONFIG');
if (!$configFile) {
    echo "[SKIP] MySQL recovery fixture configuration is not supplied.\n";
    exit(0);
}
$c = require $configFile;
if (($c['host'] ?? '') !== '127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/', $c['database'] ?? '')) {
    throw new RuntimeException('Disposable loopback database required.');
}
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Migration\MysqlHistoricalMigrationDialect;
function recovery_pdo(): PDO
{
    global $c;
    return new PDO('mysql:host=127.0.0.1;dbname=' . $c['database'] . ';charset=utf8mb4', $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
$pdo = recovery_pdo();
$checks = [];
function recovery_check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
}
$killMigration = ['id' => 'test_mysql_interrupted', 'up' => static function (PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS mysql_recovery_interrupted (id INT PRIMARY KEY) ENGINE=InnoDB');
    if (getenv('CMS_MYSQL_KILL_CHILD') === '1') {
        posix_kill(getmypid(), SIGKILL);
    }
}];
if (getenv('CMS_MYSQL_KILL_CHILD') === '1') {
    (new MigrationRunner($pdo, [$killMigration]))->run();
    exit(3);
}
try {
    recovery_check((int) $pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0, 'empty_recovery_fixture');
    $fail = true;
    $chain = [
        ['id' => 'test_mysql_first', 'up' => static fn (PDO $db) => $db->exec('CREATE TABLE IF NOT EXISTS mysql_recovery_first (id INT PRIMARY KEY) ENGINE=InnoDB')],
        ['id' => 'test_mysql_partial', 'up' => static function (PDO $db) use (&$fail): void {
            $db->exec('CREATE TABLE IF NOT EXISTS mysql_recovery_partial (id INT PRIMARY KEY) ENGINE=InnoDB');
            if ($fail) {
                $db->exec('INSERT INTO table_that_does_not_exist VALUES (1)');
            }
        }],
    ];
    try {
        (new MigrationRunner($pdo, $chain))->run();
        throw new LogicException('Fault was not triggered.');
    } catch (RuntimeException $error) {
        recovery_check(str_contains($error->getMessage(), 'Migration failed: test_mysql_partial'), 'mid_chain_database_exception_reported');
    }
    recovery_check((int) $pdo->query("SELECT COUNT(*) FROM cms_core_migrations WHERE migration_id='test_mysql_partial'")->fetchColumn() === 0, 'failed_migration_not_marked_applied');
    recovery_check($pdo->query('SELECT COUNT(*) FROM mysql_recovery_partial')->fetchColumn() == 0, 'mysql_ddl_survives_failure_no_false_rollback_claim');
    $fail = false;
    recovery_check((new MigrationRunner($pdo, $chain))->run() === 1 && (new MigrationRunner($pdo, $chain))->run() === 0, 'idempotent_partial_migration_safe_retry');
    $pdo->exec('CREATE TABLE mysql_recovery_index (value TEXT) ENGINE=InnoDB');
    $badIndex = ['id' => 'test_mysql_bad_index', 'up' => static fn (PDO $db) => $db->exec('CREATE INDEX mysql_recovery_invalid_index ON mysql_recovery_index(value)')];
    try {
        (new MigrationRunner($pdo, [$badIndex]))->run();
        throw new LogicException('Invalid index unexpectedly succeeded.');
    } catch (RuntimeException $error) {
        recovery_check(str_contains($error->getPrevious()?->getMessage() ?? '', '1170'), 'invalid_index_requires_schema_resolution');
    }
    $id = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    recovery_pdo()->exec('KILL CONNECTION ' . $id);
    try {
        (new MigrationRunner($pdo, [['id' => 'test_mysql_disconnect', 'up' => static fn () => null]]))->run();
        throw new LogicException('Killed connection was accepted.');
    } catch (PDOException $error) {
        recovery_check(str_contains($error->getMessage(), '2006') || str_contains($error->getMessage(), '2013'), 'actual_database_connection_interruption');
    }
    $pdo = recovery_pdo();
    recovery_check((new MigrationRunner($pdo, $chain))->run() === 0, 'reconnect_preserves_migration_progress');
    putenv('CMS_MYSQL_KILL_CHILD=1');
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    putenv('CMS_MYSQL_KILL_CHILD');
    recovery_check($exit !== 0 && (int) $pdo->query("SELECT COUNT(*) FROM cms_core_migrations WHERE migration_id='test_mysql_interrupted'")->fetchColumn() === 0, 'sigkill_no_false_migration_completion');
    recovery_check((new MigrationRunner($pdo, [$killMigration]))->run() === 1, 'restart_after_sigkill_idempotent_recovery');
    $pdo->exec('CREATE TABLE cms_review_submissions (id TEXT NOT NULL) ENGINE=InnoDB');
    $file = dirname(__DIR__) . '/system/migrations/2026_08_30_000002_review_submission_client_schema.php';
    $migration = require $file;
    try {
        MysqlHistoricalMigrationDialect::run($pdo, $migration);
        throw new LogicException('Foreign schema accepted.');
    } catch (RuntimeException $error) {
        recovery_check(str_contains($error->getMessage(), 'schema is incompatible'), 'incompatible_existing_table_fail_closed');
    }
    $temp = sys_get_temp_dir() . '/mysql-frozen-' . bin2hex(random_bytes(4));
    mkdir($temp);
    $copy = $temp . '/' . basename($file);
    file_put_contents($copy, file_get_contents($file) . "\n// tampered fixture\n");
    try {
        MysqlHistoricalMigrationDialect::run($pdo, require $copy);
        throw new LogicException('Changed frozen migration accepted.');
    } catch (RuntimeException $error) {
        recovery_check(str_contains($error->getMessage(), 'checksum mismatch'), 'changed_frozen_source_refused');
    } finally {
        unlink($copy);
        rmdir($temp);
    }
    $unknown = new class implements Cms\Core\Migration\MigrationInterface {
        public function id(): string { return 'unknown_dialect_migration'; }
        public function up(PDO $pdo): void { throw new RuntimeException('Unknown original logic must run.'); }
    };
    recovery_check(!MysqlHistoricalMigrationDialect::run($pdo, $unknown), 'unknown_migration_never_adapted');
    echo json_encode(['status' => 'PASS', 'checks' => $checks, 'limitations' => ['MySQL DDL is not transactionally rolled back.', 'Only verified idempotent partial migrations can be retried automatically.', 'Foreign schema or invalid index needs a restore point or explicit repair.', 'Interrupted running history rows remain audit evidence.']], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'FAIL', 'checks' => $checks, 'error' => $error->getMessage(), 'cause' => $error->getPrevious()?->getMessage()], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
