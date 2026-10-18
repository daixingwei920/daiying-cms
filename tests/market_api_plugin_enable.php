<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Plugin\LocalPluginPackageInstaller;

$root = sys_get_temp_dir() . '/daiying-market-api-enable-' . bin2hex(random_bytes(5));
register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
});
mkdir($root . '/config', 0755, true);
mkdir($root . '/content/plugins/city.fixture', 0755, true);
mkdir($root . '/storage', 0755, true);
$db = $root . '/storage/cms.sqlite';
file_put_contents($root . '/config/app.php', "<?php\nreturn ['app' => ['version' => '1.2.85']];\n");
$manifest = [
    'plugin_id' => 'city.fixture',
    'name' => 'City Fixture',
    'version' => '0.1.1',
    'author' => 'Tests',
    'core' => ['min' => '1.2.84'],
    'php' => '8.3.0',
    'entry' => 'plugin.php',
    'trust_level' => 'api',
    'capabilities' => [],
    'migrations' => [],
];
file_put_contents($root . '/content/plugins/city.fixture/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
file_put_contents($root . '/content/plugins/city.fixture/plugin.php', "<?php\nreturn static function (): void {};\n");

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE cms_plugins (
    plugin_id TEXT PRIMARY KEY, name TEXT, version TEXT, author TEXT, status TEXT,
    trust_level TEXT, capabilities_json TEXT, installed_at TEXT, updated_at TEXT,
    source TEXT, review_status TEXT, dependencies_json TEXT
)");
$pdo->exec("CREATE TABLE cms_audit_logs (
    id INTEGER PRIMARY KEY, actor_type TEXT, actor_id INTEGER, action TEXT, context_json TEXT, created_at TEXT
)");
$pdo->exec("INSERT INTO cms_plugins VALUES (
    'city.fixture', 'City Fixture', '0.1.1', 'Tests', 'Installed', 'api', '[]', 'now', 'now',
    'official_market', 'published', '[]'
)");
$installer = new LocalPluginPackageInstaller($root, $pdo);

$installer->enable('city.fixture', 1);
if ($pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'city.fixture'")->fetchColumn() !== 'Enabled') {
    throw new RuntimeException('Published API plugin without migrations did not enable.');
}
echo "PASS: published API plugin without migrations enables without official migration trust\n";

$manifest['trust_level'] = 'trusted_php';
file_put_contents($root . '/content/plugins/city.fixture/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
$pdo->exec("UPDATE cms_plugins SET status = 'Installed', trust_level = 'trusted_php' WHERE plugin_id = 'city.fixture'");
try {
    $installer->enable('city.fixture', 1);
    throw new RuntimeException('Trusted PHP plugin enabled without official trust grant.');
} catch (\Cms\Core\Plugin\PluginException $exception) {
    if (!str_contains($exception->getMessage(), 'active official trust grant')) {
        throw $exception;
    }
}
echo "PASS: trusted PHP plugin without migrations still requires official trust grant\n";

$manifest['migrations'] = ['migrations/001.php'];
file_put_contents($root . '/content/plugins/city.fixture/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
try {
    $installer->enable('city.fixture', 1);
    throw new RuntimeException('Trusted migration enabled without official trust grant.');
} catch (\Cms\Core\Plugin\PluginException $exception) {
    if (!str_contains($exception->getMessage(), 'active official trust grant')) {
        throw $exception;
    }
}
if ($pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'city.fixture'")->fetchColumn() !== 'Installed') {
    throw new RuntimeException('Rejected trusted migration changed plugin status.');
}
echo "PASS: trusted migration still requires official trust grant\n";

$official = $manifest;
$official['plugin_id'] = 'official.fixture';
$official['trust_level'] = 'api';
$official['migrations'] = [];
mkdir($root . '/content/plugins/official.fixture', 0755, true);
file_put_contents($root . '/content/plugins/official.fixture/plugin.json', json_encode($official, JSON_THROW_ON_ERROR));
file_put_contents($root . '/content/plugins/official.fixture/plugin.php', "<?php\nreturn static function (): void {};\n");
$pdo->exec("INSERT INTO cms_plugins VALUES (
    'official.fixture', 'Official Fixture', '0.1.1', 'Tests', 'Installed', 'api', '[]', 'now', 'now',
    'official_market', 'published', '[]'
)");
try {
    $installer->enable('official.fixture', 1);
    throw new RuntimeException('Reserved official plugin enabled without official trust grant.');
} catch (\Cms\Core\Plugin\PluginException $exception) {
    if (!str_contains($exception->getMessage(), 'active official trust grant')) {
        throw $exception;
    }
}
echo "PASS: reserved official identity still requires official trust grant\n";
