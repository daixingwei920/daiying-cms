<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Foundation\FoundationVersions;
use Cms\Core\Support\PublicApiRegistry;
use Cms\Core\Theme\ThemeException;
use Cms\Core\Theme\ThemeManifest;
use Cms\Core\Theme\ThemeRuntime;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$root = sys_get_temp_dir() . '/daiying-theme-api-v1-' . bin2hex(random_bytes(4));
mkdir($root . '/templates', 0777, true);
mkdir($root . '/assets/css', 0777, true);
file_put_contents($root . '/assets/css/app.css', 'body{}');
file_put_contents($root . '/templates/home.php', <<<'PHP'
<?php
echo json_encode([
    'api' => $context->apiVersion(),
    'theme' => $context->themeId(),
    'asset' => $context->asset('css/app.css'),
    'menu' => $context->menu('primary'),
    'media' => $context->media(['id' => '7', 'public_url' => '/media/cover.jpg', 'alt_text' => 'Cover', 'mime_type' => 'image/jpeg', 'file_size' => '1200', 'storage_provider' => 'local']),
    'pagination' => $context->pagination(2, 4, '/articles', ['q' => 'cms']),
    'breadcrumb' => $context->breadcrumb([['label' => 'Home', 'url' => '/'], 'Article']),
    'seo' => $context->seo(['title' => 'Override']),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP);

$manifest = ThemeManifest::fromArray([
    'theme_id' => 'theme_api_test',
    'name' => 'Theme API Test',
    'version' => '1.0.0',
    'author' => 'Daiying CMS',
    'core' => ['min' => '1.2.0', 'max' => '1.x'],
]);
$runtime = new ThemeRuntime($manifest, $root, ['accent' => '#2563eb']);

$check($manifest->apiVersion === FoundationVersions::THEME_API, 'older theme manifests default to Theme API v1');
$check(PublicApiRegistry::contract('theme.template_context')['version'] === FoundationVersions::THEME_API, 'TemplateContext is registered as public Theme API');
$check(PublicApiRegistry::contract('theme.view_model')['version'] === FoundationVersions::THEME_API, 'ThemeViewModel is registered as public Theme API');

$rendered = json_decode($runtime->render('home', [
    'menus' => [
        'primary' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Articles', 'url' => '/articles'],
        ],
    ],
    'current_path' => '/articles',
    'seo' => ['description' => 'Base description'],
]), true);

$check(is_array($rendered), 'theme template renders API data');
$check(($rendered['api'] ?? '') === FoundationVersions::THEME_API, 'TemplateContext exposes Theme API version');
$check(($rendered['theme'] ?? '') === 'theme_api_test', 'TemplateContext exposes theme id');
$check(($rendered['asset'] ?? '') === '/content/themes/theme_api_test/assets/css/app.css', 'TemplateContext builds stable asset URLs');
$check(($rendered['menu'][1]['current'] ?? false) === true, 'TemplateContext normalizes menu view models');
$check(($rendered['media']['alt'] ?? '') === 'Cover' && ($rendered['media']['provider'] ?? '') === 'local', 'TemplateContext normalizes media view models');
$check(($rendered['pagination']['prev_url'] ?? '') === '/articles?q=cms&page=1', 'TemplateContext builds pagination URLs');
$check(($rendered['pagination']['next_url'] ?? '') === '/articles?q=cms&page=3', 'TemplateContext builds next page URLs');
$check(($rendered['breadcrumb'][1]['current'] ?? false) === true, 'TemplateContext normalizes breadcrumbs');
$check(($rendered['seo']['title'] ?? '') === 'Override' && ($rendered['seo']['description'] ?? '') === 'Base description', 'TemplateContext merges SEO overrides safely');

try {
    $runtime->render('home', [
        'menus' => [],
        'current_path' => '/',
        'seo' => [],
        'asset_probe' => '../secret',
    ]);
    $runtime->render('home');
    $runtime->render('../home');
    $check(false, 'ThemeRuntime rejects template traversal');
} catch (ThemeException) {
    $check(true, 'ThemeRuntime rejects template traversal');
}

$contextReflection = new ReflectionClass('Cms\\Core\\Theme\\TemplateContext');
$context = $contextReflection->newInstance($runtime, []);
try {
    $context->asset('../secret');
    $check(false, 'TemplateContext rejects asset traversal');
} catch (ThemeException) {
    $check(true, 'TemplateContext rejects asset traversal');
}

@unlink($root . '/templates/home.php');
@unlink($root . '/assets/css/app.css');
@rmdir($root . '/assets/css');
@rmdir($root . '/assets');
@rmdir($root . '/templates');
@rmdir($root);

if ($failures > 0) {
    echo 'Theme API v1 tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Theme API v1 tests PASS' . PHP_EOL;
