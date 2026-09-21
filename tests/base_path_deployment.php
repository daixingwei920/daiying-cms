<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/themes/default/templates/_theme.php';

use Cms\Core\Extension\ExtensionAssetController;
use Cms\Core\Http\Request;
use Cms\Core\Routing\BasePath;
use Cms\Core\Theme\ThemeViewModel;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

BasePath::setCurrent('');
$check(BasePath::current() === '', 'root deployment has an empty base path');
$check(BasePath::prefixCurrent('/articles?page=2') === '/articles?page=2', 'root deployment keeps public content URLs unchanged');
$check(BasePath::stripCurrent('/articles?page=2') === '/articles', 'root deployment strips no prefix from request paths');
$check(ThemeViewModel::assetUrl('theme_api_test', 'css/app.css') === '/content/themes/theme_api_test/assets/css/app.css', 'root deployment keeps theme asset URLs unchanged');
$check(ThemeViewModel::url('/search', ['q' => 'cms']) === '/search?q=cms', 'root deployment keeps search URLs unchanged');
$rootPager = ThemeViewModel::pagination(2, 4, '/articles', [], 10, 35);
$check(($rootPager['prev_url'] ?? '') === '/articles?page=1' && ($rootPager['next_url'] ?? '') === '/articles?page=3', 'root deployment keeps pagination URLs unchanged');
$rootMenu = ThemeViewModel::menu([['label' => 'Articles', 'url' => '/articles']], '/articles');
$check(($rootMenu[0]['url'] ?? '') === '/articles' && ($rootMenu[0]['current'] ?? false) === true, 'root deployment keeps menu URLs and active state unchanged');
$check(str_starts_with(ExtensionAssetController::url('plugin', 'official.demo', 'mount.js', '1.0.0'), '/extension-assets/plugin/official.demo?'), 'root deployment keeps plugin asset URLs unchanged');
$check(dy_site_url('/') === '/', 'default theme root helper keeps home URL unchanged');
$check(dy_url(['slug' => 'hello-world', 'content_type' => 'article']) === '/articles/hello-world', 'default theme root helper keeps article permalinks unchanged');

BasePath::setCurrent('/daojia');
$check(BasePath::current() === '/daojia', 'subdirectory deployment stores normalized base path');
$check(BasePath::prefixCurrent('/articles?page=2') === '/daojia/articles?page=2', 'subdirectory deployment prefixes public content URLs');
$check(BasePath::prefixCurrent('/daojia/articles?page=2') === '/daojia/articles?page=2', 'subdirectory deployment avoids double-prefixing URLs');
$check(BasePath::stripCurrent('/daojia/articles?page=2') === '/articles', 'subdirectory deployment strips configured prefix from request paths');
$request = new Request('GET', '/daojia/articles', ['page' => '2']);
$strippedRequest = $request->withPath(BasePath::stripCurrent($request->path));
$check($request->path === '/daojia/articles' && $strippedRequest->path === '/articles', 'Request can be routed after base path stripping');
$check(ThemeViewModel::assetUrl('theme_api_test', 'css/app.css') === '/daojia/content/themes/theme_api_test/assets/css/app.css', 'subdirectory deployment prefixes theme asset URLs');
$check(ThemeViewModel::url('/search', ['q' => 'cms']) === '/daojia/search?q=cms', 'subdirectory deployment prefixes search URLs');
$basePager = ThemeViewModel::pagination(2, 4, '/articles', [], 10, 35);
$check(($basePager['prev_url'] ?? '') === '/daojia/articles?page=1' && ($basePager['next_url'] ?? '') === '/daojia/articles?page=3', 'subdirectory deployment prefixes pagination URLs');
$baseMenu = ThemeViewModel::menu([['label' => 'Articles', 'url' => '/articles']], '/articles');
$check(($baseMenu[0]['url'] ?? '') === '/daojia/articles' && ($baseMenu[0]['current'] ?? false) === true, 'subdirectory deployment prefixes menu URLs and preserves active state');
$check(str_starts_with(ExtensionAssetController::url('plugin', 'official.demo', 'mount.js', '1.0.0'), '/daojia/extension-assets/plugin/official.demo?'), 'subdirectory deployment prefixes plugin asset URLs');
$check(dy_site_url('/') === '/daojia/', 'default theme helper prefixes home URL under subdirectory deployment');
$check(dy_url(['slug' => 'hello world', 'content_type' => 'article']) === '/daojia/articles/hello%20world', 'default theme helper prefixes and encodes article permalinks under subdirectory deployment');
$check(dy_url(['slug' => 'about', 'content_type' => 'page']) === '/daojia/about', 'default theme helper prefixes page permalinks under subdirectory deployment');

BasePath::setCurrent('');

if ($failures > 0) {
    echo 'Base-path deployment tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Base-path deployment tests PASS' . PHP_EOL;
