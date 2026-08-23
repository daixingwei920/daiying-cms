<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/scripts/validate_production_readiness.php';
require CMS_SOURCE_ROOT . '/scripts/build_release_package.php';

$failures = 0;

function deployment_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

function deployment_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function deployment_write_config(string $root, array $overrides = []): void
{
    $publicKey = deployment_test_update_public_key();
    $config = [
        'app' => [
            'name' => 'PHP CMS',
            'version' => '1.2.0',
            'debug' => false,
            'mode' => 'NORMAL',
            'secure_cookies' => true,
        ],
        'site' => [
            'name' => 'Production Site',
            'url' => 'https://cms.example.test',
        ],
        'database' => [
            'dsn' => 'mysql:host=db.example.test;dbname=cms;charset=utf8mb4',
            'username' => 'cms_user',
            'password' => 'strong-password',
            'options' => [
                'PDO::MYSQL_ATTR_SSL_CA' => '/etc/ssl/certs/ca.pem',
            ],
        ],
        'theme' => [
            'active' => 'default',
            'settings' => [],
        ],
        'updates' => [
            'public_key' => $publicKey,
            'server_url' => '',
        ],
        'media' => [
            'max_file_bytes' => 1048576,
        ],
        'security' => [
            'encryption_key' => 'production-readiness-test-key-32-bytes-minimum',
            'admin_mfa' => [
                'runtime_enforcement' => true,
                'implemented_methods' => ['totp', 'recovery_codes'],
                'reserved_methods' => ['totp', 'passkey', 'recovery_codes'],
            ],
            'hsts_enabled' => true,
            'hsts_max_age' => 31536000,
            'hsts_include_subdomains' => true,
            'hsts_preload' => false,
        ],
    ];
    $config = array_replace_recursive($config, $overrides);
    if (isset($overrides['security']['admin_mfa']['reserved_methods'])) {
        $config['security']['admin_mfa']['reserved_methods'] = $overrides['security']['admin_mfa']['reserved_methods'];
    }
    if (isset($overrides['security']['admin_mfa']['implemented_methods'])) {
        $config['security']['admin_mfa']['implemented_methods'] = $overrides['security']['admin_mfa']['implemented_methods'];
    }
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    chmod($root . '/config/app.php', 0600);
}

function deployment_test_update_public_key(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($key === false) {
        return '';
    }

    $details = openssl_pkey_get_details($key);
    return is_array($details) ? (string) ($details['key'] ?? '') : '';
}

