<?php

declare(strict_types=1);

namespace Cms\Core\Update;

final class SignatureVerifier
{
    /** @param list<string> $revokedKeyIds */
    public function __construct(
        private readonly string $publicKey,
        private readonly array $revokedKeyIds = [],
        private readonly string $expiresAt = '',
    )
    {
    }

    public function verify(string $payload, string $signature, string $keyId = ''): bool
    {
        if ($this->publicKey === '') {
            throw new UpdateException('Update public key is not configured.');
        }
        if ($keyId !== '' && in_array($keyId, $this->revokedKeyIds, true)) {
            throw new UpdateException('Update signing key is revoked.');
        }
        if ($this->expiresAt !== '' && strtotime($this->expiresAt) <= time()) {
            throw new UpdateException('Update signing key is expired.');
        }

        $ed25519 = $this->ed25519PublicKey();
        if ($ed25519 !== null) {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                throw new UpdateException('Sodium extension is required for Ed25519 update verification.');
            }

            return sodium_crypto_sign_verify_detached($signature, $payload, $ed25519);
        }

        $result = openssl_verify($payload, $signature, $this->publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    private function ed25519PublicKey(): ?string
    {
        $key = trim($this->publicKey);
        if (str_contains($key, '-----BEGIN')) {
            return null;
        }
        if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            return null;
        }

        $decoded = base64_decode($key, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return $decoded;
    }
}
