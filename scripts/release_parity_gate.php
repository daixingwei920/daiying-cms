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
$options = getopt('', [
    'commit::',
    'tag::',
    'installer-zip::',
    'installer-manifest::',
    'update-zip::',
    'update-metadata::',
    'min-upgrade-from::',
    'json::',
    'require-signature::',
]);

$commit = resolveCommit($root, trim((string) ($options['commit'] ?? 'HEAD')));
$minUpgradeFrom = trim((string) ($options['min-upgrade-from'] ?? '1.2.0'));
$requireSignature = filter_var((string) ($options['require-signature'] ?? '1'), FILTER_VALIDATE_BOOL);
$checks = [];

$expected = expectedCommitState($root, $commit);
$checks[] = pass('commit.resolved', 'Target commit resolved: ' . $commit);
$checks[] = check($expected['version'] !== '', 'commit.version', 'Config version detected: ' . $expected['version']);

$tag = trim((string) ($options['tag'] ?? ''));
if ($tag !== '') {
    $tagCommit = resolveCommit($root, $tag);
    $checks[] = check(hash_equals($commit, $tagCommit), 'git.tag_exact_commit', 'Git tag points at target commit.');
}

$installerZip = trim((string) ($options['installer-zip'] ?? ''));
if ($installerZip !== '') {
    $installerZip = absolutePath($root, $installerZip);
    $checks = array_merge($checks, verifyInstaller($installerZip, $options['installer-manifest'] ?? '', $expected, $requireSignature));
}

$updateZip = trim((string) ($options['update-zip'] ?? ''));
$update = null;
if ($updateZip !== '') {
    $updateZip = absolutePath($root, $updateZip);
    [$update, $updateChecks] = verifyUpdatePackage($updateZip, $expected, $minUpgradeFrom);
    $checks = array_merge($checks, $updateChecks);
}

$metadataPath = trim((string) ($options['update-metadata'] ?? ''));
if ($metadataPath !== '') {
    $metadata = readJsonSource($metadataPath);
    $checks = array_merge($checks, verifyUpdateMetadata($metadata, $update, $updateZip, $expected, $minUpgradeFrom));
}

$failed = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'FAIL'));
$status = $failed === [] ? 'PASS' : 'FAIL';
$report = [
    'status' => $status,
    'commit' => $commit,
    'version' => $expected['version'],
    'min_upgrade_from' => $minUpgradeFrom,
    'checked_at' => gmdate('c'),
    'checks' => $checks,
];

if (filter_var((string) ($options['json'] ?? '0'), FILTER_VALIDATE_BOOL)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    foreach ($checks as $check) {
        echo '[' . $check['status'] . '] ' . $check['id'] . ' - ' . $check['message'] . "\n";
    }
    echo "Release parity gate: {$status}\n";
}

exit($status === 'PASS' ? 0 : 1);

/** @return array{id:string,status:string,message:string} */
function pass(string $id, string $message): array
{
    return ['id' => $id, 'status' => 'PASS', 'message' => $message];
}

/** @return array{id:string,status:string,message:string} */
function failCheck(string $id, string $message): array
{
    return ['id' => $id, 'status' => 'FAIL', 'message' => $message];
}

/** @return array{id:string,status:string,message:string} */
function check(bool $ok, string $id, string $message): array
{
    return $ok ? pass($id, $message) : failCheck($id, $message);
}

function resolveCommit(string $root, string $rev): string
{
    $rev = $rev === '' ? 'HEAD' : $rev;
    $commit = git($root, ['rev-parse', $rev . '^{commit}']);
    if (!preg_match('/^[a-f0-9]{40}$/', $commit)) {
        throw new RuntimeException('Unable to resolve commit: ' . $rev);
    }

    return $commit;
}

