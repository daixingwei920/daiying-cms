<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', ['version::', 'output-dir::']);
$version = trim((string) ($options['version'] ?? ''));
if ($version === '') {
    $config = require $root . '/config/app.example.php';
    $version = (string) ($config['app']['version'] ?? '');
}
if (!preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+){1,3}$/', $version)) {
    fwrite(STDERR, "Invalid version: {$version}\n");
    exit(1);
}

$outputDir = (string) ($options['output-dir'] ?? ($root . '/outputs'));
$outputDir = rtrim($outputDir, '/');
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Output directory is not writable: {$outputDir}\n");
    exit(1);
}

$packageName = 'daiying-cms-' . $version . '-full-install';
$zipPath = $outputDir . '/' . $packageName . '.zip';
$manifestPath = $outputDir . '/' . $packageName . '.manifest.json';
$shaPath = $outputDir . '/' . $packageName . '.sha256';

$requiredFiles = [
    '.htaccess',
    'README.md',
    'cli.php',
    'config/app.example.php',
    'public/.htaccess',
    'public/index.php',
    'system/core-manifest.json',
];
$requiredDirs = [
    'content/plugins',
    'content/themes',
    'public',
    'scripts',
    'storage',
    'system/core',
    'system/migrations',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        fail("Missing required file: {$file}");
    }
}
foreach ($requiredDirs as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        fail("Missing required directory: {$dir}");
    }
}

$tracked = gitTrackedFiles($root);
$include = [];
foreach ($tracked as $file) {
    if (shouldPackage($file)) {
        $include[] = $file;
    }
}
sort($include, SORT_STRING);

$missing = array_values(array_diff($requiredFiles, $include));
if ($missing !== []) {
    fail('Required files are not included: ' . implode(', ', $missing));
}
if (!hasManifest($include, 'content/plugins/', 'plugin.json')) {
    fail('No packaged plugin manifest found.');
}
if (!hasManifest($include, 'content/themes/', 'theme.json')) {
    fail('No packaged theme manifest found.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail("Unable to create ZIP: {$zipPath}");
}

$files = [];
foreach ($include as $relative) {
    assertSafePath($relative);
    $absolute = $root . '/' . $relative;
    if (!is_file($absolute)) {
        continue;
    }
    $zip->addFile($absolute, $relative);
    $files[$relative] = [
        'bytes' => filesize($absolute),
        'sha256' => hash_file('sha256', $absolute),
    ];
}
$zip->close();

verifyZip($zipPath, array_keys($files), $requiredFiles);

$packageSha256 = hash_file('sha256', $zipPath);
if (!is_string($packageSha256)) {
    fail('Unable to calculate package sha256.');
}

$manifest = [
    'package_type' => 'full_install',
    'product_id' => 'daiying.cms',
    'version' => $version,
    'created_at' => gmdate('c'),
    'file_count' => count($files),
    'package_sha256' => $packageSha256,
    'required_files' => $requiredFiles,
    'files' => $files,
];

$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($manifestJson)) {
    fail('Unable to encode package manifest.');
}
file_put_contents($manifestPath, $manifestJson . "\n");
file_put_contents($shaPath, $packageSha256 . '  ' . basename($zipPath) . "\n");

echo "Full installer built and verified.\n";
echo "ZIP: {$zipPath}\n";
echo "SHA256: {$packageSha256}\n";
echo "Manifest: {$manifestPath}\n";
echo "Files: " . count($files) . "\n";

function gitTrackedFiles(string $root): array
{
    $output = [];
    $code = 0;
    exec('cd ' . escapeshellarg($root) . ' && git ls-files', $output, $code);
    if ($code !== 0 || $output === []) {
        fail('Unable to list tracked files with git ls-files.');
    }

    return array_values(array_filter(array_map('trim', $output), static fn (string $file): bool => $file !== ''));
}

function shouldPackage(string $file): bool
{
    $file = str_replace('\\', '/', $file);
    if ($file === '' || str_starts_with($file, '.') && !in_array($file, ['.htaccess'], true)) {
        return false;
    }
    if (str_starts_with($file, 'tests/') || str_starts_with($file, 'outputs/')) {
        return false;
    }
    if (preg_match('/^DAIYING_.*\.md$/', $file) === 1) {
        return false;
    }
    if (preg_match('#(^|/)(\.env|\.DS_Store|composer\.lock|package-lock\.json)$#', $file) === 1) {
        return false;
    }
    if (preg_match('#^(storage/(?!\.gitkeep)|content/uploads/(?!\.htaccess|upload-security\.nginx\.conf))#', $file) === 1) {
        return false;
    }

    return str_starts_with($file, 'config/')
        || str_starts_with($file, 'content/')
        || str_starts_with($file, 'public/')
        || str_starts_with($file, 'scripts/')
        || str_starts_with($file, 'storage/')
        || str_starts_with($file, 'system/')
        || in_array($file, ['.htaccess', 'README.md', 'cli.php', 'nginx-root-security.conf'], true);
}

function hasManifest(array $files, string $prefix, string $manifest): bool
{
    foreach ($files as $file) {
        if (str_starts_with($file, $prefix) && basename($file) === $manifest) {
            return true;
        }
    }

    return false;
}

function assertSafePath(string $path): void
{
    if (
        $path === ''
        || str_starts_with($path, '/')
        || str_contains($path, "\0")
        || str_contains('/' . $path, '/../')
        || preg_match('/^[A-Za-z]:\//', $path) === 1
    ) {
        fail("Unsafe package path: {$path}");
    }
}

function verifyZip(string $zipPath, array $files, array $requiredFiles): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fail("Unable to reopen ZIP: {$zipPath}");
    }
    try {
        foreach ($requiredFiles as $required) {
            if ($zip->locateName($required) === false) {
                fail("ZIP is missing required file: {$required}");
            }
        }
        foreach ($files as $file) {
            if ($zip->locateName($file) === false) {
                fail("ZIP entry missing after write: {$file}");
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            assertSafePath($name);
            if (isForbiddenEntry($name)) {
                fail("Forbidden file included in ZIP: {$name}");
            }
        }
    } finally {
        $zip->close();
    }
}

function isForbiddenEntry(string $path): bool
{
    return str_starts_with($path, '.git/')
        || str_starts_with($path, 'outputs/')
        || str_starts_with($path, 'storage/database/')
        || str_starts_with($path, 'storage/logs/')
        || str_starts_with($path, 'storage/sessions/')
        || str_starts_with($path, 'storage/cache/')
        || str_starts_with($path, 'updates.daiyinggame.com/')
        || str_starts_with($path, 'updates.daiyingcms.com/')
        || preg_match('#(^|/)(\.env|config\.php|id_rsa|id_ed25519|known_hosts)$#', $path) === 1;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
