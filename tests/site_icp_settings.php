<?php

declare(strict_types=1);

define('TEST_SOURCE_ROOT', dirname(__DIR__));
require TEST_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Theme\ThemeManager;
use Cms\Core\Theme\ThemeManifest;
use Cms\Core\Theme\ThemeRuntime;

$root = sys_get_temp_dir() . '/cms-site-icp-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
define('CMS_ROOT', $root);
mkdir($root . '/content');
symlink(TEST_SOURCE_ROOT . '/content/themes', $root . '/content/themes');
register_shutdown_function(static function () use ($root): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($root);
});
$assertions = 0;
$check = static function (bool $ok, string $message) use (&$assertions): void {
    if (!$ok) throw new RuntimeException($message);
    $assertions++;
    echo "[PASS] $message\n";
};
$pdo = new PDO('sqlite:' . $root . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new MigrationRunner($pdo, array_map(static fn (string $file) => require $file, glob(TEST_SOURCE_ROOT . '/system/migrations/*.php'))))->run();
$config = require TEST_SOURCE_ROOT . '/config/app.example.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/test.sqlite'];
$config['theme']['settings']['default']['icp_text'] = '旧主题备案文本';
file_put_contents($root . '/config/app.php', '<?php return ' . var_export($config, true) . ';');
$logger = new FileLogger($root . '/test.log');
$admin = static fn () => new AdminController(Settings::fromArray(require $root . '/config/app.php'), $logger, $root);
$_SESSION = [];
$check($admin()->siteSettings()->status() === 302, 'Settings still require administrator authentication');
$auth = new AdminAuthenticator($pdo);
$auth->createAdmin('icp@example.invalid', 'Isolated-fixture-123456', 'ICP Fixture');
$check($auth->attempt('icp@example.invalid', 'Isolated-fixture-123456', '127.0.0.1'), 'Native administrator login succeeds');
$body = ['_csrf' => CsrfToken::get(), 'site_name' => 'ICP fixture', 'site_url' => 'https://isolated.example', 'site_icp_number' => ' 京ICP备12345678号-1 '];
$check(str_contains($admin()->siteSettings()->body(), 'name="site_icp_number"'), 'Site settings expose the global ICP field');
$bad = $body; $bad['_csrf'] = 'invalid';
$check($admin()->siteSettingsSave(new Request('POST', '/admin/settings', [], $bad))->status() === 403, 'ICP writes retain CSRF enforcement');
$check($admin()->siteSettingsSave(new Request('GET', '/admin/settings'))->status() === 405, 'ICP writes require POST');
$check($admin()->siteSettingsSave(new Request('POST', '/admin/settings', [], $body))->status() === 302, 'Native settings controller saves the ICP number');
$saved = require $root . '/config/app.php';
$check($saved['site']['icp_number'] === trim($body['site_icp_number']), 'Trimmed ICP number persists in global configuration');
$check($saved['theme']['settings']['default']['icp_text'] === '旧主题备案文本', 'Saving global ICP preserves legacy theme settings');
$check(str_contains($admin()->siteSettings()->body(), 'value="京ICP备12345678号-1"'), 'Reloaded settings form shows the saved number');
foreach (['<script>alert(1)</script>', "bad\nvalue", str_repeat('字', 41), "bad\xFF"] as $invalid) {
    $bad = $body; $bad['site_icp_number'] = $invalid;
    $check($admin()->siteSettingsSave(new Request('POST', '/admin/settings', [], $bad))->status() === 422, 'Invalid ICP text is rejected without saving');
    $check((require $root . '/config/app.php')['site']['icp_number'] === trim($body['site_icp_number']), 'Rejected input preserves the existing number');
}
$omitted = $body; unset($omitted['site_icp_number']);
$admin()->siteSettingsSave(new Request('POST', '/admin/settings', [], $omitted));
$check((require $root . '/config/app.php')['site']['icp_number'] === trim($body['site_icp_number']), 'Older forms omitting ICP do not erase the saved setting');
$number = (require $root . '/config/app.php')['site']['icp_number'];
$data = ['site_name' => 'ICP fixture', 'site_icp_number' => $number, 'contents' => []];
foreach (['default', 'daiying_media', 'safe'] as $theme) {
    $manager = new ThemeManager(CMS_ROOT . '/content/themes', Settings::fromArray(['app' => ['version' => '1.2.85'], 'theme' => ['active' => $theme]]), $logger);
    $html = $manager->active()->render('home', $data);
    $check(substr_count($html, 'data-cms-site-icp="1"') === 1 && str_contains($html, 'href="https://beian.miit.gov.cn/"'), "$theme homepage renders the global ICP link once");
    $check(str_contains($html, $number) && strpos($html, 'data-cms-site-icp="1"') < strripos($html, '</body>'), "$theme shows the number before the document body ends");
}
$frontSettings = Settings::fromArray(array_replace_recursive($saved, ['theme' => ['active' => 'default']]));
$response = (new ContentFrontController(CMS_ROOT, $frontSettings, $logger))->articles(new Request('GET', '/articles'));
$check($response->status() === 200 && str_contains($response->body(), $number), 'Real frontend controller passes the global ICP setting to the theme');
mkdir($root . '/templates');
file_put_contents($root . '/templates/home.php', '<?php echo "<html><body>" . $context->siteIcpFooter() . "</body></html>";');
$manifest = ThemeManifest::fromArray(['theme_id' => 'icp_fixture', 'name' => 'ICP fixture', 'version' => '1.0.0', 'author' => 'Fixture', 'core' => ['min' => '1.2.0', 'max' => '1.x']]);
$runtime = new ThemeRuntime($manifest, $root, []);
$check(substr_count($runtime->render('home', $data), 'data-cms-site-icp="1"') === 1, 'Themes using the public footer helper do not get a duplicate footer');
$escaped = $runtime->render('home', ['site_icp_number' => '<img src=x onerror=alert(1)>']);
$check(str_contains($escaped, '&lt;img') && !str_contains($escaped, '<img'), 'Footer escapes unsafe legacy or directly supplied configuration');
$check(!str_contains($runtime->render('home'), 'beian.miit.gov.cn'), 'Empty configuration emits no registration footer');
$body['site_icp_number'] = '';
$check($admin()->siteSettingsSave(new Request('POST', '/admin/settings', [], $body))->status() === 302 && (require $root . '/config/app.php')['site']['icp_number'] === '', 'Administrator can explicitly clear the global ICP number');
echo "Global ICP settings: $assertions assertions passed (isolated SQLite; not production or physical-device acceptance).\n";
