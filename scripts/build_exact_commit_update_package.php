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

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "Sodium extension is required for Ed25519 update package signing.\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', [
    'commit::',
    'version::',
    'output-dir::',
    'channel::',
    'release-id::',
    'min-upgrade-from::',
    'key-id::',
    'signature-algorithm::',
    'rsa-private-file::',
    'rsa-private-pem::',
    'ed25519-secret-base64::',
    'ed25519-secret-file::',
]);

$commit = resolveCommit($root, trim((string) ($options['commit'] ?? 'HEAD')));
$channel = trim((string) ($options['channel'] ?? 'stable')) ?: 'stable';
$minUpgradeFrom = trim((string) ($options['min-upgrade-from'] ?? '1.2.0')) ?: '1.2.0';
$version = trim((string) ($options['version'] ?? ''));
if ($version === '') {
    $version = detectVersion($root, $commit);
}
if (!preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+){1,3}$/', $version)) {
    fail('Invalid version: ' . $version);
}
$releaseId = trim((string) ($options['release-id'] ?? 'daiying-cms-core-update-' . $version));
if (!preg_match('/^[A-Za-z0-9._:-]{2,191}$/', $releaseId)) {
    fail('Invalid release id: ' . $releaseId);
}
$keyId = trim((string) ($options['key-id'] ?? 'foundation-rc-ed25519'));
$signatureAlgorithm = trim((string) ($options['signature-algorithm'] ?? 'ed25519'));
if (!in_array($signatureAlgorithm, ['ed25519', 'rsa-sha256'], true)) {
    fail('Unsupported signature algorithm: ' . $signatureAlgorithm);
}
$signer = signingMaterial($options, $signatureAlgorithm);

$outputDir = rtrim((string) ($options['output-dir'] ?? ($root . '/outputs/core-update-' . $version . '-exact')), '/');
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fail('Unable to create output directory: ' . $outputDir);
}

$packageName = 'daiying-cms-core-update-' . $version . '-' . $channel . '-exact-' . substr($commit, 0, 12);
$zipPath = $outputDir . '/' . $packageName . '.zip';
$metadataPath = $outputDir . '/' . $packageName . '.metadata.json';
$shaPath = $outputDir . '/' . $packageName . '.zip.sha256';

$files = [];
foreach (gitLines($root, ['ls-tree', '-r', '--name-only', $commit]) as $file) {
    if (isAllowedUpdatePath($file)) {
        $files[$file] = hash('sha256', gitBlob($root, $commit, $file));
    }
}
ksort($files, SORT_STRING);
if (!isset($files['system/core-manifest.json'])) {
    fail('Required update file is missing from target commit: system/core-manifest.json');
}

$expectedCoreManifest = buildCoreManifest($root, $commit);
$committedCoreManifest = json_decode(gitBlob($root, $commit, 'system/core-manifest.json'), true);
if (!is_array($committedCoreManifest) || $committedCoreManifest !== $expectedCoreManifest) {
    fail('system/core-manifest.json does not match system/core files at target commit.');
}

$requiredMigrations = migrationIds(array_keys($files));
$update = [
    'package_type' => 'core',
    'product_id' => 'daiying.cms',
    'package_id' => 'daiying.cms:' . $version . ':' . $channel,
    'release_id' => $releaseId,
    'version' => $version,
    'from_version' => $minUpgradeFrom,
    'source_versions' => ['min' => $minUpgradeFrom, 'max' => $version],
    'min_upgrade_from' => $minUpgradeFrom,
    'hard_min_version' => $minUpgradeFrom,
    'migration_floor' => $minUpgradeFrom,
    'created_at' => gmdate('c'),
    'build' => substr($commit, 0, 12),
    'exact_commit' => $commit,
    'php' => ['min' => runtimePhpMin($root, $commit)],
    'required_extensions' => ['pdo', 'json', 'openssl', 'fileinfo', 'zip'],
    'database_types' => ['sqlite', 'mysql'],
    'required_migrations' => $requiredMigrations,
    'migrations' => array_map(static fn (string $id): array => [
        'id' => $id,
        'path' => 'system/migrations/' . $id . '.php',
        'target_version' => $version,
    ], $requiredMigrations),
    'rollback' => [
        'database' => true,
        'core_pointer' => true,
        'operational_support_files' => true,
    ],
    'signature_algorithm' => $signatureAlgorithm,
    'key_id' => $keyId,
    'security_update' => false,
    'features' => ['foundation_release_parity'],
    'acceptance_gates' => ['release_parity_gate'],
    'notes' => 'Exact-commit Core update package. Do not mark current until release_parity_gate passes against metadata.',
    'files' => $files,
];
$updateJson = json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($updateJson)) {
    fail('Unable to encode update.json.');
}
$signature = signPayload($updateJson, $signer, $signatureAlgorithm);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('Unable to create update ZIP: ' . $zipPath);
}
$zip->addFromString('update.json', $updateJson);
$zip->addFromString('signature.bin', $signature);
foreach (array_keys($files) as $file) {
    $zip->addFromString($file, gitBlob($root, $commit, $file));
}
$zip->close();

