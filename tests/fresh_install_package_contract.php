<?php

declare(strict_types=1);

$zipPath = (string) ($argv[1] ?? '');
$manifestPath = preg_replace('/\.zip$/', '.manifest.json', $zipPath);
if ($zipPath === '' || !is_file($zipPath) || !is_string($manifestPath) || !is_file($manifestPath)) {
    throw new RuntimeException('Full installer ZIP and manifest are required.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    throw new RuntimeException('Unable to open full installer ZIP.');
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS {$label}\n";
};

$check(($manifest['package_type'] ?? '') === 'full_install', 'Full install package identity');
$check($zip->locateName('config/app.php') === false && !isset($manifest['files']['config/app.php']),
    'Site-specific app.php is absent from ZIP and manifest');
$check($zip->locateName('config/app.example.php') !== false
    && isset($manifest['files']['config/app.example.php']), 'Installer example configuration remains available');
$check($zip->numFiles === (int) ($manifest['file_count'] ?? -1), 'ZIP and manifest file counts agree');
$check(hash_file('sha256', $zipPath) === ($manifest['package_sha256'] ?? ''), 'ZIP SHA-256 matches manifest');
$zip->close();
