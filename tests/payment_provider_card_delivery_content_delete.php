<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\CardDelivery\CardDeliveryController;
use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\ExportPackageReader;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaidContentController;
use Cms\Core\Payment\PaidDownloadController;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Routing\Router;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/scripts/diagnose_payment_providers.php';

$failures = 0;

function pp_card_content_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function pp_card_content_remove(string $path): void
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

function pp_card_content_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $contents);
}

function pp_card_content_copy_dir(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
        } else {
            copy((string) $item->getPathname(), $destination);
        }
    }
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-provider-card-content-' . bin2hex(random_bytes(4));
pp_card_content_remove($root);
foreach (['config', 'system/core', 'system/admin', 'system/recovery', 'system/migrations', 'storage/logs', 'storage/cache', 'storage/tmp', 'storage/database', 'content/themes/default/templates', 'content/themes/safe/templates', 'content/plugins', 'content/uploads'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
pp_card_content_copy_dir(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');

$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/database/test.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Provider Card Content Test', 'url' => 'https://cms.example.test', 'id' => 'provider-card-content', 'secret' => 'provider-card-content-secret'];
$config['security']['encryption_key'] = 'provider-card-content-secret-key';
$config['payment']['fixture_provider_enabled'] = true;
$config['app']['env'] = 'production';
pp_card_content_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
pp_card_content_write($root . '/storage/installed.lock', '{}');

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

PaymentProviderRegistry::clear();
PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
PaymentProviderRegistry::register(HostedRedirectPaymentProvider::PROVIDER_ID, new HostedRedirectPaymentProvider());
PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());

$auth = new AdminAuthenticator($pdo);
$auth->createAdmin('admin@example.test', 'secret-pass', 'Provider Admin');
pp_card_content_check($auth->attempt('admin@example.test', 'secret-pass', '127.0.0.1'), 'admin session authenticates for protected backend actions');
$csrf = CsrfToken::get();
$admin = new AdminController($settings, new FileLogger($root . '/storage/logs/test.log'), $root);

$noKeySettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $root . '/storage/database/test.sqlite', 'username' => '', 'password' => '', 'options' => []],
    'site' => ['name' => 'Provider Card Content Test', 'url' => 'https://cms.example.test', 'id' => 'provider-card-content', 'secret' => 'provider-card-content-secret'],
    'app' => ['env' => 'production'],
]);
$noKeyAdmin = new AdminController($noKeySettings, new FileLogger($root . '/storage/logs/test.log'), $root);
$noKeyManualSave = $noKeyAdmin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '付款后请联系管理员确认，确认后系统自动发卡。',
    'public_config_json' => '{}',
], []));
$noKeyRepo = new PaymentProviderSettingsRepository($pdo, '');
$noKeyManualSetting = $noKeyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$noKeyManualPublic = json_decode((string) ($noKeyManualSetting['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check($noKeyManualSave->status() === 302, 'manual Provider structured form saves without payment secret encryption key when no secrets are submitted');
pp_card_content_check(($noKeyManualSetting['status'] ?? '') === 'enabled' && ($noKeyManualPublic['default_provider'] ?? null) === true, 'manual Provider no-key save persists enabled and default flags');
pp_card_content_check((new PaymentProviderSelector($pdo, $noKeySettings))->defaultProviderId() === ManualPaymentProvider::PROVIDER_ID, 'Provider selector discovers no-key manual Provider setting immediately after save');

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$_SERVER['REQUEST_URI'] = '/admin/payments/providers?provider_id=' . ManualPaymentProvider::PROVIDER_ID;
$realFormPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []));
preg_match('/name="_csrf" value="([^"]+)"/', $realFormPage->body(), $realFormTokenMatch);
$realFormToken = html_entity_decode((string) ($realFormTokenMatch[1] ?? ''), ENT_QUOTES, 'UTF-8');
pp_card_content_check(str_contains($realFormPage->body(), 'action="/admin/payments/providers/save"'), 'manual Provider form posts to the dedicated Provider save route without relying on query-string POST routing');
$methodBlockedSave = $admin->paymentProviderSave(new Request('GET', '/admin/payments/providers/save', [], [
    '_csrf' => $realFormToken,
    'provider_settings_form' => '1',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => 'GET 不应保存。',
    'public_config_json' => '{}',
], []));
pp_card_content_check(
    $methodBlockedSave->status() === 405
    && ($methodBlockedSave->headers()['Allow'] ?? '') === 'POST'
    && ($methodBlockedSave->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($methodBlockedSave->body(), 'Provider 配置保存必须通过 POST 请求提交')
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings')->fetchColumn() === 0,
    'Provider settings save rejects non-POST methods before persisting settings'
);
$realFormSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $realFormToken,
    'provider_settings_form' => '1',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '付款后请联系管理员确认，确认后系统自动发卡。',
    'public_config_json' => '   ',
    'secrets_text' => '',
], []));
$realFormReload = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []))->body();
pp_card_content_check($realFormPage->status() === 200 && $realFormToken !== '' && $realFormSave->status() === 302, 'manual Provider saves through token extracted from the real Provider form even when advanced JSON is blank');
pp_card_content_check(str_contains($realFormReload, '已配置') && str_contains($realFormReload, '启用') && str_contains($realFormReload, '是') && str_contains($realFormReload, 'Provider 配置已保存。') === false, 'manual Provider real-form save persists configured, enabled and default state after reload');
pp_card_content_check(str_contains($realFormReload, '<option value="enabled" selected>启用</option>') && str_contains($realFormReload, 'name="default_provider" value="1" checked') && str_contains($realFormReload, '付款后请联系管理员确认，确认后系统自动发卡。'), 'manual Provider edit form reloads with enabled status, default checkbox and instructions preserved');

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$rawProviderBody = http_build_query([
    '_csrf' => $realFormToken,
    'provider_settings_form' => '1',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => 'raw body 保存后应立即可用。',
    'public_config_json' => '{}',
], '', '&', PHP_QUERY_RFC3986);
$capturedRawProviderBody = Request::captureBody('POST', [], 'application/x-www-form-urlencoded; charset=UTF-8', $rawProviderBody);
pp_card_content_check(($capturedRawProviderBody['status'] ?? '') === 'enabled' && ($capturedRawProviderBody['default_provider'] ?? '') === '1', 'Request capture parses URL-encoded raw POST body when $_POST is empty');
$rawBodySave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], $capturedRawProviderBody, []));
$rawBodyRepo = new PaymentProviderSettingsRepository($pdo, (string) $settings->get('security.encryption_key', ''));
$rawBodyManualSetting = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$rawBodyManualPublic = json_decode((string) ($rawBodyManualSetting['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check($rawBodySave->status() === 302 && ($rawBodyManualSetting['status'] ?? '') === 'enabled' && ($rawBodyManualPublic['default_provider'] ?? null) === true, 'manual Provider save persists enabled/default from captured raw POST body');

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$legacyCachedFormSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $realFormToken,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'enabled' => '1',
    'is_default' => '1',
    'manual_instructions' => '旧表单字段保存后也应立即可用。',
    'public_config_json' => '{}',
], []));
$legacyManualSetting = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$legacyManualPublic = json_decode((string) ($legacyManualSetting['public_config_json'] ?? '{}'), true) ?: [];
$legacyEnabledProviders = array_column((new PaymentService($pdo, new PaymentRepository($pdo), (string) $settings->get('security.encryption_key', '')))->enabledProviders(), 'id');
pp_card_content_check($legacyCachedFormSave->status() === 302 && ($legacyManualSetting['status'] ?? '') === 'enabled' && ($legacyManualPublic['default_provider'] ?? null) === true, 'manual Provider save accepts legacy cached enabled/is_default fields and persists them');
pp_card_content_check(in_array(ManualPaymentProvider::PROVIDER_ID, $legacyEnabledProviders, true), 'PaymentService discovers manual Provider after legacy cached form save');

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$legacyPluginOnlySave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $realFormToken,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'name' => '人工确认支付',
    'enabled' => '启用',
    'is_default' => '是',
    'payment_instructions' => '半成品支付插件旧字段保存后必须转成 Core 配置。',
    'config_json' => '{}',
], []));
$legacyPluginOnlySetting = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$legacyPluginOnlyPublic = json_decode((string) ($legacyPluginOnlySetting['public_config_json'] ?? '{}'), true) ?: [];
$legacyPluginOnlyEnabledProviders = array_column((new PaymentService($pdo, new PaymentRepository($pdo), (string) $settings->get('security.encryption_key', '')))->enabledProviders(), 'id');
pp_card_content_check(
    $legacyPluginOnlySave->status() === 302
    && ($legacyPluginOnlySetting['display_name'] ?? '') === '人工确认支付'
    && ($legacyPluginOnlySetting['status'] ?? '') === 'enabled'
    && ($legacyPluginOnlyPublic['default_provider'] ?? null) === true
    && ($legacyPluginOnlyPublic['instructions'] ?? '') === '半成品支付插件旧字段保存后必须转成 Core 配置。'
    && in_array(ManualPaymentProvider::PROVIDER_ID, $legacyPluginOnlyEnabledProviders, true),
    'manual Provider save normalizes half-built payment plugin fields into Core enabled/default eligibility'
);

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$legacyIsEnabledSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $realFormToken,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'title' => '人工确认支付',
    'is_enabled' => 'yes',
    'is_default' => 'on',
    'instructions' => '旧 is_enabled 表单保存后必须转成 Core 配置。',
    'settings_json' => '{}',
], []));
$legacyIsEnabledSetting = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$legacyIsEnabledPublic = json_decode((string) ($legacyIsEnabledSetting['public_config_json'] ?? '{}'), true) ?: [];
$legacyIsEnabledProviders = array_column((new PaymentService($pdo, new PaymentRepository($pdo), (string) $settings->get('security.encryption_key', '')))->enabledProviders(), 'id');
pp_card_content_check(
    $legacyIsEnabledSave->status() === 302
    && ($legacyIsEnabledSetting['display_name'] ?? '') === '人工确认支付'
    && ($legacyIsEnabledSetting['status'] ?? '') === 'enabled'
    && ($legacyIsEnabledPublic['default_provider'] ?? null) === true
    && ($legacyIsEnabledPublic['instructions'] ?? '') === '旧 is_enabled 表单保存后必须转成 Core 配置。'
    && in_array(ManualPaymentProvider::PROVIDER_ID, $legacyIsEnabledProviders, true),
    'manual Provider save normalizes legacy is_enabled/settings_json fields into Core enabled/default eligibility'
);

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$providerRouter = new Router();
$providerRouter->post('/admin/payments/providers', [$admin, 'paymentProviderSave']);
$providerRouter->post('/admin/payments/providers/save', [$admin, 'paymentProviderSave']);
$providerRouter->post('/admin/payments/providers/repair-storage', [$admin, 'paymentProviderRepairStorage']);
$routerSave = $providerRouter->dispatch(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $realFormToken,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'enabled' => 'on',
    'default' => 'true',
    'manual_instructions' => 'Application 路由保存后应立即可用。',
    'public_config_json' => '{}',
], []));
$routerManualSetting = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$routerManualPublic = json_decode((string) ($routerManualSetting['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check($routerSave->status() === 302 && ($routerManualSetting['status'] ?? '') === 'enabled' && ($routerManualPublic['default_provider'] ?? null) === true, 'Router POST persists manual Provider enabled/default state through the registered save route');
$pdo->exec('DELETE FROM cms_payment_provider_settings');
$routerLegacyActionSave = $providerRouter->dispatch(new Request('POST', '/admin/payments/providers', [], [
    '_csrf' => $realFormToken,
    'provider_settings_form' => '1',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '旧表单 action 保存后应立即可用。',
    'public_config_json' => '{}',
    'secrets_text' => '',
], []));
$routerLegacyActionSetting = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$routerLegacyActionPublic = json_decode((string) ($routerLegacyActionSetting['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check(
    $routerLegacyActionSave->status() === 302
    && ($routerLegacyActionSetting['status'] ?? '') === 'enabled'
    && ($routerLegacyActionPublic['default_provider'] ?? null) === true,
    'Router POST also accepts cached Provider forms submitted to /admin/payments/providers'
);
$pdo->exec('DROP INDEX cms_payment_provider_settings_provider_unique');
$pdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '旧重复人工支付', 'disabled', '{}', '', '2026-08-20T00:00:00+00:00', '2026-08-20T00:00:00+00:00')");
$repairGet = $admin->paymentProviderRepairStorage(new Request('GET', '/admin/payments/providers/repair-storage', [], ['_csrf' => $realFormToken], []));
pp_card_content_check(
    $repairGet->status() === 405
    && ($repairGet->headers()['Allow'] ?? '') === 'POST'
    && ($repairGet->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($repairGet->body(), 'Provider 存储修复必须通过 POST 请求提交')
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 2,
    'Provider storage repair rejects non-POST methods before touching legacy rows'
);
$repairBadCsrf = $providerRouter->dispatch(new Request('POST', '/admin/payments/providers/repair-storage', [], ['_csrf' => 'bad-repair-token'], []));
pp_card_content_check($repairBadCsrf->status() === 403 && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 2, 'Provider storage repair route requires valid CSRF before touching legacy rows');
$repairStorage = $providerRouter->dispatch(new Request('POST', '/admin/payments/providers/repair-storage', [], ['_csrf' => $realFormToken], []));
$routerManualAfterRepair = $rawBodyRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$routerManualPublicAfterRepair = json_decode((string) ($routerManualAfterRepair['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check($repairStorage->status() === 302 && (int) $pdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 1 && ($routerManualAfterRepair['status'] ?? '') === 'enabled' && ($routerManualPublicAfterRepair['default_provider'] ?? null) === true, 'Provider storage repair POST cleans duplicate rows and preserves latest enabled default manual Provider');
$repairNoticePage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', ['repaired' => '1'], [], []))->body();
pp_card_content_check(str_contains($repairNoticePage, 'Provider 存储已修复，请重新确认状态。') && str_contains($repairNoticePage, '修复 Provider 存储') && str_contains($repairNoticePage, '/admin/payments/providers/repair-storage'), 'Provider page exposes a CSRF-protected storage repair action with Chinese success notice');

$legacyProviderPdo = new PDO('sqlite::memory:');
$legacyProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyProviderPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacyProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL, display_name VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, public_config_json TEXT NOT NULL, secret_config_ciphertext TEXT NOT NULL, created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL)');
$legacyProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '旧人工支付', 'disabled', '{}', '', '2026-08-20T00:00:00+00:00', '2026-08-20T00:00:00+00:00')");
$legacyProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '旧缓存人工支付', 'disabled', '{}', '', '2026-08-21T00:00:00+00:00', '2026-08-21T00:00:00+00:00')");
$legacyProviderRepo = new PaymentProviderSettingsRepository($legacyProviderPdo, '');
$legacyProviderRepo->save(ManualPaymentProvider::PROVIDER_ID, '人工确认支付', 'enabled', ['instructions' => '重复旧行修复', 'default_provider' => true], []);
$legacyProviderRepo->setDefaultProvider(ManualPaymentProvider::PROVIDER_ID);
$legacyProviderSetting = $legacyProviderRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$legacyProviderPublic = json_decode((string) ($legacyProviderSetting['public_config_json'] ?? '{}'), true) ?: [];
$legacyProviderIndexes = $legacyProviderPdo->query("PRAGMA index_list('cms_payment_provider_settings')")->fetchAll(PDO::FETCH_ASSOC);
$legacyProviderHasUniqueIndex = false;
foreach ($legacyProviderIndexes as $indexRow) {
    if ((string) ($indexRow['name'] ?? '') === 'cms_payment_provider_settings_provider_unique' && (int) ($indexRow['unique'] ?? 0) === 1) {
        $legacyProviderHasUniqueIndex = true;
    }
}
$legacyProviderDuplicateRejected = false;
try {
    $legacyProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '重复人工支付', 'disabled', '{}', '', '2026-08-23T00:00:00+00:00', '2026-08-23T00:00:00+00:00')");
} catch (PDOException) {
    $legacyProviderDuplicateRejected = true;
}
pp_card_content_check(
    (int) $legacyProviderPdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 1
    && ($legacyProviderSetting['status'] ?? '') === 'enabled'
    && ($legacyProviderPublic['default_provider'] ?? null) === true,
    'Provider repository repairs duplicate legacy rows so reload cannot read stale disabled state'
);
pp_card_content_check(
    $legacyProviderHasUniqueIndex && $legacyProviderDuplicateRejected,
    'Provider repository restores provider_id uniqueness after repairing legacy duplicate rows'
);

$sameTimestampProviderPdo = new PDO('sqlite::memory:');
$sameTimestampProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sameTimestampProviderPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$sameTimestampProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id VARCHAR(96) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    public_config_json TEXT NOT NULL,
    secret_config_ciphertext TEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    is_default INTEGER NOT NULL DEFAULT 0,
    config_json TEXT NOT NULL DEFAULT "{}"
)');
$sameTimestampProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json) VALUES ('core.manual-payment', '相同时间旧人工支付 A', 'disabled', '{}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00', 0, 0, '{}')");
$sameTimestampProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json) VALUES ('core.manual-payment', '相同时间旧人工支付 B', 'disabled', '{}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00', 0, 0, '{}')");
$sameTimestampProviderRepo = new PaymentProviderSettingsRepository($sameTimestampProviderPdo, '');
$sameTimestampProviderRepo->save(ManualPaymentProvider::PROVIDER_ID, '人工确认支付', 'enabled', ['instructions' => '相同时间重复旧行保存后必须立即可用。', 'default_provider' => true], []);
$sameTimestampProviderRepo->setDefaultProvider(ManualPaymentProvider::PROVIDER_ID);
$sameTimestampProviderSetting = $sameTimestampProviderRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$sameTimestampProviderPublic = json_decode((string) ($sameTimestampProviderSetting['public_config_json'] ?? '{}'), true) ?: [];
$sameTimestampProviderEnabled = array_column((new PaymentService($sameTimestampProviderPdo, new PaymentRepository($sameTimestampProviderPdo), ''))->enabledProviders(), 'id');
pp_card_content_check(
    (int) $sameTimestampProviderPdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 1
    && ($sameTimestampProviderSetting['status'] ?? '') === 'enabled'
    && (int) ($sameTimestampProviderSetting['enabled'] ?? 0) === 1
    && (int) ($sameTimestampProviderSetting['is_default'] ?? 0) === 1
    && ($sameTimestampProviderPublic['default_provider'] ?? null) === true
    && in_array(ManualPaymentProvider::PROVIDER_ID, $sameTimestampProviderEnabled, true),
    'Provider save first collapses same-timestamp duplicate legacy rows before writing enabled/default eligibility'
);

$partialProviderPdo = new PDO('sqlite::memory:');
$partialProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$partialProviderPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$partialProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL)');
$partialProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id) VALUES ('core.manual-payment')");
$partialProviderRepo = new PaymentProviderSettingsRepository($partialProviderPdo, '');
$partialProviderRepo->save(ManualPaymentProvider::PROVIDER_ID, '人工确认支付', 'enabled', ['instructions' => '旧表补列修复', 'default_provider' => true], []);
$partialProviderSetting = $partialProviderRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$partialProviderPublic = json_decode((string) ($partialProviderSetting['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check(
    ($partialProviderSetting['status'] ?? '') === 'enabled'
    && ($partialProviderSetting['display_name'] ?? '') === '人工确认支付'
    && ($partialProviderPublic['default_provider'] ?? null) === true,
    'Provider repository upgrades partial legacy storage before saving enabled/default state'
);

$legacyMirrorPdo = new PDO('sqlite::memory:');
$legacyMirrorPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyMirrorPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacyMirrorPdo->exec('CREATE TABLE cms_payment_provider_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id VARCHAR(96) NOT NULL,
    display_name VARCHAR(191) NOT NULL DEFAULT "",
    status VARCHAR(32) NOT NULL DEFAULT "disabled",
    public_config_json TEXT NOT NULL DEFAULT "{}",
    secret_config_ciphertext TEXT NOT NULL DEFAULT "",
    created_at VARCHAR(64) NOT NULL DEFAULT "",
    updated_at VARCHAR(64) NOT NULL DEFAULT "",
    enabled INTEGER NOT NULL DEFAULT 0,
    is_enabled INTEGER NOT NULL DEFAULT 0,
    is_default INTEGER NOT NULL DEFAULT 0,
    default_provider INTEGER NOT NULL DEFAULT 0,
    config_json TEXT NOT NULL DEFAULT "{}",
    public_config TEXT NOT NULL DEFAULT "{}",
    instructions TEXT NOT NULL DEFAULT ""
)');
$legacyMirrorRepo = new PaymentProviderSettingsRepository($legacyMirrorPdo, '');
$legacyMirrorRepo->save(ManualPaymentProvider::PROVIDER_ID, '人工确认支付', 'enabled', ['instructions' => '旧列同步保存后应立即可用。', 'default_provider' => true], []);
$legacyMirrorRepo->setDefaultProvider(ManualPaymentProvider::PROVIDER_ID);
$legacyMirrorRow = $legacyMirrorPdo->query("SELECT * FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetch(PDO::FETCH_ASSOC) ?: [];
$legacyMirrorPublic = json_decode((string) ($legacyMirrorRow['public_config_json'] ?? '{}'), true) ?: [];
$legacyMirrorConfig = json_decode((string) ($legacyMirrorRow['config_json'] ?? '{}'), true) ?: [];
$legacyMirrorEnabled = array_column((new PaymentService($legacyMirrorPdo, new PaymentRepository($legacyMirrorPdo), ''))->enabledProviders(), 'id');
pp_card_content_check(
    ($legacyMirrorRow['status'] ?? '') === 'enabled'
    && (int) ($legacyMirrorRow['enabled'] ?? 0) === 1
    && (int) ($legacyMirrorRow['is_enabled'] ?? 0) === 1
    && (int) ($legacyMirrorRow['is_default'] ?? 0) === 1
    && (int) ($legacyMirrorRow['default_provider'] ?? 0) === 1
    && ($legacyMirrorPublic['default_provider'] ?? null) === true
    && ($legacyMirrorConfig['default_provider'] ?? null) === true
    && ($legacyMirrorRow['instructions'] ?? '') === '旧列同步保存后应立即可用。'
    && in_array(ManualPaymentProvider::PROVIDER_ID, $legacyMirrorEnabled, true),
    'Provider repository mirrors enabled/default/manual instructions into legacy payment plugin columns while preserving Core eligibility'
);

$legacyPluginProviderPath = $root . '/storage/database/legacy-plugin-provider.sqlite';
@unlink($legacyPluginProviderPath);
$legacyPluginProviderPdo = new PDO('sqlite:' . $legacyPluginProviderPath);
$legacyPluginProviderPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyPluginProviderPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacyPluginProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id VARCHAR(96) NOT NULL, name VARCHAR(191), enabled INTEGER, is_default INTEGER, config_json TEXT)');
$legacyPluginProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, name, enabled, is_default, config_json) VALUES ('core.manual-payment', '人工确认支付', 1, 1, '{\"instructions\":\"旧支付插件迁移后应立即可用\"}')");
$legacyPluginSettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $legacyPluginProviderPath, 'username' => '', 'password' => '', 'options' => []],
    'site' => ['name' => 'Legacy Plugin Provider Test', 'url' => 'https://cms.example.test', 'id' => 'legacy-plugin-provider', 'secret' => 'legacy-plugin-provider-secret'],
    'app' => ['env' => 'production'],
]);
$legacyPluginRepo = new PaymentProviderSettingsRepository($legacyPluginProviderPdo, '');
$legacyPluginSetting = $legacyPluginRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$legacyPluginPublic = json_decode((string) ($legacyPluginSetting['public_config_json'] ?? '{}'), true) ?: [];
$legacyPluginEnabled = array_column((new PaymentService($legacyPluginProviderPdo, new PaymentRepository($legacyPluginProviderPdo), ''))->enabledProviders(), 'id');
$legacyPluginProviderPage = (new AdminController($legacyPluginSettings, new FileLogger($root . '/storage/logs/test.log'), $root))->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []))->body();
pp_card_content_check(
    ($legacyPluginSetting['status'] ?? '') === 'enabled'
    && ($legacyPluginSetting['display_name'] ?? '') === '人工确认支付'
    && ($legacyPluginPublic['default_provider'] ?? null) === true
    && ($legacyPluginPublic['instructions'] ?? '') === '旧支付插件迁移后应立即可用',
    'Provider repository migrates legacy plugin enabled/is_default/config_json fields into Core provider settings'
);
pp_card_content_check(in_array(ManualPaymentProvider::PROVIDER_ID, $legacyPluginEnabled, true), 'PaymentService discovers manual Provider migrated from legacy plugin storage');
pp_card_content_check(str_contains($legacyPluginProviderPage, '已配置') && str_contains($legacyPluginProviderPage, '启用') && str_contains($legacyPluginProviderPage, '是'), 'Provider admin list displays migrated legacy plugin Provider as configured enabled default');
pp_card_content_check(str_contains($legacyPluginProviderPage, '旧字段同步') && str_contains($legacyPluginProviderPage, '已同步'), 'Provider admin list shows legacy payment plugin field sync status');

