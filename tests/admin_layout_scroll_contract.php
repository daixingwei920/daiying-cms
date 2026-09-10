<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Support\View;
use Cms\Core\Update\UpdatePackageManifest;

$root = dirname(__DIR__);
$cssPath = $root . '/public/assets/admin/admin.css';

if (!is_file($cssPath)) {
    fwrite(STDERR, "admin.css missing\n");
    exit(1);
}

$css = (string) file_get_contents($cssPath);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$block = static function (string $selector) use ($css): string {
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{(?P<body>[^}]*)\}/m';
    if (!preg_match($pattern, $css, $matches)) {
        return '';
    }

    return (string) $matches['body'];
};

$adminShell = $block('.admin-shell');
$adminSidebar = $block('.admin-sidebar');
$adminWorkspace = $block('.admin-workspace');
$adminMain = $block('.admin-main');
$mobile = '';

if (preg_match('/@media\s*\(max-width:\s*760px\)\s*\{(?P<body>.*)\z/s', $css, $matches)) {
    $mobile = (string) $matches['body'];
}

$assert($adminShell !== '', 'admin shell block missing');
$assert(str_contains($adminShell, 'height: 100dvh'), 'admin shell must be viewport-height bounded on desktop');
$assert(str_contains($adminShell, 'overflow: hidden'), 'admin shell must delegate desktop scrolling to inner containers');

$assert($adminSidebar !== '', 'admin sidebar block missing');
$assert(str_contains($adminSidebar, 'height: 100dvh'), 'admin sidebar must be viewport-height bounded');
$assert(str_contains($adminSidebar, 'overflow-y: auto'), 'admin sidebar must scroll independently');

$assert($adminWorkspace !== '', 'admin workspace block missing');
$assert(str_contains($adminWorkspace, 'min-height: 0'), 'admin workspace must allow flex child scrolling');
$assert(str_contains($adminWorkspace, 'overflow: hidden'), 'admin workspace must keep desktop scroll inside admin main');

$assert($adminMain !== '', 'admin main block missing');
$assert(str_contains($adminMain, 'flex: 1 1 auto'), 'admin main must fill remaining workspace height');
$assert(str_contains($adminMain, 'min-height: 0'), 'admin main must be allowed to shrink inside flex layout');
$assert(str_contains($adminMain, 'overflow-y: auto'), 'admin main must support vertical scrolling');
$assert(str_contains($adminMain, 'env(safe-area-inset-bottom)'), 'admin main must reserve bottom safe-area padding');

$assert(str_contains($css, '.admin-card,'), 'admin card selector block missing');
$assert(str_contains($css, 'minmax(min(220px, 100%), 1fr)'), 'extension/media/theme grids must not overflow narrow admin content');
$assert(str_contains($css, 'min-width: 0;'), 'admin cards and grid containers must allow content to shrink');
$assert(str_contains($css, '.admin-nav-group.is-collapsed .admin-nav-group-links'), 'admin nav groups must support collapsed sections');

$assert(
    UpdatePackageManifest::isAllowedUpdatePath('public/assets/admin/admin.css'),
    'admin shell CSS must be an allowed Core update operational support file'
);
$assert(
    UpdatePackageManifest::isAllowedUpdatePath('public/assets/admin/admin.js'),
    'admin JS must be an allowed Core update operational support file'
);
// Keep the exact-commit builder and release parity gate in lockstep with runtime validation.
foreach ([
    $root . '/scripts/build_exact_commit_update_package.php',
    $root . '/scripts/release_parity_gate.php',
] as $script) {
    $content = (string) file_get_contents($script);
    $assert(str_contains($content, "'public/assets/admin/admin.css'"), basename($script) . ' must include admin CSS in the update allow-list');
    $assert(str_contains($content, "'public/assets/admin/admin.js'"), basename($script) . ' must include admin JS in the update allow-list');
}

$assert($mobile !== '', 'mobile media block missing');
$assert(str_contains($mobile, 'height: auto'), 'mobile layout must restore natural document height');
$assert(str_contains($mobile, 'overflow-y: auto'), 'mobile body must allow normal page scrolling');
$assert(str_contains($mobile, 'overflow: visible'), 'mobile inner containers must not trap page scrolling');

$_SERVER['REQUEST_URI'] = '/admin/settings/ai';
$_SESSION = [];
$html = View::page('AI 设置', '<h1>AI 设置</h1>');
$assert(str_contains($html, 'data-admin-nav-group="platform"'), 'admin sidebar must render stable platform nav group ids');
$assert(str_contains($html, 'data-admin-nav-section-toggle'), 'admin sidebar must render section toggle buttons');
$assert(str_contains($html, 'class="active" aria-current="page" title="AI 设置"'), 'admin sidebar must keep the current page link active inside its group');
$assert(str_contains($html, 'daiying.admin.navGroups.v1'), 'admin layout must ship nav group collapse behavior through Core View');

echo "admin layout scroll contract PASS\n";
