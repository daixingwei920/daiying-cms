<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Config\Settings;
use Cms\Core\Market\CommercialLicenseStore;

final class PluginLicenseService
{
    private const VALID_STATUSES = ['active', 'offline_grace'];

    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly CommercialLicenseStore $store,
        private readonly ?Settings $settings = null,
    ) {
    }

    /** @return array{plugin_id:string,status:string,tier:string,features:list<string>,domain:string,expires_at:string,checked_at:string,grace_until:string} */
    public function current(): array
    {
        $row = $this->store->licenseForProduct($this->manifest->id);
        if ($row === []) {
            return $this->result('missing');
        }

        $payload = $this->decodePayload((string) ($row['activation_payload_json'] ?? ''));
        $code = $this->licenseCode($payload, (string) ($row['license_key_credential'] ?? ''));
        if ($code === '') {
            return $this->result('missing');
        }

        $verified = $this->verifyLicenseCode($code);
        if (!$verified['valid']) {
            return $this->result($verified['status']);
        }

        $license = $verified['payload'];
        if ((string) ($license['plugin_id'] ?? '') !== $this->manifest->id) {
            return $this->result('invalid');
        }

        $domain = $this->payloadDomain($license);
        if (!$this->domainMatches($domain, $this->currentSiteDomain())) {
            return $this->result('invalid');
        }

        if ($this->isExpired($license['expires_at'] ?? null)) {
            return $this->result('expired', $license);
        }

        $status = strtolower((string) ($row['status'] ?? 'ACTIVE'));
        if ($status === 'expired') {
            return $this->result('expired', $license);
        }
        if ($status === 'revoked') {
            return $this->result('revoked', $license);
        }
        if (in_array($status, ['offline_grace', 'offline-grace'], true) || (bool) ($payload['offline_grace'] ?? false)) {
            $graceUntil = (string) ($payload['grace_until'] ?? ($license['grace_until'] ?? ''));
            if ($graceUntil === '' || strtotime($graceUntil) === false || strtotime($graceUntil) < time()) {
                return $this->result('invalid', $license);
            }

            return $this->result('offline_grace', $license, $graceUntil);
        }
        if (!in_array($status, ['active', 'ACTIVE'], true)) {
            return $this->result('invalid', $license);
        }

        return $this->result('active', $license, (string) ($payload['grace_until'] ?? ($license['grace_until'] ?? '')));
    }

    public function hasFeature(string $feature): bool
    {
        $state = $this->current();
        return in_array($state['status'], self::VALID_STATUSES, true)
            && in_array($feature, $state['features'], true);
    }

    public function requireFeature(string $feature): void
    {
        if (!$this->hasFeature($feature)) {
            throw new PluginException('Plugin license does not allow feature: ' . $feature);
        }
    }

    /** @return array{plugin_id:string,status:string,tier:string,features:list<string>,domain:string,expires_at:string,checked_at:string,grace_until:string} */
    public function activate(string $licenseCode): array
    {
        $verified = $this->verifyLicenseCode($licenseCode);
        if (!$verified['valid']) {
            throw new PluginException('Invalid plugin license.');
        }

        $license = $verified['payload'];
        if ((string) ($license['plugin_id'] ?? '') !== $this->manifest->id) {
            throw new PluginException('Plugin license belongs to a different plugin.');
        }
        if (!$this->domainMatches($this->payloadDomain($license), $this->currentSiteDomain())) {
            throw new PluginException('Plugin license is not valid for this site.');
        }
        if ($this->isExpired($license['expires_at'] ?? null)) {
            throw new PluginException('Plugin license is expired.');
        }

        $this->store->saveActivation([
            'license_code' => trim($licenseCode),
            'license' => [
                'product_id' => $this->manifest->id,
                'license_id' => (string) ($license['license_id'] ?? ''),
                'license_key_hash' => hash('sha256', trim($licenseCode)),
                'license_key_mask' => $this->mask(trim($licenseCode)),
                'license_key_credential' => '',
                'status' => 'ACTIVE',
                'update_until' => $this->dateString($license['expires_at'] ?? null),
                'activated_at' => gmdate('c'),
            ],
            'checked_at' => gmdate('c'),
            'grace_until' => $this->defaultGraceUntil(),
        ]);

        return $this->current();
    }

    public function clear(): void
    {
        $this->store->clear($this->manifest->id);
    }

    /** @return array{valid:bool,status:string,payload:array<string,mixed>} */
    private function verifyLicenseCode(string $licenseCode): array
    {
        $code = trim($licenseCode);
        $parts = explode('.', $code);
        if (count($parts) === 3 && $parts[0] === 'dylic_v1') {
            array_shift($parts);
        }
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['valid' => false, 'status' => 'invalid', 'payload' => []];
        }

        $payloadJson = $this->base64UrlDecode($parts[0]);
        $signature = $this->base64UrlDecode($parts[1]);
        if ($payloadJson === null || $signature === null) {
            return ['valid' => false, 'status' => 'invalid', 'payload' => []];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return ['valid' => false, 'status' => 'invalid', 'payload' => []];
        }

        foreach ($this->publicKeys($payload) as $publicKey) {
            $ok = openssl_verify($payloadJson, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            if ($ok === 1) {
                return ['valid' => true, 'status' => 'active', 'payload' => $payload];
            }
        }

        return ['valid' => false, 'status' => 'invalid', 'payload' => []];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function publicKeys(array $payload): array
    {
        $keys = [];
        $configured = $this->settings?->get('market.license_public_keys', []);
        if (is_array($configured)) {
            $keyId = (string) ($payload['key_id'] ?? ($payload['kid'] ?? ''));
            if ($keyId !== '' && is_string($configured[$keyId] ?? null)) {
                $keys[] = trim((string) $configured[$keyId]);
            }
            foreach ($configured as $key) {
                if (is_string($key) && trim($key) !== '') {
                    $keys[] = trim($key);
                }
            }
        }

        $single = $this->settings?->get('market.license_public_key', '');
        if (is_string($single) && trim($single) !== '') {
            $keys[] = trim($single);
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    }

    /** @param array<string,mixed> $license */
    private function payloadDomain(array $license): string
    {
        $site = $license['site'] ?? null;
        if (is_array($site)) {
            $domains = $site['domains'] ?? null;
            if (is_array($domains) && isset($domains[0])) {
                return $this->normalizeDomain((string) $domains[0]);
            }
            return $this->normalizeDomain((string) ($site['domain'] ?? ($site['url'] ?? '')));
        }

        return $this->normalizeDomain((string) ($license['domain'] ?? ($license['site_url'] ?? '')));
    }

    private function currentSiteDomain(): string
    {
        $siteUrl = (string) ($this->settings?->get('site.url', '') ?? '');
        if ($siteUrl !== '') {
            return $this->normalizeDomain($siteUrl);
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
        return $this->normalizeDomain($host);
    }

    private function normalizeDomain(string $domain): string
    {
        $value = strtolower(trim($domain));
        if ($value === '') {
            return '';
        }
        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = rtrim($host, '.');
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function domainMatches(string $licensed, string $current): bool
    {
        return $licensed !== '' && $current !== '' && hash_equals($licensed, $current);
    }

    private function isExpired(mixed $expiresAt): bool
    {
        $timestamp = $this->timestamp($expiresAt);
        return $timestamp !== null && $timestamp > 0 && $timestamp < time();
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            if (ctype_digit($value)) {
                return (int) $value;
            }
            $parsed = strtotime($value);
            return $parsed === false ? null : $parsed;
        }

        return null;
    }

    private function dateString(mixed $value): string
    {
        $timestamp = $this->timestamp($value);
        return $timestamp !== null && $timestamp > 0 ? gmdate('c', $timestamp) : '';
    }

    /** @param array<string,mixed> $license @return list<string> */
    private function features(array $license): array
    {
        $features = $license['features'] ?? [];
        if (!is_array($features)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $features), static fn (string $feature): bool => $feature !== '')));
    }

    /** @param array<string,mixed> $license @return array{plugin_id:string,status:string,tier:string,features:list<string>,domain:string,expires_at:string,checked_at:string,grace_until:string} */
    private function result(string $status, array $license = [], string $graceUntil = ''): array
    {
        return [
            'plugin_id' => $this->manifest->id,
            'status' => $status,
            'tier' => (string) ($license['tier'] ?? ($license['type'] ?? 'free')),
            'features' => $this->features($license),
            'domain' => $license === [] ? '' : $this->payloadDomain($license),
            'expires_at' => $this->dateString($license['expires_at'] ?? null),
            'checked_at' => gmdate('c'),
            'grace_until' => $graceUntil,
        ];
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $json): array
    {
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : [];
    }

    /** @param array<string,mixed> $payload */
    private function licenseCode(array $payload, string $credential): string
    {
        foreach (['license_code', 'signed_license', 'signed_license_code'] as $key) {
            if (is_string($payload[$key] ?? null) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }
        $license = $payload['license'] ?? null;
        if (is_array($license)) {
            foreach (['license_code', 'signed_license', 'signed_license_code'] as $key) {
                if (is_string($license[$key] ?? null) && trim((string) $license[$key]) !== '') {
                    return trim((string) $license[$key]);
                }
            }
        }

        return str_contains($credential, '.') ? trim($credential) : '';
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }

    private function mask(string $code): string
    {
        if (strlen($code) <= 16) {
            return str_repeat('*', strlen($code));
        }

        return substr($code, 0, 6) . '...' . substr($code, -4);
    }

    private function defaultGraceUntil(): string
    {
        return gmdate('c', time() + 7 * 86400);
    }
}