$legacyServicePdo = new PDO('sqlite::memory:');
$legacyServicePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyServicePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacyServiceMigrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $legacyServiceMigrations[] = require $file;
}
(new MigrationRunner($legacyServicePdo, $legacyServiceMigrations))->run();
$legacyServicePdo->exec('DROP INDEX cms_payment_provider_settings_provider_unique');
$legacyServicePdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '更早禁用人工支付', 'disabled', '{}', '', '2026-08-19T00:00:00+00:00', '2026-08-19T00:00:00+00:00')");
$legacyServicePdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"instructions\":\"直接支付读取最新配置\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$legacyProviderPayment = (new PaymentService($legacyServicePdo, new PaymentRepository($legacyServicePdo), ''))->createProviderPayment(
    'card_delivery_order',
    'order:legacy-provider',
    ManualPaymentProvider::PROVIDER_ID,
    990,
    'CNY',
    'legacy-provider-direct-payment'
);
pp_card_content_check(
    (string) ($legacyProviderPayment['provider_id'] ?? '') === ManualPaymentProvider::PROVIDER_ID
    && (string) ($legacyProviderPayment['status'] ?? '') === 'pending',
    'PaymentService direct payment creation reads latest Provider setting instead of stale legacy disabled rows'
);
$legacyProviderExport = (new ExportPackageReader())->paymentLedger((new ExportPackageBuilder($root, $legacyServicePdo, '1.2.1-provider-dedupe-test'))->build('provider-settings-dedupe'));
$legacyProviderExportSettings = array_values(array_filter(
    is_array($legacyProviderExport['provider_settings'] ?? null) ? $legacyProviderExport['provider_settings'] : [],
    static fn (array $row): bool => (string) ($row['provider_id'] ?? '') === ManualPaymentProvider::PROVIDER_ID
));
pp_card_content_check(
    count($legacyProviderExportSettings) === 1
    && (string) ($legacyProviderExportSettings[0]['status'] ?? '') === 'enabled',
    'official export deduplicates legacy Provider settings and exports the latest enabled row'
);

