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

function theme_assets_range_request(ExtensionAssetController $controller, string $path, string $range): \Cms\Core\Http\Response
{
    return $controller->showThemeContentAsset(new Request('GET', $path, [], [], ['HTTP_RANGE' => $range]));
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
        'assets/audio/fate-question.mp3' => '0123456789abcdef',
        'assets/audio/ambient.ogg' => 'ogg-body',
        'assets/audio/bell.wav' => 'wav-body',
        'assets/audio/chant.m4a' => 'm4a-body',
        'assets/secret.php' => '<?php echo "secret";',
        'assets/secret.phar' => 'phar',
        'assets/.hidden.css' => '.hidden{}',
        'assets/.env' => 'SECRET=1',
    ]);
    file_put_contents($root . '/outside-secret.mp3', 'outside');
    symlink($root . '/outside-secret.mp3', $root . '/content/themes/test_theme/assets/audio/outside.mp3');

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

    $mp3 = theme_assets_request($controller, '/content/themes/test_theme/assets/audio/fate-question.mp3');
    $check($mp3->status() === 200 && $mp3->body() === '0123456789abcdef', 'serves MP3 theme audio assets from assets/audio');
    $check(($mp3->headers()['Content-Type'] ?? '') === 'audio/mpeg', 'serves MP3 with audio/mpeg content type');
    $check(($mp3->headers()['Accept-Ranges'] ?? '') === 'bytes', 'advertises byte range support for theme audio');
    $check(($mp3->headers()['Content-Length'] ?? '') === '16', 'serves audio with correct Content-Length');

    $range = theme_assets_range_request($controller, '/content/themes/test_theme/assets/audio/fate-question.mp3', 'bytes=0-3');
    $check($range->status() === 206 && $range->body() === '0123', 'serves theme audio Range requests with 206');
    $check(($range->headers()['Content-Range'] ?? '') === 'bytes 0-3/16', 'sets Content-Range for theme audio Range requests');
    $check(($range->headers()['Content-Length'] ?? '') === '4', 'sets ranged Content-Length for theme audio');

    $suffixRange = theme_assets_range_request($controller, '/content/themes/test_theme/assets/audio/fate-question.mp3', 'bytes=-4');
    $check($suffixRange->status() === 206 && $suffixRange->body() === 'cdef', 'serves suffix byte ranges for theme audio');

    $invalidRange = theme_assets_range_request($controller, '/content/themes/test_theme/assets/audio/fate-question.mp3', 'bytes=99-120');
    $check($invalidRange->status() === 416 && ($invalidRange->headers()['Content-Range'] ?? '') === 'bytes */16', 'rejects unsatisfiable audio ranges');

    $ogg = theme_assets_request($controller, '/content/themes/test_theme/assets/audio/ambient.ogg');
    $check($ogg->status() === 200 && ($ogg->headers()['Content-Type'] ?? '') === 'audio/ogg', 'serves OGG theme audio assets');

    $wav = theme_assets_request($controller, '/content/themes/test_theme/assets/audio/bell.wav');
    $check($wav->status() === 200 && ($wav->headers()['Content-Type'] ?? '') === 'audio/wav', 'serves WAV theme audio assets');

    $m4a = theme_assets_request($controller, '/content/themes/test_theme/assets/audio/chant.m4a');
    $check($m4a->status() === 200 && ($m4a->headers()['Content-Type'] ?? '') === 'audio/mp4', 'serves M4A theme audio assets');

    $missingAudio = theme_assets_request($controller, '/content/themes/test_theme/assets/audio/missing.mp3');
    $check($missingAudio->status() === 404, 'missing theme audio returns 404');

    $template = theme_assets_request($controller, '/content/themes/test_theme/templates/home.php');
    $check($template->status() === 404, 'does not serve theme templates through the public asset contract');

    $manifest = theme_assets_request($controller, '/content/themes/test_theme/theme.json');
    $check($manifest->status() === 404, 'does not serve theme.json through the public asset contract');

    $php = theme_assets_request($controller, '/content/themes/test_theme/assets/secret.php');
    $check($php->status() === 404, 'does not serve PHP from theme assets');

    $phar = theme_assets_request($controller, '/content/themes/test_theme/assets/secret.phar');
    $check($phar->status() === 404, 'does not serve executable archive extensions from theme assets');

    $hiddenCss = theme_assets_request($controller, '/content/themes/test_theme/assets/.hidden.css');
    $check($hiddenCss->status() === 404, 'does not serve hidden files from theme assets');

    $env = theme_assets_request($controller, '/content/themes/test_theme/assets/.env');
    $check($env->status() === 404, 'does not serve environment-style files from theme assets');

    $traversal = theme_assets_request($controller, '/content/themes/test_theme/assets/../theme.json');
    $check($traversal->status() === 404, 'rejects traversal attempts in theme asset paths');

    $encodedTraversal = theme_assets_request($controller, '/content/themes/test_theme/assets/%2e%2e/theme.json');
    $check($encodedTraversal->status() === 404, 'rejects encoded traversal attempts in theme asset paths');

    $symlinkEscape = theme_assets_request($controller, '/content/themes/test_theme/assets/audio/outside.mp3');
    $check($symlinkEscape->status() === 404, 'rejects symlink escapes from theme assets');

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
