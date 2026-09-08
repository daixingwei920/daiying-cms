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
$options = getopt('', ['commit::', 'version::', 'output-dir::', 'channel::']);
$commit = trim((string) ($options['commit'] ?? 'HEAD'));
$channel = trim((string) ($options['channel'] ?? 'stable'));
if ($channel === '') {
    $channel = 'stable';
}

$resolvedCommit = git($root, ['rev-parse', $commit . '^{commit}']);
if (!preg_match('/^[a-f0-9]{40}$/', $resolvedCommit)) {
    fail('Unable to resolve target commit.');
}

$version = trim((string) ($options['version'] ?? ''));
if ($version === '') {
    $config = gitBlob($root, $resolvedCommit, 'config/app.example.php');
    if (!preg_match("/['\"]version['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $config, $match)) {
        fail('Unable to detect version from config/app.example.php at commit ' . $resolvedCommit);
    }
    $version = $match[1];
}
if (!preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+){1,3}$/', $version)) {
    fail("Invalid version: {$version}");
}

$outputDir = rtrim((string) ($options['output-dir'] ?? ($root . '/outputs/exact-commit-' . $version)), '/');
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fail('Unable to create output directory: ' . $outputDir);
}

$packageName = 'daiying-cms-' . $version . '-' . $channel . '-exact-' . substr($resolvedCommit, 0, 12);
$zipPath = $outputDir . '/' . $packageName . '.zip';
$manifestPath = $outputDir . '/' . $packageName . '.manifest.json';
$shaPath = $outputDir . '/' . $packageName . '.zip.sha256';

$tracked = gitLines($root, ['ls-tree', '-r', '--name-only', $resolvedCommit]);
$include = array_values(array_filter($tracked, static fn (string $file): bool => shouldPackage($file)));
sort($include, SORT_STRING);

$requiredFiles = [
    '.htaccess',
    'README.md',
    'cli.php',
    'config/app.example.php',
    'public/.htaccess',
    'public/index.php',
    'system/core-manifest.json',
];
foreach ($requiredFiles as $file) {
    if (!in_array($file, $include, true)) {
        fail('Required file is not tracked at target commit: ' . $file);
    }
}
if (!hasManifest($include, 'content/plugins/', 'plugin.json')) {
    fail('No packaged plugin manifest found at target commit.');
}
if (!hasManifest($include, 'content/themes/', 'theme.json')) {
    fail('No packaged theme manifest found at target commit.');
}

$expectedCoreManifest = buildCoreManifest($root, $resolvedCommit);
$committedCoreManifest = json_decode(gitBlob($root, $resolvedCommit, 'system/core-manifest.json'), true);
if (!is_array($committedCoreManifest) || $committedCoreManifest !== $expectedCoreManifest) {
    fail('system/core-manifest.json does not match system/core files at target commit.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('Unable to create ZIP: ' . $zipPath);
}

$files = [];
foreach ($include as $relative) {
    assertSafePath($relative);
    $content = gitBlob($root, $resolvedCommit, $relative);
    $zip->addFromString($relative, $content);
    $files[$relative] = [
        'bytes' => strlen($content),
        'sha256' => hash('sha256', $content),
    ];
}
$zip->close();

verifyZip($zipPath, array_keys($files), $requiredFiles);
$packageSha256 = hash_file('sha256', $zipPath);
if (!is_string($packageSha256)) {
    fail('Unable to calculate package SHA-256.');
}

$manifest = [
    'package_type' => 'full_install',
    'product_id' => 'daiying.cms',
    'version' => $version,
    'channel' => $channel,
    'exact_commit' => $resolvedCommit,
    'git_short' => substr($resolvedCommit, 0, 12),
    'created_at' => gmdate('c'),
    'file_count' => count($files),
    'package_sha256' => $packageSha256,
    'required_files' => $requiredFiles,
    'required_migrations' => migrationIds($include),
    'files' => $files,
];

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($json)) {
    fail('Unable to encode manifest JSON.');
}
file_put_contents($manifestPath, $json . "\n");
file_put_contents($shaPath, $packageSha256 . '  ' . basename($zipPath) . "\n");

echo "Exact commit installer built and verified.\n";
echo "Commit: {$resolvedCommit}\n";
echo "Version: {$version}\n";
echo "ZIP: {$zipPath}\n";
echo "SHA256: {$packageSha256}\n";
echo "Manifest: {$manifestPath}\n";
echo "Files: " . count($files) . "\n";

/** @return list<string> */
function gitLines(string $root, array $args): array
{
    $output = git($root, $args);
    return array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));
}

function git(string $root, array $args): string
{
    $cmd = 'cd ' . escapeshellarg($root) . ' && git';
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fail('Git command failed: git ' . implode(' ', $args) . "\n" . implode("\n", $output));
    }

    return trim(implode("\n", $output));
}

function gitBlob(string $root, string $commit, string $path): string
{
    assertSafePath($path);
    git($root, ['cat-file', '-e', $commit . ':' . $path]);
    $cmd = 'cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg($commit . ':' . $path);
    $content = shell_exec($cmd);
    if (!is_string($content)) {
        fail('Unable to read git blob: ' . $path);
    }

    return $content;
}

function shouldPackage(string $file): bool
{
    $file = str_replace('\\', '/', $file);
    if ($file === '' || (str_starts_with($file, '.') && !in_array($file, ['.htaccess'], true))) {
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
        || str_starts_with($file, 'docs/')
        || str_starts_with($file, '.github/assets/')
        || in_array($file, ['.htaccess', 'README.md', 'README.zh-CN.md', 'CHANGELOG.md', 'LICENSE', 'cli.php', 'nginx-root-security.conf'], true);
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

/** @return array<string,string> */
function buildCoreManifest(string $root, string $commit): array
{
    $manifest = [];
    foreach (gitLines($root, ['ls-tree', '-r', '--name-only', $commit, 'system/core']) as $file) {
        if (!str_starts_with($file, 'system/core/') || str_ends_with($file, '/')) {
            continue;
        }
        $relative = substr($file, strlen('system/core/'));
        if ($relative === '' || str_ends_with($relative, '.DS_Store')) {
            continue;
        }
        $manifest[$relative] = hash('sha256', gitBlob($root, $commit, $file));
    }
    ksort($manifest, SORT_STRING);

    return $manifest;
}

/** @return list<string> */
function migrationIds(array $files): array
{
    $ids = [];
    foreach ($files as $file) {
        if (preg_match('#^system/migrations/([A-Za-z0-9_.-]+)\.php$#', $file, $match) === 1) {
            $ids[] = $match[1];
        }
    }
    sort($ids, SORT_STRING);

    return array_values(array_unique($ids));
}

function assertSafePath(string $path): void
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains('/' . $path, '/../') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
        fail('Unsafe package path: ' . $path);
    }
}

function verifyZip(string $zipPath, array $files, array $requiredFiles): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fail('Unable to reopen ZIP: ' . $zipPath);
    }
    try {
        foreach ($requiredFiles as $required) {
            if ($zip->locateName($required) === false) {
                fail('ZIP is missing required file: ' . $required);
            }
        }
        foreach ($files as $file) {
            if ($zip->locateName($file) === false) {
                fail('ZIP entry missing after write: ' . $file);
            }
        }
        if ($zip->numFiles !== count($files)) {
            fail('ZIP entry count does not match manifest file count.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            assertSafePath((string) $zip->getNameIndex($i));
        }
    } finally {
        $zip->close();
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