$diagnosticRoot = $root . '/payment-diagnostic-site';
foreach (['config', 'storage/database'] as $dir) {
    mkdir($diagnosticRoot . '/' . $dir, 0755, true);
}
$diagnosticConfig = $config;
$diagnosticConfig['database'] = ['dsn' => 'sqlite:' . $diagnosticRoot . '/storage/database/diagnostic.sqlite', 'username' => '', 'password' => '', 'options' => []];
$diagnosticConfig['payment']['fixture_provider_enabled'] = false;
pp_card_content_write($diagnosticRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($diagnosticConfig, true) . ";\n");
$diagnosticSettings = Settings::load($diagnosticRoot);
$diagnosticPdo = ConnectionFactory::make($diagnosticSettings);
(new MigrationRunner($diagnosticPdo, $migrations))->run();
$diagnosticPdo->exec('DROP INDEX cms_payment_provider_settings_provider_unique');
$diagnosticPdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN enabled INTEGER');
$diagnosticPdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN is_default INTEGER');
$diagnosticPdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN config_json TEXT');
$diagnosticPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json) VALUES ('core.manual-payment', '旧人工支付', 'disabled', '{}', '', '2026-08-20T00:00:00+00:00', '2026-08-20T00:00:00+00:00', 1, 1, '{\"instructions\":\"旧插件字段需要迁移\"}')");
$diagnosticPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"instructions\":\"诊断脚本读取最新启用配置\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00')");
$diagnostics = cms_diagnose_payment_providers($diagnosticRoot);
$diagnosticChecks = [];
foreach ($diagnostics['checks'] as $check) {
    $diagnosticChecks[(string) ($check['id'] ?? '')] = $check;
}
pp_card_content_check(($diagnostics['status'] ?? '') === 'error' && isset($diagnosticChecks['provider_settings.unique_provider_id']) && ($diagnosticChecks['provider_settings.unique_provider_id']['ok'] ?? true) === false && isset($diagnosticChecks['provider_settings.legacy_plugin_storage']) && ($diagnosticChecks['provider_settings.legacy_plugin_storage']['ok'] ?? true) === false, 'Payment Provider diagnostic reports duplicate legacy Provider rows and unmigrated plugin storage fields');
pp_card_content_check(($diagnostics['providers'][ManualPaymentProvider::PROVIDER_ID]['row_count'] ?? 0) === 2 && ($diagnostics['providers'][ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) === true, 'Payment Provider diagnostic reads latest manual Provider eligibility without hiding duplicate rows');
pp_card_content_check(
    isset($diagnosticChecks['provider_settings.payment_service_enabled_provider'], $diagnosticChecks['provider_settings.selector_default_provider'])
    && ($diagnosticChecks['provider_settings.payment_service_enabled_provider']['ok'] ?? false) === true
    && ($diagnosticChecks['provider_settings.selector_default_provider']['ok'] ?? false) === true
    && str_contains((string) ($diagnosticChecks['provider_settings.payment_service_enabled_provider']['detail'] ?? ''), ManualPaymentProvider::PROVIDER_ID)
    && str_contains((string) ($diagnosticChecks['provider_settings.selector_default_provider']['detail'] ?? ''), ManualPaymentProvider::PROVIDER_ID),
    'Payment Provider diagnostic probes PaymentService and default selector on a database copy without mutating production storage'
);
pp_card_content_check((int) $diagnosticPdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 2, 'Payment Provider diagnostic is read-only and does not repair legacy rows while inspecting');
$diagnosticHelpOutput = [];
$diagnosticHelpCode = 1;
exec(PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/diagnose_payment_providers.php') . ' --help --root=' . escapeshellarg($root . '/missing-help-site'), $diagnosticHelpOutput, $diagnosticHelpCode);
$diagnosticHelpText = implode("\n", $diagnosticHelpOutput);
pp_card_content_check($diagnosticHelpCode === 0 && str_contains($diagnosticHelpText, 'Usage: php scripts/diagnose_payment_providers.php') && str_contains($diagnosticHelpText, '--repair') && !str_contains($diagnosticHelpText, 'Database DSN is not configured'), 'Payment Provider diagnostic CLI help does not require database configuration');
$diagnosticCliOutput = [];
$diagnosticCliCode = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/diagnose_payment_providers.php') . ' --json --root=' . escapeshellarg($diagnosticRoot), $diagnosticCliOutput, $diagnosticCliCode);
$diagnosticCli = json_decode(implode("\n", $diagnosticCliOutput), true);
pp_card_content_check($diagnosticCliCode === 1 && is_array($diagnosticCli) && ($diagnosticCli['providers'][ManualPaymentProvider::PROVIDER_ID]['default_provider'] ?? false) === true, 'Payment Provider diagnostic CLI emits JSON and exits non-zero when duplicate rows need repair');
pp_card_content_check(is_array($diagnosticCli) && str_contains(json_encode($diagnosticCli['next_actions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '/admin/payments/providers') && str_contains(json_encode($diagnosticCli['next_actions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '旧支付插件字段') && !str_contains(json_encode($diagnosticCli, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '诊断脚本读取最新启用配置'), 'Payment Provider diagnostic CLI gives safe legacy-storage repair next actions without leaking Provider public config values');
$diagnosticRepairOutput = [];
$diagnosticRepairCode = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/diagnose_payment_providers.php') . ' --repair --json --root=' . escapeshellarg($diagnosticRoot), $diagnosticRepairOutput, $diagnosticRepairCode);
$diagnosticRepair = json_decode(implode("\n", $diagnosticRepairOutput), true);
$diagnosticRepairChecks = [];
foreach (is_array($diagnosticRepair) ? ($diagnosticRepair['checks'] ?? []) : [] as $check) {
    $diagnosticRepairChecks[(string) ($check['id'] ?? '')] = $check;
}
pp_card_content_check($diagnosticRepairCode === 0 && is_array($diagnosticRepair) && ($diagnosticRepair['repair']['ok'] ?? false) === true && ($diagnosticRepairChecks['provider_settings.unique_provider_id']['ok'] ?? false) === true && ($diagnosticRepairChecks['provider_settings.legacy_plugin_storage']['ok'] ?? false) === true, 'Payment Provider diagnostic repair CLI migrates legacy plugin storage, cleans duplicate rows and exits successfully');
pp_card_content_check((int) $diagnosticPdo->query("SELECT COUNT(*) FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetchColumn() === 1 && ($diagnosticRepair['providers'][ManualPaymentProvider::PROVIDER_ID]['enabled'] ?? false) === true && ($diagnosticRepair['providers'][ManualPaymentProvider::PROVIDER_ID]['default_provider'] ?? false) === true && ($diagnosticRepair['providers'][ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) === true, 'Payment Provider diagnostic repair preserves latest enabled default manual Provider eligibility');

$reverseLegacyRoot = $root . '/payment-reverse-legacy-site';
foreach (['config', 'storage/database'] as $dir) {
    mkdir($reverseLegacyRoot . '/' . $dir, 0755, true);
}
$reverseLegacyConfig = $config;
$reverseLegacyConfig['database'] = ['dsn' => 'sqlite:' . $reverseLegacyRoot . '/storage/database/reverse-legacy.sqlite', 'username' => '', 'password' => '', 'options' => []];
pp_card_content_write($reverseLegacyRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($reverseLegacyConfig, true) . ";\n");
$reverseLegacyPdo = new PDO('sqlite:' . $reverseLegacyRoot . '/storage/database/reverse-legacy.sqlite');
$reverseLegacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$reverseLegacyPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$reverseLegacyPdo->exec('CREATE TABLE cms_payment_provider_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id VARCHAR(96) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    public_config_json TEXT NOT NULL,
    secret_config_ciphertext TEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    is_default INTEGER NOT NULL DEFAULT 0,
    config_json TEXT NOT NULL DEFAULT "{}",
    instructions TEXT NOT NULL DEFAULT ""
)');
$reverseLegacyPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at, enabled, is_default, config_json, instructions) VALUES ('core.manual-payment', '人工确认支付', 'enabled', '{\"instructions\":\"标准字段已启用但旧字段仍停用\",\"default_provider\":true}', '', '2026-08-22T00:00:00+00:00', '2026-08-22T00:00:00+00:00', 0, 0, '{}', '')");
$reverseDiagnostics = cms_diagnose_payment_providers($reverseLegacyRoot);
$reverseChecks = [];
foreach ($reverseDiagnostics['checks'] as $check) {
    $reverseChecks[(string) ($check['id'] ?? '')] = $check;
}
pp_card_content_check(
    ($reverseDiagnostics['providers'][ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) === true
    && ($reverseChecks['provider_settings.legacy_plugin_storage']['ok'] ?? true) === false
    && str_contains((string) ($reverseChecks['provider_settings.legacy_plugin_storage']['detail'] ?? ''), 'enabled')
    && str_contains((string) ($reverseChecks['provider_settings.legacy_plugin_storage']['detail'] ?? ''), 'default'),
    'Payment Provider diagnostic detects reverse legacy mismatch when Core fields are enabled but old plugin columns remain disabled'
);
$reverseRepair = cms_repair_payment_provider_settings($reverseLegacyRoot);
$reverseRow = $reverseLegacyPdo->query("SELECT * FROM cms_payment_provider_settings WHERE provider_id = 'core.manual-payment'")->fetch(PDO::FETCH_ASSOC) ?: [];
$reverseConfigJson = json_decode((string) ($reverseRow['config_json'] ?? '{}'), true) ?: [];
pp_card_content_check(
    ($reverseRepair['ok'] ?? false) === true
    && (int) ($reverseRow['enabled'] ?? 0) === 1
    && (int) ($reverseRow['is_default'] ?? 0) === 1
    && ($reverseConfigJson['default_provider'] ?? null) === true
    && ($reverseRow['instructions'] ?? '') === '标准字段已启用但旧字段仍停用',
    'Payment Provider storage repair synchronizes old plugin columns from canonical enabled/default Core settings'
);

$noIdProviderRoot = $root . '/payment-no-id-provider-site';
foreach (['config', 'storage/database', 'storage/logs'] as $dir) {
    mkdir($noIdProviderRoot . '/' . $dir, 0755, true);
}
$noIdProviderConfig = $config;
$noIdProviderConfig['database'] = ['dsn' => 'sqlite:' . $noIdProviderRoot . '/storage/database/no-id-provider.sqlite', 'username' => '', 'password' => '', 'options' => []];
$noIdProviderConfig['payment']['fixture_provider_enabled'] = false;
pp_card_content_write($noIdProviderRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($noIdProviderConfig, true) . ";\n");
$noIdProviderSettings = Settings::load($noIdProviderRoot);
$noIdProviderPdo = ConnectionFactory::make($noIdProviderSettings);
(new MigrationRunner($noIdProviderPdo, $migrations))->run();
$noIdProviderAuth = new AdminAuthenticator($noIdProviderPdo);
$noIdProviderAuth->createAdmin('no-id-admin@example.test', 'secret-pass', 'No ID Provider Admin');
$noIdProviderPdo->exec('DROP TABLE cms_payment_provider_settings');
$noIdProviderPdo->exec('CREATE TABLE cms_payment_provider_settings (
    provider_id VARCHAR(96) NOT NULL,
    name VARCHAR(191),
    enabled INTEGER,
    is_default INTEGER,
    config_json TEXT
)');
$noIdProviderPdo->exec("INSERT INTO cms_payment_provider_settings (provider_id, name, enabled, is_default, config_json) VALUES ('core.manual-payment', '旧无主键人工支付', 0, 0, '{}')");
pp_card_content_check($noIdProviderAuth->attempt('no-id-admin@example.test', 'secret-pass', '127.0.0.1'), 'no-id legacy Provider site admin authenticates for real save POST');
$noIdProviderAdmin = new AdminController($noIdProviderSettings, new FileLogger($noIdProviderRoot . '/storage/logs/test.log'), $noIdProviderRoot);
$noIdProviderSave = $noIdProviderAdmin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_settings_form' => '1',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '无主键旧支付插件表保存后必须立即可用。',
    'public_config_json' => '{}',
    'secrets_text' => '',
], []));
$noIdProviderRepo = new PaymentProviderSettingsRepository($noIdProviderPdo, '');
$noIdProviderSetting = $noIdProviderRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$noIdProviderPublic = json_decode((string) ($noIdProviderSetting['public_config_json'] ?? '{}'), true) ?: [];
$noIdProviderPage = $noIdProviderAdmin->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []))->body();
$noIdProviderEnabled = array_column((new PaymentService($noIdProviderPdo, new PaymentRepository($noIdProviderPdo), ''))->enabledProviders(), 'id');
pp_card_content_check(
    $noIdProviderSave->status() === 302
    && in_array('id', array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $noIdProviderPdo->query("PRAGMA table_info('cms_payment_provider_settings')")->fetchAll(PDO::FETCH_ASSOC)), true)
    && ($noIdProviderSetting['status'] ?? '') === 'enabled'
    && ($noIdProviderPublic['default_provider'] ?? null) === true
    && str_contains($noIdProviderPage, '已配置')
    && str_contains($noIdProviderPage, '启用')
    && str_contains($noIdProviderPage, '是')
    && str_contains($noIdProviderPage, '<option value="enabled" selected>启用</option>')
    && in_array(ManualPaymentProvider::PROVIDER_ID, $noIdProviderEnabled, true),
    'Provider save upgrades no-id legacy payment plugin storage and persists manual enabled/default eligibility through reload'
);
$noIdProviderColdPdo = ConnectionFactory::make($noIdProviderSettings);
$noIdProviderColdRepo = new PaymentProviderSettingsRepository($noIdProviderColdPdo, '');
$noIdProviderColdSetting = $noIdProviderColdRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$noIdProviderColdPublic = json_decode((string) ($noIdProviderColdSetting['public_config_json'] ?? '{}'), true) ?: [];
$noIdProviderColdEnabled = array_column((new PaymentService($noIdProviderColdPdo, new PaymentRepository($noIdProviderColdPdo), ''))->enabledProviders(), 'id');
$noIdProviderColdPage = (new AdminController($noIdProviderSettings, new FileLogger($noIdProviderRoot . '/storage/logs/test-cold.log'), $noIdProviderRoot))
    ->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []))
    ->body();
