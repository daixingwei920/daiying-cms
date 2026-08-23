<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$pluginId = 'official.cj-dropshipping';
$version = '1.0.0-rc1';
$pluginDir = $root . '/content/plugins/' . $pluginId;
$outputDir = $root;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output-dir=')) {
        $outputDir = rtrim(substr($arg, strlen('--output-dir=')), '/');
    }
}

if (!is_dir($pluginDir)) {
    fwrite(STDERR, "CJ plugin directory not found: {$pluginDir}\n");
    exit(1);
}
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

$zipName = 'official-cj-dropshipping-v1.0.0-rc1.zip';
$zipPath = $outputDir . '/' . $zipName;
$shaPath = $outputDir . '/' . $zipName . '.sha256';
$manifestPath = $outputDir . '/official-cj-dropshipping-v1.0.0-rc1.manifest.json';
$manifestZipPath = $pluginId . '/package-manifest.json';
$fixedTime = strtotime('2026-08-16T00:00:00Z') ?: 1786838400;

$excludedDirs = ['.git', 'storage', 'cache', 'logs', 'tmp', 'temp', 'tests', 'node_modules', 'vendor'];
$excludedExtensions = ['sqlite', 'sqlite3', 'db', 'log', 'env', 'pem', 'key', 'crt', 'p12', 'pfx'];
$secretPatterns = [
    '/-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----/',
    '/\b(?:access[_-]?token|refresh[_-]?token|api[_-]?key|open[_-]?id|authorization|cookie|password)\b\s*[:=]\s*[A-Za-z0-9+\/_.=-]{12,}/i',
];

/** @return list<array{abs:string,zip:string,size:int,sha256:string}> */
function collect_release_files(string $pluginDir, string $pluginId, array $excludedDirs, array $excludedExtensions, array $secretPatterns): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $abs = (string) $item->getPathname();
        $relative = str_replace('\\', '/', substr($abs, strlen($pluginDir) + 1));
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if (in_array($part, $excludedDirs, true) || str_starts_with($part, '.')) {
                continue 2;
            }
        }
        if (!$item->isFile()) {
            continue;
        }
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (in_array($ext, $excludedExtensions, true)) {
            continue;
        }
        if ($item->isLink()) {
            fwrite(STDERR, "Refusing symlink in package input: {$relative}\n");
            exit(1);
        }
        $contents = (string) file_get_contents($abs);
        foreach ($secretPatterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                fwrite(STDERR, "Potential secret pattern in package input: {$relative}\n");
                exit(1);
            }
        }
        $files[] = [
            'abs' => $abs,
            'zip' => $pluginId . '/' . $relative,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }
    usort($files, static fn (array $a, array $b): int => strcmp($a['zip'], $b['zip']));
    return $files;
}

$files = collect_release_files($pluginDir, $pluginId, $excludedDirs, $excludedExtensions, $secretPatterns);
$manifest = [
    'package' => $zipName,
    'package_type' => 'plugin',
    'plugin_id' => $pluginId,
    'version' => $version,
    'generated_at' => '2026-08-16T00:00:00Z',
    'deterministic' => [
        'file_order' => 'lexicographic',
        'mtime' => gmdate('c', $fixedTime),
        'unix_mode' => '0100644',
    ],
    'manifest_zip_path' => $manifestZipPath,
    'files' => array_map(
        static fn (array $file): array => ['path' => $file['zip'], 'size' => $file['size'], 'sha256' => $file['sha256']],
        $files
    ),
];
$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to write ZIP: {$zipPath}\n");
    exit(1);
}

$add = static function (ZipArchive $zip, string $zipName, string $contents, int $fixedTime): void {
    $zip->addFromString($zipName, $contents);
    if (method_exists($zip, 'setMtimeName')) {
        $zip->setMtimeName($zipName, $fixedTime);
    }
    if (method_exists($zip, 'setExternalAttributesName')) {
        $zip->setExternalAttributesName($zipName, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
};

foreach ($files as $file) {
    $add($zip, $file['zip'], (string) file_get_contents($file['abs']), $fixedTime);
}
$add($zip, $manifestZipPath, $manifestJson, $fixedTime);
$zip->close();

file_put_contents($manifestPath, $manifestJson);
$sha = hash_file('sha256', $zipPath);
if ($sha === false) {
    fwrite(STDERR, "Unable to hash ZIP: {$zipPath}\n");
    exit(1);
}
file_put_contents($shaPath, $sha . '  ' . basename($zipPath) . "\n");

echo json_encode([
    'zip' => $zipPath,
    'sha256' => $sha,
    'sha256_sidecar' => $shaPath,
    'manifest' => $manifestPath,
    'manifest_zip_path' => $manifestZipPath,
    'file_count' => count($files) + 1,
    'size_bytes' => filesize($zipPath),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
