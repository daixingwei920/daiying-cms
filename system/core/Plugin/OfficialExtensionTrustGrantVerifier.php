<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Market\MarketException;
use Cms\Core\Update\SignatureVerifier;

final class OfficialExtensionTrustGrantVerifier
{
    public function __construct(private readonly string $publicKey, private readonly string $expectedKeyId = '')
    {
    }

    /**
     * @param array<string,mixed> $envelope
     */
    public function verify(array $envelope): OfficialExtensionTrustGrant
    {
        $payload = $envelope['payload'] ?? $envelope['grant'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payload)) {
            throw new PluginException('Official extension trust grant payload is missing.');
        }

        $signature = (string) ($envelope['signature'] ?? $payload['signature'] ?? '');
        if ($signature === '') {
            throw new PluginException('Official extension trust grant is not signed.');
        }
        $keyId = (string) ($envelope['key_id'] ?? $payload['key_id'] ?? '');
        if ($this->expectedKeyId !== '' && $keyId !== '' && !hash_equals($this->expectedKeyId, $keyId)) {
            throw new PluginException('Official extension trust grant key id is not trusted.');
        }

        $canonical = OfficialExtensionTrustGrant::canonicalPayload($payload);
        $rawSignature = base64_decode($signature, true);
        if (!is_string($rawSignature) || $rawSignature === '') {
            throw new PluginException('Official extension trust grant signature is invalid.');
        }
        if (!(new SignatureVerifier($this->publicKey))->verify($canonical, $rawSignature)) {
            throw new PluginException('Official extension trust grant signature verification failed.');
        }

        $fingerprint = (string) ($envelope['grant_fingerprint'] ?? $payload['grant_fingerprint'] ?? $payload['fingerprint'] ?? '');
        $grant = OfficialExtensionTrustGrant::fromPayload($payload, $signature, $keyId);
        if ($fingerprint !== '' && !hash_equals($grant->fingerprint, $fingerprint)) {
            throw new PluginException('Official extension trust grant fingerprint mismatch.');
        }

        $this->assertGrantAllowed($grant);

        return $grant;
    }

    private function assertGrantAllowed(OfficialExtensionTrustGrant $grant): void
    {
        if ($grant->schemaVersion !== 1) {
            throw new PluginException('Unsupported official extension trust grant schema version.');
        }
        if (!in_array($grant->extensionType, ['plugin', 'payment_provider', 'theme'], true)) {
            throw new PluginException('Official extension trust grant extension type is invalid.');
        }
        if (!preg_match('/^official\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $grant->extensionId)) {
            throw new PluginException('Official extension trust grant extension id is invalid.');
        }
        if ($grant->publisher !== 'official') {
            throw new PluginException('Official extension trust grant publisher is not trusted.');
        }
        if (!in_array($grant->source, ['official_market', 'bundled_official'], true)) {
            throw new PluginException('Official extension trust grant source is invalid.');
        }
        if (!in_array($grant->trustLevel, ['api', 'trusted_php'], true)) {
            throw new PluginException('Official extension trust grant trust level is invalid.');
        }
        if ($grant->status !== 'active') {
            throw new PluginException('Official extension trust grant is not active.');
        }
        if ($grant->expiresAt !== '' && strtotime($grant->expiresAt) <= time()) {
            throw new PluginException('Official extension trust grant is expired.');
        }
        foreach ($grant->capabilityNamespaces as $namespace) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $namespace)) {
                throw new PluginException('Official extension trust grant capability namespace is invalid.');
            }
        }
        foreach ($grant->tablePrefixes as $prefix) {
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}_$/', $prefix) || in_array($prefix, ['cms_', 'market_'], true)) {
                throw new PluginException('Official extension trust grant table prefix is invalid.');
            }
        }
        foreach ($grant->routePrefixes as $prefix) {
            if ($prefix === '' || !str_starts_with($prefix, '/') || str_contains($prefix, '..')) {
                throw new PluginException('Official extension trust grant route prefix is invalid.');
            }
            if ($this->isReservedRoutePrefix($prefix)) {
                throw new PluginException('Official extension trust grant route prefix is reserved.');
            }
        }
    }

    private function isReservedRoutePrefix(string $prefix): bool
    {
        foreach (['/admin/login', '/recovery', '/diagnostics', '/health', '/install', '/admin/update', '/admin/market', '/api/market'] as $reserved) {
            if ($prefix === $reserved || str_starts_with($prefix, $reserved . '/')) {
                return true;
            }
        }

        return $prefix === '/';
    }
}