pp_card_content_check(
    ($noIdProviderColdSetting['status'] ?? '') === 'enabled'
    && ($noIdProviderColdPublic['default_provider'] ?? null) === true
    && in_array(ManualPaymentProvider::PROVIDER_ID, $noIdProviderColdEnabled, true)
    && str_contains($noIdProviderColdPage, '已配置')
    && str_contains($noIdProviderColdPage, '启用')
    && str_contains($noIdProviderColdPage, '是'),
    'Provider save remains enabled/default and PaymentService-discoverable across a cold admin request'
);
pp_card_content_check($auth->attempt('admin@example.test', 'secret-pass', '127.0.0.1'), 'admin session restored after isolated no-id Provider save test');

$badProviderCsrf = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => 'bad-provider-csrf',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '付款后请联系管理员确认，确认后系统自动发卡。',
    'public_config_json' => '{}',
], []));
pp_card_content_check($badProviderCsrf->status() === 403 && str_contains($badProviderCsrf->body(), 'CSRF 校验失败，请刷新页面重试。') && str_contains($badProviderCsrf->body(), '/admin/payments/providers?provider_id=core.manual-payment#provider-form'), 'Provider save failure shows Chinese error and returns to the selected Provider form');

$incompleteProviderSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'default_provider' => '1',
    'manual_instructions' => '付款后请联系管理员确认，确认后系统自动发卡。',
    'public_config_json' => '{}',
], []));
pp_card_content_check($incompleteProviderSave->status() === 400 && str_contains($incompleteProviderSave->body(), '保存失败：后台没有收到完整的 Provider 表单字段，请刷新页面后重新保存。'), 'incomplete Provider save shows explicit Chinese failure instead of silently falling back to disabled');

