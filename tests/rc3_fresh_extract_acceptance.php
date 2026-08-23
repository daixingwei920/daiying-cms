<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Install\InstallController;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Official\Commerce\Admin\CheckoutController;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/tests/payment_p1_test_helpers.php';

set_time_limit(300);

$failures = 0;
function final_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function final_remove(string $path): void
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

SessionManager::start(false);

$zipPath = CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.zip';
$root = sys_get_temp_dir() . '/cms-final-fresh-' . bin2hex(random_bytes(4));
final_remove($root);
mkdir($root, 0755, true);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    throw new RuntimeException('Unable to open Final ZIP.');
}
$zip->extractTo($root);
$zip->close();

$settings = Settings::load($root);
$install = new InstallController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$installResponse = $install->store(new Request('POST', '/install', [], [
    '_csrf' => CsrfToken::get(),
    'db_driver' => 'sqlite',
    'sqlite_path' => 'storage/database/final.sqlite',
    'site_name' => 'Final Fresh Site',
    'site_url' => 'https://final.example.test',
    'email' => 'admin@example.test',
    'display_name' => 'Final Admin',
    'password' => 'final-fresh-password',
    'site_id' => 'final-fresh',
    'site_secret' => 'final-fresh-secret',
    'install_action' => 'install',
]));
final_check($installResponse->status() === 302 && is_file($root . '/storage/installed.lock'), 'fresh Final ZIP installs from empty SQLite database');

$pdo = ConnectionFactory::make(Settings::load($root));
$GLOBALS['p1_site_roots'][spl_object_id($pdo)] = $root;
$app = Application::boot($root);
final_check($app->handle(new Request('GET', '/health'))->status() === 200, 'fresh Final health endpoint works');
final_check($app->handle(new Request('GET', '/admin/login'))->status() === 200, 'fresh Final admin login page works');
final_check($app->handle(new Request('GET', '/recovery'))->status() === 200, 'fresh Final Recovery entry works');
final_check(is_file($root . '/content/themes/default/theme.json') && is_file($root . '/content/themes/safe/theme.json'), 'fresh Final contains Daiying Default and Safe themes');

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Final Admin'];
$controller = new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root);
$csrf = CsrfToken::get();
$article = $controller->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_action' => 'save',
    'content_type' => 'article',
    'title' => 'Final Article',
    'slug' => 'final-article',
    'status' => 'published',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Fresh Final article']]],
]));
$page = $controller->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_action' => 'save',
    'content_type' => 'page',
    'title' => 'Final Page',
    'slug' => 'final-page',
    'status' => 'published',
    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Fresh Final page']]],
]));
final_check($article->status() === 302 && $app->handle(new Request('GET', '/articles/final-article'))->status() === 200, 'fresh Final publishes and renders Article detail');
final_check($page->status() === 302 && $app->handle(new Request('GET', '/final-page'))->status() === 200, 'fresh Final publishes and renders independent Page');
final_check($app->handle(new Request('GET', '/'))->status() === 200, 'fresh Final home page renders with Daiying Default');

$installer = new LocalPluginPackageInstaller($root, $pdo);
$installer->installBundled('official.commerce', 1, true);
$installer->installBundled('official.payment-fixture', 1, true);
$installer->installBundled('official.cj-dropshipping', 1, true);
$runtime = p1_boot($pdo);
$app = Application::boot($root);
$services = p1_services($pdo);
$order = p1_pending_order($pdo, 'Final');
$checkout = new CheckoutController($services['checkout'], $services['orders'], $services['fulfillments'], $services['payment']);
$token = $order['public_token'];
final_check($checkout->publicOrder(new Request('GET', '/order/' . $token))->status() === 200, 'fresh Final public order page works');
final_check($checkout->payment(new Request('GET', '/order/' . $token . '/pay'))->status() === 200, 'fresh Final Payment Fixture public payment page works');
$paid = $checkout->pay(new Request('POST', '/order/' . $token . '/pay', [], [
    'provider_id' => 'official.payment-fixture',
    'amount_minor' => (string) $order['total'],
    'currency' => 'USD',
    'scenario' => 'success',
    'idempotency_key' => 'final-fresh-payment',
]));
$paidOrder = $services['orders']->order((int) $order['order']['id']);
final_check($paid->status() === 303 && (string) $paidOrder['status'] === 'paid', 'fresh Final Fixture payment moves order to paid');

$installer->disableWithDependents('official.payment-fixture', 1, true);
$app = Application::boot($root);
final_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.payment-fixture'")->fetchColumn() === PluginLifecycle::DISABLED, 'fresh Final Payment Fixture can be disabled');
final_check($app->handle(new Request('GET', '/shop'))->status() === 200, 'fresh Final Commerce keeps running after Payment Fixture disable');
$installer->disableWithDependents('official.commerce', 1, true);
$app = Application::boot($root);
final_check((string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.cj-dropshipping'")->fetchColumn() !== PluginLifecycle::ENABLED, 'fresh Final CJ depends on Commerce and pauses when Commerce is disabled');
final_check($app->handle(new Request('GET', '/recovery'))->status() === 200, 'fresh Final Recovery remains available after plugin dependency changes');

final_remove($root);

if ($failures > 0) {
    echo '[RESULT] Final fresh-extract acceptance failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] Final fresh-extract acceptance passed.' . PHP_EOL;
