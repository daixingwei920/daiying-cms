<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class PackageSigner
{
    public function __construct(private readonly string $privateKey)
    {
    }

    /** @return array{payload: string, signature: string, algorithm: string} */
    public function sign(array $manifest): array
    {
        if ($this->privateKey === '') {
            throw new MarketServerException('Market signing private key is not configured.');
        }

        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new MarketServerException('Unable to encode market manifest.');
        }

        $signature = '';
        if (openssl_sign($payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256) !== true) {
            throw new MarketServerException('Unable to sign market package.');
        }

        return [
            'payload' => $payload,
            'signature' => base64_encode($signature),
            'algorithm' => 'openssl-sha256',
        ];
    }
}