$invalidProviderJsonSave = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '付款后请联系管理员确认，确认后系统自动发卡。',
    'public_config_json' => '{',
], []));
pp_card_content_check(
    $invalidProviderJsonSave->status() === 400
    && str_contains($invalidProviderJsonSave->body(), '保存失败：公共配置 JSON 无效，请检查高级配置。')
    && !str_contains($invalidProviderJsonSave->body(), 'Payment provider public config JSON is invalid.'),
    'Provider save invalid JSON failure is localized and does not expose internal English exception text'
);

$hostedRedirectIncompleteDefault = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => HostedRedirectPaymentProvider::PROVIDER_ID,
    'display_name' => '托管跳转支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'hosted_checkout_url' => '',
    'hosted_return_url_base' => 'https://cms.example.test',
    'public_config_json' => '{}',
], []));
$hostedRedirectAfterIncomplete = (new PaymentProviderSettingsRepository($pdo, (string) $settings->get('security.encryption_key', '')))->setting(HostedRedirectPaymentProvider::PROVIDER_ID);
pp_card_content_check(
    $hostedRedirectIncompleteDefault->status() === 400
    && str_contains($hostedRedirectIncompleteDefault->body(), '托管跳转收银台 URL 未配置')
    && ($hostedRedirectAfterIncomplete === null || (string) ($hostedRedirectAfterIncomplete['status'] ?? '') !== 'enabled'),
    'hosted redirect cannot be saved as enabled default with incomplete checkout configuration'
);

