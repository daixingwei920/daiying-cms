<?php

declare(strict_types=1);

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Recovery\RecoveryException;
use Cms\Core\Recovery\RestorePointService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function release_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function release_remove(string $path): void
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

function release_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/recovery/') || str_starts_with($relative, 'storage/updates/')) {
            continue;
        }
        $dest = $target . '/' . $relative;
        $item->isDir() ? (!is_dir($dest) && mkdir($dest, 0755, true)) : copy((string) $item->getPathname(), $dest);
    }
}

function release_throws(callable $callback, string $message): void
{
    try {
        $callback();
        release_check(false, $message);
    } catch (RecoveryException) {
        release_check(true, $message);
    }
}

$root = sys_get_temp_dir() . '/cms-release-batch7-' . bin2hex(random_bytes(4));
release_remove($root);
release_copy(CMS_SOURCE_ROOT, $root);
foreach (['storage/logs', 'storage/cache', 'storage/recovery', 'storage/updates/history', 'content/themes/default', 'content/plugins/demo', 'content/uploads'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        mkdir($root . '/' . $dir, 0755, true);
    }
}
file_put_contents($root . '/content/plugins/demo/plugin.php', 'plugin');
file_put_contents($root . '/content/uploads/file.txt', 'upload');
$config = require $root . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/release.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Release Diagnostics', 'url' => 'https://release.example.test', 'id' => 'release-site', 'secret' => 'super-secret-token'];
$config['app']['debug'] = false;
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
$pdo->exec("INSERT INTO cms_core_settings (setting_key, setting_value, updated_at) VALUES ('release_marker','before','now')");

$app = Application::boot($root);
$health = $app->handle(new Request('GET', '/health'));
release_check($health->status() === 200 && str_contains($health->body(), '"status":"ok"'), 'startup boots and health endpoint is available');
$log = file_get_contents($root . '/storage/logs/app.log') ?: '';
release_check(str_contains($log, 'Core startup') && !str_contains($log, $root) && !str_contains($log, 'super-secret-token'), 'startup log is written and redacts absolute paths and secrets');

$restoreService = new RestorePointService($root);
$restorePath = $restoreService->create('batch7-release-test');
$manifest = $restoreService->verify($restorePath);
release_check(is_file($restorePath) && ($manifest['database']['driver'] ?? '') === 'sqlite' && isset($manifest['checksums']['database/database.sqlite']), 'creates verified restore point with real SQLite database backup');

