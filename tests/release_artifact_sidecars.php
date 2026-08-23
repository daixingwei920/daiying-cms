<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/scripts/build_release_package.php';

$failures = 0;

function sidecar_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

$work = sys_get_temp_dir() . '/cms-release-sidecars-' . bin2hex(random_bytes(4));
mkdir($work, 0777, true);
$zipPath = $work . '/daiying-cms-1.2.0.zip';
$sourceCoreManifestBeforeBuild = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core-manifest.json');

cms_build_release_package(CMS_SOURCE_ROOT, $zipPath);
$sourceCoreManifestAfterBuild = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core-manifest.json');
$sidecars = cms_write_release_sidecars(CMS_SOURCE_ROOT, $zipPath);

$shaPath = $sidecars['sha256'];
$manifestPath = $sidecars['manifest'];
$actualHash = hash_file('sha256', $zipPath);
$shaText = is_file($shaPath) ? trim((string) file_get_contents($shaPath)) : '';
$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;

sidecar_check(is_file($zipPath) && filesize($zipPath) > 100000, 'release ZIP builds for sidecar verification');
sidecar_check($sourceCoreManifestAfterBuild === $sourceCoreManifestBeforeBuild, 'release ZIP build preserves source tree Core manifest');
sidecar_check(is_file($shaPath) && filesize($shaPath) > 70, 'SHA-256 sidecar is written');
sidecar_check(is_file($manifestPath) && filesize($manifestPath) > 100, 'artifact manifest sidecar is written');
sidecar_check($shaText === $actualHash . '  daiying-cms-1.2.0.zip', 'SHA-256 sidecar matches package hash and basename');
sidecar_check(is_array($manifest), 'artifact manifest is valid JSON');
sidecar_check(($manifest['package'] ?? '') === 'daiying-cms-1.2.0.zip', 'artifact manifest records package basename');
sidecar_check(($manifest['package_sha256'] ?? '') === $actualHash, 'artifact manifest records package hash');
sidecar_check(($manifest['size_bytes'] ?? 0) === filesize($zipPath), 'artifact manifest records package size');
sidecar_check(($manifest['app_version'] ?? '') === '1.2.0', 'artifact manifest records stable release-candidate app version');
sidecar_check(($manifest['audit_pass_count'] ?? 0) === cms_release_audit_pass_count(CMS_SOURCE_ROOT), 'artifact manifest records current audit PASS count');

$zip = new ZipArchive();
$zip->open($zipPath);
sidecar_check($zip->locateName('daiying-cms-1.2.0.zip.sha256') === false, 'package does not contain its SHA-256 sidecar');
sidecar_check($zip->locateName('daiying-cms-1.2.0.manifest.json') === false, 'package does not contain its artifact manifest sidecar');
$zip->close();

@unlink($shaPath);
@unlink($manifestPath);
@unlink($zipPath);
@rmdir($work);

if ($failures > 0) {
    fwrite(STDERR, $failures . " release artifact sidecar checks failed.\n");
    exit(1);
}

echo "Release artifact sidecar tests passed.\n";
