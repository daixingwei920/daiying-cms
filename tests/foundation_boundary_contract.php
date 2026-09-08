<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }

    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$read = static fn (string $path): string => (string) file_get_contents(CMS_ROOT . '/' . ltrim($path, '/'));
$filesContaining = static function (string $directory, string $pattern): array {
    $matches = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMS_ROOT . '/' . trim($directory, '/'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $content = (string) file_get_contents($file->getPathname());
        if (preg_match($pattern, $content) === 1) {
            $matches[] = substr($file->getPathname(), strlen(CMS_ROOT) + 1);
        }
    }
    sort($matches);

    return $matches;
};

$builder = $read('scripts/build_full_install_package.php');
$check(str_contains($builder, "str_starts_with(\$path, 'updates.daiyinggame.com/')"), 'installer builder keeps legacy private update-server directory guard');
$check(str_contains($builder, "str_starts_with(\$path, 'updates.daiyingcms.com/')"), 'installer builder rejects current private update-server directory guard');
$check(str_contains($builder, '.env') && str_contains($builder, 'id_ed25519'), 'installer builder rejects secrets and private keys');

$siteAiReferences = $filesContaining('system/core/Ai', '/cms_market_ai|cms_market_ai_review/i');
$check($siteAiReferences === [], 'site AI runtime does not reference market AI review tables');

$marketAiMigration = $read('system/migrations/2026_08_23_000002_market_ai_review_schema.php');
$check(!str_contains($marketAiMigration, 'cms_ai_settings'), 'market AI review migration does not reference site AI settings');

$marketServerMigration = $read('system/migrations/2026_08_12_000007_market_server_schema.php');
$check(str_contains($marketServerMigration, 'cms_market_ai_settings'), 'historical private-market AI settings remain isolated under cms_market_* names');

$aiDocs = $read('docs/ai.md');
$check(str_contains($aiDocs, 'do not use this site AI configuration'), 'official market AI review isolation is documented');

$configExample = $read('config/app.example.php');
$check(str_contains($configExample, 'https://updates.daiyingcms.com'), 'current official update server URL is configured in app example');

$boundaryReport = $read('DAIYING_CMS_FOUNDATION_BOUNDARY_REPORT.md');
$check(str_contains($boundaryReport, 'Distribution remains external content/product channel distribution'), 'Distribution boundary is explicitly separated from marketplace/license delivery');
$check(str_contains($boundaryReport, 'Must not call `Cms\\Core\\Ai\\AI::forSite()`'), 'official AI review must not call site AI facade');

if ($failures > 0) {
    echo 'Foundation boundary contract tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Foundation boundary contract tests PASS' . PHP_EOL;
