<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Market\ExtensionSource;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketApiClientInterface;
use Cms\Core\Market\MarketItem;
use Cms\Core\Plugin\OfficialExtensionTrustGrant;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** @return array{public:string,secret:string} */
function admin_market_trust_grant_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    if (!function_exists('sodium_crypto_sign_seed_keypair')) {
        throw new RuntimeException('Sodium is required for signed trust grant tests.');
    }
    $keypair = sodium_crypto_sign_seed_keypair(str_repeat("\x54", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $keys = [
        'public' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        'secret' => sodium_crypto_sign_secretkey($keypair),
    ];

    return $keys;
}

/** @param list<string> $capabilityNamespaces @param list<string> $tablePrefixes @param list<string> $routePrefixes */
function admin_market_trust_grant(string $extensionId, array $capabilityNamespaces, array $tablePrefixes, array $routePrefixes, string $trustLevel = 'api'): array
{
    $payload = [
        'schema_version' => 1,
        'extension_id' => $extensionId,
        'extension_type' => 'plugin',
        'publisher' => 'official',
        'source' => 'official_market',
        'trust_level' => $trustLevel,
        'capability_namespaces' => $capabilityNamespaces,
        'table_prefixes' => $tablePrefixes,
        'route_prefixes' => $routePrefixes,
        'admin_menu' => ['section' => '扩展', 'label' => 'External Storage'],
        'provider_capabilities' => [],
        'status' => 'active',
        'issued_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + 2592000),
    ];
    $canonical = OfficialExtensionTrustGrant::canonicalPayload($payload);
    $signature = sodium_crypto_sign_detached($canonical, admin_market_trust_grant_keys()['secret']);

    return [
        'payload' => $payload,
        'signature' => base64_encode($signature),
        'grant_fingerprint' => OfficialExtensionTrustGrant::fingerprint($payload),
        'key_id' => 'admin-market-test-key',
    ];
}

/** @return array{root:string,pdo:PDO,settings:Settings} */
function admin_market_trust_grant_root(): array
{
    $keys = admin_market_trust_grant_keys();
    $root = sys_get_temp_dir() . '/daiying-admin-market-trust-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    mkdir($root . '/content/plugins', 0777, true);
    mkdir($root . '/content/themes', 0777, true);
    mkdir($root . '/storage/logs', 0777, true);
    mkdir($root . '/storage/market/tmp', 0777, true);
    $db = $root . '/cms.sqlite';
    file_put_contents($root . '/config/app.php', "<?php\nreturn [\n    'app' => ['version' => '1.2.51'],\n    'database' => ['dsn' => 'sqlite:" . addslashes($db) . "', 'username' => '', 'password' => '', 'options' => []],\n    'updates' => ['public_key' => '" . $keys['public'] . "', 'key_id' => 'admin-market-test-key'],\n];\n");
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE cms_admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE cms_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        ip_address TEXT NOT NULL,
        success INTEGER NOT NULL,
        attempted_at TEXT NOT NULL
    )');
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
        optional_dependencies_json TEXT,
        data_policy_json TEXT,
        data_schema_version TEXT,
        dormant_data_json TEXT,
        removed_at TEXT,
        last_error TEXT,
        table_prefixes_json TEXT
    )');
    $pdo->exec('CREATE TABLE cms_extension_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        extension_id TEXT NOT NULL,
        extension_type TEXT NOT NULL,
        source TEXT NOT NULL,
        market_id TEXT,
        version TEXT NOT NULL,
        installed_at TEXT NOT NULL,
        metadata_json TEXT
    )');
    $pdo->exec('CREATE TABLE cms_market_install_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        market_id TEXT NOT NULL,
        extension_id TEXT NOT NULL,
        extension_type TEXT NOT NULL,
        status TEXT NOT NULL,
        plan_json TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE cms_plugin_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plugin_id TEXT NOT NULL,
        plugin_version TEXT NOT NULL,
        migration_id TEXT NOT NULL,
        checksum TEXT NOT NULL,
        status TEXT NOT NULL,
        affected_objects_json TEXT,
        started_at TEXT,
        completed_at TEXT,
        rollback_at TEXT,
        error_code TEXT,
        error_summary TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE cms_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_type TEXT NOT NULL,
        actor_id INTEGER,
        action TEXT NOT NULL,
        context_json TEXT,
        created_at TEXT NOT NULL
    )');
    $migration = require CMS_ROOT . '/system/migrations/2026_09_10_000001_extension_trust_grants.php';
    $migration['up']($pdo);

    return ['root' => $root, 'pdo' => $pdo, 'settings' => Settings::load($root)];
}

