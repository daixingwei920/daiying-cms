<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$out = sys_get_temp_dir() . '/daiying-release-phase0-' . bin2hex(random_bytes(4));
mkdir($out, 0755, true);

$build = run('php scripts/build_exact_commit_installer.php --commit=HEAD --output-dir=' . escapeshellarg($out) . ' 2>&1', $root);
if ($build['code'] !== 0) {
    fail('Exact commit installer builder failed.' . "\n" . $build['output']);
}

$zip = firstGlob($out . '/*.zip');
$manifest = firstGlob($out . '/*.manifest.json');
if ($zip === '' || $manifest === '') {
    fail('Builder did not create expected ZIP and manifest artifacts.');
}

$manifestData = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
assertTrue((string) ($manifestData['exact_commit'] ?? '') === trim(run('git rev-parse HEAD', $root)['output']), 'manifest records exact commit');
assertTrue((string) ($manifestData['package_sha256'] ?? '') === hash_file('sha256', $zip), 'manifest sha256 matches ZIP');

writeEd25519Sidecar($zip);

$gate = run('php scripts/release_parity_gate.php --commit=HEAD --installer-zip=' . escapeshellarg($zip) . ' --installer-manifest=' . escapeshellarg($manifest) . ' 2>&1', $root);
if ($gate['code'] !== 0) {
    fail('Release parity gate should pass for exact commit installer.' . "\n" . $gate['output']);
}

$updateOut = $out . '/update';
mkdir($updateOut, 0755, true);
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$updateBuild = run(
    'php scripts/build_exact_commit_update_package.php --commit=HEAD --output-dir=' . escapeshellarg($updateOut) . ' --ed25519-secret-base64=' . escapeshellarg(base64_encode($secret)) . ' 2>&1',
    $root
);
if ($updateBuild['code'] !== 0) {
    fail('Exact commit update package builder failed.' . "\n" . $updateBuild['output']);
}
$updateZip = firstGlob($updateOut . '/*.zip');
$updateMetadata = firstGlob($updateOut . '/*.metadata.json');
if ($updateZip === '' || $updateMetadata === '') {
    fail('Update builder did not create expected ZIP and metadata artifacts.');
}
$updateGate = run(
    'php scripts/release_parity_gate.php --commit=HEAD --update-zip=' . escapeshellarg($updateZip) . ' --update-metadata=' . escapeshellarg($updateMetadata) . ' 2>&1',
    $root
);
if ($updateGate['code'] !== 0) {
    fail('Release parity gate should pass for exact commit update package.' . "\n" . $updateGate['output']);
}

$tampered = $out . '/tampered.zip';
copy($zip, $tampered);
$za = new ZipArchive();
if ($za->open($tampered) !== true) {
    fail('Unable to open tampered ZIP.');
}
$za->addFromString('README.md', 'tampered');
$za->close();
writeEd25519Sidecar($tampered);
$badGate = run('php scripts/release_parity_gate.php --commit=HEAD --installer-zip=' . escapeshellarg($tampered) . ' --installer-manifest=' . escapeshellarg($manifest) . ' 2>&1', $root);
assertTrue($badGate['code'] !== 0, 'release parity gate rejects tampered installer');

removeDirectory($out);
echo "Release Phase 0 gate tests PASS\n";

/** @return array{code:int,output:string} */
function run(string $command, string $cwd): array
{
    $output = [];
    $code = 0;
    exec('cd ' . escapeshellarg($cwd) . ' && ' . $command, $output, $code);

    return ['code' => $code, 'output' => implode("\n", $output)];
}

function firstGlob(string $pattern): string
{
    $files = glob($pattern);
    if (!is_array($files) || $files === []) {
        return '';
    }
    sort($files, SORT_STRING);

    return (string) $files[0];
}

function writeEd25519Sidecar(string $zipPath): void
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secret = sodium_crypto_sign_secretkey($pair);
    $payload = [
        'artifact' => basename($zipPath),
        'sha256' => hash_file('sha256', $zipPath),
        'signed_at' => gmdate('c'),
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $signature = sodium_crypto_sign_detached($payloadJson, $secret);
    file_put_contents($zipPath . '.signature.json', json_encode([
        'payload' => $payload,
        'public_key' => base64_encode($public),
        'signature' => base64_encode($signature),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fail('Assertion failed: ' . $message);
    }
}

function removeDirectory(string $dir): void
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

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
