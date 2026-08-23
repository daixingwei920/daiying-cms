<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Bootstrap\Application;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Media\MediaController;
use Cms\Core\Migration\MigrationRunner;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function security_header_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function security_header_defaults(Response $response): bool
{
    $headers = $response->headers();
    return ($headers['X-Content-Type-Options'] ?? '') === 'nosniff'
        && ($headers['X-Frame-Options'] ?? '') === 'SAMEORIGIN'
        && ($headers['Referrer-Policy'] ?? '') === 'strict-origin-when-cross-origin'
        && ($headers['Permissions-Policy'] ?? '') === 'geolocation=(), microphone=(), camera=()';
}

function security_header_remove(string $path): void
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

function security_header_app_root(array $security, string $siteUrl = 'https://cms.example.test'): string
{
    $root = sys_get_temp_dir() . '/cms-hsts-app-' . bin2hex(random_bytes(4));
    foreach ([
        'config',
        'system/core',
        'system/admin',
        'system/recovery',
        'system/migrations',
        'content/uploads',
        'content/themes',
        'content/plugins',
        'storage/logs',
    ] as $directory) {
        mkdir($root . '/' . $directory, 0755, true);
    }

    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['site']['url'] = $siteUrl;
    $config['security'] = $security;
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

    return $root;
}

security_header_check(security_header_defaults(Response::html('<h1>CMS</h1>')), 'HTML responses include default production security headers');
security_header_check(security_header_defaults(Response::json(['ok' => true])), 'JSON responses include default production security headers');
security_header_check(security_header_defaults(Response::text('ok')), 'text responses include default production security headers');
security_header_check(security_header_defaults(Response::redirect('/admin/login')), 'redirect responses include default production security headers');

$custom = new Response('ok', 200, ['X-Frame-Options' => 'DENY', 'Content-Type' => 'text/plain']);
security_header_check(($custom->headers()['X-Frame-Options'] ?? '') === 'DENY' && ($custom->headers()['Content-Type'] ?? '') === 'text/plain' && ($custom->headers()['X-Content-Type-Options'] ?? '') === 'nosniff', 'explicit response headers are preserved while missing security defaults are added');

$root = sys_get_temp_dir() . '/cms-security-headers-' . bin2hex(random_bytes(4));
security_header_remove($root);
mkdir($root . '/config', 0755, true);
mkdir($root . '/storage/database', 0755, true);
mkdir($root . '/content/uploads', 0755, true);
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/database/headers.sqlite', 'username' => '', 'password' => '', 'options' => []];
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
$asset = $root . '/storage/database/headers.txt';
file_put_contents($asset, 'media-body');
$mediaId = (new MediaLibrary($pdo, $root . '/content/uploads'))->registerLocalFile($asset, 'headers.txt');
$media = (new MediaController($root, $settings))->show(new Request('GET', '/media/' . $mediaId . '/headers.txt'));
security_header_check(security_header_defaults($media) && ($media->headers()['Accept-Ranges'] ?? '') === 'bytes' && str_contains((string) ($media->headers()['Content-Disposition'] ?? ''), 'filename='), 'controlled media responses retain Range/download headers plus default security headers');
security_header_remove($root);

$hstsRoot = security_header_app_root([
    'hsts_enabled' => true,
    'hsts_max_age' => 31536000,
    'hsts_include_subdomains' => true,
    'hsts_preload' => true,
]);
$hsts = Application::boot($hstsRoot)->handle(new Request('GET', '/health'));
security_header_check(($hsts->headers()['Strict-Transport-Security'] ?? '') === 'max-age=31536000; includeSubDomains; preload', 'HTTPS application responses include configured HSTS header');
security_header_remove($hstsRoot);

$hstsDisabledRoot = security_header_app_root([
    'hsts_enabled' => false,
    'hsts_max_age' => 31536000,
    'hsts_include_subdomains' => true,
    'hsts_preload' => false,
]);
$hstsDisabled = Application::boot($hstsDisabledRoot)->handle(new Request('GET', '/health'));
security_header_check(!array_key_exists('Strict-Transport-Security', $hstsDisabled->headers()), 'application responses omit HSTS when disabled');
security_header_remove($hstsDisabledRoot);

$hstsHttpRoot = security_header_app_root([
    'hsts_enabled' => true,
    'hsts_max_age' => 31536000,
    'hsts_include_subdomains' => true,
    'hsts_preload' => false,
], 'http://cms.example.test');
$hstsHttp = Application::boot($hstsHttpRoot)->handle(new Request('GET', '/health'));
security_header_check(!array_key_exists('Strict-Transport-Security', $hstsHttp->headers()), 'application responses omit HSTS for non-HTTPS site URL');
security_header_remove($hstsHttpRoot);

if ($failures > 0) {
    fwrite(STDERR, $failures . " production security header checks failed.\n");
    exit(1);
}

echo "Production security header tests passed.\n";