$saveManual = $admin->paymentProviderSave(new Request('POST', '/admin/payments/providers/save', [], [
    '_csrf' => $csrf,
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => '付款后请联系管理员确认，确认后系统自动发卡。',
    'public_config_json' => '{}',
], []));
pp_card_content_check($saveManual->status() === 302, 'configures core.manual-payment through backend structured form');
$settingsRepo = new PaymentProviderSettingsRepository($pdo, (string) $settings->get('security.encryption_key', ''));
$manualSetting = $settingsRepo->setting(ManualPaymentProvider::PROVIDER_ID);
$manualPublic = json_decode((string) ($manualSetting['public_config_json'] ?? '{}'), true) ?: [];
pp_card_content_check(($manualSetting['status'] ?? '') === 'enabled', 'manual Provider setting is enabled after save');
pp_card_content_check(($manualPublic['default_provider'] ?? null) === true, 'manual Provider is stored as default Provider');
pp_card_content_check((new PaymentProviderSelector($pdo, $settings))->defaultProviderId() === ManualPaymentProvider::PROVIDER_ID, 'Card Delivery selector discovers enabled manual Provider');
$serviceEnabledProviders = array_column((new PaymentService($pdo, new PaymentRepository($pdo), (string) $settings->get('security.encryption_key', '')))->enabledProviders(), 'id');
pp_card_content_check(in_array(ManualPaymentProvider::PROVIDER_ID, $serviceEnabledProviders, true), 'PaymentService enabledProviders discovers enabled manual Provider');

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$_SERVER['REQUEST_URI'] = '/admin/payments/providers?provider_id=' . ManualPaymentProvider::PROVIDER_ID;
$appForm = Application::boot($root)->handle(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []));
preg_match('/name="_csrf" value="([^"]+)"/', $appForm->body(), $appTokenMatch);
$appProviderToken = html_entity_decode((string) ($appTokenMatch[1] ?? ''), ENT_QUOTES, 'UTF-8');
$appPostBody = Request::captureBody('POST', [], 'application/x-www-form-urlencoded; charset=UTF-8', http_build_query([
    '_csrf' => $appProviderToken,
    'provider_settings_form' => '1',
    'provider_id' => ManualPaymentProvider::PROVIDER_ID,
    'display_name' => '人工确认支付',
    'status' => 'enabled',
    'default_provider' => '1',
    'manual_instructions' => 'Application 完整请求保存后应立即可用。',
    'public_config_json' => '{}',
    'secrets_text' => '',
], '', '&', PHP_QUERY_RFC3986));
$_SERVER['REQUEST_URI'] = '/admin/payments/providers/save';
$appSave = Application::boot($root)->handle(new Request('POST', '/admin/payments/providers/save', [], $appPostBody, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8']));
$_SERVER['REQUEST_URI'] = '/admin/payments/providers?provider_id=' . ManualPaymentProvider::PROVIDER_ID . '&saved=1';
$appReload = Application::boot($root)->handle(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID, 'saved' => '1'], [], []));
$appEnabledProviders = array_column((new PaymentService(ConnectionFactory::make($settings), new PaymentRepository(ConnectionFactory::make($settings)), (string) $settings->get('security.encryption_key', '')))->enabledProviders(), 'id');
pp_card_content_check(
    $appForm->status() === 200
    && $appProviderToken !== ''
    && $appSave->status() === 302
    && str_contains($appReload->body(), '已配置')
    && str_contains($appReload->body(), '启用')
    && str_contains($appReload->body(), '是')
    && str_contains($appReload->body(), '<option value="enabled" selected>启用</option>')
    && str_contains($appReload->body(), '回读状态：已配置 / 启用 / 默认：是 / PaymentService：可发现')
    && in_array(ManualPaymentProvider::PROVIDER_ID, $appEnabledProviders, true),
    'Application full request saves manual Provider, reloads enabled/default state and exposes it to PaymentService'
);
pp_card_content_check(
    str_contains($appReload->body(), '支付链路自检')
    && str_contains($appReload->body(), '链路可用')
    && str_contains($appReload->body(), 'Provider 配置存储')
    && str_contains($appReload->body(), '配置行：1')
    && str_contains($appReload->body(), '重复 Provider：无')
    && str_contains($appReload->body(), 'PaymentService 可用 Provider')
    && str_contains($appReload->body(), 'Card Delivery 默认 Provider')
    && substr_count($appReload->body(), ManualPaymentProvider::PROVIDER_ID) >= 2,
    'Provider settings page shows storage, PaymentService and Card Delivery selector chain check after saving manual Provider'
);

$_SERVER['REQUEST_URI'] = '/admin/payments/providers';
$providerPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []));
$providerHtml = $providerPage->body();
pp_card_content_check($providerPage->status() === 200 && str_contains($providerHtml, '配置 / 编辑') && str_contains($providerHtml, '已配置') && str_contains($providerHtml, '启用') && str_contains($providerHtml, '是'), 'Provider list shows configured, enabled and default status with edit action');
pp_card_content_check(str_contains($providerHtml, '付款说明') && str_contains($providerHtml, ManualPaymentProvider::PROVIDER_ID), 'Provider edit form loads manual schema fields and selected Provider ID');
pp_card_content_check(!str_contains($providerHtml, FixturePaymentProvider::PROVIDER_ID), 'production Provider page does not show fixture Provider even if registered for tests');

$cardRepo = new CardDeliveryRepository($pdo, (string) $settings->get('security.encryption_key', ''));
$productId = $cardRepo->saveProduct(null, 'Manual Card', 990, 'USD', 'active', 1, null, 'Manual payment card');
pp_card_content_check($cardRepo->importInventory($productId, "CARD-ONE\nCARD-TWO\n") === 2, 'imports card inventory for manual payment checkout');

$checkoutWrongMethod = (new CardDeliveryController($settings))->checkout(new Request('GET', '/card-delivery/' . $productId . '/checkout', [], [], []));
pp_card_content_check($checkoutWrongMethod->status() === 405 && ($checkoutWrongMethod->headers()['Allow'] ?? '') === 'POST' && str_contains($checkoutWrongMethod->body(), '购买请求必须通过 POST 提交'), 'Card Delivery checkout rejects non-POST methods with Chinese message');
$checkoutBadCsrf = (new CardDeliveryController($settings))->checkout(new Request('POST', '/card-delivery/' . $productId . '/checkout', [], [
    '_csrf' => 'bad-token',
    'quantity' => '1',
], []));
pp_card_content_check($checkoutBadCsrf->status() === 403 && ($checkoutBadCsrf->headers()['Cache-Control'] ?? '') === 'private, no-store' && str_contains($checkoutBadCsrf->body(), 'CSRF 校验失败') && !str_contains($checkoutBadCsrf->body(), 'Forbidden'), 'Card Delivery checkout rejects invalid CSRF with Chinese no-store message');

$checkout = (new CardDeliveryController($settings))->checkout(new Request('POST', '/card-delivery/' . $productId . '/checkout', [], [
    '_csrf' => $csrf,
    'quantity' => '1',
], []));
pp_card_content_check($checkout->status() === 202 && str_contains($checkout->body(), '等待支付确认'), 'Card Delivery checkout creates a pending manual payment order');
$payment = (new PaymentRepository($pdo))->paymentByIdempotency((string) $pdo->query('SELECT idempotency_key FROM cms_card_orders ORDER BY id DESC LIMIT 1')->fetchColumn());
pp_card_content_check(is_array($payment) && ($payment['provider_id'] ?? '') === ManualPaymentProvider::PROVIDER_ID && ($payment['status'] ?? '') === 'pending', 'manual checkout creates pending Core Payment');
$paymentId = (int) ($payment['id'] ?? 0);

$getCapture = $admin->paymentCapture(new Request('GET', '/admin/payments/' . $paymentId . '/capture', [], ['_csrf' => $csrf, 'idempotency_key' => 'manual-capture-get'], []));
$pendingAfterGetCapture = (new PaymentRepository($pdo))->payment($paymentId);
pp_card_content_check(
    $getCapture->status() === 405
    && ($getCapture->headers()['Allow'] ?? '') === 'POST'
    && ($getCapture->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($getCapture->body(), '支付操作必须通过 POST 请求提交')
    && ($pendingAfterGetCapture['status'] ?? '') === 'pending'
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_card_deliveries')->fetchColumn() === 0,
    'manual capture rejects non-POST methods before paid status or card delivery'
);
$captureOne = $admin->paymentCapture(new Request('POST', '/admin/payments/' . $paymentId . '/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => 'manual-capture-one',
], []));
$captured = (new PaymentRepository($pdo))->payment($paymentId);
$order = $cardRepo->orders(1)[0] ?? [];
$productAfterCapture = $cardRepo->product($productId);
$deliveries = $cardRepo->deliveriesForOrder($productId, (string) ($order['id'] ?? ''));
$trusted = (new PaymentRepository($pdo))->trustedStatus('card_delivery_order', 'order:' . (int) ($order['id'] ?? 0), 'USD');
pp_card_content_check($captureOne->status() === 302 && ($captured['status'] ?? '') === 'paid' && ($trusted['status'] ?? '') === 'paid', 'manual capture moves Core Payment into trusted paid');
pp_card_content_check(($order['status'] ?? '') === 'delivered' && count($deliveries) === 1, 'manual capture triggers Card Delivery fulfillment and emits one card');
pp_card_content_check((int) ($productAfterCapture['available_count'] ?? -1) === 1 && (int) ($productAfterCapture['delivered_count'] ?? -1) === 1, 'manual capture decrements inventory and increments sold count');
$deliveryCountBeforeRepeat = (int) $pdo->query('SELECT COUNT(*) FROM cms_card_deliveries')->fetchColumn();
$admin->paymentCapture(new Request('POST', '/admin/payments/' . $paymentId . '/capture', [], [
    '_csrf' => $csrf,
    'idempotency_key' => 'manual-capture-repeat',
], []));
pp_card_content_check((int) $pdo->query('SELECT COUNT(*) FROM cms_card_deliveries')->fetchColumn() === $deliveryCountBeforeRepeat, 'repeat capture does not duplicate card delivery records');

