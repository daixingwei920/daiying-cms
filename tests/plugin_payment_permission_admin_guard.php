<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;

$dbFile = tempnam(sys_get_temp_dir(), 'daiying-permission-admin-');
if ($dbFile === false) {
    throw new RuntimeException('Unable to create isolated database.');
}
register_shutdown_function(static function () use ($dbFile): void {
    @unlink($dbFile);
});
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_admin_users (id INTEGER PRIMARY KEY, email TEXT, display_name TEXT)');
$pdo->exec("INSERT INTO cms_admin_users VALUES (1, 'admin@example.invalid', 'Fixture admin')");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$controller = new AdminController(
    Settings::fromArray(['database' => ['dsn' => 'sqlite:' . $dbFile]]),
    new FileLogger(sys_get_temp_dir() . '/daiying-permission-admin-guard.log'),
    dirname(__DIR__),
);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
};
$request = static fn (string $method, array $body = []): Request => new Request($method, '/admin/plugins/permissions/grant', [], $body);
$body = ['plugin_id' => 'local.permission-fixture', 'confirm_plugin_id' => 'local.permission-fixture', 'version' => '1.0.0'];

$check($controller->pluginPermissionGrant($request('POST', $body))->status() === 302, 'Anonymous grant request redirects to login');
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.invalid', 'display_name' => 'Fixture admin', 'capabilities' => []];
$check($controller->pluginPermissionGrant($request('POST', $body))->status() === 403, 'Admin without plugins.manage is denied');
$_SESSION['admin_user']['capabilities'] = ['plugins.manage'];
$check($controller->pluginPermissionGrant($request('GET', $body))->status() === 405, 'GET cannot grant permission');
$check($controller->pluginPermissionGrant($request('POST', $body))->status() === 403, 'Missing CSRF is denied');
$body['_csrf'] = CsrfToken::get();
$check($controller->pluginPermissionGrant($request('POST', $body))->status() === 403, 'Missing recent reauthentication is denied');
$_SESSION['admin_reauthenticated_at'] = time() - 601;
$check($controller->pluginPermissionGrant($request('POST', $body))->status() === 403, 'Expired 600-second reauthentication is denied');
$_SESSION['admin_reauthenticated_at'] = time();
$body['confirm_plugin_id'] = 'local.other';
$check($controller->pluginPermissionGrant($request('POST', $body))->status() === 400, 'Wrong typed plugin ID is denied before service call');
$check($controller->pluginPermissionRevoke($request('POST', $body))->status() === 400, 'Revoke requires the same exact identity confirmation');
$check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'cms_plugin_permission_grants'")->fetchColumn() === 0,
    'Denied requests never initialized or wrote a grant table');

echo 'Plugin payment permission admin guard: ' . $checks . ' checks passed.' . PHP_EOL;