$sha256 = hash_file('sha256', $zipPath);
if (!is_string($sha256)) {
    fail('Unable to calculate update ZIP SHA-256.');
}
$metadata = [
    'product_id' => 'daiying.cms',
    'package_id' => 'daiying.cms:' . $version . ':' . $channel,
    'release_id' => $releaseId,
    'version' => $version,
    'channel' => $channel,
    'exact_commit' => $commit,
    'package_url' => '',
    'package_sha256' => $sha256,
    'min_upgrade_from' => $minUpgradeFrom,
    'hard_min_version' => $minUpgradeFrom,
    'migration_floor' => $minUpgradeFrom,
    'required_migrations' => $requiredMigrations,
    'changed_files' => array_keys($files),
    'signature_algorithm' => $signatureAlgorithm,
    'key_id' => $keyId,
    'public_key' => $signer['public_key'],
    'is_current_candidate' => false,
    'created_at' => gmdate('c'),
];
$metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($metadataJson)) {
    fail('Unable to encode metadata JSON.');
}
file_put_contents($metadataPath, $metadataJson . "\n");
file_put_contents($shaPath, $sha256 . '  ' . basename($zipPath) . "\n");

echo "Exact commit update package built and signed.\n";
echo "Commit: {$commit}\n";
echo "Version: {$version}\n";
echo "ZIP: {$zipPath}\n";
echo "SHA256: {$sha256}\n";
echo "Metadata: {$metadataPath}\n";
echo "Changed files: " . count($files) . "\n";
echo "Required migrations: " . count($requiredMigrations) . "\n";

/** @return array{secret:string,public_key:string} */
function signingMaterial(array $options, string $algorithm): array
{
    if ($algorithm === 'rsa-sha256') {
        $private = trim((string) ($options['rsa-private-pem'] ?? ''));
        $file = trim((string) ($options['rsa-private-file'] ?? ''));
        if ($private === '' && $file !== '') {
            $private = (string) file_get_contents($file);
        }
        if ($private === '') {
            $private = (string) getenv('DAIYING_UPDATE_RSA_PRIVATE_PEM');
        }
        $key = openssl_pkey_get_private($private);
        if ($key === false) {
            fail('A valid RSA private key is required via --rsa-private-pem, --rsa-private-file, or DAIYING_UPDATE_RSA_PRIVATE_PEM.');
        }
        $details = openssl_pkey_get_details($key);
        $public = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($public === '' || !str_contains($public, 'BEGIN PUBLIC KEY')) {
            fail('Unable to derive RSA public key from private key.');
        }

        return ['secret' => $private, 'public_key' => $public];
    }

    $secret = signingSecret($options);

    return ['secret' => $secret, 'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))];
}

function signingSecret(array $options): string
{
    $encoded = trim((string) ($options['ed25519-secret-base64'] ?? ''));
    $file = trim((string) ($options['ed25519-secret-file'] ?? ''));
    if ($encoded === '' && $file !== '') {
        $encoded = trim((string) file_get_contents($file));
    }
    if ($encoded === '') {
        $encoded = trim((string) getenv('DAIYING_UPDATE_ED25519_SECRET_BASE64'));
    }
    $secret = base64_decode($encoded, true);
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail('A base64 Ed25519 secret key is required via --ed25519-secret-base64, --ed25519-secret-file, or DAIYING_UPDATE_ED25519_SECRET_BASE64.');
    }

    return $secret;
}

/** @param array{secret:string,public_key:string} $signer */
function signPayload(string $payload, array $signer, string $algorithm): string
{
    if ($algorithm === 'rsa-sha256') {
        $signature = '';
        if (!openssl_sign($payload, $signature, $signer['secret'], OPENSSL_ALGO_SHA256)) {
            fail('Unable to sign update package with RSA private key.');
        }

        return $signature;
    }

    return sodium_crypto_sign_detached($payload, $signer['secret']);
}

function resolveCommit(string $root, string $rev): string
{
    $rev = $rev === '' ? 'HEAD' : $rev;
    $commit = git($root, ['rev-parse', $rev . '^{commit}']);
    if (!preg_match('/^[a-f0-9]{40}$/', $commit)) {
        fail('Unable to resolve target commit.');
    }

    return $commit;
}

function detectVersion(string $root, string $commit): string
{
    $config = gitBlob($root, $commit, 'config/app.example.php');
    if (preg_match("/['\"]version['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $config, $match) === 1) {
        return $match[1];
    }
    fail('Unable to detect version from config/app.example.php.');
}

function runtimePhpMin(string $root, string $commit): string
{
    $path = 'system/core/Support/RuntimeRequirements.php';
    $content = gitBlob($root, $commit, $path);
    if (preg_match("/PHP_MIN\\s*=\\s*['\"]([^'\"]+)['\"]/", $content, $match) === 1) {
        return $match[1];
    }

    return '8.3.0';
}

function isAllowedUpdatePath(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains('/' . $path, '/../')) {
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
        if (preg_match('#^system/migrations/([A-Za-z0-9_.-]+)\.php$#', (string) $file, $match) === 1) {
            if (isDeprecatedUpdateManifestMigration($match[1])) {
                continue;
            }
            $ids[] = $match[1];
        }
    }
    sort($ids, SORT_STRING);

    return array_values(array_unique($ids));
}

function isDeprecatedUpdateManifestMigration(string $migrationId): bool
{
    return $migrationId === '2026_09_07_000001_official_plugins_registry';
}

/** @return list<string> */
function gitLines(string $root, array $args): array
{
    return array_values(array_filter(array_map('trim', explode("\n", git($root, $args))), static fn (string $line): bool => $line !== ''));
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
    git($root, ['cat-file', '-e', $commit . ':' . $path]);
    $cmd = 'cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg($commit . ':' . $path);
    $content = shell_exec($cmd);
    if (!is_string($content)) {
        fail('Unable to read git blob: ' . $path);
    }

    return $content;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