function deployment_prepare_root(string $root): void
{
    foreach ([
        'config',
        'public',
        'scripts',
        'system/core/Auth',
        'system/core/Bootstrap',
        'system/migrations',
        'content/themes/default',
        'content/themes/safe',
        'content/uploads',
        'storage/logs',
        'storage/cache',
        'storage/tmp',
        'storage/database',
        'storage/updates/incoming',
        'storage/recovery',
        'storage/plugin-installs/uploads',
        'storage/plugin-installs/staging',
    ] as $directory) {
        mkdir($root . '/' . $directory, 0755, true);
    }

    file_put_contents($root . '/config/app.example.php', "<?php\nreturn [];\n");
    file_put_contents($root . '/storage/installed.lock', '{}');
    chmod($root . '/storage/installed.lock', 0600);
    file_put_contents($root . '/public/index.php', "<?php\n");
    file_put_contents($root . '/public/.htaccess', "Options -Indexes\n<FilesMatch \"^(\\.env.*|\\..+|.*\\.(sql|sqlite|db|bak|backup|old|orig|pem|key|crt|csr|zip|manifest\\.json))$\">\n    Require all denied\n</FilesMatch>\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^ index.php [L]\n");
    file_put_contents($root . '/scripts/publish_scheduled_content.php', "<?php\n");
    file_put_contents($root . '/.htaccess', "Require all denied\n<FilesMatch \"^(\\.env.*|composer\\.(json|lock)|CMS_RELEASE_.*\\.md|CMS_COMPLETION_STATUS_AUDIT.*\\.md|.*\\.(zip(\\.sha256)?|manifest\\.json|sql|sqlite|db|bak|backup|old|orig|pem|key|crt|csr))$\">\n    Require all denied\n</FilesMatch>\nRewriteRule ^(config|storage|system|tests|scripts|content/plugins|content/themes)(/|$) - [F,L]\n");
    file_put_contents($root . '/nginx-root-security.conf', "deny all;\nlocation ~* /(\\.env.*|composer\\.(json|lock)|CMS_RELEASE_.*\\.md|CMS_COMPLETION_STATUS_AUDIT.*\\.md|.*\\.(zip(\\.sha256)?|manifest\\.json|sql|sqlite|db|bak|backup|old|orig|pem|key|crt|csr))$ {\n    deny all;\n}\ntry_files /public\$uri /public\$uri/ /public/index.php?\$query_string;\n");
    file_put_contents($root . '/system/core/Auth/AdminMfaService.php', "<?php\n");
    file_put_contents($root . '/system/core/Bootstrap/Application.php', "<?php\n// /admin/mfa\n// /admin/security/mfa-enable\n");
    file_put_contents($root . '/system/migrations/2026_08_23_000001_admin_mfa_schema.php', "<?php\n");
    file_put_contents($root . '/system/core-manifest.json', json_encode([
        'Auth/AdminMfaService.php' => hash_file('sha256', $root . '/system/core/Auth/AdminMfaService.php'),
        'Bootstrap/Application.php' => hash_file('sha256', $root . '/system/core/Bootstrap/Application.php'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($root . '/content/themes/default/theme.json', '{"id":"default"}');
    file_put_contents($root . '/content/themes/safe/theme.json', '{"id":"safe"}');
    file_put_contents($root . '/content/uploads/.htaccess', "Require all denied\n");
    file_put_contents($root . '/content/uploads/upload-security.nginx.conf', "return 403;\n");
    if (is_file(CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.zip')) {
        copy(CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.zip', $root . '/daiying-cms-1.2.0.zip');
    }
    if (is_file(CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.zip.sha256')) {
        copy(CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.zip.sha256', $root . '/daiying-cms-1.2.0.zip.sha256');
    }
    if (is_file(CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.manifest.json')) {
        copy(CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.manifest.json', $root . '/daiying-cms-1.2.0.manifest.json');
    }
}

function deployment_write_clean_release_sidecars(string $root): void
{
    $zipPath = $root . '/daiying-cms-1.2.0.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('public/index.php', '<?php');
    $zip->close();

    $hash = hash_file('sha256', $zipPath);
    file_put_contents($zipPath . '.sha256', $hash . '  daiying-cms-1.2.0.zip' . PHP_EOL);
    file_put_contents(substr($zipPath, 0, -4) . '.manifest.json', json_encode([
        'package' => 'daiying-cms-1.2.0.zip',
        'package_sha256' => $hash,
        'size_bytes' => filesize($zipPath),
    ], JSON_PRETTY_PRINT) . PHP_EOL);
}

function deployment_checks_ok(array $checks, array $ids): bool
{
    foreach ($checks as $check) {
        if (in_array($check['id'] ?? '', $ids, true) && !($check['ok'] ?? false)) {
            return false;
        }
    }

    return true;
}

function deployment_check_by_id(array $checks, string $id): ?array
{
    foreach ($checks as $check) {
        if (($check['id'] ?? '') === $id) {
            return $check;
        }
    }

    return null;
}

$restrictedPluginBoundary = \Cms\Core\Plugin\PluginRiskBoundaryPolicy::describe(['trust_level' => 'api'], 'local_unreviewed', 'unreviewed');
$trustedPluginBoundary = \Cms\Core\Plugin\PluginRiskBoundaryPolicy::describe(['trust_level' => 'trusted_php'], 'bundled_official', 'official_trusted');
deployment_check(($restrictedPluginBoundary['mode'] ?? '') === 'restricted_api' && ($restrictedPluginBoundary['raw_database_access'] ?? true) === false && str_contains((string) ($restrictedPluginBoundary['admin_notice'] ?? ''), '不承诺硬沙箱'), 'plugin risk boundary policy documents restricted API plugins without claiming a hard PHP sandbox');
deployment_check(($trustedPluginBoundary['mode'] ?? '') === 'trusted_php' && ($trustedPluginBoundary['raw_database_access'] ?? false) === true && ($trustedPluginBoundary['allowed_install_source'] ?? '') === 'bundled_official', 'plugin risk boundary policy reserves trusted PHP mode for official reviewed bundled plugins');
try {
    \Cms\Core\Plugin\PluginRiskBoundaryPolicy::assertLocalManifestAllowed(['trust_level' => 'trusted_php']);
    deployment_check(false, 'plugin risk boundary policy blocks local trusted PHP claims');
} catch (\Cms\Core\Plugin\PluginException) {
    deployment_check(true, 'plugin risk boundary policy blocks local trusted PHP claims');
}
$updatePayload = 'core update zip bytes';
$updateRequests = [];
$updateClient = new \Cms\Core\Update\UpdateServerClient('https://updates.example.test', static function (string $url) use (&$updateRequests, $updatePayload): string {
    $updateRequests[] = $url;
    if (str_contains($url, '/latest?')) {
        return json_encode([
            'version' => '1.2.1',
            'release_id' => 'release-1.2.1',
            'package_url' => 'https://updates.example.test/packages/daiying-cms-core-1.2.1.zip',
            'package_sha256' => hash('sha256', $updatePayload),
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    return $updatePayload;
});
$latestUpdate = $updateClient->latest('1.2.0', 'stable', 'site-123');
deployment_check(($latestUpdate['version'] ?? '') === '1.2.1' && str_contains($updateRequests[0] ?? '', 'current_version=1.2.0') && str_contains($updateRequests[0] ?? '', 'channel=stable'), 'official Core update server client queries latest manifest with current version and channel');
$updateDownloadDir = sys_get_temp_dir() . '/cms-update-client-' . bin2hex(random_bytes(4));
$downloadedUpdate = $updateClient->downloadPackage($latestUpdate, $updateDownloadDir);
deployment_check(is_file($downloadedUpdate['path']) && (string) file_get_contents($downloadedUpdate['path']) === $updatePayload && $downloadedUpdate['sha256'] === hash('sha256', $updatePayload), 'official Core update server client downloads package into incoming storage and verifies SHA-256');
try {
    (new \Cms\Core\Update\UpdateServerClient('', static fn (): string => '{}'))->latest('1.2.0');
    deployment_check(false, 'official Core update server client rejects missing server URL');
} catch (\Cms\Core\Update\UpdateException) {
    deployment_check(true, 'official Core update server client rejects missing server URL');
}
try {
    $badHash = $latestUpdate;
    $badHash['package_sha256'] = str_repeat('0', 64);
    $updateClient->downloadPackage($badHash, $updateDownloadDir);
    deployment_check(false, 'official Core update server client rejects package hash mismatches');
} catch (\Cms\Core\Update\UpdateException) {
    deployment_check(true, 'official Core update server client rejects package hash mismatches');
}
deployment_remove($updateDownloadDir);

$backupRoot = sys_get_temp_dir() . '/cms-market-extension-backup-' . bin2hex(random_bytes(4));
deployment_remove($backupRoot);
deployment_prepare_root($backupRoot);
deployment_write_config($backupRoot, [
    'database' => [
        'dsn' => 'sqlite:' . $backupRoot . '/storage/database/backup.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
mkdir($backupRoot . '/content/plugins/paid-gallery', 0755, true);
mkdir($backupRoot . '/content/themes/market-theme', 0755, true);
mkdir($backupRoot . '/storage/market/cache', 0755, true);
file_put_contents($backupRoot . '/content/plugins/paid-gallery/plugin.json', '{"id":"paid-gallery"}');
file_put_contents($backupRoot . '/content/themes/market-theme/theme.json', '{"id":"market-theme"}');
file_put_contents($backupRoot . '/storage/market/cache/runtime.json', '{"temporary":true}');
$backupPdo = new PDO('sqlite:' . $backupRoot . '/storage/database/backup.sqlite');
$backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$backupMigrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $backupMigrations[] = require $file;
}
(new \Cms\Core\Migration\MigrationRunner($backupPdo, $backupMigrations))->run();
(new \Cms\Core\Market\MarketInstallRepository($backupPdo))->recordSource('paid-gallery', 'plugin', 'market', 'market-main', '1.0.0', ['license_id' => 'lic_123']);
(new \Cms\Core\Market\MarketInstallRepository($backupPdo))->recordLog('market-main', 'paid-gallery', 'plugin', 'Installed', ['package_hash' => str_repeat('a', 64)]);
$restorePath = (new \Cms\Core\Recovery\RestorePointService($backupRoot))->create('market-extension-backup-test');
$restoreZip = new ZipArchive();
$restoreZip->open($restorePath);
$restoreManifest = json_decode((string) $restoreZip->getFromName('manifest.json'), true) ?: [];
$extensionBackup = json_decode((string) $restoreZip->getFromName('extensions/market-extensions.json'), true) ?: [];
$containsPlugin = $restoreZip->locateName('content/plugins/paid-gallery/plugin.json') !== false;
$containsTheme = $restoreZip->locateName('content/themes/market-theme/theme.json') !== false;
$containsRuntimeMarketCache = $restoreZip->locateName('storage/market/cache/runtime.json') !== false;
$restoreZip->close();
deployment_check(in_array('market-extensions', $restoreManifest['includes'] ?? [], true) && isset($restoreManifest['checksums']['extensions/market-extensions.json']), 'restore point manifest explicitly declares market extension backup metadata');
deployment_check(($extensionBackup['sources_table_available'] ?? false) === true && ($extensionBackup['install_logs_table_available'] ?? false) === true && ($extensionBackup['sources'][0]['extension_id'] ?? '') === 'paid-gallery' && ($extensionBackup['latest_installed'][0]['version'] ?? '') === '1.0.0', 'restore point captures Market extension source, version, authorization metadata and install log references');
deployment_check($containsPlugin && $containsTheme && !$containsRuntimeMarketCache && ($extensionBackup['policy']['runtime_market_cache_included'] ?? true) === false, 'restore point includes installed plugin/theme directories while excluding Market runtime cache');

$work = sys_get_temp_dir() . '/cms-production-readiness-' . bin2hex(random_bytes(4));
deployment_remove($work);
mkdir($work, 0755, true);

$readyRoot = $work . '/ready';
deployment_prepare_root($readyRoot);
deployment_write_clean_release_sidecars($readyRoot);
deployment_write_config($readyRoot);
$ready = cms_validate_production_readiness($readyRoot);

deployment_check(in_array($ready['status'], ['ready', 'ready_with_warnings'], true), 'valid production configuration has no blocking errors');
deployment_check($ready['errors'] === 0, 'valid production configuration has no blocking errors or unsafe secrets');
deployment_check(count($ready['checks']) >= 41, 'readiness checker emits broad deployment checks');
deployment_check(array_reduce($ready['checks'], static fn (bool $ok, array $check): bool => $ok && !str_contains($check['detail'], 'strong-password'), true), 'readiness output redacts sensitive details');
deployment_check(deployment_checks_ok($ready['checks'], ['config.app_private']), 'readiness checker validates private installed config permissions');
deployment_check(deployment_checks_ok($ready['checks'], ['core.manifest', 'core.manifest_integrity']), 'readiness checker validates Core manifest JSON and installed Core file hashes');
deployment_check(deployment_checks_ok($ready['checks'], ['install.lock_exists', 'install.lock_private']), 'readiness checker validates installed lock presence and private permissions');
deployment_check(deployment_checks_ok($ready['checks'], ['package.sha256_sidecar', 'package.manifest_sidecar']), 'readiness checker validates release package sidecars');
deployment_check(deployment_checks_ok($ready['checks'], ['webroot.apache.release_artifact_guard', 'webroot.nginx.release_artifact_guard']), 'readiness checker validates release report and artifact direct-download guards');
deployment_check(deployment_checks_ok($ready['checks'], ['webroot.apache.root_sensitive_file_guard', 'webroot.nginx.root_sensitive_file_guard', 'webroot.apache.public_sensitive_file_guard']), 'readiness checker validates root and public sensitive file guards');
deployment_check(deployment_checks_ok($ready['checks'], ['updates.public_key']), 'readiness checker accepts configured Core update signing public key');
deployment_check(deployment_checks_ok($ready['checks'], ['security.encryption_key']), 'readiness checker accepts configured production encryption key');
deployment_check(deployment_checks_ok($ready['checks'], ['security.admin_mfa.reserved_methods']), 'readiness checker accepts explicit admin MFA reserve configuration');
deployment_check(deployment_checks_ok($ready['checks'], ['security.admin_mfa.runtime_enforcement']), 'readiness checker accepts Core admin MFA runtime enforcement');
deployment_check(deployment_checks_ok($ready['checks'], ['seo.robots_index']), 'readiness checker accepts public SEO indexing for production launch');
deployment_check(deployment_checks_ok($ready['checks'], ['security.hsts_enabled', 'security.hsts_max_age', 'security.hsts_subdomains']), 'readiness checker validates HTTPS HSTS production settings');
deployment_check(deployment_checks_ok($ready['checks'], ['scheduler.publish_cli']), 'readiness checker validates scheduled publish CLI for cron');
deployment_check(deployment_checks_ok($ready['checks'], ['writable.storage.updates.incoming', 'writable.storage.recovery']), 'readiness checker validates Core update incoming and recovery directories');
deployment_check(deployment_checks_ok($ready['checks'], ['writable.storage.plugin-installs.uploads', 'writable.storage.plugin-installs.staging']), 'readiness checker validates local plugin ZIP upload and staging directories');
deployment_check(deployment_checks_ok($ready['checks'], ['php.extension.fileinfo', 'php.file_uploads', 'php.upload_max_filesize', 'php.post_max_size', 'php.memory_limit']), 'readiness checker validates PHP media upload runtime limits and Fileinfo MIME detection');
deployment_check(deployment_checks_ok($ready['checks'], ['php.extension.zip']), 'readiness checker validates PHP ZipArchive extension for packages, restore points and Core updates');
deployment_check(cms_readiness_runtime_requirements(CMS_SOURCE_ROOT)['extensions'] === \Cms\Core\Support\RuntimeRequirements::requiredExtensions(), 'readiness checker sources required PHP extensions from shared RuntimeRequirements');
deployment_check(deployment_checks_ok($ready['checks'], ['database.mysql.mysqldump_tool', 'database.mysql.mysql_tool']), 'readiness checker validates MySQL/MariaDB backup and restore client tools');
deployment_check(($readyProviderInspection = deployment_check_by_id($ready['checks'], 'payment.providers.database_inspected')) !== null && ($readyProviderInspection['ok'] ?? true) === false && ($readyProviderInspection['severity'] ?? '') === 'warning', 'readiness checker does not require remote MySQL Provider inspection during offline preflight');
deployment_check(cms_ini_bytes('2M') === 2097152 && cms_ini_bytes('1G') === 1073741824 && cms_ini_bytes('-1') === null, 'readiness helper parses PHP shorthand byte values');
deployment_check(cms_readiness_command_available('mysql') && !cms_readiness_command_available('not-a-real-cms-command'), 'readiness helper detects available shell commands without executing them');

$sqliteProviderRoot = $work . '/sqlite-provider-ready';
deployment_prepare_root($sqliteProviderRoot);
deployment_write_config($sqliteProviderRoot, [
    'database' => [
        'dsn' => 'sqlite:' . $sqliteProviderRoot . '/storage/database/provider.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
$sqliteProviderPdo = new PDO('sqlite:' . $sqliteProviderRoot . '/storage/database/provider.sqlite');
$sqliteProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqliteProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL, display_name VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, public_config_json TEXT NOT NULL, secret_config_ciphertext TEXT NOT NULL, created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL)');
$sqliteProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"instructions\":\"生产预检不输出付款说明\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$sqliteProviderReady = cms_validate_production_readiness($sqliteProviderRoot);
deployment_check(deployment_checks_ok($sqliteProviderReady['checks'], ['payment.providers.database_inspected', 'payment.providers.unique_provider_id', 'payment.providers.secret_ciphertext', 'payment.providers.enabled_checkout', 'payment.providers.default_checkout', 'payment.providers.manual_payment_ready', 'payment.providers.runtime_enabled_checkout', 'payment.providers.runtime_default_checkout']), 'readiness checker validates SQLite manual Payment Provider readiness through table scan and runtime service probe');
deployment_check(array_reduce($sqliteProviderReady['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'payment.providers.runtime_enabled_checkout' && ($check['ok'] ?? false) === true && str_contains((string) ($check['detail'] ?? ''), 'core.manual-payment')), false), 'readiness checker probes PaymentService enabled Providers on a copied SQLite database');
deployment_check(array_reduce($sqliteProviderReady['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'payment.providers.runtime_default_checkout' && ($check['ok'] ?? false) === true && str_contains((string) ($check['detail'] ?? ''), 'core.manual-payment')), false), 'readiness checker probes PaymentProviderSelector default Provider on a copied SQLite database');
deployment_check(array_reduce($sqliteProviderReady['checks'], static fn (bool $ok, array $check): bool => $ok && !str_contains((string) ($check['detail'] ?? ''), '生产预检不输出付款说明'), true), 'readiness checker does not leak Provider public configuration values');

$sqliteWalProviderRoot = $work . '/sqlite-provider-wal-ready';
deployment_prepare_root($sqliteWalProviderRoot);
deployment_write_config($sqliteWalProviderRoot, [
    'database' => [
        'dsn' => 'sqlite:' . $sqliteWalProviderRoot . '/storage/database/provider.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
$sqliteWalProviderPdo = new PDO('sqlite:' . $sqliteWalProviderRoot . '/storage/database/provider.sqlite');
$sqliteWalProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqliteWalProviderPdo->exec('PRAGMA journal_mode=WAL');
$sqliteWalProviderPdo->exec('PRAGMA wal_autocheckpoint=0');
$sqliteWalProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL, display_name VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, public_config_json TEXT NOT NULL, secret_config_ciphertext TEXT NOT NULL, created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL)');
$sqliteWalProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"instructions\":\"WAL 中的付款说明不能泄露\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$sqliteWalProviderReady = cms_validate_production_readiness($sqliteWalProviderRoot);
deployment_check(deployment_checks_ok($sqliteWalProviderReady['checks'], ['payment.providers.runtime_enabled_checkout', 'payment.providers.runtime_default_checkout']) && is_file($sqliteWalProviderRoot . '/storage/database/provider.sqlite-wal'), 'readiness checker snapshots SQLite WAL databases before probing Provider runtime services');
deployment_check(array_reduce($sqliteWalProviderReady['checks'], static fn (bool $ok, array $check): bool => $ok && !str_contains((string) ($check['detail'] ?? ''), 'WAL 中的付款说明不能泄露'), true), 'readiness checker does not leak Provider public configuration from WAL snapshots');

$corruptProviderSecretRoot = $work . '/sqlite-provider-corrupt-secret';
deployment_prepare_root($corruptProviderSecretRoot);
deployment_write_config($corruptProviderSecretRoot, [
    'database' => [
        'dsn' => 'sqlite:' . $corruptProviderSecretRoot . '/storage/database/provider.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
$corruptProviderSecretPdo = new PDO('sqlite:' . $corruptProviderSecretRoot . '/storage/database/provider.sqlite');
$corruptProviderSecretPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$corruptProviderSecretPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL, display_name VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, public_config_json TEXT NOT NULL, secret_config_ciphertext TEXT NOT NULL, created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL)');
$corruptProviderSecretPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.hosted-redirect', '托管跳转支付', 'enabled', '{\"checkout_url\":\"https://pay.example.test/checkout\",\"return_url_base\":\"https://cms.example.test\",\"default_provider\":true}', 'not-a-valid-core-provider-secret-ciphertext', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$corruptProviderSecret = cms_validate_production_readiness($corruptProviderSecretRoot);
deployment_check(array_reduce($corruptProviderSecret['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'payment.providers.secret_ciphertext' && !$check['ok'] && str_contains((string) ($check['detail'] ?? ''), 'core.hosted-redirect') && str_contains((string) ($check['detail'] ?? ''), '/admin/payments/providers')), false), 'readiness checker detects unreadable Payment Provider secret ciphertext before production rollout');
deployment_check(array_reduce($corruptProviderSecret['checks'], static fn (bool $ok, array $check): bool => $ok && !str_contains((string) ($check['detail'] ?? ''), 'not-a-valid-core-provider-secret-ciphertext'), true), 'readiness checker does not leak unreadable Provider secret ciphertext values');

$duplicateProviderRoot = $work . '/sqlite-provider-duplicate';
deployment_prepare_root($duplicateProviderRoot);
deployment_write_config($duplicateProviderRoot, [
    'database' => [
        'dsn' => 'sqlite:' . $duplicateProviderRoot . '/storage/database/provider.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);
$duplicateProviderPdo = new PDO('sqlite:' . $duplicateProviderRoot . '/storage/database/provider.sqlite');
$duplicateProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$duplicateProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL, display_name VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, public_config_json TEXT NOT NULL, secret_config_ciphertext TEXT NOT NULL, created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL, enabled INTEGER, is_default INTEGER, config_json TEXT)');
$duplicateProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json) VALUES ('core.manual-payment', '旧人工支付', 'disabled', '{}', '', '2026-08-20T00:00:00+00:00', '2026-08-20T00:00:00+00:00', 1, 1, '{\"instructions\":\"生产预检不输出付款说明\"}')");
$duplicateProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$duplicateProvider = cms_validate_production_readiness($duplicateProviderRoot);
deployment_check(array_reduce($duplicateProvider['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'payment.providers.unique_provider_id' && !$check['ok'] && str_contains((string) ($check['detail'] ?? ''), 'core.manual-payment')), false), 'readiness checker blocks duplicate Payment Provider rows before production rollout');
deployment_check(array_reduce($duplicateProvider['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'payment.providers.unique_provider_id' && !$check['ok'] && str_contains((string) ($check['detail'] ?? ''), '/admin/payments/providers') && str_contains((string) ($check['detail'] ?? ''), 'diagnose_payment_providers.php --json --repair')), false), 'readiness checker tells operators how to repair duplicate Payment Provider rows');
deployment_check(array_reduce($duplicateProvider['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'payment.providers.legacy_plugin_storage' && !$check['ok'] && str_contains((string) ($check['detail'] ?? ''), 'core.manual-payment') && str_contains((string) ($check['detail'] ?? ''), 'diagnose_payment_providers.php --json --repair')), false), 'readiness checker detects legacy payment plugin Provider fields before production rollout');
deployment_check(array_reduce($duplicateProvider['checks'], static fn (bool $ok, array $check): bool => $ok && !str_contains((string) ($check['detail'] ?? ''), '生产预检不输出付款说明'), true), 'readiness checker does not leak legacy Provider config values while reporting storage debt');

$badRoot = $work . '/bad';
deployment_prepare_root($badRoot);
deployment_write_config($badRoot, [
    'app' => ['debug' => true, 'secure_cookies' => false],
    'site' => ['url' => 'http://cms.example.test'],
    'database' => [
        'dsn' => 'mysql:host=db.example.test;dbname=cms',
        'username' => 'root',
        'password' => 'change-me',
        'options' => [],
    ],
    'updates' => [
        'public_key' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
    ],
    'security' => [
        'encryption_key' => 'change-me-generate-a-random-32-byte-secret',
        'admin_mfa' => [
            'runtime_enforcement' => true,
            'implemented_methods' => ['totp', 'recovery_codes'],
            'reserved_methods' => ['totp', 'passkey', 'recovery_codes'],
        ],
        'hsts_enabled' => false,
        'hsts_max_age' => 86400,
        'hsts_include_subdomains' => false,
    ],
]);
$bad = cms_validate_production_readiness($badRoot);

deployment_check($bad['status'] === 'not_ready', 'unsafe production configuration is not ready');
deployment_check($bad['errors'] >= 4, 'unsafe production configuration reports blocking errors');
deployment_check($bad['warnings'] >= 2, 'unsafe production configuration reports deployment warnings');

$ids = array_column($bad['checks'], 'id');
deployment_check(in_array('site.url', $ids, true) && in_array('app.secure_cookies', $ids, true) && in_array('database.mysql.least_privilege', $ids, true), 'checker covers HTTPS, secure cookies and least-privilege database user');
deployment_check(in_array('security.hsts_enabled', $ids, true) && in_array('security.hsts_max_age', $ids, true) && in_array('security.hsts_subdomains', $ids, true), 'checker covers HTTPS HSTS production settings');
deployment_check(in_array('database.mysql.tls_documented', $ids, true), 'checker covers remote MySQL/MariaDB TLS documentation');
deployment_check(in_array('webroot.apache.root_guard', $ids, true) && in_array('webroot.nginx.root_guard', $ids, true), 'checker covers project-root web exposure guard files');
deployment_check(in_array('webroot.apache.root_sensitive_file_guard', $ids, true) && in_array('webroot.nginx.root_sensitive_file_guard', $ids, true) && in_array('webroot.apache.public_sensitive_file_guard', $ids, true), 'checker covers root and public sensitive file guards');
deployment_check(array_reduce($bad['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'updates.public_key' && !$check['ok']), false), 'checker rejects missing or placeholder Core update signing public keys');
deployment_check(array_reduce($bad['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'security.encryption_key' && !$check['ok'] && ($check['detail'] ?? '') === 'placeholder'), false), 'checker rejects placeholder Core encryption keys without exposing the key');
deployment_check(deployment_checks_ok($bad['checks'], ['security.admin_mfa.runtime_enforcement']), 'checker recognizes available admin MFA runtime enforcement even when other production settings are unsafe');

$missingMfaReserveRoot = $work . '/missing-mfa-reserve';
deployment_prepare_root($missingMfaReserveRoot);
deployment_write_config($missingMfaReserveRoot, ['security' => ['admin_mfa' => ['runtime_enforcement' => true, 'implemented_methods' => ['totp', 'recovery_codes'], 'reserved_methods' => ['totp']]]]);
$missingMfaReserve = cms_validate_production_readiness($missingMfaReserveRoot);
deployment_check(array_reduce($missingMfaReserve['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'security.admin_mfa.reserved_methods' && !$check['ok'] && str_contains((string) ($check['detail'] ?? ''), 'passkey') && str_contains((string) ($check['detail'] ?? ''), 'recovery_codes')), false), 'checker warns when admin MFA reserve methods are incomplete');

$noIndexRoot = $work . '/noindex-production';
deployment_prepare_root($noIndexRoot);
deployment_write_config($noIndexRoot, ['seo' => ['robots_index' => false]]);
$noIndex = cms_validate_production_readiness($noIndexRoot);
deployment_check(array_reduce($noIndex['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'seo.robots_index' && !$check['ok'] && ($check['severity'] ?? '') === 'warning' && str_contains((string) ($check['detail'] ?? ''), '/admin/settings')), false), 'readiness checker warns when production SEO indexing is disabled and points to admin site settings');

$oversizedMediaRoot = $work . '/oversized-media';
deployment_prepare_root($oversizedMediaRoot);
deployment_write_config($oversizedMediaRoot, ['media' => ['max_file_bytes' => 536870912]]);
$oversizedMedia = cms_validate_production_readiness($oversizedMediaRoot);
deployment_check(array_reduce($oversizedMedia['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'php.upload_max_filesize' && !$check['ok']), false), 'checker warns when configured media max file size exceeds PHP upload_max_filesize');

$guardlessRoot = $work . '/guardless';
deployment_prepare_root($guardlessRoot);
deployment_write_config($guardlessRoot);
unlink($guardlessRoot . '/.htaccess');
unlink($guardlessRoot . '/nginx-root-security.conf');
$guardless = cms_validate_production_readiness($guardlessRoot);
deployment_check($guardless['warnings'] >= 2 && array_reduce($guardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.apache.root_guard' && !$check['ok']), false), 'missing project-root Apache guard is reported before deployment');
deployment_check(array_reduce($guardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.nginx.root_guard' && !$check['ok']), false), 'missing project-root Nginx guard is reported before deployment');
deployment_check(array_reduce($guardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.apache.release_artifact_guard' && !$check['ok']), false), 'missing Apache release artifact guard is reported before deployment');
deployment_check(array_reduce($guardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.nginx.release_artifact_guard' && !$check['ok']), false), 'missing Nginx release artifact guard is reported before deployment');
deployment_check(array_reduce($guardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.apache.root_sensitive_file_guard' && !$check['ok']), false), 'missing Apache root sensitive file guard is reported before deployment');
deployment_check(array_reduce($guardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.nginx.root_sensitive_file_guard' && !$check['ok']), false), 'missing Nginx root sensitive file guard is reported before deployment');

$publicGuardlessRoot = $work . '/public-guardless';
deployment_prepare_root($publicGuardlessRoot);
deployment_write_config($publicGuardlessRoot);
file_put_contents($publicGuardlessRoot . '/public/.htaccess', "Options -Indexes\nRewriteEngine On\nRewriteRule ^ index.php [L]\n");
$publicGuardless = cms_validate_production_readiness($publicGuardlessRoot);
deployment_check(array_reduce($publicGuardless['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'webroot.apache.public_sensitive_file_guard' && !$check['ok']), false), 'missing Apache public sensitive file guard is reported before deployment');

$missingRecoveryDirsRoot = $work . '/missing-recovery-dirs';
deployment_prepare_root($missingRecoveryDirsRoot);
deployment_write_config($missingRecoveryDirsRoot);
deployment_remove($missingRecoveryDirsRoot . '/storage/updates/incoming');
deployment_remove($missingRecoveryDirsRoot . '/storage/recovery');
$missingRecoveryDirs = cms_validate_production_readiness($missingRecoveryDirsRoot);
deployment_check(array_reduce($missingRecoveryDirs['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'writable.storage.updates.incoming' && !$check['ok']), false), 'missing Core update incoming directory is reported before deployment');
deployment_check(array_reduce($missingRecoveryDirs['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'writable.storage.recovery' && !$check['ok']), false), 'missing recovery directory is reported before deployment');

$missingPluginInstallDirsRoot = $work . '/missing-plugin-install-dirs';
deployment_prepare_root($missingPluginInstallDirsRoot);
deployment_write_config($missingPluginInstallDirsRoot);
deployment_remove($missingPluginInstallDirsRoot . '/storage/plugin-installs/uploads');
deployment_remove($missingPluginInstallDirsRoot . '/storage/plugin-installs/staging');
$missingPluginInstallDirs = cms_validate_production_readiness($missingPluginInstallDirsRoot);
deployment_check(array_reduce($missingPluginInstallDirs['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'writable.storage.plugin-installs.uploads' && !$check['ok']), false), 'missing local plugin ZIP upload directory is reported before deployment');
deployment_check(array_reduce($missingPluginInstallDirs['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'writable.storage.plugin-installs.staging' && !$check['ok']), false), 'missing local plugin ZIP staging directory is reported before deployment');

$missingInstallLockRoot = $work . '/missing-install-lock';
deployment_prepare_root($missingInstallLockRoot);
deployment_write_config($missingInstallLockRoot);
unlink($missingInstallLockRoot . '/storage/installed.lock');
$missingInstallLock = cms_validate_production_readiness($missingInstallLockRoot);
deployment_check(array_reduce($missingInstallLock['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'install.lock_exists' && !$check['ok']), false), 'missing installed lock is reported before production deployment');

$looseConfigRoot = $work . '/loose-config';
deployment_prepare_root($looseConfigRoot);
deployment_write_config($looseConfigRoot);
chmod($looseConfigRoot . '/config/app.php', 0644);
$looseConfig = cms_validate_production_readiness($looseConfigRoot);
deployment_check(array_reduce($looseConfig['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'config.app_private' && !$check['ok']), false), 'world-readable installed config is reported before deployment');

$looseInstallLockRoot = $work . '/loose-install-lock';
deployment_prepare_root($looseInstallLockRoot);
deployment_write_config($looseInstallLockRoot);
chmod($looseInstallLockRoot . '/storage/installed.lock', 0644);
$looseInstallLock = cms_validate_production_readiness($looseInstallLockRoot);
deployment_check(array_reduce($looseInstallLock['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'install.lock_private' && !$check['ok']), false), 'world-readable installed lock is reported before deployment');

$brokenCoreManifestRoot = $work . '/broken-core-manifest';
deployment_prepare_root($brokenCoreManifestRoot);
deployment_write_config($brokenCoreManifestRoot);
file_put_contents($brokenCoreManifestRoot . '/system/core-manifest.json', json_encode([
    'Bootstrap/Application.php' => str_repeat('0', 64),
    'Missing/File.php' => hash('sha256', 'missing'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
$brokenCoreManifest = cms_validate_production_readiness($brokenCoreManifestRoot);
deployment_check(array_reduce($brokenCoreManifest['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'core.manifest_integrity' && !$check['ok'] && str_contains((string) ($check['detail'] ?? ''), 'manifest-hash-mismatch:Bootstrap/Application.php')), false), 'mismatched Core manifest hash is blocking before production deployment');

$brokenSidecarRoot = $work . '/broken-sidecar';
deployment_prepare_root($brokenSidecarRoot);
deployment_write_clean_release_sidecars($brokenSidecarRoot);
deployment_write_config($brokenSidecarRoot);
file_put_contents($brokenSidecarRoot . '/daiying-cms-1.2.0.zip.sha256', str_repeat('0', 64) . '  daiying-cms-1.2.0.zip' . PHP_EOL);
file_put_contents($brokenSidecarRoot . '/daiying-cms-1.2.0.manifest.json', json_encode([
    'package' => 'daiying-cms-1.2.0.zip',
    'package_sha256' => str_repeat('0', 64),
    'size_bytes' => 1,
], JSON_PRETTY_PRINT) . PHP_EOL);
$brokenSidecar = cms_validate_production_readiness($brokenSidecarRoot);
deployment_check(array_reduce($brokenSidecar['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'package.sha256_sidecar' && !$check['ok']), false), 'mismatched release package SHA-256 sidecar is blocking before deployment');
deployment_check(array_reduce($brokenSidecar['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'package.manifest_sidecar' && !$check['ok']), false), 'mismatched release artifact manifest sidecar is blocking before deployment');

$missingToolsRoot = $work . '/missing-tools';
deployment_prepare_root($missingToolsRoot);
deployment_write_config($missingToolsRoot, [
    'database' => [
        'mysqldump_command' => $missingToolsRoot . '/bin/mysqldump-missing',
        'mysql_command' => $missingToolsRoot . '/bin/mysql-missing',
    ],
]);
$missingTools = cms_validate_production_readiness($missingToolsRoot);
deployment_check(array_reduce($missingTools['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'database.mysql.mysqldump_tool' && !$check['ok']), false), 'missing mysqldump blocks MySQL/MariaDB recovery readiness');
deployment_check(array_reduce($missingTools['checks'], static fn (bool $found, array $check): bool => $found || ($check['id'] === 'database.mysql.mysql_tool' && !$check['ok']), false), 'missing mysql client blocks MySQL/MariaDB restore readiness');

$zipPath = $work . '/release.zip';
cms_build_release_package(CMS_SOURCE_ROOT, $zipPath);
$package = cms_readiness_package_runtime_exclusions($zipPath);
deployment_check($package['ok'] === true, 'canonical release package passes runtime artifact exclusion check');

$dirtyZip = $work . '/dirty.zip';
$zip = new ZipArchive();
$zip->open($dirtyZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('public/index.php', '<?php');
$zip->addFromString('storage/logs/app.log', 'secret log');
$zip->addFromString('content/plugins/official.payment-fixture/plugin.php', '<?php');
$zip->addFromString('nested.zip', 'not really zip');
$zip->close();
$dirty = cms_readiness_package_runtime_exclusions($dirtyZip);
deployment_check($dirty['ok'] === false && count($dirty['violations']) === 3 && in_array('content/plugins/official.payment-fixture/plugin.php', $dirty['violations'], true), 'package verifier detects runtime logs, nested ZIP artifacts and retired payment fixture plugin artifacts');

$cliOutput = [];
$cliCode = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/validate_production_readiness.php') . ' --json', $cliOutput, $cliCode);
$json = json_decode(implode("\n", $cliOutput), true);
deployment_check($cliCode === 0 && is_array($json) && isset($json['status'], $json['checks']), 'deployment readiness CLI emits JSON status');

$helpOutput = [];
$helpCode = 1;
exec(PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/validate_production_readiness.php') . ' --help', $helpOutput, $helpCode);
$helpText = implode("\n", $helpOutput);
deployment_check($helpCode === 0 && str_contains($helpText, 'Usage: php scripts/validate_production_readiness.php') && str_contains($helpText, '--strict') && !str_contains($helpText, 'Production readiness status:'), 'deployment readiness CLI help does not run environment checks');

$scheduledHelpOutput = [];
$scheduledHelpCode = 1;
exec(PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/publish_scheduled_content.php') . ' --help', $scheduledHelpOutput, $scheduledHelpCode);
$scheduledHelpText = implode("\n", $scheduledHelpOutput);
deployment_check($scheduledHelpCode === 0 && str_contains($scheduledHelpText, 'Usage: php scripts/publish_scheduled_content.php') && str_contains($scheduledHelpText, 'cron') && !str_contains($scheduledHelpText, 'Database DSN is not configured'), 'scheduled publish CLI help does not require database configuration');

deployment_remove($work);

if ($failures > 0) {
    fwrite(STDERR, $failures . " production deployment readiness checks failed.\n");
    exit(1);
}

echo "Production deployment readiness tests passed.\n";
