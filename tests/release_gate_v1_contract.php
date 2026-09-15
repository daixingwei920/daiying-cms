<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateException;
use Cms\Core\Update\UpdatePackageReader;

$failures = 0;

function release_gate_v1_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$tmp = sys_get_temp_dir() . '/daiying-release-gate-v1-' . bin2hex(random_bytes(4));
mkdir($tmp, 0755, true);

$partialZip = $tmp . '/daiying-cms-core-update-partial.zip';
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = base64_encode(sodium_crypto_sign_publickey($keypair));

$files = [
    'system/core-manifest.json' => (string) file_get_contents(CMS_ROOT . '/system/core-manifest.json'),
    'system/core/Bootstrap/Application.php' => (string) file_get_contents(CMS_ROOT . '/system/core/Bootstrap/Application.php'),
    'system/core/Admin/AdminController.php' => (string) file_get_contents(CMS_ROOT . '/system/core/Admin/AdminController.php'),
];
$hashes = [];
foreach ($files as $path => $content) {
    $hashes[$path] = hash('sha256', $content);
}

$update = [
    'package_type' => 'core',
    'snapshot_type' => 'core-owned-full-snapshot',
    'product_id' => 'daiying.cms',
    'package_id' => 'daiying.cms:9.9.9:test',
    'release_id' => 'daiying-cms-core-update-partial-test',
    'version' => '9.9.9',
    'from_version' => '1.2.0',
    'source_versions' => ['min' => '1.2.0', 'max' => '9.9.9'],
    'min_upgrade_from' => '1.2.0',
    'hard_min_version' => '1.2.0',
    'migration_floor' => '1.2.0',
    'created_at' => gmdate('c'),
    'build' => 'partial-regression',
    'php' => ['min' => '8.3.0'],
    'required_extensions' => ['pdo', 'json', 'openssl', 'fileinfo', 'zip'],
    'database_types' => ['sqlite', 'mysql'],
    'required_migrations' => [],
    'migrations' => [],
    'signature_algorithm' => 'ed25519',
    'key_id' => 'release-gate-v1-test',
    'features' => ['foundation_release_gate_v1', 'core_owned_full_snapshot'],
    'acceptance_gates' => ['release_gate_v1', 'release_parity_gate'],
    'files' => $hashes,
];
$updateJson = json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$signature = sodium_crypto_sign_detached($updateJson, $secret);

$zip = new ZipArchive();
release_gate_v1_check($zip->open($partialZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'created partial update regression package');
$zip->addFromString('update.json', $updateJson);
$zip->addFromString('signature.bin', $signature);
foreach ($files as $path => $content) {
    $zip->addFromString($path, $content);
}
$zip->close();

try {
    (new UpdatePackageReader())->read($partialZip, new SignatureVerifier($public));
    release_gate_v1_check(false, 'UpdatePackageReader rejects a partial modern Core snapshot');
} catch (UpdateException $exception) {
    release_gate_v1_check(
        str_contains($exception->getMessage(), 'full Core snapshot')
            || str_contains($exception->getMessage(), 'Core manifest hash'),
        'UpdatePackageReader rejects a partial modern Core snapshot'
    );
}

$gate = run_release_gate_v1('php scripts/release_parity_gate.php --commit=HEAD --update-zip=' . escapeshellarg($partialZip) . ' --require-signature=0 2>&1');
release_gate_v1_check($gate['code'] !== 0, 'release gate rejects a partial Core update package before publication');
release_gate_v1_check(
    str_contains($gate['output'], 'update.exact_commit_files')
        || str_contains($gate['output'], 'update.full_core_snapshot'),
    'release gate failure explains update package parity or full snapshot violation'
);

remove_release_gate_v1_dir($tmp);

if ($failures > 0) {
    echo 'Release Gate V1 contract tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Release Gate V1 contract tests passed.' . PHP_EOL;

/** @return array{code:int,output:string} */
function run_release_gate_v1(string $command): array
{
    $output = [];
    $code = 0;
    exec('cd ' . escapeshellarg(CMS_ROOT) . ' && ' . $command, $output, $code);

    return ['code' => $code, 'output' => implode("\n", $output)];
}

function remove_release_gate_v1_dir(string $dir): void
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
