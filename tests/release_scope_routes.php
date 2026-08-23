<?php

declare(strict_types=1);

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Install\InstallController;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function scope_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

function scope_remove(string $path): void
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

function scope_copy(string $source, string $target): void
{
    if (is_file($source)) {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        copy($source, $target);
        return;
    }
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) {
            continue;
        }
        scope_copy($item->getPathname(), $target . '/' . $item->getBasename());
    }
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-release-scope-routes-' . bin2hex(random_bytes(4));
scope_remove($root);
mkdir($root . '/config', 0755, true);
mkdir($root . '/storage/logs', 0755, true);
mkdir($root . '/storage/database', 0755, true);
scope_copy(CMS_SOURCE_ROOT . '/system/migrations', $root . '/system/migrations');
scope_copy(CMS_SOURCE_ROOT . '/system/core', $root . '/system/core');
mkdir($root . '/system/admin', 0755, true);
mkdir($root . '/system/recovery', 0755, true);
scope_copy(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');
scope_copy(CMS_SOURCE_ROOT . '/content/themes/safe', $root . '/content/themes/safe');

$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => '', 'username' => '', 'password' => '', 'options' => []];
unset($config['market']);
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$settings = Settings::load($root);
$controller = new InstallController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$install = $controller->store(new Request('POST', '/install', [], [
    '_csrf' => CsrfToken::get(),
    'db_driver' => 'sqlite',
    'sqlite_path' => 'storage/database/scope.sqlite',
    'site_name' => 'Scope Site',
    'site_url' => 'https://scope.example.test',
    'email' => 'admin@example.test',
    'display_name' => 'Scope Admin',
    'password' => 'scope-secret-pass',
    'site_id' => 'scope-site',
    'site_secret' => 'scope-secret',
    'install_action' => 'install',
]));
scope_check($install->status() === 302, 'temporary scope site installs successfully');

$installedSettings = Settings::load($root);
$pdo = ConnectionFactory::make($installedSettings);
scope_check((new AdminAuthenticator($pdo))->attempt('admin@example.test', 'scope-secret-pass', '127.0.0.1'), 'temporary scope admin session authenticates');

$app = Application::boot($root);
scope_check($app->handle(new Request('GET', '/api/market/search'))->status() === 404, 'market API routes are disabled by default');
scope_check($app->handle(new Request('GET', '/developer/market/login'))->status() === 404, 'developer market routes are disabled by default');
scope_check($app->handle(new Request('GET', '/admin/market-server/operations'))->status() === 404, 'market server admin routes are disabled by default');
scope_check($app->handle(new Request('GET', '/admin/market/plugins'))->status() === 404, 'market install UI routes are disabled by default');
scope_check($app->handle(new Request('GET', '/admin/extensions/updates'))->status() === 404, 'paused remote extension update routes are disabled by default');

$dashboard = $app->handle(new Request('GET', '/admin'));
$body = $dashboard->body();
scope_check($dashboard->status() === 200 && str_contains($body, '/admin/plugins') && str_contains($body, '/admin/update') && str_contains($body, '/admin/recovery'), 'CMS admin dashboard keeps plugin, update and recovery links');
scope_check(!str_contains($body, '插件市场') && !str_contains($body, '市场运营') && !str_contains($body, 'AI 设置') && !str_contains($body, '付款结算') && !str_contains($body, 'Phase'), 'CMS admin dashboard hides paused market, commercial, AI and phase labels by default');

scope_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " release scope route checks failed.\n");
    exit(1);
}

echo "Release scope route tests passed.\n";
