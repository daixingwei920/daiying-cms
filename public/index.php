<?php

declare(strict_types=1);

use Cms\Core\Bootstrap\Application;
use Cms\Core\Http\Request;

define('CMS_START', microtime(true));
define('CMS_ROOT', dirname(__DIR__));

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
$maintenance = CMS_ROOT . '/storage/maintenance.mode';
$maintenanceBypass = $path === '/health'
    || $path === '/recovery'
    || str_starts_with($path, '/recovery/')
    || $path === '/admin'
    || str_starts_with($path, '/admin/');
if (is_file($maintenance) && !$maintenanceBypass) {
    if (function_exists('header_remove')) {
        header_remove('X-Powered-By');
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 120');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    echo '<!doctype html><meta charset="utf-8"><title>Maintenance</title><h1>Maintenance Mode</h1><p>Core update is in progress.</p>';
    return;
}

$autoload = CMS_ROOT . '/system/core/Bootstrap/autoload.php';
$pointerFile = CMS_ROOT . '/storage/updates/current-release.json';
if (is_file($pointerFile)) {
    $pointer = json_decode((string) file_get_contents($pointerFile), true);
    $candidate = is_array($pointer) ? (string) ($pointer['path'] ?? '') . '/system/core/Bootstrap/autoload.php' : '';
    if ($candidate !== '' && is_file($candidate)) {
        $autoload = $candidate;
    } else {
        if (!is_dir(CMS_ROOT . '/storage')) {
            mkdir(CMS_ROOT . '/storage', 0755, true);
        }
        file_put_contents(CMS_ROOT . '/storage/recovery.mode', gmdate('c') . PHP_EOL, LOCK_EX);
        @chmod(CMS_ROOT . '/storage/recovery.mode', 0600);
    }
}

require $autoload;

$app = Application::boot(CMS_ROOT);
$app->handle(Request::capture())->send();
