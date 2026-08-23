<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function admin_density_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function admin_density_remove(string $path): void
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

function admin_density_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $contents);
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-admin-density-static-' . bin2hex(random_bytes(4));
admin_density_remove($root);
foreach (['config', 'storage/logs', 'storage/cache', 'storage/tmp', 'storage/database', 'content/themes/default/templates', 'content/plugins', 'content/uploads'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}

$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/database/test.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Admin Density Static Test', 'url' => 'https://cms.example.test', 'id' => 'admin-density-static', 'secret' => 'admin-density-static-secret'];
$config['security']['encryption_key'] = 'admin-density-static-secret-key';
$config['app']['env'] = 'production';
admin_density_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
admin_density_write($root . '/storage/installed.lock', '{}');

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
(new PaymentProviderSettingsRepository($pdo, (string) $settings->get('security.encryption_key', '')))->save(
    ManualPaymentProvider::PROVIDER_ID,
    '人工确认支付',
    'enabled',
    ['instructions' => '付款后请联系管理员确认。', 'default_provider' => true],
    [],
);

$auth = new AdminAuthenticator($pdo);
$auth->createAdmin('admin@example.test', 'secret-pass', 'Density Admin');
admin_density_check($auth->attempt('admin@example.test', 'secret-pass', '127.0.0.1'), 'admin session authenticates for admin density static render checks');

$admin = new AdminController($settings, new FileLogger($root . '/storage/logs/test.log'), $root);
$_SERVER['REQUEST_URI'] = '/admin/payments/providers?provider_id=' . ManualPaymentProvider::PROVIDER_ID;
$providers = $admin->paymentProviders(new Request('GET', '/admin/payments/providers', ['provider_id' => ManualPaymentProvider::PROVIDER_ID], [], []));
$html = $providers->body();

admin_density_check($providers->status() === 200 && str_contains($html, 'class="admin-shell"') && str_contains($html, 'class="admin-main"') && str_contains($html, 'class="admin-sidebar"'), 'admin pages render through isolated admin shell classes');
admin_density_check(str_contains($html, '.admin-main h1{font-size:26px') && str_contains($html, '.admin-main table{font-size:13px;margin-top:10px;display:block;overflow-x:auto}') && str_contains($html, '.admin-main th,.admin-main td{padding:7px 8px'), 'admin shared CSS keeps compact headings, table density and overflow protection');
admin_density_check(str_contains($html, '.admin-main input,.admin-main select{min-height:34px') && str_contains($html, '.admin-main fieldset{border:1px solid #d8dee8;border-radius:6px;margin:10px 0 8px;padding:10px 12px}') && str_contains($html, 'textarea name="manual_instructions" rows="3"') && str_contains($html, 'textarea name="public_config_json" rows="3"') && str_contains($html, '.admin-main button,.admin-main a.button{font-size:13px') && str_contains($html, '.admin-main .editor-card{padding:14px'), 'admin shared CSS keeps compact form controls, Provider textareas, buttons and cards');
admin_density_check(str_contains($html, '.admin-nav a{display:block') && str_contains($html, 'font-size:14px;line-height:1.25') && str_contains($html, 'padding:7px 10px'), 'admin sidebar navigation uses compact type and spacing');
admin_density_check(str_contains($html, '.admin-main td:first-child,.admin-nowrap{white-space:nowrap}') && str_contains($html, '<code class="admin-nowrap">' . ManualPaymentProvider::PROVIDER_ID . '</code>') && str_contains($html, 'class="admin-tag"') && str_contains($html, 'payment.create') && str_contains($html, 'id="provider-form"'), 'Provider page keeps Provider ID from ugly line breaks and keeps the edit form in the same admin page');
admin_density_check(str_contains($html, 'admin-badge') && str_contains($html, '已配置') && str_contains($html, '启用') && str_contains($html, '是'), 'Provider states render as compact badges with configured, enabled and default signals');

$_SERVER['REQUEST_URI'] = '/articles/front-density';
$front = Cms\Core\Support\View::page('Front Density Probe', '<h1>Front Density Probe</h1>');
admin_density_check(!str_contains($front, 'class="admin-shell"') && !str_contains($front, 'class="admin-main"') && !str_contains($front, 'class="admin-sidebar"'), 'front pages do not receive admin layout classes');

$defaultTheme = file_get_contents(CMS_SOURCE_ROOT . '/content/themes/default/templates/_theme.php');
admin_density_check(is_string($defaultTheme) && !str_contains($defaultTheme, 'admin-main') && !str_contains($defaultTheme, 'admin-sidebar'), 'default frontend theme remains isolated from admin density selectors');

admin_density_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " admin density static checks failed.\n");
    exit(1);
}

echo '[RESULT] Admin density static checks passed.' . PHP_EOL;