$pdo->exec('DELETE FROM cms_payment_provider_settings');
$noProviderResponse = (new CardDeliveryController($settings))->checkout(new Request('POST', '/card-delivery/' . $productId . '/checkout', [], [
    '_csrf' => $csrf,
], []));
pp_card_content_check($noProviderResponse->status() === 400 && str_contains($noProviderResponse->body(), '当前没有可用的支付方式，请联系网站管理员。') && !str_contains($noProviderResponse->body(), 'No enabled payment provider'), 'front checkout shows safe Chinese message when no Provider is configured');
$brokenCardSettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $root . '/storage/missing-database-dir/broken-card.sqlite', 'username' => '', 'password' => '', 'options' => []],
    'site' => ['name' => 'Provider Card Content Test', 'url' => 'https://cms.example.test', 'id' => 'provider-card-content', 'secret' => 'provider-card-content-secret'],
    'security' => ['encryption_key' => 'provider-card-content-secret-key'],
    'app' => ['env' => 'production'],
]);
$brokenCardCheckout = (new CardDeliveryController($brokenCardSettings))->checkout(new Request('POST', '/card-delivery/' . $productId . '/checkout', [], [
    '_csrf' => $csrf,
], []));
$brokenCardComplete = (new CardDeliveryController($brokenCardSettings))->complete(new Request('GET', '/card-delivery/orders/1/complete', ['payment_key' => 'x', 'claim' => 'y'], [], []));
$cardCompleteWrongMethod = (new CardDeliveryController($settings))->complete(new Request('POST', '/card-delivery/orders/1/complete', [], [], []));
pp_card_content_check(
    $brokenCardCheckout->status() === 503
    && $brokenCardComplete->status() === 503
    && $cardCompleteWrongMethod->status() === 405
    && ($brokenCardCheckout->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && ($brokenCardComplete->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && ($cardCompleteWrongMethod->headers()['Allow'] ?? '') === 'GET'
    && str_contains($brokenCardCheckout->body(), '自动发卡暂不可用，请稍后重试或联系网站管理员。')
    && str_contains($brokenCardComplete->body(), '自动发卡暂不可用，请稍后重试或联系网站管理员。')
    && str_contains($cardCompleteWrongMethod->body(), '发卡完成页只能通过 GET 访问')
    && !str_contains($brokenCardCheckout->body(), 'Card delivery is unavailable')
    && !str_contains($brokenCardComplete->body(), 'Card delivery is unavailable')
    && !str_contains($cardCompleteWrongMethod->body(), 'Method Not Allowed'),
    'Card Delivery public unexpected failures and complete method errors show Chinese safe messages'
);
$noProviderAdminPage = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []))->body();
pp_card_content_check(str_contains($noProviderAdminPage, '支付链路自检') && str_contains($noProviderAdminPage, '链路不可用') && str_contains($noProviderAdminPage, '没有已启用且可创建支付的 Provider。'), 'Provider settings page shows Chinese chain-check failure when no Provider is configured');