/** @return array{version:string,package_files:array<string,string>,update_files:array<string,string>,core_manifest:array<string,string>,migration_ids:list<string>} */
function expectedCommitState(string $root, string $commit): array
{
    $files = [];
    $updateFiles = [];
    foreach (gitLines($root, ['ls-tree', '-r', '--name-only', $commit]) as $file) {
        if (shouldPackage($file)) {
            $files[$file] = hash('sha256', gitBlob($root, $commit, $file));
        }
        if (isAllowedUpdatePath($file)) {
            $updateFiles[$file] = hash('sha256', gitBlob($root, $commit, $file));
        }
    }
    ksort($files, SORT_STRING);
    ksort($updateFiles, SORT_STRING);

    $coreManifest = [];
    foreach (gitLines($root, ['ls-tree', '-r', '--name-only', $commit, 'system/core']) as $file) {
        if (str_starts_with($file, 'system/core/') && !str_ends_with($file, '/')) {
            $relative = substr($file, strlen('system/core/'));
            if ($relative !== '' && !str_ends_with($relative, '.DS_Store')) {
                $coreManifest[$relative] = hash('sha256', gitBlob($root, $commit, $file));
            }
        }
    }
    ksort($coreManifest, SORT_STRING);

    $config = gitBlob($root, $commit, 'config/app.example.php');
    $version = preg_match("/['\"]version['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $config, $match) === 1 ? $match[1] : '';

    return [
        'version' => $version,
        'package_files' => $files,
        'update_files' => $updateFiles,
        'core_manifest' => $coreManifest,
        'migration_ids' => migrationIds(array_keys($files)),
    ];
}

/** @return list<array{id:string,status:string,message:string}> */
function verifyInstaller(string $zipPath, mixed $manifestOption, array $expected, bool $requireSignature): array
{
    $checks = [];
    $zipState = readZipHashes($zipPath);
    $checks[] = check($zipState['ok'], 'installer.zip_open', $zipState['message']);
    if (!$zipState['ok']) {
        return $checks;
    }
    $checks[] = check($zipState['files'] === $expected['package_files'], 'installer.exact_commit_files', 'Installer ZIP file list and hashes match target commit.');
    $checks[] = check(isset($zipState['files']['system/core-manifest.json']), 'installer.core_manifest_present', 'Installer contains system/core-manifest.json.');
    if (isset($zipState['files']['system/core-manifest.json'])) {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $manifest = json_decode((string) $zip->getFromName('system/core-manifest.json'), true);
        $zip->close();
        $checks[] = check(is_array($manifest) && $manifest === $expected['core_manifest'], 'installer.core_manifest_exact', 'Core manifest matches target commit system/core files.');
    }

    $manifestPath = is_string($manifestOption) && trim($manifestOption) !== '' ? absolutePath(dirname($zipPath), $manifestOption) : preg_replace('/\.zip$/', '.manifest.json', $zipPath);
    if (is_string($manifestPath) && is_file($manifestPath)) {
        $manifest = readJsonFile($manifestPath);
        $checks[] = check((string) ($manifest['exact_commit'] ?? '') !== '', 'installer.manifest_exact_commit_present', 'Installer manifest records exact_commit.');
        $checks[] = check((string) ($manifest['package_sha256'] ?? '') === hash_file('sha256', $zipPath), 'installer.manifest_sha256', 'Installer manifest SHA-256 matches ZIP.');
        $checks[] = check((string) ($manifest['version'] ?? '') === $expected['version'], 'installer.manifest_version', 'Installer manifest version matches target commit.');
        $checks[] = check((int) ($manifest['file_count'] ?? -1) === count($expected['package_files']), 'installer.manifest_file_count', 'Installer manifest file count matches exact commit.');
    } else {
        $checks[] = failCheck('installer.manifest_sidecar', 'Installer manifest sidecar is missing.');
    }

    $checks[] = verifyArtifactSignature($zipPath, $requireSignature);

    return $checks;
}

/** @return array{0:array<string,mixed>|null,1:list<array{id:string,status:string,message:string}>} */
function verifyUpdatePackage(string $zipPath, array $expected, string $minUpgradeFrom): array
{
    $checks = [];
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [null, [failCheck('update.zip_open', 'Unable to open update package ZIP.')]];
    }
    $updateJson = $zip->getFromName('update.json');
    $signature = $zip->getFromName('signature.bin');
    $checks[] = check(is_string($updateJson), 'update.manifest_present', 'Update package contains update.json.');
    $checks[] = check(is_string($signature), 'update.signature_present', 'Update package contains signature.bin.');
    if (!is_string($updateJson)) {
        $zip->close();
        return [null, $checks];
    }
    $update = json_decode($updateJson, true);
    $checks[] = check(is_array($update), 'update.manifest_json', 'Update manifest JSON is valid.');
    if (!is_array($update)) {
        $zip->close();
        return [null, $checks];
    }

    $manifestFiles = [];
    foreach (($update['files'] ?? []) as $path => $hash) {
        $manifestFiles[(string) $path] = (string) $hash;
    }
    ksort($manifestFiles, SORT_STRING);
    $zipFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($name === 'update.json' || $name === 'signature.bin' || str_ends_with($name, '/')) {
            continue;
        }
        $content = $zip->getFromIndex($i);
        $zipFiles[$name] = is_string($content) ? hash('sha256', $content) : '';
    }
    ksort($zipFiles, SORT_STRING);

    $checks[] = check($zipFiles === $manifestFiles, 'update.zip_manifest_exact', 'Update ZIP entries match update.json files exactly.');
    $checks[] = check($manifestFiles === $expected['update_files'], 'update.exact_commit_files', 'Update package files and hashes match target commit Core-owned release files.');
    $checks[] = check((string) ($update['version'] ?? $update['to_version'] ?? '') === $expected['version'], 'update.version', 'Update manifest version matches target commit.');
    $checks[] = check((string) ($update['min_upgrade_from'] ?? '') === $minUpgradeFrom, 'update.min_upgrade_from', 'Update min_upgrade_from stays at ' . $minUpgradeFrom . '.');
    $checks[] = check((string) ($update['hard_min_version'] ?? '') === $minUpgradeFrom, 'update.hard_min_version', 'Update hard_min_version stays at ' . $minUpgradeFrom . '.');
    $checks[] = check((string) ($update['migration_floor'] ?? '') === $minUpgradeFrom, 'update.migration_floor', 'Update migration_floor stays at ' . $minUpgradeFrom . '.');
    $checks[] = check((string) ($update['package_sha256'] ?? '') === '' || (string) ($update['package_sha256'] ?? '') === hash_file('sha256', $zipPath), 'update.package_sha256', 'Update manifest package_sha256 matches ZIP when present.');

    $requiredMigrations = stringList($update['required_migrations'] ?? []);
    $packagedMigrations = migrationIds(array_keys($manifestFiles));
    $missingRequired = array_values(array_diff($packagedMigrations, $requiredMigrations));
    $checks[] = check($missingRequired === [], 'update.required_migrations_cover_package', 'All packaged Core migrations are declared as required migrations.');
    if (isset($manifestFiles['system/core-manifest.json'])) {
        $manifest = json_decode((string) $zip->getFromName('system/core-manifest.json'), true);
        $checks[] = check(is_array($manifest) && $manifest === $expected['core_manifest'], 'update.core_manifest_exact', 'Update core-manifest matches target commit.');
    } else {
        $checks[] = failCheck('update.core_manifest_present', 'Update package must include system/core-manifest.json.');
    }
    $zip->close();

    return [$update, $checks];
}