$unsafeManifestZip = $root . '/storage/recovery/restore-unsafe-manifest.zip';
$zip = new ZipArchive();
$zip->open($unsafeManifestZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('system/core/../escape.php', 'escape');
$zip->addFromString('manifest.json', json_encode([
    'checksums' => [
        'system/core/../escape.php' => hash('sha256', 'escape'),
    ],
], JSON_UNESCAPED_SLASHES));
$zip->close();
release_throws(static fn () => $restoreService->verify($unsafeManifestZip), 'rejects restore point manifest entries with path traversal');

$undeclaredZip = $root . '/storage/recovery/restore-undeclared-entry.zip';
$zip = new ZipArchive();
$zip->open($undeclaredZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('config/app.php', (string) file_get_contents($root . '/config/app.php'));
$zip->addFromString('content/uploads/extra.txt', 'undeclared');
$zip->addFromString('manifest.json', json_encode([
    'checksums' => [
        'config/app.php' => hash_file('sha256', $root . '/config/app.php'),
    ],
], JSON_UNESCAPED_SLASHES));
$zip->close();
release_throws(static fn () => $restoreService->verify($undeclaredZip), 'rejects restore point ZIP entries that are not declared in the manifest');

$unsafeExtraZip = $root . '/storage/recovery/restore-unsafe-extra.zip';
$zip = new ZipArchive();
$zip->open($unsafeExtraZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('system/core/Recovery/RunMode.php', (string) file_get_contents($root . '/system/core/Recovery/RunMode.php'));
$zip->addFromString('system/core/../../escape.php', 'escape');
$zip->addFromString('manifest.json', json_encode([
    'checksums' => [
        'system/core/Recovery/RunMode.php' => hash_file('sha256', $root . '/system/core/Recovery/RunMode.php'),
    ],
], JSON_UNESCAPED_SLASHES));
$zip->close();
release_throws(static fn () => $restoreService->restoreCore($unsafeExtraZip), 'rejects restore point ZIP entries with undeclared path traversal during Core restore');

$pdo->exec("UPDATE cms_core_settings SET setting_value = 'after' WHERE setting_key = 'release_marker'");
$restoreService->restoreDatabase($restorePath);
$restoredPdo = ConnectionFactory::make(Settings::load($root));
release_check((string) $restoredPdo->query("SELECT setting_value FROM cms_core_settings WHERE setting_key = 'release_marker'")->fetchColumn() === 'before', 'restores database from verified restore point');

$coreFile = $root . '/system/core/Recovery/RunMode.php';
$originalCoreHash = hash_file('sha256', $coreFile);
file_put_contents($coreFile, "<?php\nbroken");
$restoreService->restoreCore($restorePath);
release_check(hash_file('sha256', $coreFile) === $originalCoreHash, 'restores Core files from verified restore point');

$diagnosticPdo = ConnectionFactory::make(Settings::load($root));
$diagnosticPdo->exec('DROP INDEX IF EXISTS cms_payment_provider_settings_provider_unique');
$diagnosticPdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN enabled INTEGER');
$diagnosticPdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN is_default INTEGER');
$diagnosticPdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN config_json TEXT');
$diagnosticPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json) VALUES ('core.manual-payment', '旧人工支付', 'disabled', '{}', '', '2026-08-20T00:00:00+00:00', '2026-08-20T00:00:00+00:00', 1, 1, '{\"instructions\":\"后台诊断不应泄露旧插件付款说明\"}')");
$diagnosticPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"instructions\":\"后台诊断不应泄露付款说明\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$diagnosticPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json) VALUES ('core.hosted-redirect', '托管跳转支付', 'enabled', '{\"checkout_url\":\"https://pay.example.test/checkout\",\"return_url_base\":\"https://release.example.test\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00', 0, 0, '{}')");

$diagnostics = Application::boot($root)->handle(new Request('GET', '/diagnostics'));
release_check($diagnostics->status() === 200 && str_contains($diagnostics->body(), 'core_integrity') && str_contains($diagnostics->body(), 'restore_point_count') && !str_contains($diagnostics->body(), 'recent_startup_logs') && !str_contains($diagnostics->body(), 'payment_providers'), 'public diagnostics page renders safe summary without recent logs or Provider internals');
release_check(!str_contains($diagnostics->body(), basename($restorePath)) && !str_contains($diagnostics->body(), $root), 'public diagnostics page does not expose restore point names or absolute paths');
file_put_contents($root . '/storage/logs/app.log', json_encode([
    'time' => gmdate('c'),
    'level' => 'ERROR',
    'message' => 'legacy failure password=hunter2 token=abc123 dsn=mysql:host=127.0.0.1;dbname=private_db file=' . $root . '/secret.php',
    'context' => [
        'api_token' => 'raw-token-value',
        'nested' => [
            'session_secret' => 'raw-session-secret',
            'path' => $root . '/nested/path.php',
        ],
    ],
], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
file_put_contents($root . '/storage/logs/app.log', json_encode([
    'time' => gmdate('c'),
    'level' => 'WARNING',
    'message' => 'plugin warning with token=plugin-secret-value',
    'context' => ['source' => 'Plugin', 'path' => $root . '/content/plugins/demo/plugin.php'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
$redactedDiagnostics = Application::boot($root)->handle(new Request('GET', '/diagnostics'));
release_check($redactedDiagnostics->status() === 200 && !str_contains($redactedDiagnostics->body(), 'recent_startup_logs') && !str_contains($redactedDiagnostics->body(), 'hunter2') && !str_contains($redactedDiagnostics->body(), 'abc123') && !str_contains($redactedDiagnostics->body(), 'private_db') && !str_contains($redactedDiagnostics->body(), 'raw-token-value') && !str_contains($redactedDiagnostics->body(), 'raw-session-secret') && !str_contains($redactedDiagnostics->body(), $root), 'public diagnostics omits recent logs and legacy secrets');
$adminDiagnosticsAnon = Application::boot($root)->handle(new Request('GET', '/admin/diagnostics'));
release_check($adminDiagnosticsAnon->status() === 302 && ($adminDiagnosticsAnon->headers()['Location'] ?? '') === '/admin/login', 'admin diagnostics page requires an administrator session');
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$adminDiagnostics = Application::boot($root)->handle(new Request('GET', '/admin/diagnostics'));
release_check($adminDiagnostics->status() === 200 && str_contains($adminDiagnostics->body(), 'recent_startup_logs') && str_contains($adminDiagnostics->body(), basename($restorePath)) && !str_contains($adminDiagnostics->body(), 'hunter2') && !str_contains($adminDiagnostics->body(), 'abc123') && !str_contains($adminDiagnostics->body(), 'private_db') && !str_contains($adminDiagnostics->body(), 'raw-token-value') && !str_contains($adminDiagnostics->body(), 'raw-session-secret') && !str_contains($adminDiagnostics->body(), $root), 'admin diagnostics shows full diagnostic scope with recursive redaction');
release_check(str_contains($adminDiagnostics->body(), 'recent_log_summary') && str_contains($adminDiagnostics->body(), 'error_count') && str_contains($adminDiagnostics->body(), 'warning_count') && str_contains($adminDiagnostics->body(), 'severe_count') && str_contains($adminDiagnostics->body(), 'source_counts') && str_contains($adminDiagnostics->body(), 'Plugin') && !str_contains($adminDiagnostics->body(), 'plugin-secret-value'), 'admin diagnostics summarizes severe recent logs by level and source without leaking log secrets');
release_check(str_contains($adminDiagnostics->body(), 'runtime_requirements') && str_contains($adminDiagnostics->body(), 'php_min') && str_contains($adminDiagnostics->body(), 'required_extensions') && str_contains($adminDiagnostics->body(), 'fileinfo') && str_contains($adminDiagnostics->body(), 'zip'), 'admin diagnostics reports shared RuntimeRequirements PHP version and extension list');
release_check(str_contains($adminDiagnostics->body(), 'php_fileinfo') && str_contains($adminDiagnostics->body(), '媒体上传 MIME 类型安全检测'), 'admin diagnostics reports PHP Fileinfo status and purpose');
release_check(str_contains($adminDiagnostics->body(), 'php_ziparchive') && str_contains($adminDiagnostics->body(), '主题/插件 ZIP 安装') && str_contains($adminDiagnostics->body(), 'Core 更新包'), 'admin diagnostics reports PHP ZipArchive status and package/update purpose');
release_check(str_contains($adminDiagnostics->body(), 'payment_providers') && str_contains($adminDiagnostics->body(), 'needs_repair') && str_contains($adminDiagnostics->body(), 'duplicate_rows') && str_contains($adminDiagnostics->body(), 'legacy_plugin_storage') && str_contains($adminDiagnostics->body(), 'legacy_storage_provider_ids') && str_contains($adminDiagnostics->body(), 'manual_payment_ready') && !str_contains($adminDiagnostics->body(), '后台诊断不应泄露付款说明') && !str_contains($adminDiagnostics->body(), '后台诊断不应泄露旧插件付款说明'), 'admin diagnostics reports Provider duplicate and legacy storage health without leaking Provider public config values');
release_check(str_contains($adminDiagnostics->body(), 'legacy_storage_issues') && str_contains($adminDiagnostics->body(), 'core.hosted-redirect') && str_contains($adminDiagnostics->body(), 'enabled') && str_contains($adminDiagnostics->body(), 'default') && str_contains($adminDiagnostics->body(), 'config_json'), 'admin diagnostics reports reverse legacy Provider field mismatches with actionable issue labels');
release_check(str_contains($adminDiagnostics->body(), 'next_actions') && str_contains($adminDiagnostics->body(), '/admin/payments/providers') && str_contains($adminDiagnostics->body(), '修复 Provider 存储') && str_contains($adminDiagnostics->body(), '旧支付插件字段') && str_contains($adminDiagnostics->body(), 'diagnose_payment_providers.php --json --repair'), 'admin diagnostics gives actionable Provider duplicate and legacy storage repair next steps');
unset($_SESSION['admin_user']);

file_put_contents($root . '/storage/recovery.mode', gmdate('c'));
$recovery = Application::boot($root)->handle(new Request('GET', '/recovery'));
$front = Application::boot($root)->handle(new Request('GET', '/'));
release_check($recovery->status() === 200 && str_contains($recovery->body(), '恢复与诊断'), 'Recovery entry remains reachable in Recovery Mode');
release_check(!str_contains($recovery->body(), 'restore_database') && !str_contains($recovery->body(), basename($restorePath)), 'public Recovery entry is read-only and does not expose restore point actions');
$publicAction = Application::boot($root)->handle(new Request('POST', '/recovery/action', [], ['action' => 'enable_safe', '_csrf' => \Cms\Core\Security\CsrfToken::get()]));
release_check($publicAction->status() === 403 && str_contains($publicAction->body(), '恢复操作只能从已登录后台执行') && !is_file($root . '/storage/safe.mode'), 'public Recovery action endpoint rejects dangerous anonymous actions with Chinese error');
$adminRecoveryAnon = Application::boot($root)->handle(new Request('GET', '/admin/recovery'));
release_check($adminRecoveryAnon->status() === 302 && ($adminRecoveryAnon->headers()['Location'] ?? '') === '/admin/login', 'admin Recovery page requires an administrator session');
$adminRecoveryActionAnon = Application::boot($root)->handle(new Request('POST', '/admin/recovery/action', [], ['action' => 'enable_safe', '_csrf' => \Cms\Core\Security\CsrfToken::get()]));
release_check($adminRecoveryActionAnon->status() === 403 && str_contains($adminRecoveryActionAnon->body(), '请先登录后台再执行恢复操作') && !is_file($root . '/storage/safe.mode'), 'admin Recovery action requires login with Chinese error');
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$adminRecovery = Application::boot($root)->handle(new Request('GET', '/admin/recovery'));
release_check($adminRecovery->status() === 200 && str_contains($adminRecovery->body(), 'restore_database') && str_contains($adminRecovery->body(), basename($restorePath)), 'admin Recovery page keeps restore point actions for signed-in administrators');
$adminRecoveryBadCsrf = Application::boot($root)->handle(new Request('POST', '/admin/recovery/action', [], ['action' => 'enable_safe', '_csrf' => 'bad-token']));
release_check($adminRecoveryBadCsrf->status() === 403 && str_contains($adminRecoveryBadCsrf->body(), 'CSRF 校验失败') && !is_file($root . '/storage/safe.mode'), 'admin Recovery action rejects invalid CSRF with Chinese error');
$adminRecoveryBadConfirm = Application::boot($root)->handle(new Request('POST', '/admin/recovery/action', [], [
    'action' => 'restore_database',
    'restore_point' => basename($restorePath),
    'confirmation' => 'RESTORE',
    '_csrf' => \Cms\Core\Security\CsrfToken::get(),
]));
release_check($adminRecoveryBadConfirm->status() === 400 && str_contains($adminRecoveryBadConfirm->body(), '恢复操作需要输入完整确认文本'), 'admin Recovery restore rejects incomplete confirmation with Chinese error');
$adminRecoveryUnknown = Application::boot($root)->handle(new Request('POST', '/admin/recovery/action', [], [
    'action' => 'unknown_action',
    '_csrf' => \Cms\Core\Security\CsrfToken::get(),
]));
release_check($adminRecoveryUnknown->status() === 400 && str_contains($adminRecoveryUnknown->body(), '未知恢复操作') && !is_file($root . '/storage/safe.mode'), 'admin Recovery action rejects unknown actions with Chinese error');
$auditCountBeforeRecoveryAction = (int) ConnectionFactory::make(Settings::load($root))->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action LIKE 'recovery.%'")->fetchColumn();
$adminRecoveryEnableSafe = Application::boot($root)->handle(new Request('POST', '/admin/recovery/action', [], [
    'action' => 'enable_safe',
    '_csrf' => \Cms\Core\Security\CsrfToken::get(),
]));
$auditPdo = ConnectionFactory::make(Settings::load($root));
$latestRecoveryAudit = $auditPdo->query("SELECT actor_type, actor_id, action, context_json FROM cms_audit_logs WHERE action = 'recovery.enable_safe' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$latestRecoveryAuditContext = json_decode((string) ($latestRecoveryAudit['context_json'] ?? '{}'), true) ?: [];
release_check(
    $adminRecoveryEnableSafe->status() === 302
    && is_file($root . '/storage/safe.mode')
    && (int) $auditPdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action LIKE 'recovery.%'")->fetchColumn() === $auditCountBeforeRecoveryAction + 1
    && ($latestRecoveryAudit['actor_type'] ?? '') === 'admin'
    && (int) ($latestRecoveryAudit['actor_id'] ?? 0) === 1
    && ($latestRecoveryAuditContext['action'] ?? '') === 'enable_safe'
    && !str_contains((string) ($latestRecoveryAudit['context_json'] ?? ''), $root),
    'admin Recovery successful actions write safe audit records'
);
@unlink($root . '/storage/safe.mode');
unset($_SESSION['admin_user']);
release_check($front->status() === 200, 'frontend does not white-screen in Recovery Mode');
@unlink($root . '/storage/recovery.mode');

file_put_contents($root . '/storage/maintenance.mode', '{}');
$maintenance = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg('$_SERVER["REQUEST_URI"]="/"; require "' . $root . '/public/index.php";'));
@unlink($root . '/storage/maintenance.mode');
release_check(is_string($maintenance) && str_contains($maintenance, 'Maintenance Mode'), 'maintenance page is available during release operations');

$configHash = hash_file('sha256', $root . '/config/app.php');
$pluginHash = hash_file('sha256', $root . '/content/plugins/demo/plugin.php');
$uploadHash = hash_file('sha256', $root . '/content/uploads/file.txt');
release_check($configHash !== false && $pluginHash !== false && $uploadHash !== false, 'config, plugin and upload files remain present after recovery drill');

release_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " release recovery diagnostics checks failed.\n");
    exit(1);
}

echo "Release recovery diagnostics tests passed.\n";
