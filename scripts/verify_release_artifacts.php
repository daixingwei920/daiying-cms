<?php

declare(strict_types=1);

if (!extension_loaded('sodium')) {
    throw new RuntimeException('Sodium extension is required for Ed25519 verification.');
}

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "Usage: php scripts/verify_release_artifacts.php <artifact.zip> [...]\n");
    exit(1);
}

foreach ($files as $file) {
    $signaturePath = $file . '.signature.json';
    if (!is_file($file) || !is_file($signaturePath)) {
        throw new RuntimeException('Artifact or signature sidecar is missing: ' . $file);
    }
    $decoded = json_decode((string) file_get_contents($signaturePath), true, 512, JSON_THROW_ON_ERROR);
    $payload = (array) ($decoded['payload'] ?? []);
    $expectedHash = (string) ($payload['sha256'] ?? '');
    $actualHash = hash_file('sha256', $file);
    if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('SHA-256 mismatch: ' . basename($file));
    }
    $public = base64_decode((string) ($decoded['public_key'] ?? ''), true);
    $signature = base64_decode((string) ($decoded['signature'] ?? ''), true);
    if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($signature)) {
        throw new RuntimeException('Invalid signature metadata: ' . basename($file));
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!sodium_crypto_sign_verify_detached($signature, $payloadJson, $public)) {
        throw new RuntimeException('Signature verification failed: ' . basename($file));
    }
    echo '[PASS] ' . basename($file) . PHP_EOL;
}

echo "Release artifact verification passed.\n";
