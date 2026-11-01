<?php
/** Offline Core-owned index migration regression. Never connects to a production database. */
declare(strict_types=1);
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\PluginDataStore;

$config = getenv('CMS_PLUGIN_DATA_INDEX_TEST_CONFIG');
if ($config) {
    $fixture = require $config;
    if (!str_starts_with((string) ($fixture['socket'] ?? ''), '/root/daiying-pr80-')
        || !preg_match('/^cms_index_fixture_[a-z0-9_]+$/D', $fixture['database'] ?? '')) {
        throw new RuntimeException('Only an explicitly isolated private-server MySQL socket fixture is allowed.');
    }
    $pdo = new PDO('mysql:unix_socket=' . $fixture['socket'] . ';dbname=' . $fixture['database'] . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('MySQL fixture must be empty; never drop existing tables.');
    }
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$migration = require dirname(__DIR__) . '/system/migrations/2026_11_01_000001_plugin_data_scope_index.php';
$base = require dirname(__DIR__) . '/system/migrations/2026_08_12_000003_plugin_schema.php';
$base->up($pdo);
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "[PASS] $label\n";
};
$drop = static function () use ($pdo, $driver): void {
    $pdo->exec('DROP INDEX idx_plugin_data_scope_id' . ($driver === 'mysql' ? ' ON cms_plugin_data' : ''));
};
$schema = static fn (): array => $driver === 'sqlite'
    ? $pdo->query("SELECT * FROM sqlite_master WHERE name='idx_plugin_data_scope_id'")->fetchAll()
    : $pdo->query('SHOW CREATE TABLE cms_plugin_data')->fetchAll();
$store = new PluginDataStore($pdo, 'fixture.novel');
$pdo->beginTransaction();
for ($book=0; $book<32; $book++) {
    $store->put('novel_books', 'book-' . $book, ['title'=>'TEST ONLY book ' . $book]);
    for ($chapter=1; $chapter<=400; $chapter++) {
        foreach (['index','workflow','pubs'] as $kind) {
            $store->put('novel_' . $kind . '_' . $book, (string) $chapter, ['number'=>$chapter,'words'=>1000,'status'=>'published']);
        }
    }
}
$store->put('novel_index_31', '400', ['number'=>400,'words'=>1234,'status'=>'published']);
(new PluginDataStore($pdo, 'fixture.other'))->put('novel_index_31', '400', ['private'=>'must stay isolated']);
$pdo->commit();
$hash = static fn (): string => hash('sha256', json_encode($pdo->query('SELECT * FROM cms_plugin_data ORDER BY id')->fetchAll(), JSON_THROW_ON_ERROR));
$beforeHash = $hash();
$before = $store->all('novel_index_31');
$query = 'SELECT data_key,payload_json FROM cms_plugin_data WHERE plugin_id=\'fixture.novel\' AND data_type=\'novel_index_31\' ORDER BY id DESC';
$bench = static function () use ($pdo, $query): float {
    $times=[];
    for ($i=0; $i<5; $i++) {
        $start=hrtime(true); $pdo->query($query)->fetchAll(); $times[]=(hrtime(true)-$start)/1e6;
    }
    sort($times); return $times[2];
};
$without = $bench();
$runner = new MigrationRunner($pdo, [$migration]);
$check($runner->run() === 1, 'Fresh index is installed and recorded by the native Core migration runner');
$check($runner->run() === 0, 'A repeated native upgrade does not reapply the migration');
$afterSchema = $schema();
$pdo->prepare('DELETE FROM cms_core_migrations WHERE migration_id=?')->execute([$migration->id()]);
$check($runner->run() === 1 && $schema() === $afterSchema,
    'Native upgrade adopts an operator-created matching index without dropping or rebuilding it');
$check($hash() === $beforeHash && $store->all('novel_index_31') === $before, 'All payloads, duplicates, plugin isolation and newest-first ordering are preserved');
$check(json_decode($store->all('novel_index_31')[0]['payload_json'], true)['words'] === 1234, 'Newest duplicate remains the winning record');
$with = $bench();
$plan = $pdo->query(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ') . $query)->fetchAll();
$encoded = json_encode($plan, JSON_THROW_ON_ERROR);
$check(str_contains($encoded, 'idx_plugin_data_scope_id') && !str_contains(strtoupper($encoded), 'TEMP B-TREE'), 'Scoped descending query uses the index without a temporary sort');
$drop();
$pdo->exec('CREATE INDEX idx_plugin_data_scope_id ON cms_plugin_data(data_type,plugin_id,id DESC)');
$wrong = $schema();
try {
    $migration->up($pdo);
    throw new LogicException('A conflicting index was accepted');
} catch (RuntimeException $error) {
    $check(str_contains($error->getMessage(), 'definition does not match') && $schema() === $wrong && $hash() === $beforeHash,
        'A same-name conflicting definition fails closed without dropping the index or modifying data');
}
$drop();
if ($driver === 'sqlite') {
    foreach (['CREATE UNIQUE INDEX idx_plugin_data_scope_id ON cms_plugin_data(plugin_id,data_type,id DESC)',
              "CREATE INDEX idx_plugin_data_scope_id ON cms_plugin_data(plugin_id,data_type,id DESC) WHERE plugin_id='fixture.novel'"] as $sql) {
        $pdo->exec($sql);
        try { $migration->up($pdo); throw new LogicException('Unsafe index accepted'); }
        catch (RuntimeException $error) { $check(str_contains($error->getMessage(), 'definition does not match'), 'Unique or partial same-name index is rejected'); }
        $drop();
    }
}
$migration->up($pdo);
$check($hash() === $beforeHash && $pdo->query('SELECT COUNT(*) FROM cms_plugin_data')->fetchColumn() == 38434,
    '32 books and 12800 chapter fixtures survive migration and conflict checks');
echo json_encode(['status'=>'PASS','driver'=>$driver,'rows'=>38434,'books'=>32,'chapters'=>12800,'median_without_index_ms'=>$without,'median_with_index_ms'=>$with,'query_plan'=>$plan], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . "\n";
