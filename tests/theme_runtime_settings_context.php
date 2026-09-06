<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

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

$root = sys_get_temp_dir() . '/daiying-theme-settings-' . bin2hex(random_bytes(4));
mkdir($root . '/templates', 0777, true);
file_put_contents($root . '/templates/home.php', <<<'PHP'
<?php
$themeSettings = $context->get('theme_settings', []);
$settingsAlias = $context->get('settings', []);
echo ($themeSettings['home_title'] ?? 'missing') . '|' . ($settingsAlias['home_eyebrow'] ?? 'missing') . '|' . $context->setting('accent_color', 'missing');
PHP);

$manifest = ThemeManifest::fromArray([
    'theme_id' => 'runtime_settings_test',
    'name' => 'Runtime Settings Test',
    'version' => '1.0.0',
    'author' => 'Daiying CMS',
    'core' => ['min' => '1.2.0', 'max' => '1.x'],
]);
$runtime = new ThemeRuntime($manifest, $root, [
    'home_title' => '全部小说',
    'home_eyebrow' => '可配置标题',
    'accent_color' => '#b8324a',
]);

$check($runtime->render('home') === '全部小说|可配置标题|#b8324a', 'injects active theme settings into template context');
$check($runtime->render('home', ['theme_settings' => ['home_title' => '传入覆盖']]) === '传入覆盖|可配置标题|#b8324a', 'explicit render data can override theme_settings only');

@unlink($root . '/templates/home.php');
@rmdir($root . '/templates');
@rmdir($root);

if ($failures > 0) {
    echo 'Theme runtime settings context tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Theme runtime settings context tests passed.' . PHP_EOL;
