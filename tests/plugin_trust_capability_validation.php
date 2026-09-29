<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\Capability;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginTableOwnership;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$root = sys_get_temp_dir() . '/daiying-plugin-trust-' . bin2hex(random_bytes(4));
mkdir($root . '/content/plugins', 0777, true);
mkdir($root . '/storage/logs', 0777, true);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE cms_plugins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plugin_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    version TEXT NOT NULL,
    author TEXT NOT NULL,
    status TEXT NOT NULL,
    trust_level TEXT NOT NULL,
    capabilities_json TEXT NOT NULL,
    installed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    source TEXT,
    review_status TEXT,
    dependencies_json TEXT,
    declared_permissions_json TEXT,
    permission_grant_status TEXT,
    table_prefixes_json TEXT,
    last_error TEXT
)');

try {
    $writePlugin = static function (string $id, array $manifest) use ($root): void {
        $dir = $root . '/content/plugins/' . $id;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/plugin.php', "<?php\nreturn static function (): void {};\n");
        file_put_contents($dir . '/plugin.json', json_encode($manifest + [
            'plugin_id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'author' => 'Test',
            'core' => ['min' => '1.2.70'],
            'php' => '>=8.3.0',
            'entry' => 'plugin.php',
            'trust_level' => 'api',
            'capabilities' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    };

    $writePlugin('local.storage.baidu', [
        'type' => 'storage_provider',
        'capabilities' => ['storage.plugin', 'baidu_storage.manage'],
        'capability_namespaces' => ['baidu_storage', 'storage_baidu'],
        'table_prefixes' => ['baidu_storage_'],
    ]);
    $writePlugin('vendor.good', []);
    $writePlugin('vendor.badprefix', [
        'table_prefixes' => ['baidu_storage_'],
    ]);

    $registry = new OfficialPluginRegistry($root, $pdo);
    $check($registry->isTrustedBundled('local.storage.baidu', $root . '/content/plugins/local.storage.baidu'), 'bundled Baidu storage plugin is trusted by exact manifest identity and directory.');
    $check(in_array('baidu_storage_', $registry->tablePrefixes('local.storage.baidu'), true), 'bundled Baidu storage trust record grants its reserved table prefix.');
    $prefixes = (new PluginTableOwnership($pdo, $registry))->prefixesFor([
        'plugin_id' => 'local.storage.baidu',
        'table_prefixes' => ['baidu_storage_'],
    ], true);
    $check($prefixes === ['baidu_storage_'], 'trusted bundled plugin can use its registered reserved table prefix.');

    try {
        Capability::assertPluginAllowed('daiying.webclip', ['admin.menu'], []);
        $check(false, 'admin.menu is rejected as an unknown capability.');
    } catch (PluginException $exception) {
        $check($exception->getMessage() === 'Unknown capability: admin.menu', 'admin.menu rejection names the unknown capability.');
    }

    $manager = new PluginManager(
        $root . '/content/plugins',
        $pdo,
        new FileLogger($root . '/storage/logs/app.log'),
        new EventDispatcher(),
        new BlockRegistry(),
        null,
        $registry,
    );
    $count = $manager->syncDiscovered();
    $rows = $pdo->query('SELECT plugin_id, source, review_status, table_prefixes_json FROM cms_plugins ORDER BY plugin_id')->fetchAll();
    $ids = array_map(static fn (array $row): string => (string) $row['plugin_id'], $rows);
    $check($count === 2, 'syncDiscovered installs valid plugins and isolates the invalid reserved-prefix plugin.');
    $check($ids === ['local.storage.baidu', 'vendor.good'], 'invalid plugin is not inserted while unrelated valid plugins continue.');
    $baidu = $pdo->query("SELECT source, review_status, table_prefixes_json FROM cms_plugins WHERE plugin_id = 'local.storage.baidu'")->fetch();
    $baiduPrefixes = is_array($baidu) ? json_decode((string) $baidu['table_prefixes_json'], true) : [];
    $check(($baidu['source'] ?? '') === 'bundled_official' && ($baidu['review_status'] ?? '') === 'official_trusted', 'bundled Baidu storage is recorded as trusted official.');
    $check($baiduPrefixes === ['baidu_storage_'], 'bundled Baidu storage receives the trusted prefix in cms_plugins.');
    $log = (string) file_get_contents($root . '/storage/logs/app.log');
    $check(str_contains($log, 'vendor.badprefix') && str_contains($log, 'Plugin table prefix is reserved.'), 'isolated plugin failure is logged with plugin id and reason.');
} finally {
    plugin_trust_capability_remove_dir($root);
}

if ($failures > 0) {
    exit(1);
}

echo "Plugin trust and capability validation tests PASS\n";

function plugin_trust_capability_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($dir);
}