/** @return list<array{id:string,status:string,message:string}> */
function verifyUpdateMetadata(array $metadata, ?array $update, string $updateZip, array $expected, string $minUpgradeFrom): array
{
    $checks = [];
    $checks[] = check((string) ($metadata['version'] ?? '') === $expected['version'], 'metadata.version', 'Update metadata version matches target commit.');
    $checks[] = check((string) ($metadata['min_upgrade_from'] ?? '') === $minUpgradeFrom, 'metadata.min_upgrade_from', 'Metadata min_upgrade_from stays at ' . $minUpgradeFrom . '.');
    $checks[] = check((string) ($metadata['hard_min_version'] ?? '') === $minUpgradeFrom, 'metadata.hard_min_version', 'Metadata hard_min_version stays at ' . $minUpgradeFrom . '.');
    $checks[] = check((string) ($metadata['migration_floor'] ?? '') === $minUpgradeFrom, 'metadata.migration_floor', 'Metadata migration_floor stays at ' . $minUpgradeFrom . '.');
    if ($updateZip !== '') {
        $checks[] = check((string) ($metadata['package_sha256'] ?? '') === hash_file('sha256', $updateZip), 'metadata.package_sha256', 'Metadata package_sha256 matches update ZIP.');
    }
    if ($update !== null) {
        $metadataFiles = stringList($metadata['changed_files'] ?? []);
        $updateFiles = array_keys((array) ($update['files'] ?? []));
        sort($metadataFiles, SORT_STRING);
        sort($updateFiles, SORT_STRING);
        $checks[] = check($metadataFiles === $updateFiles, 'metadata.changed_files_exact', 'Metadata changed_files exactly matches update package files.');

        $required = stringList($metadata['required_migrations'] ?? []);
        $packageRequired = stringList($update['required_migrations'] ?? []);
        $checks[] = check($required === $packageRequired, 'metadata.required_migrations_exact', 'Metadata required_migrations matches update package.');
    }

    return $checks;
}

