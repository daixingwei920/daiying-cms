<?php

declare(strict_types=1);

use Cms\Core\Plugin\LocalPluginPackageInstaller;

$root = dirname(__DIR__);
require $root . '/system/core/Bootstrap/autoload.php';

$tmp = sys_get_temp_dir() . '/daiying_plugin_enable_runtime_version_' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0755, true);
mkdir($tmp . '/content/plugins/runtime.version', 0755, true);
mkdir($tmp . '/storage/updates', 0755, true);
$db = $tmp . '/cms.sqlite';

file_put_contents($tmp . '/config/app.php', "<?php\nreturn ['app' => ['version' => '1.2.66'], 'database' => ['dsn' => 'sqlite:" . addslashes($db) . "']];\n");
file_put_contents($tmp . '/storage/updates/current-release.json', json_encode([
    'release_id' => 'runtime-test',
    'version' => '1.2.71',
    'path' => $tmp . '/storage/updates/releases/runtime-test',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/content/plugins/runtime.version/plugin.json', json_encode([
    'plugin_id' => 'runtime.version',
    'name' => 'Runtime Version',
    'version' => '1.0.0',
    'author' => 'Tests',
    'core' => ['min' => '1.2.70'],
    'php' => '8.3.0',
    'entry' => 'plugin.php',
    'trust_level' => 'api',
    'capabilities' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/content/plugins/runtime.version/plugin.php', "<?php\nreturn static function (): void {};\n");

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE cms_plugins (
    plugin_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    version TEXT NOT NULL,
    author TEXT NOT NULL,
    status TEXT NOT NULL,
    trust_level TEXT NOT NULL,
    capabilities_json TEXT NOT NULL,
    installed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'local_unreviewed',
    review_status TEXT NOT NULL DEFAULT 'unreviewed',
    dependencies_json TEXT NOT NULL DEFAULT '[]',
    optional_dependencies_json TEXT NOT NULL DEFAULT '[]',
    data_policy_json TEXT NULL,
    data_schema_version TEXT NULL,
    dormant_data_json TEXT NULL,
    removed_at TEXT NULL,
    last_error TEXT NULL,
    table_prefixes_json TEXT NULL,
    runtime_status TEXT NOT NULL DEFAULT 'OK',
    runtime_failure_count INTEGER NOT NULL DEFAULT 0,
    runtime_last_failure_at TEXT NULL,
    runtime_error_summary TEXT NULL,
    declared_permissions_json TEXT NULL,
    permission_grant_status TEXT NOT NULL DEFAULT 'legacy'
)");
$pdo->exec("CREATE TABLE cms_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type TEXT NOT NULL,
    actor_id INTEGER NULL,
    action TEXT NOT NULL,
    context_json TEXT NOT NULL,
    created_at TEXT NOT NULL
)");
$pdo->exec("INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at, source, review_status, dependencies_json)
    VALUES ('runtime.version', 'Runtime Version', '1.0.0', 'Tests', 'Installed', 'api', '[]', 'now', 'now', 'local_unreviewed', 'unreviewed', '[]')");

(new LocalPluginPackageInstaller($tmp, $pdo))->enable('runtime.version', 1);

$status = (string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'runtime.version'")->fetchColumn();
if ($status !== 'Enabled') {
    fwrite(STDERR, "Expected Enabled, got {$status}\n");
    exit(1);
}

echo "PASS plugin enable uses active runtime Core version\n";