/** @param array<string,string> $files @return array{path:string,sha256:string} */
function admin_market_trust_grant_package(string $extensionId, string $version, array $files): array
{
    if (!isset($files['content/plugins/' . $extensionId . '/plugin.php'])) {
        $files['content/plugins/' . $extensionId . '/plugin.php'] = "<?php\nreturn static function (): void {};\n";
    }
    $zipPath = sys_get_temp_dir() . '/daiying-admin-market-package-' . bin2hex(random_bytes(5)) . '.zip';
    $manifestFiles = [];
    foreach ($files as $path => $content) {
        $manifestFiles[$path] = hash('sha256', $content);
    }
    $package = [
        'extension_id' => $extensionId,
        'type' => 'plugin',
        'version' => $version,
        'source' => ExtensionSource::OFFICIAL_MARKET,
        'review_status' => 'published',
        'core' => '>=1.2.0',
        'php' => '>=8.3.0',
        'dependencies' => [],
        'files' => $manifestFiles,
    ];
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create admin market package fixture.');
    }
    $zip->addFromString('market-package.json', json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();

    return ['path' => $zipPath, 'sha256' => (string) hash_file('sha256', $zipPath)];
}

function admin_market_plugin_json(string $pluginId, string $version): string
{
    return json_encode([
        'plugin_id' => $pluginId,
        'name' => 'External Storage Test',
        'version' => $version,
        'author' => 'Daiying CMS',
        'package_type' => 'plugin',
        'type' => 'plugin',
        'entry' => 'plugin.php',
        'core' => ['min' => '1.2.0'],
        'php' => '>=8.3.0',
        'trust_level' => 'api',
        'capabilities' => [],
        'capability_namespaces' => ['external_storage'],
        'table_prefixes' => ['external_storage_'],
        'migrations' => ['migrations/001_external_storage.php'],
        'data_policy' => ['uninstall' => 'retain'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function admin_market_plugin_migration(): string
{
    return <<<'PHP'
<?php
return [
    'id' => 'official_storage_external_001',
    'affected_objects' => ['table:external_storage_items'],
    'up' => static function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE external_storage_items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');
    },
    'down' => static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS external_storage_items');
    },
];
PHP;
}

function admin_market_trust_grant_login(): string
{
    $_SESSION = [];
    $_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));

    return $_SESSION['_csrf'];
}

function admin_market_trust_grant_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

final class AdminMarketTrustGrantClient implements MarketApiClientInterface
{
    /** @param array<string,mixed> $trustGrant */
    public function __construct(
        private readonly string $zipPath,
        private readonly string $sha256,
        private readonly array $trustGrant,
        private readonly string $marketId = 'official.storage.external:0.1.4:stable',
    ) {
    }

    /** @return list<MarketItem> */
    public function search(string $type, string $query = '', bool $forceRefresh = false): array
    {
        return [];
    }

    public function authorizeInstall(string $marketId, string $siteId, string $licenseKey = ''): InstallAuthorization
    {
        return new InstallAuthorization(
            'admin-market-token',
            'file://' . $this->zipPath,
            gmdate('c', time() + 3600),
            $this->sha256,
            $this->marketId,
            $this->trustGrant,
        );
    }

    /** @return array<string, mixed> */
    public function detail(string $marketId, string $version = '', bool $forceRefresh = false): array
    {
        return ['version' => ['product_id' => 'official.storage.external']];
    }

    /** @return array<string, mixed> */
    public function diagnostics(bool $forceRefresh = false): array
    {
        return ['api_status' => 'ok'];
    }

    public function clearCache(): void
    {
    }
}

