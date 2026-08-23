<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginAdminRequestContext;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Security\CsrfToken;

define('FRIEND_LINKS_SOURCE_ROOT', dirname(__DIR__));

require_once FRIEND_LINKS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

function friend_links_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function friend_links_remove(string $path): void
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

$root = sys_get_temp_dir() . '/cms-friend-links-' . bin2hex(random_bytes(4));
friend_links_remove($root);
foreach (['config', 'storage/logs'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require FRIEND_LINKS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/friend-links.sqlite', 'username' => '', 'password' => '', 'options' => []];
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$pdo = ConnectionFactory::make(Settings::load($root));
$migrations = [];
foreach (glob(FRIEND_LINKS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

$friendMigration = require FRIEND_LINKS_SOURCE_ROOT . '/content/plugins/official.friend-links/migrations/001_friend_links.php';
$friendMigration['up']($pdo);

$runtime = new PluginRuntimeRegistry();
$manager = new PluginManager(
    FRIEND_LINKS_SOURCE_ROOT . '/content/plugins',
    $pdo,
    new FileLogger($root . '/storage/logs/plugin.log'),
    new EventDispatcher(),
    new BlockRegistry(),
    $runtime,
    new OfficialPluginRegistry(FRIEND_LINKS_SOURCE_ROOT),
);
$manager->syncDiscovered();
$manager->setStatus('official.friend-links', PluginLifecycle::ENABLED);
friend_links_check($manager->bootEnabled() >= 1, 'official.friend-links boots as trusted bundled plugin');

$routes = [];
foreach ($runtime->routes() as $route) {
    $routes[$route->method . ' ' . $route->path] = $route;
}
$menus = array_map(static fn ($menu): string => $menu->label . '|' . $menu->path, $runtime->menus());

friend_links_check(isset($routes['GET /links'], $routes['GET /admin/friend-links'], $routes['POST /admin/friend-links']), 'friend links registers front and admin routes');
friend_links_check(in_array('友情链接|/admin/friend-links', $menus, true), 'friend links registers Chinese admin menu item');
friend_links_check($routes['POST /admin/friend-links']->csrf === true && $routes['POST /admin/friend-links']->capability === 'friend_links.manage', 'friend links write route requires CSRF and manage capability');

$forbidden = $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
    'new' => ['name' => '无权限链接', 'url' => 'https://denied.example.test'],
], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.view'], 'c', 'r', '127.0.0.1')]));
friend_links_check($forbidden->status() === 403 && (int) $pdo->query('SELECT COUNT(*) FROM cms_friend_links_links')->fetchColumn() === 0, 'manage capability is required before writing links');

$bad = $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
    'new' => ['name' => '危险链接', 'url' => 'javascript:alert(1)'],
], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.manage'], 'c', 'r', '127.0.0.1')]));
friend_links_check($bad->status() === 400 && (int) $pdo->query('SELECT COUNT(*) FROM cms_friend_links_links')->fetchColumn() === 0, 'javascript URL is rejected and no link is saved');

foreach (['http://localhost/admin', 'https://127.0.0.1/status', 'http://192.168.1.10/', 'https://intranet/'] as $unsafeUrl) {
    $unsafe = $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
        'new' => ['name' => '内部链接', 'url' => $unsafeUrl],
    ], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.manage'], 'c', 'r', '127.0.0.1')]));
    friend_links_check($unsafe->status() === 400 && (int) $pdo->query('SELECT COUNT(*) FROM cms_friend_links_links')->fetchColumn() === 0, 'unsafe internal friend link URL is rejected: ' . $unsafeUrl);
}

$saved = $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
    '_csrf' => CsrfToken::get(),
    'new' => ['name' => '朋友博客', 'url' => 'https://friend.example.test', 'description' => '<b>好朋友</b>', 'sort_order' => '1', 'status' => 'enabled', 'rel' => 'noopener noreferrer sponsored'],
], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.manage'], 'c', 'r', '127.0.0.1')]));
friend_links_check($saved->status() === 302 && (int) $pdo->query('SELECT COUNT(*) FROM cms_friend_links_links')->fetchColumn() === 1, 'valid friend link is saved');
friend_links_check((int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'friend_links.updated' ORDER BY id DESC LIMIT 1")->fetchColumn() === 7, 'friend link updates write audit log with real administrator id');

$duplicate = $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
    '_csrf' => CsrfToken::get(),
    'new' => ['name' => '重复朋友博客', 'url' => 'https://friend.example.test'],
], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.manage'], 'c', 'r', '127.0.0.1')]));
friend_links_check($duplicate->status() === 400 && (int) $pdo->query('SELECT COUNT(*) FROM cms_friend_links_links')->fetchColumn() === 1, 'duplicate friend link URL is rejected');

$hidden = $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
    '_csrf' => CsrfToken::get(),
    'new' => ['name' => '隐藏博客', 'url' => 'https://hidden.example.test', 'description' => '不在前台显示', 'sort_order' => '2', 'status' => 'disabled'],
], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.manage'], 'c', 'r', '127.0.0.1')]));
friend_links_check($hidden->status() === 302 && (int) $pdo->query('SELECT COUNT(*) FROM cms_friend_links_links')->fetchColumn() === 2, 'disabled friend link can be saved for later use');

