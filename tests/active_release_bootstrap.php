<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/daiying-active-bootstrap-' . bin2hex(random_bytes(4));
$release = $tmp . '/active';
mkdir($release . '/system/core/Bootstrap', 0755, true);
mkdir($tmp . '/storage/updates', 0755, true);
$marker = $tmp . '/active-autoload-used';
file_put_contents($release . '/system/core/Bootstrap/autoload.php', '<?php file_put_contents(' . var_export($marker, true) . ', "yes");' . "\n");
file_put_contents($tmp . '/storage/updates/current-release.json', json_encode([
    'release_id' => 'active-bootstrap-test',
    'version' => '9.9.9',
    'path' => $release,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$output = [];
$code = 0;
exec(
    'cd ' . escapeshellarg($root) . ' && CMS_ROOT_OVERRIDE=' . escapeshellarg($tmp) . ' ' . PHP_BINARY . ' cli.php help 2>&1',
    $output,
    $code
);

$ok = $code === 0 && is_file($marker) && str_contains(implode("\n", $output), 'Available commands:');
remove_active_release_bootstrap_dir($tmp);

if (!$ok) {
    echo '[FAIL] CLI uses active release autoload when current-release.json is present' . PHP_EOL;
    echo implode("\n", $output) . PHP_EOL;
    exit(1);
}

echo '[PASS] CLI uses active release autoload when current-release.json is present' . PHP_EOL;
echo 'Active release bootstrap tests passed.' . PHP_EOL;

function remove_active_release_bootstrap_dir(string $dir): void
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
