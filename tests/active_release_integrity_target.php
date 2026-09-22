<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateException;
use Cms\Core\Update\UpdatePackageManifest;
use Cms\Core\Update\UpdateService;

$failures = 0;

function active_integrity_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$tmp = sys_get_temp_dir() . '/daiying-active-integrity-' . bin2hex(random_bytes(4));
mkdir($tmp . '/system/core/Bootstrap', 0755, true);
mkdir($tmp . '/storage/updates/releases/current/system/core/Bootstrap', 0755, true);
mkdir($tmp . '/storage/updates/releases/next/system/core/Bootstrap', 0755, true);
file_put_contents($tmp . '/system/core/Bootstrap/autoload.php', '<?php');
file_put_contents($tmp . '/storage/updates/releases/current/system/core/Bootstrap/autoload.php', '<?php');
file_put_contents($tmp . '/storage/updates/releases/next/system/core/Bootstrap/autoload.php', '<?php');

$service = new UpdateService($tmp, '1.2.67', new SignatureVerifier(''));
$target = (new ReflectionMethod(UpdateService::class, 'currentCoreIntegrityTarget'))->invoke($service);
active_integrity_check($target === ['path' => $tmp, 'source' => 'root-shell'], 'root shell is integrity target when no active release pointer exists');

file_put_contents($tmp . '/storage/updates/current-release.json', json_encode([
    'release_id' => 'current',
    'version' => '1.2.67',
    'path' => $tmp . '/storage/updates/releases/current',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$target = (new ReflectionMethod(UpdateService::class, 'currentCoreIntegrityTarget'))->invoke($service);
active_integrity_check($target['path'] === realpath($tmp . '/storage/updates/releases/current'), 'active release is integrity target when pointer is valid');
active_integrity_check($target['source'] === 'active-release', 'active release target is reported in compatibility checks');
active_integrity_check((new ReflectionMethod(UpdateService::class, 'pointerValid'))->invoke($service) === true, 'valid active release pointer passes pointer health');

$writePointer = new ReflectionMethod(UpdateService::class, 'writePointer');
$writePointer->invoke($service, [
    'release_id' => 'next',
    'version' => '1.2.68',
    'path' => $tmp . '/storage/updates/releases/next',
]);
$mode = fileperms($tmp . '/storage/updates/current-release.json') & 0777;
active_integrity_check($mode === 0644, 'active release pointer is web-readable after updater writes it');
active_integrity_check(UpdatePackageManifest::isAllowedUpdatePath('public/index.php'), 'web root launcher is allowed as operational support in update packages');
active_integrity_check(UpdatePackageManifest::isAllowedUpdatePath('cli.php'), 'CLI launcher is allowed as operational support in update packages');

mkdir($tmp . '/outside/system/core/Bootstrap', 0755, true);
file_put_contents($tmp . '/outside/system/core/Bootstrap/autoload.php', '<?php');
file_put_contents($tmp . '/storage/updates/current-release.json', json_encode([
    'release_id' => 'outside',
    'version' => '9.9.9',
    'path' => $tmp . '/outside',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
active_integrity_check((new ReflectionMethod(UpdateService::class, 'pointerValid'))->invoke($service) === false, 'pointer outside storage/updates/releases fails pointer health');
try {
    (new ReflectionMethod(UpdateService::class, 'currentCoreIntegrityTarget'))->invoke($service);
    active_integrity_check(false, 'invalid active release pointer blocks current Core integrity target selection');
} catch (UpdateException) {
    active_integrity_check(true, 'invalid active release pointer blocks current Core integrity target selection');
}

remove_active_integrity_dir($tmp);

if ($failures > 0) {
    echo 'Active release integrity target tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Active release integrity target tests passed.' . PHP_EOL;

function remove_active_integrity_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