$searchPage = $routes['GET /admin/friend-links']->handler->__invoke(new Request('GET', '/admin/friend-links', ['q' => '朋友']));
friend_links_check($searchPage->status() === 200 && str_contains($searchPage->body(), '朋友博客') && !str_contains($searchPage->body(), '隐藏博客'), 'admin friend links page supports keyword search');

$disabledPage = $routes['GET /admin/friend-links']->handler->__invoke(new Request('GET', '/admin/friend-links', ['status' => 'disabled']));
friend_links_check($disabledPage->status() === 200 && str_contains($disabledPage->body(), '隐藏博客') && !str_contains($disabledPage->body(), '朋友博客'), 'admin friend links page supports disabled status filter');
friend_links_check(str_contains($disabledPage->body(), '确认删除选中的友情链接吗？') && str_contains($disabledPage->body(), 'delete[]'), 'admin friend links delete action requires visible confirmation');
friend_links_check(str_contains($disabledPage->body(), '前台隐藏') && str_contains($disabledPage->body(), '打开链接'), 'admin friend links page shows clear status labels and link preview actions');

for ($i = 3; $i <= 25; $i++) {
    $routes['POST /admin/friend-links']->handler->__invoke(new Request('POST', '/admin/friend-links', [], [
        '_csrf' => CsrfToken::get(),
        'new' => ['name' => '分页友链 ' . $i, 'url' => 'https://friend-' . $i . '.example.test', 'sort_order' => (string) $i, 'status' => 'enabled'],
    ], ['plugin_admin_context' => new PluginAdminRequestContext('official.friend-links', 7, ['friend_links.manage'], 'c', 'r', '127.0.0.1')]));
}
$pageOne = $routes['GET /admin/friend-links']->handler->__invoke(new Request('GET', '/admin/friend-links'));
friend_links_check($pageOne->status() === 200 && str_contains($pageOne->body(), '第 1 / 2 页，共 25 条') && !str_contains($pageOne->body(), '分页友链 25'), 'admin friend links first page is paginated');
$pageTwo = $routes['GET /admin/friend-links']->handler->__invoke(new Request('GET', '/admin/friend-links', ['page' => '2']));
friend_links_check($pageTwo->status() === 200 && str_contains($pageTwo->body(), '分页友链 25') && str_contains($pageTwo->body(), '第 2 / 2 页，共 25 条'), 'admin friend links second page renders remaining links');
$filteredPage = $routes['GET /admin/friend-links']->handler->__invoke(new Request('GET', '/admin/friend-links', ['q' => '分页友链', 'page' => '1']));
friend_links_check($filteredPage->status() === 200 && str_contains($filteredPage->body(), 'q=%E5%88%86%E9%A1%B5%E5%8F%8B%E9%93%BE') && str_contains($filteredPage->body(), 'page=2'), 'pagination links preserve keyword filter');

$public = $routes['GET /links']->handler->__invoke(new Request('GET', '/links'));
friend_links_check($public->status() === 200 && str_contains($public->body(), '朋友博客') && str_contains($public->body(), 'https://friend.example.test'), 'public links page renders saved links');
friend_links_check(str_contains($public->body(), '<meta name="description" content="友情链接，收录站点合作伙伴和推荐网站。">'), 'public links page includes a friendly meta description');
friend_links_check(str_contains($public->body(), 'rel="noopener noreferrer sponsored"') && !str_contains($public->body(), '<b>'), 'public links escape descriptions and use configured safe external link attributes');
friend_links_check(!str_contains($public->body(), '隐藏博客') && !str_contains($public->body(), 'https://hidden.example.test'), 'public links page hides disabled links');

$friendMigration['down']($pdo);
friend_links_check(!$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'cms_friend_links_links'")->fetch(), 'friend links migration rolls back its own table');

friend_links_remove($root);
echo "Friend links plugin tests passed.\n";