$noProviderContentRepo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$paidContentId = $noProviderContentRepo->create('article', 'No Provider Paid Content', 'no-provider-paid-content', [
    ['type' => 'paragraph', 'data' => ['text' => 'Paid content body']],
], 'published', [
    'paid_content_enabled' => true,
    'paid_content_price_minor' => 690,
    'paid_content_currency' => 'USD',
    'paid_content_preview_blocks' => 1,
    'paid_content_label' => '解锁全文',
]);
$paidDownloadSource = $root . '/storage/tmp/no-provider-download.txt';
pp_card_content_write($paidDownloadSource, 'paid download body');
$paidMediaId = (new MediaLibrary($pdo, $root . '/content/uploads'))->registerLocalFile($paidDownloadSource, 'no-provider-download.txt');
$paidDownloadContentId = $noProviderContentRepo->create('article', 'No Provider Paid Download', 'no-provider-paid-download', [
    ['type' => 'attachment', 'data' => [
        'media_id' => $paidMediaId,
        'display_name' => 'No Provider Download',
        'paid_enabled' => true,
        'price_minor' => 990,
        'currency' => 'USD',
        'payment_label' => '购买下载',
    ]],
], 'published');
$noProviderPaidContent = (new PaidContentController($settings))->checkout(new Request('POST', '/paid-content/' . $paidContentId . '/checkout', [], [
    '_csrf' => $csrf,
], []));
$noProviderPaidDownload = (new PaidDownloadController($settings))->checkout(new Request('POST', '/paid-download/' . $paidDownloadContentId . '/' . $paidMediaId . '/checkout', [], [
    '_csrf' => $csrf,
], []));
pp_card_content_check(
    $noProviderPaidContent->status() === 400
    && $noProviderPaidDownload->status() === 400
    && str_contains($noProviderPaidContent->body(), '当前没有可用的支付方式，请联系网站管理员。')
    && str_contains($noProviderPaidDownload->body(), '当前没有可用的支付方式，请联系网站管理员。')
    && !str_contains($noProviderPaidContent->body(), 'No enabled payment provider')
    && !str_contains($noProviderPaidDownload->body(), 'No enabled payment provider')
    && (string) ($noProviderPaidContent->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($noProviderPaidDownload->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'paid content and paid download checkout show safe Chinese messages when no Provider is configured'
);
$paidContentCheckoutWrongMethod = (new PaidContentController($settings))->checkout(new Request('GET', '/paid-content/' . $paidContentId . '/checkout', [], [], []));
$paidContentCompleteWrongMethod = (new PaidContentController($settings))->complete(new Request('POST', '/paid-content/' . $paidContentId . '/complete', [], [], []));
$paidDownloadCheckoutWrongMethod = (new PaidDownloadController($settings))->checkout(new Request('GET', '/paid-download/' . $paidDownloadContentId . '/' . $paidMediaId . '/checkout', [], [], []));
$paidDownloadCompleteWrongMethod = (new PaidDownloadController($settings))->complete(new Request('POST', '/paid-download/' . $paidDownloadContentId . '/' . $paidMediaId . '/complete', [], [], []));
$brokenPaymentSettings = Settings::fromArray([
    'database' => ['dsn' => 'sqlite:' . $root . '/storage/missing-database-dir/broken.sqlite', 'username' => '', 'password' => '', 'options' => []],
    'site' => ['name' => 'Provider Card Content Test', 'url' => 'https://cms.example.test', 'id' => 'provider-card-content', 'secret' => 'provider-card-content-secret'],
    'security' => ['encryption_key' => 'provider-card-content-secret-key'],
    'app' => ['env' => 'production'],
]);
$brokenPaidContent = (new PaidContentController($brokenPaymentSettings))->checkout(new Request('POST', '/paid-content/' . $paidContentId . '/checkout', [], [
    '_csrf' => $csrf,
], []));
$brokenPaidDownload = (new PaidDownloadController($brokenPaymentSettings))->checkout(new Request('POST', '/paid-download/' . $paidDownloadContentId . '/' . $paidMediaId . '/checkout', [], [
    '_csrf' => $csrf,
], []));
pp_card_content_check(
    $brokenPaidContent->status() === 500
    && $brokenPaidDownload->status() === 500
    && $paidContentCheckoutWrongMethod->status() === 405
    && $paidContentCompleteWrongMethod->status() === 405
    && $paidDownloadCheckoutWrongMethod->status() === 405
    && $paidDownloadCompleteWrongMethod->status() === 405
    && ($paidContentCheckoutWrongMethod->headers()['Allow'] ?? '') === 'POST'
    && ($paidContentCompleteWrongMethod->headers()['Allow'] ?? '') === 'GET'
    && ($paidDownloadCheckoutWrongMethod->headers()['Allow'] ?? '') === 'POST'
    && ($paidDownloadCompleteWrongMethod->headers()['Allow'] ?? '') === 'GET'
    && (string) ($paidContentCheckoutWrongMethod->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($paidContentCompleteWrongMethod->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($paidDownloadCheckoutWrongMethod->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($paidDownloadCompleteWrongMethod->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && str_contains($brokenPaidContent->body(), '付费内容暂不可用，请稍后重试或联系网站管理员。')
    && str_contains($brokenPaidDownload->body(), '付费下载暂不可用，请稍后重试或联系网站管理员。')
    && str_contains($paidContentCheckoutWrongMethod->body(), '付费内容购买请求必须通过 POST 提交')
    && str_contains($paidContentCompleteWrongMethod->body(), '付费内容完成页只能通过 GET 访问')
    && str_contains($paidDownloadCheckoutWrongMethod->body(), '付费下载购买请求必须通过 POST 提交')
    && str_contains($paidDownloadCompleteWrongMethod->body(), '付费下载完成页只能通过 GET 访问')
    && !str_contains($brokenPaidContent->body(), 'Paid content is unavailable')
    && !str_contains($brokenPaidDownload->body(), 'Paid download is unavailable')
    && !str_contains($paidContentCheckoutWrongMethod->body(), 'Method Not Allowed')
    && !str_contains($paidContentCompleteWrongMethod->body(), 'Method Not Allowed')
    && !str_contains($paidDownloadCheckoutWrongMethod->body(), 'Method Not Allowed')
    && !str_contains($paidDownloadCompleteWrongMethod->body(), 'Method Not Allowed')
    && (string) ($brokenPaidContent->headers()['Cache-Control'] ?? '') === 'private, no-store'
    && (string) ($brokenPaidDownload->headers()['Cache-Control'] ?? '') === 'private, no-store',
    'paid content and paid download unexpected failures and method errors show Chinese no-store fallback messages'
);
$settingsRepo->save(ManualPaymentProvider::PROVIDER_ID, '人工确认支付', 'enabled', ['instructions' => '付款后请联系管理员确认，确认后系统自动发卡。', 'default_provider' => true], []);

$contentRepo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$articleId = $contentRepo->create('article', 'Delete Article', 'delete-article', [
    ['type' => 'paragraph', 'data' => ['text' => 'Article body']],
    ['type' => 'card_delivery', 'data' => ['card_product_id' => $productId, 'show_name' => true]],
], 'published', [
    'preview_token' => '0123456789abcdef0123456789abcdef',
    'preview_expires_at' => gmdate('c', time() + 900),
], ['Tests'], ['Delete']);
$pageId = $contentRepo->create('page', 'Delete Page', 'delete-page', [
    ['type' => 'paragraph', 'data' => ['text' => 'Page body']],
], 'published');
$blockArticleId = $contentRepo->create('article', 'Delete Blocks', 'delete-blocks', [
    ['type' => 'heading', 'data' => ['text' => 'Block heading']],
], 'published');
$pdo->prepare("INSERT INTO cms_media_references (media_id, content_id, block_type, field_name, created_at) VALUES (1, :content_id, 'image', 'media_id', :created_at)")
    ->execute([':content_id' => $blockArticleId, ':created_at' => gmdate('c')]);
$pdo->prepare("INSERT INTO cms_content_events (event_type, content_id, payload_json, created_at) VALUES ('content.scheduled_published', :content_id, :payload_json, :created_at)")
    ->execute([':content_id' => $articleId, ':payload_json' => '{}', ':created_at' => gmdate('c')]);
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/old-delete-article', ':target' => '/articles/delete-article', ':source_platform' => 'delete-test', ':created_at' => gmdate('c')]);
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/old-delete-page', ':target' => '/delete-page', ':source_platform' => 'delete-test', ':created_at' => gmdate('c')]);
$pdo->prepare('INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at) VALUES (:source, :target, 301, :source_platform, :created_at)')
    ->execute([':source' => '/old-other-content', ':target' => '/articles/other-content', ':source_platform' => 'delete-test', ':created_at' => gmdate('c')]);

$listHtml = $admin->contentIndex(new Request('GET', '/admin/content', [], [], []))->body();
pp_card_content_check(str_contains($listHtml, '/admin/content/delete/' . $articleId) && str_contains($listHtml, 'class="admin-danger"') && str_contains($listHtml, '确定要删除这篇内容吗？此操作不可撤销。'), 'content list shows a CSRF-protected delete button with confirmation');
pp_card_content_check($contentRepo->find($articleId) !== null, 'cancelled browser confirm would leave content untouched before POST');
$deleteSeoBefore = Application::boot($root)->handle(new Request('GET', '/sitemap.xml'));
pp_card_content_check(
    $deleteSeoBefore->status() === 200
    && str_contains($deleteSeoBefore->body(), 'https://cms.example.test/articles/delete-article')
    && str_contains($deleteSeoBefore->body(), 'https://cms.example.test/delete-page'),
    'content scheduled for deletion is visible in sitemap before the confirmed delete POST'
);
pp_card_content_check($contentRepo->previewByToken($articleId, '0123456789abcdef0123456789abcdef') !== null, 'content preview token resolves before the confirmed delete POST');

unset($_SESSION['admin_user']);
$unauthorized = $admin->contentDelete(new Request('POST', '/admin/content/delete/' . $articleId, [], ['_csrf' => $csrf], []));
pp_card_content_check($unauthorized->status() === 302 && ($unauthorized->headers()['Location'] ?? '') === '/admin/login', 'unauthorized user cannot delete content');
pp_card_content_check($auth->attempt('admin@example.test', 'secret-pass', '127.0.0.1'), 'admin session restores for delete checks');
$getDelete = $admin->contentDelete(new Request('GET', '/admin/content/delete/' . $articleId, [], ['_csrf' => CsrfToken::get()], []));
pp_card_content_check($getDelete->status() === 405 && ($getDelete->headers()['Allow'] ?? '') === 'POST' && str_contains($getDelete->body(), '删除内容必须通过 POST 请求提交') && $contentRepo->find($articleId) !== null, 'content delete controller rejects GET even if a route is misconfigured');
$badCsrf = $admin->contentDelete(new Request('POST', '/admin/content/delete/' . $articleId, [], ['_csrf' => 'bad-token'], []));
pp_card_content_check($badCsrf->status() === 403 && $contentRepo->find($articleId) !== null, 'invalid CSRF cannot delete content');
$_SESSION['admin_user']['capabilities'] = ['content.write'];
$noDeleteCapabilityList = $admin->contentIndex(new Request('GET', '/admin/content', [], [], []))->body();
$noDeleteCapability = $admin->contentDelete(new Request('POST', '/admin/content/delete/' . $articleId, [], ['_csrf' => CsrfToken::get()], []));
pp_card_content_check(!str_contains($noDeleteCapabilityList, '/admin/content/delete/' . $articleId), 'content list hides delete action when admin lacks content.delete capability');
pp_card_content_check($noDeleteCapability->status() === 403 && str_contains($noDeleteCapability->body(), '当前管理员没有删除内容权限') && $contentRepo->find($articleId) !== null, 'server-side content.delete capability is required before deleting content');
$_SESSION['admin_user']['capabilities'] = ['content.delete'];

$deleteArticle = $admin->contentDelete(new Request('POST', '/admin/content/delete/' . $articleId, [], ['_csrf' => CsrfToken::get()], []));
pp_card_content_check($deleteArticle->status() === 302 && ($deleteArticle->headers()['Location'] ?? '') === '/admin/content?deleted=1', 'Article delete succeeds and redirects to content list');
pp_card_content_check($contentRepo->find($articleId) === null && $contentRepo->publicBySlug('article', 'delete-article') === null, 'deleted Article no longer appears in repository or public URL lookup');
$deletedArticleFront = Application::boot($root)->handle(new Request('GET', '/articles/delete-article'));
$deletedArticleLegacy = Application::boot($root)->handle(new Request('GET', '/old-delete-article'));
$deletedArticlePreview = Application::boot($root)->handle(new Request('GET', '/preview/' . $articleId, ['token' => '0123456789abcdef0123456789abcdef'], [], []));
pp_card_content_check($deletedArticleFront->status() === 404 && $deletedArticleLegacy->status() === 404, 'deleted Article public and legacy mapped URLs no longer resolve or redirect');
pp_card_content_check($contentRepo->previewByToken($articleId, '0123456789abcdef0123456789abcdef') === null && $deletedArticlePreview->status() === 403 && str_contains($deletedArticlePreview->body(), '预览链接无效或已过期。'), 'deleted Article preview token no longer resolves after hard delete');
pp_card_content_check(
    (int) $pdo->query('SELECT COUNT(*) FROM cms_content_terms WHERE content_id = ' . $articleId)->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_content_events WHERE content_id = ' . $articleId)->fetchColumn() === 0,
    'Article delete removes category, tag and scheduled content event rows'
);
pp_card_content_check((int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE target_url = '/articles/delete-article'")->fetchColumn() === 0, 'Article delete removes URL mappings that target the deleted public article route');
pp_card_content_check($cardRepo->product($productId) !== null && (int) $pdo->query('SELECT COUNT(*) FROM cms_card_inventory WHERE product_id = ' . $productId)->fetchColumn() === 2, 'deleting a card_delivery block article does not delete Card Product or inventory');

$deletePage = $admin->contentDelete(new Request('POST', '/admin/content/delete/' . $pageId, [], ['_csrf' => CsrfToken::get()], []));
pp_card_content_check($deletePage->status() === 302 && $contentRepo->find($pageId) === null && $contentRepo->publicBySlug('page', 'delete-page') === null, 'Page delete succeeds and front page lookup no longer resolves');
$deletedPageFront = Application::boot($root)->handle(new Request('GET', '/delete-page'));
$deletedPageLegacy = Application::boot($root)->handle(new Request('GET', '/old-delete-page'));
pp_card_content_check($deletedPageFront->status() === 404 && $deletedPageLegacy->status() === 404, 'deleted Page public and legacy mapped URLs no longer resolve or redirect');
pp_card_content_check(
    (int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE target_url = '/delete-page'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_url_mappings WHERE target_url = '/articles/other-content'")->fetchColumn() === 1,
    'Page delete removes its own URL mappings while preserving mappings for other content'
);
$deleteSeoAfter = Application::boot($root)->handle(new Request('GET', '/sitemap.xml'));
pp_card_content_check(
    $deleteSeoAfter->status() === 200
    && !str_contains($deleteSeoAfter->body(), 'delete-article')
    && !str_contains($deleteSeoAfter->body(), 'delete-page'),
    'deleted Article and Page are removed from sitemap output'
);

$deleteBlockArticle = $admin->contentDelete(new Request('POST', '/admin/content/delete/' . $blockArticleId, [], ['_csrf' => CsrfToken::get()], []));
pp_card_content_check($deleteBlockArticle->status() === 302 && (int) $pdo->query('SELECT COUNT(*) FROM cms_media_references WHERE content_id = ' . $blockArticleId)->fetchColumn() === 0, 'content delete clears block-owned media references and leaves no obvious orphan rows');

$deleteMissing = $admin->contentDelete(new Request('POST', '/admin/content/delete/999999', [], ['_csrf' => CsrfToken::get()], []));
pp_card_content_check($deleteMissing->status() === 400 && str_contains($deleteMissing->body(), '删除失败，请稍后重试。'), 'content delete failure returns a clear Chinese error');

if ($failures > 0) {
    fwrite(STDERR, $failures . " payment Provider, Card Delivery and content delete checks failed.\n");
    pp_card_content_remove($root);
    exit(1);
}

pp_card_content_remove($root);
echo '[RESULT] Payment Provider, Card Delivery manual capture and content delete checks passed.' . PHP_EOL;
