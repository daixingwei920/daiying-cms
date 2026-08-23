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

        $result = openssl_verify($payload, $signature, $this->publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }
}