/** @return array{id:string,status:string,message:string} */
function verifyArtifactSignature(string $zipPath, bool $required): array
{
    $signaturePath = $zipPath . '.signature.json';
    if (!is_file($signaturePath)) {
        return $required
            ? failCheck('artifact.signature_sidecar', 'Signature sidecar is missing: ' . basename($signaturePath))
            : pass('artifact.signature_sidecar', 'Signature sidecar check skipped.');
    }
    try {
        $decoded = readJsonFile($signaturePath);
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        $expectedHash = (string) ($payload['sha256'] ?? '');
        if ($expectedHash === '' || !hash_equals($expectedHash, (string) hash_file('sha256', $zipPath))) {
            return failCheck('artifact.signature_sha256', 'Signature payload SHA-256 does not match artifact.');
        }
        $public = base64_decode((string) ($decoded['public_key'] ?? ''), true);
        $signature = base64_decode((string) ($decoded['signature'] ?? ''), true);
        if (!is_string($public) || !is_string($signature) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return failCheck('artifact.signature_metadata', 'Signature sidecar metadata is invalid.');
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!sodium_crypto_sign_verify_detached($signature, $payloadJson, $public)) {
            return failCheck('artifact.signature_verify', 'Signature sidecar verification failed.');
        }

        return pass('artifact.signature_verify', 'Signature sidecar is present and valid.');
    } catch (Throwable $exception) {
        return failCheck('artifact.signature_exception', 'Signature sidecar check failed: ' . $exception->getMessage());
    }
}

/** @return array{ok:bool,message:string,files:array<string,string>} */
function readZipHashes(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'message' => 'Unable to open ZIP: ' . $zipPath, 'files' => []];
    }
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (str_ends_with($name, '/')) {
            continue;
        }
        if (!safePath($name)) {
            $zip->close();
            return ['ok' => false, 'message' => 'Unsafe ZIP path: ' . $name, 'files' => []];
        }
        $content = $zip->getFromIndex($i);
        $files[$name] = is_string($content) ? hash('sha256', $content) : '';
    }
    $zip->close();
    ksort($files, SORT_STRING);

    return ['ok' => true, 'message' => 'ZIP opened and paths are safe.', 'files' => $files];
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
        throw new RuntimeException('Git command failed: git ' . implode(' ', $args) . "\n" . implode("\n", $output));
    }

    return trim(implode("\n", $output));
}

/** @return list<string> */
function gitLines(string $root, array $args): array
{
    return array_values(array_filter(array_map('trim', explode("\n", git($root, $args))), static fn (string $line): bool => $line !== ''));
}

function gitBlob(string $root, string $commit, string $path): string
{
    if (!safePath($path)) {
        throw new RuntimeException('Unsafe git path: ' . $path);
    }

    git($root, ['cat-file', '-e', $commit . ':' . $path]);
    $cmd = 'cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg($commit . ':' . $path);
    $content = shell_exec($cmd);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read git blob: ' . $path);
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

function isAllowedUpdatePath(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    if (!safePath($path)) {
        return false;
    }
    if (str_starts_with($path, 'system/core/') || str_starts_with($path, 'system/migrations/') || $path === 'system/core-manifest.json') {
        return true;
    }

    return in_array($path, [
        'README.md',
        'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md',
        'scripts/diagnose_payment_providers.php',
        'scripts/publish_scheduled_content.php',
        'scripts/validate_production_readiness.php',
        'scripts/verify_release_audit_counts.php',
    ], true);
}

/** @return list<string> */
function migrationIds(array $files): array
{
    $ids = [];
    foreach ($files as $file) {
        if (preg_match('#^system/migrations/([A-Za-z0-9_.-]+)\.php$#', (string) $file, $match) === 1) {
            $ids[] = $match[1];
        }
    }
    sort($ids, SORT_STRING);

    return array_values(array_unique($ids));
}

/** @return list<string> */
function stringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $item) {
        if (is_string($item) && trim($item) !== '') {
            $items[] = trim($item);
        }
    }
    sort($items, SORT_STRING);

    return array_values(array_unique($items));
}

function readJsonSource(string $source): array
{
    if (preg_match('#^https?://#', $source) === 1) {
        $body = @file_get_contents($source);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to read metadata URL.');
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    return readJsonFile(absolutePath(getcwd() ?: '.', $source));
}

function readJsonFile(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON is not an object: ' . $path);
    }

    return $decoded;
}

function absolutePath(string $base, string $path): string
{
    if ($path === '') {
        return $path;
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }

    return rtrim($base, '/') . '/' . $path;
}

function safePath(string $path): bool
{
    return $path !== ''
        && !str_starts_with($path, '/')
        && !str_contains($path, "\0")
        && !str_contains('/' . $path, '/../')
        && preg_match('/^[A-Za-z]:\//', $path) !== 1;
}
