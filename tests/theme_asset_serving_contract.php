<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Extension\ExtensionAssetController;
use Cms\Core\Http\Request;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$root = sys_get_temp_dir() . '/daiying-theme-assets-' . bin2hex(random_bytes(4));

function theme_assets_write(string $root, string $themeId, array $files): void
{
    mkdir($root . '/content/themes/' . $themeId . '/templates', 0777, true);
    mkdir($root . '/content/themes/' . $themeId . '/assets/css', 0777, true);
    file_put_contents($root . '/content/themes/' . $themeId . '/theme.json', json_encode([
        'theme_id' => $themeId,
        'name' => $themeId,
        'version' => '1.0.0',
        'author' => 'Test',
        'core' => ['min' => '1.2.52', 'max' => '1.x'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($root . '/content/themes/' . $themeId . '/templates/home.php', '<?php echo "private";');

    foreach ($files as $path => $body) {
        $full = $root . '/content/themes/' . $themeId . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $body);
    }
}

function theme_assets_request(ExtensionAssetController $controller, string $path, string $method = 'GET'): \Cms\Core\Http\Response
{
    return $controller->showThemeContentAsset(new Request($method, $path));
}

try {
    theme_assets_write($root, 'daiying_novel', [
        'assets/style.css' => 'body{color:#111}',
    ]);
    theme_assets_write($root, 'guofeng_zhuhong', [
        'assets/css/base.css' => '.gf{display:block}',
        'assets/css/palette-indigo-ink.css' => ':root{--ink:#111827}',
    ]);
    theme_assets_write($root, 'test_theme', [
        'assets/style.css' => '.test{color:blue}',
        'assets/app.js' => 'console.log("ok");',
        'assets/image.svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        'assets/secret.php' => '<?php echo "secret";',
        'assets/.hidden.css' => '.hidden{}',
        'assets/.env' => 'SECRET=1',
    ]);

    $controller = new ExtensionAssetController($root);

    $novel = theme_assets_request($controller, '/content/themes/daiying_novel/assets/style.css');
    $check($novel->status() === 200 && str_contains($novel->body(), 'color:#111'), 'serves existing novel theme assets through the generic theme asset contract');
    $check(str_starts_with($novel->headers()['Content-Type'] ?? '', 'text/css'), 'serves CSS with text/css content type');

    $guofeng = theme_assets_request($controller, '/content/themes/guofeng_zhuhong/assets/css/base.css');
    $check($guofeng->status() === 200 && str_contains($guofeng->body(), '.gf'), 'serves market-installed Guofeng theme assets without theme-id whitelisting');

    $palette = theme_assets_request($controller, '/content/themes/guofeng_zhuhong/assets/css/palette-indigo-ink.css');
    $check($palette->status() === 200 && str_contains($palette->body(), '--ink'), 'serves nested palette CSS assets');

    $third = theme_assets_request($controller, '/content/themes/test_theme/assets/style.css');
    $check($third->status() === 200 && str_contains($third->body(), '.test'), 'serves a third arbitrary theme asset without Apache Alias hard-coding');

    $template = theme_assets_request($controller, '/content/themes/test_theme/templates/home.php');
    $check($template->status() === 404, 'does not serve theme templates through the public asset contract');

    $manifest = theme_assets_request($controller, '/content/themes/test_theme/theme.json');
    $check($manifest->status() === 404, 'does not serve theme.json through the public asset contract');

    $php = theme_assets_request($controller, '/content/themes/test_theme/assets/secret.php');
    $check($php->status() === 404, 'does not serve PHP from theme assets');

    $hiddenCss = theme_assets_request($controller, '/content/themes/test_theme/assets/.hidden.css');
    $check($hiddenCss->status() === 404, 'does not serve hidden files from theme assets');

    $env = theme_assets_request($controller, '/content/themes/test_theme/assets/.env');
    $check($env->status() === 404, 'does not serve environment-style files from theme assets');

    $traversal = theme_assets_request($controller, '/content/themes/test_theme/assets/../theme.json');
    $check($traversal->status() === 404, 'rejects traversal attempts in theme asset paths');

    $post = theme_assets_request($controller, '/content/themes/test_theme/assets/style.css', 'POST');
    $check($post->status() === 405, 'non-GET theme asset requests are rejected');
} finally {
    $iterator = is_dir($root) ? new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) : [];
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

if ($failures > 0) {
    echo 'Theme asset serving contract tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Theme asset serving contract tests passed.' . PHP_EOL;