$extensionId = 'official.storage.external';
$files = [
    'content/plugins/' . $extensionId . '/plugin.json' => admin_market_plugin_json($extensionId, '0.1.4'),
    'content/plugins/' . $extensionId . '/migrations/001_external_storage.php' => admin_market_plugin_migration(),
];
$package = admin_market_trust_grant_package($extensionId, '0.1.4', $files);
$grant = admin_market_trust_grant(
    $extensionId,
    ['external_storage'],
    ['external_storage_'],
    ['/admin/external-storage', '/admin/external-storage/save', '/admin/external-storage/test', '/admin/external-storage/browser'],
);

$fixture = admin_market_trust_grant_root();
$csrf = admin_market_trust_grant_login();
$controller = new AdminController(
    $fixture['settings'],
    new FileLogger($fixture['root'] . '/storage/logs/app.log'),
    $fixture['root'],
    new AdminMarketTrustGrantClient($package['path'], $package['sha256'], $grant),
);
$response = $controller->marketAuthorize(new Request('POST', '/admin/market/authorize', [], [
    '_csrf' => $csrf,
    'market_id' => 'official.storage.external:0.1.4:stable',
    'product_id' => $extensionId,
]));
$check($response->status() === 200 && str_contains($response->body(), '安装完成'), 'admin market authorize path preserves signed trust grant and installs official API plugin.');
$check(is_dir($fixture['root'] . '/content/plugins/' . $extensionId), 'authorized official market plugin is moved into content/plugins.');
$check((int) $fixture['pdo']->query("SELECT COUNT(*) FROM external_storage_items")->fetchColumn() === 0, 'authorized official market plugin migration created its own table.');
$check((int) $fixture['pdo']->query("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = 'official.storage.external' AND status = 'applied'")->fetchColumn() === 1, 'authorized official market plugin records applied migration.');
$check((int) $fixture['pdo']->query("SELECT COUNT(*) FROM cms_extension_trust_grants WHERE extension_id = 'official.storage.external' AND status = 'active'")->fetchColumn() === 1, 'authorized official market plugin stores signed trust grant.');
admin_market_trust_grant_remove_tree($fixture['root']);

$fixture = admin_market_trust_grant_root();
$csrf = admin_market_trust_grant_login();
$controller = new AdminController(
    $fixture['settings'],
    new FileLogger($fixture['root'] . '/storage/logs/app.log'),
    $fixture['root'],
    new AdminMarketTrustGrantClient($package['path'], $package['sha256'], []),
);
$response = $controller->marketAuthorize(new Request('POST', '/admin/market/authorize', [], [
    '_csrf' => $csrf,
    'market_id' => 'official.storage.external:0.1.4:stable',
    'product_id' => $extensionId,
]));
$check($response->status() === 400 && str_contains($response->body(), 'signed official trust grant'), 'admin market authorize path still rejects official plugin claims without trust grant.');
admin_market_trust_grant_remove_tree($fixture['root']);

$fixture = admin_market_trust_grant_root();
$csrf = admin_market_trust_grant_login();
$controller = new AdminController(
    $fixture['settings'],
    new FileLogger($fixture['root'] . '/storage/logs/app.log'),
    $fixture['root'],
);
$response = $controller->marketInstall(new Request('POST', '/admin/market/install', [], [
    '_csrf' => $csrf,
    'token' => 'manual-market-token',
    'package_url' => 'file://' . $package['path'],
    'expires_at' => gmdate('c', time() + 3600),
    'package_sha256' => $package['sha256'],
    'market_id' => 'official.storage.external:0.1.4:stable',
    'package_path' => $package['path'],
    'trust_grant' => json_encode($grant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]));
$check($response->status() === 200 && str_contains($response->body(), '扩展安装完成'), 'legacy admin market install path accepts serialized trust_grant.');
admin_market_trust_grant_remove_tree($fixture['root']);

@unlink($package['path']);
$_SESSION = [];

if ($failures > 0) {
    fwrite(STDERR, $failures . " admin market trust grant authorization checks failed.\n");
    exit(1);
}

echo "Admin market trust grant authorization tests passed.\n";
