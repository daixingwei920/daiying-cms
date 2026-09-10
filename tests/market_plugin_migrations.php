<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Market\ExtensionSource;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketException;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialExtensionTrustGrant;
use Cms\Core\Plugin\PluginLifecycle;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

/** @return array{root:string,pdo:PDO} */
function market_plugin_migration_fixture_root(): array
{
    $keys = market_plugin_test_keys();
    $root = sys_get_temp_dir() . '/daiying-market-migrations-' . bin2hex(random_bytes(5));
    mkdir($root . '/config', 0777, true);
    mkdir($root . '/content/plugins', 0777, true);
    mkdir($root . '/content/themes', 0777, true);
    mkdir($root . '/storage/market/tmp', 0777, true);
    file_put_contents($root . '/config/app.php', "<?php\nreturn ['app' => ['version' => '1.2.48'], 'updates' => ['public_key' => '" . $keys['public'] . "', 'key_id' => 'test-key']];\n");

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    return ['root' => $root, 'pdo' => $pdo];
}

/** @return array{public:string,secret:string} */
function market_plugin_test_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    if (!function_exists('sodium_crypto_sign_seed_keypair')) {
        throw new RuntimeException('Sodium is required for signed trust grant tests.');
    }
    $keypair = sodium_crypto_sign_seed_keypair(str_repeat("\x4d", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $keys = [
        'public' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        'secret' => sodium_crypto_sign_secretkey($keypair),
    ];

    return $keys;
}

/** @param list<string> $capabilityNamespaces @param list<string> $tablePrefixes */
function market_plugin_trust_grant(string $extensionId, array $capabilityNamespaces, array $tablePrefixes, string $status = 'active', string $expiresAt = '+30 days'): array
{
    $payload = [
        'schema_version' => 1,
        'extension_id' => $extensionId,
        'extension_type' => 'plugin',
        'publisher' => 'official',
        'source' => 'official_market',
        'trust_level' => 'trusted_php',
        'capability_namespaces' => $capabilityNamespaces,
        'table_prefixes' => $tablePrefixes,
        'route_prefixes' => ['/admin/' . str_replace(['official.', '.'], ['', '-'], $extensionId)],
        'admin_menu' => ['section' => '扩展', 'label' => $extensionId],
        'provider_capabilities' => [],
        'status' => $status,
        'issued_at' => gmdate('c'),
        'expires_at' => gmdate('c', strtotime($expiresAt) ?: (time() + 2592000)),
    ];
    $canonical = OfficialExtensionTrustGrant::canonicalPayload($payload);
    $signature = sodium_crypto_sign_detached($canonical, market_plugin_test_keys()['secret']);

    return [
        'payload' => $payload,
        'signature' => base64_encode($signature),
        'grant_fingerprint' => OfficialExtensionTrustGrant::fingerprint($payload),
        'key_id' => 'test-key',
    ];
}

function market_plugin_migration_remove_tree(string $path): void
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

/** @param array<string,string> $files */
function market_plugin_migration_package(string $extensionId, string $version, array $files, string $type = 'plugin', array $trustGrant = []): array
{
    if (in_array($type, ['plugin', 'payment_provider'], true) && !isset($files['content/plugins/' . $extensionId . '/plugin.php'])) {
        $files['content/plugins/' . $extensionId . '/plugin.php'] = "<?php\nreturn static function (): void {};\n";
    }
    $zipPath = sys_get_temp_dir() . '/daiying-market-package-' . bin2hex(random_bytes(5)) . '.zip';
    $manifestFiles = [];
    foreach ($files as $path => $content) {
        $manifestFiles[$path] = hash('sha256', $content);
    }
    $package = [
        'extension_id' => $extensionId,
        'type' => $type,
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
        throw new RuntimeException('Unable to create market package.');
    }
    $zip->addFromString('market-package.json', json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();

    $sha = hash_file('sha256', $zipPath);

    return [$zipPath, new InstallAuthorization('test-token', 'https://updates.daiyingcms.com/test.zip', gmdate('c', time() + 3600), (string) $sha, 'test-market', $trustGrant)];
}

function market_plugin_manifest(string $pluginId, string $version, array $migrations, array $tablePrefixes = ['market_test_'], array $capabilityNamespaces = []): string
{
    if ($capabilityNamespaces === [] && str_starts_with($pluginId, 'official.')) {
        $capabilityNamespaces = [explode('.', $pluginId)[1] ?? 'official'];
    }

    return json_encode([
        'plugin_id' => $pluginId,
        'name' => 'Migration Test Plugin',
        'version' => $version,
        'author' => 'Daiying CMS',
        'package_type' => 'plugin',
        'core' => ['min' => '1.2.0'],
        'php' => '>=8.3.0',
        'entry' => 'plugin.php',
        'trust_level' => str_starts_with($pluginId, 'official.') ? 'trusted_php' : 'api',
        'capabilities' => [],
        'capability_namespaces' => $capabilityNamespaces,
        'table_prefixes' => $tablePrefixes,
        'migrations' => $migrations,
        'data_policy' => ['uninstall' => 'retain'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function market_plugin_migration_file(string $id, string $table, bool $fail = false): string
{
    $failure = $fail ? "throw new \\RuntimeException('planned migration failure');" : '';

    return <<<PHP
<?php
return [
    'id' => '{$id}',
    'affected_objects' => ['table:{$table}'],
    'up' => static function (PDO \$pdo): void {
        {$failure}
        \$pdo->exec('CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');
    },
    'down' => static function (PDO \$pdo): void {
        \$pdo->exec('DROP TABLE IF EXISTS {$table}');
    },
];
PHP;
}

/** @return array<string,string> */
function market_plugin_files_from_directory(string $pluginId): array
{
    $dir = CMS_ROOT . '/content/plugins/' . $pluginId;
    if (!is_dir($dir)) {
        throw new RuntimeException('Plugin fixture directory is missing: ' . $pluginId);
    }
    $base = realpath($dir) ?: $dir;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $path = (string) $item->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen($base) + 1));
        $files['content/plugins/' . $pluginId . '/' . $relative] = (string) file_get_contents($path);
    }

    return $files;
}

function market_plugin_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
    $stmt->execute([':table' => $table]);

    return $stmt->fetchColumn() === $table;
}

$fixture = market_plugin_migration_fixture_root();
$root = $fixture['root'];
$pdo = $fixture['pdo'];
$installer = new MarketPackageInstaller($root);

try {
    [$zipV1, $authV1] = market_plugin_migration_package('acme.markettest', '1.0.0', [
        'content/plugins/acme.markettest/plugin.json' => market_plugin_manifest('acme.markettest', '1.0.0', ['migrations/001_create.php']),
        'content/plugins/acme.markettest/migrations/001_create.php' => market_plugin_migration_file('market_test_001_create', 'market_test_items'),
    ]);
    $installer->install($zipV1, $authV1, $pdo);
    $check(market_plugin_table_exists($pdo, 'market_test_items'), 'market install runs plugin migrations and creates plugin tables.');
    $migration = $pdo->query("SELECT status FROM cms_plugin_migrations WHERE plugin_id = 'acme.markettest' AND migration_id = 'market_test_001_create'")->fetchColumn();
    $check($migration === 'applied', 'market install records applied plugin migration checksums.');
    $row = $pdo->query("SELECT status, source, review_status FROM cms_plugins WHERE plugin_id = 'acme.markettest'")->fetch(PDO::FETCH_ASSOC);
    $check(($row['status'] ?? '') === PluginLifecycle::INSTALLED && ($row['source'] ?? '') === ExtensionSource::OFFICIAL_MARKET, 'market install leaves plugin installed with official market source.');

    [$commerceZip, $commerceAuth] = market_plugin_migration_package('official.commerce', '0.1.0-alpha.16', [
        'content/plugins/official.commerce/plugin.json' => market_plugin_manifest('official.commerce', '0.1.0-alpha.16', ['migrations/001_commerce.php'], ['commerce_']),
        'content/plugins/official.commerce/migrations/001_commerce.php' => market_plugin_migration_file('commerce_market_001_create', 'commerce_market_items'),
    ], 'plugin', market_plugin_trust_grant('official.commerce', ['commerce'], ['commerce_']));
    $installer->install($commerceZip, $commerceAuth, $pdo);
    $check(market_plugin_table_exists($pdo, 'commerce_market_items'), 'official commerce market install may use its reserved commerce_ table prefix.');

    [$affiliateZip, $affiliateAuth] = market_plugin_migration_package('official.affiliate-hub', '0.1.0-alpha.6', [
        'content/plugins/official.affiliate-hub/plugin.json' => market_plugin_manifest('official.affiliate-hub', '0.1.0-alpha.6', ['migrations/001_affiliate.php'], ['affiliate_'], ['affiliate']),
        'content/plugins/official.affiliate-hub/migrations/001_affiliate.php' => market_plugin_migration_file('affiliate_market_001_create', 'affiliate_market_items'),
    ], 'plugin', market_plugin_trust_grant('official.affiliate-hub', ['affiliate'], ['affiliate_']));
    $installer->install($affiliateZip, $affiliateAuth, $pdo);
    $check(market_plugin_table_exists($pdo, 'affiliate_market_items'), 'official Affiliate Hub market install may use its reserved affiliate_ table prefix.');

    $tamperedGrant = market_plugin_trust_grant('official.tampered', ['tampered'], ['tampered_']);
    $tamperedGrant['payload']['table_prefixes'] = ['commerce_'];
    [$tamperedZip, $tamperedAuth] = market_plugin_migration_package('official.tampered', '1.0.0', [
        'content/plugins/official.tampered/plugin.json' => market_plugin_manifest('official.tampered', '1.0.0', ['migrations/001.php'], ['tampered_']),
        'content/plugins/official.tampered/migrations/001.php' => market_plugin_migration_file('tampered_001', 'tampered_items'),
    ], 'plugin', $tamperedGrant);
    try {
        $installer->install($tamperedZip, $tamperedAuth, $pdo);
        $check(false, 'tampered official trust grant signature is rejected.');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), 'signature verification failed'), 'tampered official trust grant signature is rejected.');
    }

    [$revokedZip, $revokedAuth] = market_plugin_migration_package('official.revoked', '1.0.0', [
        'content/plugins/official.revoked/plugin.json' => market_plugin_manifest('official.revoked', '1.0.0', ['migrations/001.php'], ['revoked_']),
        'content/plugins/official.revoked/migrations/001.php' => market_plugin_migration_file('revoked_001', 'revoked_items'),
    ], 'plugin', market_plugin_trust_grant('official.revoked', ['revoked'], ['revoked_'], 'revoked'));
    try {
        $installer->install($revokedZip, $revokedAuth, $pdo);
        $check(false, 'revoked official trust grant is rejected.');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), 'not active'), 'revoked official trust grant is rejected.');
    }

    [$expiredZip, $expiredAuth] = market_plugin_migration_package('official.expired', '1.0.0', [
        'content/plugins/official.expired/plugin.json' => market_plugin_manifest('official.expired', '1.0.0', ['migrations/001.php'], ['expired_']),
        'content/plugins/official.expired/migrations/001.php' => market_plugin_migration_file('expired_001', 'expired_items'),
    ], 'plugin', market_plugin_trust_grant('official.expired', ['expired'], ['expired_'], 'active', '-1 day'));
    try {
        $installer->install($expiredZip, $expiredAuth, $pdo);
        $check(false, 'expired official trust grant is rejected.');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), 'expired'), 'expired official trust grant is rejected.');
    }

    [$driftZip, $driftAuth] = market_plugin_migration_package('official.affiliatedrift', '1.0.0', [
        'content/plugins/official.affiliatedrift/plugin.json' => market_plugin_manifest('official.affiliatedrift', '1.0.0', ['migrations/001.php'], ['affiliate_'], ['affiliate']),
        'content/plugins/official.affiliatedrift/migrations/001.php' => market_plugin_migration_file('affiliate_drift_001', 'commerce_drift_items'),
    ], 'plugin', market_plugin_trust_grant('official.affiliatedrift', ['affiliate'], ['affiliate_']));
    try {
        $installer->install($driftZip, $driftAuth, $pdo);
        $check(false, 'official grant cannot use migrations outside its granted table prefix.');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), 'plugin-owned database objects'), 'official grant cannot use migrations outside its granted table prefix.');
    }
    $driftGrantCount = $pdo->query("SELECT COUNT(*) FROM cms_extension_trust_grants WHERE extension_id = 'official.affiliatedrift'")->fetchColumn();
    $check((int) $driftGrantCount === 0, 'failed official install rolls back the staged trust grant.');

    [$fakeAffiliateZip, $fakeAffiliateAuth] = market_plugin_migration_package('official.fake-affiliate', '1.0.0', [
        'content/plugins/official.fake-affiliate/plugin.json' => market_plugin_manifest('official.fake-affiliate', '1.0.0', ['migrations/001_fake.php'], ['affiliate_']),
        'content/plugins/official.fake-affiliate/migrations/001_fake.php' => market_plugin_migration_file('affiliate_fake_001_create', 'affiliate_fake_items'),
    ]);
    try {
        $installer->install($fakeAffiliateZip, $fakeAffiliateAuth, $pdo);
        $check(false, 'unregistered official-like market plugin cannot use the reserved affiliate_ table prefix.');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), 'signed official trust grant'), 'unregistered official-like market plugin cannot use the reserved affiliate_ table prefix.');
    }
    $check(!market_plugin_table_exists($pdo, 'affiliate_fake_items'), 'rejected affiliate_ prefix package leaves no plugin table behind.');

    [$zipV2, $authV2] = market_plugin_migration_package('acme.markettest', '1.1.0', [
        'content/plugins/acme.markettest/plugin.json' => market_plugin_manifest('acme.markettest', '1.1.0', ['migrations/001_create.php', 'migrations/002_more.php']),
        'content/plugins/acme.markettest/migrations/001_create.php' => market_plugin_migration_file('market_test_001_create', 'market_test_items'),
        'content/plugins/acme.markettest/migrations/002_more.php' => market_plugin_migration_file('market_test_002_more', 'market_test_more'),
    ]);
    $installer->install($zipV2, $authV2, $pdo);
    $check(market_plugin_table_exists($pdo, 'market_test_more'), 'market upgrade runs newly declared plugin migrations.');
    $check($pdo->query("SELECT version FROM cms_plugins WHERE plugin_id = 'acme.markettest'")->fetchColumn() === '1.1.0', 'market upgrade updates plugin record after migrations pass.');

    [$zipChanged, $authChanged] = market_plugin_migration_package('acme.markettest', '1.2.0', [
        'content/plugins/acme.markettest/plugin.json' => market_plugin_manifest('acme.markettest', '1.2.0', ['migrations/001_create.php']),
        'content/plugins/acme.markettest/migrations/001_create.php' => market_plugin_migration_file('market_test_001_create', 'market_test_items') . "\n// changed",
    ]);
    try {
        $installer->install($zipChanged, $authChanged, $pdo);
        $check(false, 'market upgrade rejects changed migration checksum.');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), 'checksum changed'), 'market upgrade rejects changed migration checksum.');
    }
    $check($pdo->query("SELECT version FROM cms_plugins WHERE plugin_id = 'acme.markettest'")->fetchColumn() === '1.1.0', 'checksum rejection restores previous plugin record.');

    [$zipFail, $authFail] = market_plugin_migration_package('acme.markettest', '1.3.0', [
        'content/plugins/acme.markettest/plugin.json' => market_plugin_manifest('acme.markettest', '1.3.0', ['migrations/001_create.php', 'migrations/003_fail.php']),
        'content/plugins/acme.markettest/migrations/001_create.php' => market_plugin_migration_file('market_test_001_create', 'market_test_items'),
        'content/plugins/acme.markettest/migrations/003_fail.php' => market_plugin_migration_file('market_test_003_fail', 'market_test_failures', true),
    ]);
    try {
        $installer->install($zipFail, $authFail, $pdo);
        $check(false, 'market upgrade rolls back when a migration fails.');
    } catch (Throwable) {
        $check(true, 'market upgrade rolls back when a migration fails.');
    }
    $check(!market_plugin_table_exists($pdo, 'market_test_failures'), 'failed migration does not leave plugin-owned table behind.');
    $check($pdo->query("SELECT version FROM cms_plugins WHERE plugin_id = 'acme.markettest'")->fetchColumn() === '1.1.0', 'failed migration preserves previous plugin version.');

    [$mailZip, $mailAuth] = market_plugin_migration_package(
        'official.mail',
        '0.2.0-alpha.1',
        market_plugin_files_from_directory('official.mail'),
        'plugin',
        market_plugin_trust_grant('official.mail', ['mail', 'notifications'], ['mail_'])
    );
    $installer->install($mailZip, $mailAuth, $pdo);
    $check(market_plugin_table_exists($pdo, 'mail_oauth_configs'), 'official.mail market install creates mail_oauth_configs.');
    $check(market_plugin_table_exists($pdo, 'mail_accounts'), 'official.mail market install creates mail_accounts.');
    $check(market_plugin_table_exists($pdo, 'mail_messages'), 'official.mail market install creates mail_messages.');
    $check($pdo->query("SELECT status FROM cms_plugin_migrations WHERE plugin_id = 'official.mail' AND migration_id = 'official_mail_001_mail_client'")->fetchColumn() === 'applied', 'official.mail market install records its migration as applied.');

    $pdo->exec("UPDATE cms_plugins SET status = 'Installed' WHERE plugin_id = 'official.mail'");
    $pdo->exec("DELETE FROM cms_plugin_migrations WHERE plugin_id = 'official.mail'");
    $pdo->exec('DROP TABLE IF EXISTS mail_oauth_configs');
    $pdo->exec('DROP TABLE IF EXISTS mail_accounts');
    $pdo->exec('DROP TABLE IF EXISTS mail_messages');
    (new LocalPluginPackageInstaller($root, $pdo))->enable('official.mail', 1);
    $check($pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.mail'")->fetchColumn() === PluginLifecycle::ENABLED, 'market plugin enable succeeds after migration backfill.');
    $check(market_plugin_table_exists($pdo, 'mail_accounts'), 'market plugin enable backfills missing declared migrations before enabling.');
} finally {
    foreach (glob(sys_get_temp_dir() . '/daiying-market-package-*.zip') ?: [] as $zip) {
        @unlink($zip);
    }
    market_plugin_migration_remove_tree($root);
}

if ($failures > 0) {
    echo 'market_plugin_migrations failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'market_plugin_migrations: PASS' . PHP_EOL;
