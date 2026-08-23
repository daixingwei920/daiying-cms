<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateException;
use Cms\Core\Update\UpdatePackageReader;
use Cms\Core\Update\UpdateService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

SessionManager::start(false);

$failures = 0;

function core_update_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function core_update_throws(callable $callback, string $message): void
{
    try {
        $callback();
        core_update_check(false, $message);
    } catch (UpdateException|RuntimeException|PDOException) {
        core_update_check(true, $message);
    }
}

function core_update_remove(string $path): void
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

function core_update_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/updates/') || str_starts_with($relative, 'storage/recovery/')) {
            continue;
        }
        $dest = $target . '/' . $relative;
        $item->isDir() ? (!is_dir($dest) && mkdir($dest, 0755, true)) : copy((string) $item->getPathname(), $dest);
    }
}

function core_update_site(): array
{
    $root = sys_get_temp_dir() . '/cms-core-update-' . bin2hex(random_bytes(4));
    core_update_remove($root);
    core_update_copy(CMS_SOURCE_ROOT, $root);
    foreach (['storage/logs', 'storage/cache', 'storage/updates/incoming', 'storage/updates/releases', 'storage/recovery', 'content/plugins/custom', 'content/themes/custom', 'content/uploads'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            mkdir($root . '/' . $dir, 0755, true);
        }
    }
    file_put_contents($root . '/content/plugins/custom/plugin.php', 'plugin-data');
    file_put_contents($root . '/content/themes/custom/theme.php', 'theme-data');
    file_put_contents($root . '/content/uploads/media.txt', 'upload-data');
    $config = require $root . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/core-update.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['site'] = ['name' => 'Core Update Test', 'url' => 'http://127.0.0.1', 'id' => 'core-update-test', 'secret' => 'secret'];
    $config['updates']['public_key'] = '';
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($root . '/storage/installed.lock', '{}');
    file_put_contents($root . '/system/core-manifest.json', json_encode(ManifestBuilder::build($root . '/system/core'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $settings = Settings::load($root);
    $pdo = ConnectionFactory::make($settings);
    $migrations = [];
    foreach (glob($root . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();

    return [$root, $settings, $pdo, $config['app']['version']];
}

function core_update_hash_dir(string $dir): string
{
    $hashes = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->isFile()) {
            $relative = substr((string) $item->getPathname(), strlen($dir) + 1);
            $hashes[$relative] = hash_file('sha256', (string) $item->getPathname());
        }
    }
    ksort($hashes);
    return hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES));
}

function core_update_keys(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);
    return [(string) $private, (string) ($details['key'] ?? '')];
}

