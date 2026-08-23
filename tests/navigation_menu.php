<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Navigation\NavigationBuilder;
use Cms\Core\Security\CsrfToken;

require __DIR__ . '/theme_t1_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = theme_t1_root('navigation');
theme_t1_copy_dir(THEME_T1_SOURCE_ROOT . '/content/plugins/official.commerce', $root . '/content/plugins/official.commerce');
$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$repo = new ContentRepository($pdo, ContentTypeRegistry::defaults());
$pageId = $repo->create('page', '关于我们', 'about', [
    ['type' => 'paragraph', 'data' => ['text' => '关于页面内容']],
], 'published');
$pdo->exec("INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at) VALUES ('official.commerce', 'Commerce', '1.0.0-rc1', 'Daiying', 'Enabled', 'trusted_php', '[]', 'now', 'now')");

$defaultNav = NavigationBuilder::build(Settings::load($root), $pdo, $root);
theme_t1_check(array_column($defaultNav, 'label') === ['首页', '文章', '商城'], 'default navigation includes plugin-declared shop entry when Commerce is enabled');
theme_t1_check(!str_contains((string) file_get_contents(THEME_T1_SOURCE_ROOT . '/system/core/Navigation/NavigationBuilder.php'), 'official.commerce') && !str_contains((string) file_get_contents(THEME_T1_SOURCE_ROOT . '/system/core/Admin/AdminController.php'), "value=\"commerce_shop\""), 'Core navigation UI consumes generic plugin front_navigation metadata without hard-coded Commerce defaults');

$_SESSION['admin_user'] = ['id' => 7, 'email' => 'admin@example.test', 'display_name' => '管理员'];
$token = CsrfToken::get();
$app = theme_t1_app($root);

$index = $app->handle(new Request('GET', '/admin/navigation'));
theme_t1_check($index->status() === 200 && str_contains($index->body(), '导航菜单') && str_contains($index->body(), '快速添加') && str_contains($index->body(), '商城'), 'admin navigation page renders Chinese management UI');

$badMethod = (new AdminController(Settings::load($root), new FileLogger($root . '/storage/logs/app.log'), $root))->navigationSave(new Request('GET', '/admin/navigation', [], [
    '_csrf' => $token,
    'navigation' => [
        ['label' => '不应保存', 'url' => '/bad', 'type' => 'custom', 'enabled' => '1', 'requires_plugin' => ''],
    ],
]));
theme_t1_check($badMethod->status() === 405 && ($badMethod->headers()['Allow'] ?? '') === 'POST' && str_contains($badMethod->body(), '必须通过 POST') && !str_contains((string) file_get_contents($root . '/config/app.php'), '不应保存'), 'admin navigation save rejects non-POST methods before writing config');

$save = $app->handle(new Request('POST', '/admin/navigation', [], [
    '_csrf' => $token,
    'navigation' => [
        ['label' => '首页', 'url' => '/', 'type' => 'home', 'enabled' => '1', 'requires_plugin' => ''],
        ['label' => '鞋店', 'url' => '/shop', 'type' => 'commerce_shop', 'enabled' => '1', 'requires_plugin' => 'official.commerce'],
    ],
    'quick_add' => 'page:' . $pageId,
]));
theme_t1_check($save->status() === 302 && (int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'navigation.update' ORDER BY id DESC LIMIT 1")->fetchColumn() === 7, 'admin navigation save uses POST, redirects after success and records administrator audit');

$updated = Settings::load($root);
$configured = NavigationBuilder::build($updated, $pdo);
theme_t1_check(array_column($configured, 'label') === ['首页', '鞋店', '关于我们'], 'saved navigation preserves configured labels and page links');

$home = theme_t1_app($root)->handle(new Request('GET', '/'));
theme_t1_check($home->status() === 200 && str_contains($home->body(), '>鞋店<') && str_contains($home->body(), 'href="/shop"') && str_contains($home->body(), '>关于我们<'), 'default theme renders saved navigation labels and links');

$pdo->exec("UPDATE cms_plugins SET status = 'Disabled' WHERE plugin_id = 'official.commerce'");
$withoutCommerce = NavigationBuilder::build(Settings::load($root), $pdo, $root);
theme_t1_check(!in_array('鞋店', array_column($withoutCommerce, 'label'), true), 'Commerce menu item is hidden when required plugin is disabled');

theme_t1_remove($root);
echo "Navigation menu tests passed.\n";