function core_update_package(string $path, string $privateKey, string $fromVersion, string $toVersion, array $files, array $extra = []): string
{
    $manifestFiles = [];
    foreach ($files as $name => $content) {
        $manifestFiles[$name] = hash('sha256', $content);
    }
    $manifest = array_replace_recursive([
        'package_type' => 'core',
        'package_id' => basename($path, '.zip'),
        'release_id' => basename($path, '.zip'),
        'version' => $toVersion,
        'build' => 'test-build',
        'source_versions' => ['min' => $fromVersion, 'max' => $fromVersion],
        'from_version' => $fromVersion,
        'to_version' => $toVersion,
        'php' => ['min' => '8.0.0', 'max' => '99.0.0'],
        'required_extensions' => ['openssl', 'pdo_sqlite'],
        'database_types' => ['sqlite'],
        'core_schema_version' => 'test',
        'migrations' => [],
        'files' => $manifestFiles,
        'signature_algorithm' => 'rsa-sha256',
        'key_id' => 'test-key',
        'created_at' => gmdate('c'),
        'security_update' => true,
        'notes' => 'Core update lifecycle test.',
    ], $extra);
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    openssl_sign($json, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('update.json', $json);
    $zip->addFromString('signature.bin', $signature);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return $path;
}

[$site, $settings, $pdo, $currentVersion] = core_update_site();
[$privateKey, $publicKey] = core_update_keys();
$verifier = new SignatureVerifier($publicKey);
$tmp = $site . '/storage/updates/incoming';
$markerFile = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cms\\Core\\Update;\n\nfinal class UpdatedMarker { public const VERSION = '1.2.1'; }\n";
$migration = "<?php\nreturn [\n    'up' => static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE IF NOT EXISTS cms_update_success_marker (id INTEGER PRIMARY KEY)'); },\n    'down' => static function (PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS cms_update_success_marker'); },\n];\n";
$supportReadiness = "<?php\n\ndeclare(strict_types=1);\n\necho 'updated production readiness support';\n";
$supportChecklist = "# Updated Deployment Checklist\n\nProvider storage repair guidance is bundled with Core update support files.\n";
$package = core_update_package($tmp . '/release-good.zip', $privateKey, $currentVersion, '1.2.1', [
    'system/core/Update/UpdatedMarker.php' => $markerFile,
    'system/migrations/2026_08_14_000001_update_success.php' => $migration,
    'scripts/validate_production_readiness.php' => $supportReadiness,
    'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md' => $supportChecklist,
], [
    'features' => ['core_payment_provider_settings', 'core_manual_payment_card_delivery_fulfillment'],
    'acceptance_gates' => ['provider_settings_persist_after_reload', 'card_delivery_manual_capture_trusted_paid_fulfills_once'],
    'migrations' => [[
        'migration_id' => '2026_08_14_000001_update_success',
        'version' => '1.2.1',
        'path' => 'system/migrations/2026_08_14_000001_update_success.php',
        'checksum' => hash('sha256', $migration),
        'affected_objects' => ['table:cms_update_success_marker'],
    ]],
]);

$reader = new UpdatePackageReader();
$manifest = $reader->read($package, $verifier);
core_update_check($manifest->releaseId === 'release-good' && count($manifest->files) === 4, 'verifies legal signed Core package manifest and file hashes');
core_update_check(isset($manifest->files['scripts/validate_production_readiness.php'], $manifest->files['CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md']), 'Core update manifest accepts whitelisted operational support files');
core_update_check(in_array('core_payment_provider_settings', $manifest->features, true) && in_array('card_delivery_manual_capture_trusted_paid_fulfills_once', $manifest->acceptanceGates, true), 'preserves Core update manifest feature and acceptance gate metadata');
$dryBefore = core_update_hash_dir($site . '/content') . core_update_hash_dir($site . '/config');
$plan = (new UpdateService($site, $currentVersion, $verifier))->dryRun($package);
$dryAfter = core_update_hash_dir($site . '/content') . core_update_hash_dir($site . '/config');
core_update_check(($plan['status'] ?? '') === 'dry_run_passed' && $dryBefore === $dryAfter && !is_file($site . '/storage/updates/current-release.json'), 'dry-run validates package without modifying site, database or Core pointer');
core_update_check(in_array('core_manual_payment_card_delivery_fulfillment', $plan['features'] ?? [], true) && in_array('provider_settings_persist_after_reload', $plan['acceptance_gates'] ?? [], true), 'dry-run exposes Core update feature and acceptance gate metadata');
$adminControllerSource = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core/Admin/AdminController.php');
core_update_check(str_contains($adminControllerSource, '包含能力') && str_contains($adminControllerSource, '验收门') && str_contains($adminControllerSource, '完整验证计划 JSON'), 'admin Core update verification page renders feature and acceptance gate summary before raw JSON');
$adminSettingsConfig = require $site . '/config/app.php';
$adminSettingsConfig['updates']['public_key'] = $publicKey;
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$adminController = new AdminController(Settings::fromArray($adminSettingsConfig), new FileLogger($site . '/storage/logs/core-update-admin.log'), $site);
$adminVerify = $adminController->updateVerify(new Request('POST', '/admin/update/verify', [], [
    '_csrf' => CsrfToken::get(),
    'package_path' => $package,
]));
$adminVerifyHtml = $adminVerify->body();
core_update_check(
    $adminVerify->status() === 200
    && str_contains($adminVerifyHtml, '包含能力')
    && str_contains($adminVerifyHtml, '人工支付自动发卡')
    && str_contains($adminVerifyHtml, '验收门')
    && str_contains($adminVerifyHtml, 'capture 后只发卡一次')
    && str_contains($adminVerifyHtml, '完整验证计划 JSON'),
    'admin Core update verification response renders feature and acceptance gate summary HTML'
);
$verifyAuditJson = (string) $pdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'core.update_verified' ORDER BY id DESC LIMIT 1")->fetchColumn();
$verifyAudit = json_decode($verifyAuditJson, true);
core_update_check(
    is_array($verifyAudit)
    && ($verifyAudit['package_name'] ?? '') === 'release-good.zip'
    && ($verifyAudit['release_id'] ?? '') === 'release-good'
    && ($verifyAudit['target_version'] ?? '') === '1.2.1'
    && !str_contains($verifyAuditJson, $tmp),
    'admin Core update verification writes safe audit context without leaking package absolute path'
);
$adminVerifyFailed = $adminController->updateVerify(new Request('POST', '/admin/update/verify', [], [
    '_csrf' => CsrfToken::get(),
    'package_path' => $tmp . '/missing-package.zip',
]));
$verifyFailedAuditJson = (string) $pdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'core.update_verify_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
$verifyFailedAudit = json_decode($verifyFailedAuditJson, true);
core_update_check(
    $adminVerifyFailed->status() === 400
    && is_array($verifyFailedAudit)
    && ($verifyFailedAudit['package_name'] ?? '') === 'missing-package.zip'
    && !str_contains($verifyFailedAuditJson, $tmp),
    'admin Core update verification failure writes safe audit context without leaking package absolute path'
);
$adminExecuteFailed = $adminController->updateExecute(new Request('POST', '/admin/update/execute', [], [
    '_csrf' => CsrfToken::get(),
    'package_path' => $package,
    'confirmation' => 'WRONG',
]));
$executeFailedAuditJson = (string) $pdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'core.update_execute_failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
$executeFailedAudit = json_decode($executeFailedAuditJson, true);
core_update_check(
    $adminExecuteFailed->status() === 400
    && is_array($executeFailedAudit)
    && ($executeFailedAudit['package_name'] ?? '') === 'release-good.zip'
    && !str_contains($executeFailedAuditJson, $tmp),
    'admin Core update execution entry failures write audit evidence without leaking package absolute path'
);

core_update_throws(static fn () => $reader->read(core_update_package($tmp . '/unsigned.zip', $privateKey, $currentVersion, '1.2.1', ['system/core/Update/A.php' => '<?php'], ['key_id' => 'revoked']), new SignatureVerifier($publicKey, ['revoked'])), 'rejects revoked signing key');
$badSig = $tmp . '/bad-signature.zip';
copy($package, $badSig);
$zip = new ZipArchive();
$zip->open($badSig);
$zip->deleteName('signature.bin');
$zip->addFromString('signature.bin', 'bad');
$zip->close();
core_update_throws(static fn () => $reader->read($badSig, $verifier), 'rejects wrong signature');
core_update_throws(static fn () => $reader->read(core_update_package($tmp . '/bad-package-hash.zip', $privateKey, $currentVersion, '1.2.1', ['system/core/Update/B.php' => '<?php'], ['package_sha256' => str_repeat('0', 64)]), $verifier), 'rejects package hash mismatch');
$badFile = core_update_package($tmp . '/bad-file.zip', $privateKey, $currentVersion, '1.2.1', ['system/core/Update/C.php' => '<?php']);
$zip = new ZipArchive();
$zip->open($badFile);
$zip->deleteName('system/core/Update/C.php');
$zip->addFromString('system/core/Update/C.php', 'changed');
$zip->close();
core_update_throws(static fn () => $reader->read($badFile, $verifier), 'rejects single file hash mismatch');
core_update_throws(static fn () => (new UpdateService($site, $currentVersion, $verifier))->dryRun(core_update_package($tmp . '/downgrade.zip', $privateKey, $currentVersion, '1.0.0', ['system/core/Update/D.php' => '<?php'])), 'rejects downgrade during planning');
core_update_throws(static fn () => (new UpdateService($site, $currentVersion, $verifier))->dryRun(core_update_package($tmp . '/bad-php.zip', $privateKey, $currentVersion, '1.2.1', ['system/core/Update/E.php' => '<?php'], ['php' => ['min' => '99.0.0']])), 'rejects PHP incompatibility');
core_update_throws(static fn () => (new UpdateService($site, $currentVersion, $verifier))->dryRun(core_update_package($tmp . '/bad-ext.zip', $privateKey, $currentVersion, '1.2.1', ['system/core/Update/F.php' => '<?php'], ['required_extensions' => ['definitely_missing_ext']])), 'rejects extension incompatibility');
core_update_throws(static fn () => (new UpdateService($site, $currentVersion, $verifier))->dryRun(core_update_package($tmp . '/bad-db.zip', $privateKey, $currentVersion, '1.2.1', ['system/core/Update/FDb.php' => '<?php'], ['database_types' => ['mysql']])), 'rejects database driver incompatibility and MySQL/MariaDB restore boundary without support');
file_put_contents($site . '/storage/updates/core-update.lock', '{}');
core_update_throws(static fn () => (new UpdateService($site, $currentVersion, $verifier))->dryRun($package), 'rejects concurrent Core update lock');
unlink($site . '/storage/updates/core-update.lock');
core_update_throws(static fn () => $reader->read(core_update_package($tmp . '/bad-path.zip', $privateKey, $currentVersion, '1.2.1', ['content/plugins/evil.php' => 'x']), $verifier), 'rejects forbidden non-Core paths in manifest');
core_update_throws(static fn () => $reader->read(core_update_package($tmp . '/bad-support-path.zip', $privateKey, $currentVersion, '1.2.1', ['scripts/unsafe.php' => 'x']), $verifier), 'rejects non-whitelisted operational support paths in manifest');

[$unknownCoreSite, $unknownCoreSettings, $unknownCorePdo, $unknownCoreCurrent] = core_update_site();
$unknownCoreTmp = $unknownCoreSite . '/storage/updates/incoming';
$unknownCorePackage = core_update_package($unknownCoreTmp . '/unknown-current-core.zip', $privateKey, $unknownCoreCurrent, '1.2.1', ['system/core/Update/UnknownCurrentCoreMarker.php' => '<?php']);
file_put_contents($unknownCoreSite . '/system/core/Update/UnexpectedCoreFile.php', "<?php\n");
core_update_throws(static fn () => (new UpdateService($unknownCoreSite, $unknownCoreCurrent, $verifier))->dryRun($unknownCorePackage), 'rejects Core update when current Core contains unknown files');
core_update_remove($unknownCoreSite);

$traversal = $tmp . '/traversal.zip';
$zip = new ZipArchive();
$zip->open($traversal, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$json = json_encode(['package_type' => 'core', 'release_id' => 'traversal', 'version' => '1.2.1', 'from_version' => $currentVersion, 'source_versions' => ['min' => $currentVersion, 'max' => $currentVersion], 'created_at' => gmdate('c'), 'files' => ['system/core/Update/G.php' => hash('sha256', 'x')]]);
openssl_sign($json, $sig, $privateKey, OPENSSL_ALGO_SHA256);
$zip->addFromString('update.json', $json);
$zip->addFromString('signature.bin', $sig);
$zip->addFromString('../evil.php', 'x');
$zip->close();
core_update_throws(static fn () => $reader->read($traversal, $verifier), 'rejects ZIP path traversal');

$many = [];
for ($i = 0; $i < 1002; $i++) {
    $many['system/core/Update/Many' . $i . '.php'] = '<?php';
}
core_update_throws(static fn () => $reader->read(core_update_package($tmp . '/too-many.zip', $privateKey, $currentVersion, '1.2.1', $many), $verifier), 'rejects ZIP bombs by file count');

$beforeConfig = core_update_hash_dir($site . '/config');
$beforePlugins = core_update_hash_dir($site . '/content/plugins');
$beforeThemes = core_update_hash_dir($site . '/content/themes');
$beforeUploads = core_update_hash_dir($site . '/content/uploads');
$adminExecute = $adminController->updateExecute(new Request('POST', '/admin/update/execute', [], [
    '_csrf' => CsrfToken::get(),
    'package_path' => $package,
    'confirmation' => 'UPDATE CORE',
]));
$result = ['status' => $adminExecute->status() === 200 && str_contains($adminExecute->body(), 'Completed') ? 'Completed' : 'Failed'];
$pointer = json_decode((string) file_get_contents($site . '/storage/updates/current-release.json'), true);
core_update_check(($result['status'] ?? '') === 'Completed' && ($pointer['version'] ?? '') === '1.2.1' && is_file((string) $pointer['path'] . '/system/core/Update/UpdatedMarker.php'), 'executes update and atomically switches current-release pointer');
$freshPdo = ConnectionFactory::make(Settings::load($site));
$executeAuditJson = (string) $freshPdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'core.update_execute_completed' ORDER BY id DESC LIMIT 1")->fetchColumn();
$executeAudit = json_decode($executeAuditJson, true);
core_update_check(
    is_array($executeAudit)
    && ($executeAudit['package_name'] ?? '') === 'release-good.zip'
    && ($executeAudit['status'] ?? '') === 'Completed'
    && ($executeAudit['release_id'] ?? '') === 'release-good'
    && !str_contains($executeAuditJson, $tmp),
    'admin Core update execution success writes safe audit context without leaking package absolute path'
);
core_update_check((string) file_get_contents($site . '/scripts/validate_production_readiness.php') === $supportReadiness && (string) file_get_contents($site . '/CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md') === $supportChecklist, 'executes Core update operational support file installation for readiness CLI and deployment checklist');
core_update_check((int) $freshPdo->query("SELECT COUNT(*) FROM cms_update_success_marker")->fetchColumn() === 0, 'migration execution created readable marker table');
core_update_check((int) $freshPdo->query("SELECT COUNT(*) FROM cms_core_update_operations WHERE status = 'Completed'")->fetchColumn() === 1 && (int) $freshPdo->query("SELECT COUNT(*) FROM cms_core_update_migrations WHERE status = 'applied'")->fetchColumn() === 1, 'records operation and applied Core migration state');
core_update_check(core_update_hash_dir($site . '/config') === $beforeConfig && core_update_hash_dir($site . '/content/plugins') === $beforePlugins && core_update_hash_dir($site . '/content/themes') === $beforeThemes && core_update_hash_dir($site . '/content/uploads') === $beforeUploads, 'keeps config, plugins, themes and uploads unchanged after Core update');
core_update_check(count(array_filter(glob($site . '/storage/updates/releases/*') ?: [], 'is_dir')) >= 1 && count(glob($site . '/storage/updates/restore-points/*/core.zip') ?: []) >= 1 && count(glob($site . '/storage/updates/restore-points/*/database.sqlite') ?: []) >= 1, 'keeps old release capacity and verified Core/SQLite restore points');

[$preflightSite, $preflightSettings, $preflightPdo, $preflightCurrent] = core_update_site();
$preflightTmp = $preflightSite . '/storage/updates/incoming';
$badSyntaxPackage = core_update_package($preflightTmp . '/bad-syntax.zip', $privateKey, $preflightCurrent, '1.2.1', ['system/core/Update/BadSyntax.php' => "<?php\nthis is not php"]);
core_update_throws(static fn () => (new UpdateService($preflightSite, $preflightCurrent, $verifier))->execute($badSyntaxPackage, 1, 'UPDATE CORE'), 'rolls back when isolated new Core PHP preflight fails');
core_update_check(!is_file($preflightSite . '/storage/maintenance.mode') && !is_file($preflightSite . '/storage/updates/current-release.json'), 'preflight failure leaves no active maintenance or switched Core pointer');
core_update_remove($preflightSite);

[$failSite, $failSettings, $failPdo, $failCurrent] = core_update_site();
$failTmp = $failSite . '/storage/updates/incoming';
$failMigration = "<?php\nreturn [\n    'up' => static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE IF NOT EXISTS cms_update_fail_marker (id INTEGER PRIMARY KEY)'); throw new RuntimeException('fail migration'); },\n    'down' => static function (PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS cms_update_fail_marker'); },\n];\n";
$failPackage = core_update_package($failTmp . '/release-fail.zip', $privateKey, $failCurrent, '1.2.1', [
    'system/core/Update/FailedMarker.php' => $markerFile,
    'system/migrations/2026_08_14_000002_update_fail.php' => $failMigration,
], [
    'migrations' => [[
        'migration_id' => '2026_08_14_000002_update_fail',
        'version' => '1.2.1',
        'path' => 'system/migrations/2026_08_14_000002_update_fail.php',
        'checksum' => hash('sha256', $failMigration),
        'affected_objects' => ['table:cms_update_fail_marker'],
    ]],
]);
core_update_throws(static fn () => (new UpdateService($failSite, $failCurrent, $verifier))->execute($failPackage, 1, 'UPDATE CORE'), 'rolls back automatically when Core migration fails');
$failFresh = ConnectionFactory::make(Settings::load($failSite));
core_update_check(!is_file($failSite . '/storage/maintenance.mode') && !is_file($failSite . '/storage/recovery.mode') && !is_file($failSite . '/storage/updates/current-release.json'), 'restores old Core pointer and exits maintenance after successful rollback');
core_update_check(!in_array('cms_update_fail_marker', array_map(static fn (array $row): string => (string) $row['name'], $failFresh->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll()), true), 'restores SQLite database after failed migration');

@unlink($failSite . '/storage/recovery.mode');
$manualSteps = ['package_uploaded', 'unpacked', 'restore_points', 'prepare_release'];
$dangerSteps = ['migrations', 'switch', 'health', 'rollback'];
$manualOk = true;
foreach ($manualSteps as $step) {
    file_put_contents($failSite . '/storage/updates/core-update.lock', json_encode(['operation_id' => 'stale-' . $step, 'step' => $step, 'heartbeat_at' => gmdate('c')]));
    $manualOk = $manualOk && ((new UpdateService($failSite, $failCurrent, $verifier))->recoverInterrupted()['status'] ?? '') === 'ManualRecoveryRequired';
    unlink($failSite . '/storage/updates/core-update.lock');
}
$dangerOk = true;
foreach ($dangerSteps as $step) {
    @unlink($failSite . '/storage/recovery.mode');
    file_put_contents($failSite . '/storage/updates/core-update.lock', json_encode(['operation_id' => 'stale-' . $step, 'step' => $step, 'heartbeat_at' => gmdate('c')]));
    $dangerOk = $dangerOk && ((new UpdateService($failSite, $failCurrent, $verifier))->recoverInterrupted()['status'] ?? '') === 'RecoveryMode' && is_file($failSite . '/storage/recovery.mode');
    unlink($failSite . '/storage/updates/core-update.lock');
}
core_update_check($manualOk, 'interrupted safe preparation steps require manual recovery review');
core_update_check($dangerOk, 'interrupted dangerous update steps enter Recovery Mode');
file_put_contents($failSite . '/storage/updates/current-release.json', '{"path":"/missing/release","version":"bad"}');
$pointerRecovery = (new UpdateService($failSite, $failCurrent, $verifier))->recoverInterrupted();
core_update_check(($pointerRecovery['status'] ?? '') === 'RecoveryMode', 'current pointer damage enters Recovery Mode');

file_put_contents($failSite . '/storage/maintenance.mode', '{}');
$maintenanceBody = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg('$_SERVER["REQUEST_URI"]="/"; require "' . $failSite . '/public/index.php";'));
core_update_check(is_string($maintenanceBody) && str_contains($maintenanceBody, 'Maintenance Mode'), 'maintenance page is served for ordinary frontend requests');
@unlink($failSite . '/storage/maintenance.mode');

core_update_remove($site);
core_update_remove($failSite);

if ($failures > 0) {
    fwrite(STDERR, $failures . " Core update lifecycle checks failed.\n");
    exit(1);
}

echo "Core update lifecycle tests passed.\n";
