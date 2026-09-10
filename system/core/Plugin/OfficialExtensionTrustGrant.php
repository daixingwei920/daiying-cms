<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class OfficialExtensionTrustGrant
{
    /**
     * @param list<string> $capabilityNamespaces
     * @param list<string> $tablePrefixes
     * @param list<string> $routePrefixes
     * @param array<string,mixed> $adminMenu
     * @param array<string,mixed> $providerCapabilities
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public readonly string $extensionId,
        public readonly string $extensionType,
        public readonly string $publisher,
        public readonly string $source,
        public readonly string $trustLevel,
        public readonly array $capabilityNamespaces,
        public readonly array $tablePrefixes,
        public readonly array $routePrefixes,
        public readonly array $adminMenu,
        public readonly array $providerCapabilities,
        public readonly string $status,
        public readonly int $schemaVersion,
        public readonly string $issuedAt,
        public readonly string $expiresAt,
        public readonly string $fingerprint,
        public readonly string $signature,
        public readonly string $keyId,
        public readonly array $payload,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload, string $signature, string $keyId = ''): self
    {
        $normalized = self::normalizePayload($payload);

        return new self(
            (string) $normalized['extension_id'],
            (string) $normalized['extension_type'],
            (string) $normalized['publisher'],
            (string) $normalized['source'],
            (string) $normalized['trust_level'],
            $normalized['capability_namespaces'],
            $normalized['table_prefixes'],
            $normalized['route_prefixes'],
            $normalized['admin_menu'],
            $normalized['provider_capabilities'],
            (string) $normalized['status'],
            (int) $normalized['schema_version'],
            (string) $normalized['issued_at'],
            (string) $normalized['expires_at'],
            self::fingerprint($normalized),
            $signature,
            $keyId,
            $normalized,
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function normalizePayload(array $payload): array
    {
        $payload = self::stripEnvelopeFields($payload);
        $payload['extension_id'] = trim((string) ($payload['extension_id'] ?? ''));
        $payload['extension_type'] = str_replace('-', '_', trim((string) ($payload['extension_type'] ?? 'plugin')));
        $payload['publisher'] = trim((string) ($payload['publisher'] ?? 'official'));
        $payload['source'] = trim((string) ($payload['source'] ?? 'official_market'));
        $payload['trust_level'] = trim((string) ($payload['trust_level'] ?? 'api'));
        $payload['status'] = trim((string) ($payload['status'] ?? 'active'));
        $payload['schema_version'] = (int) ($payload['schema_version'] ?? 1);
        $payload['issued_at'] = trim((string) ($payload['issued_at'] ?? ''));
        $payload['expires_at'] = trim((string) ($payload['expires_at'] ?? ''));
        $payload['capability_namespaces'] = self::stringList($payload['capability_namespaces'] ?? []);
        $payload['table_prefixes'] = self::stringList($payload['table_prefixes'] ?? []);
        $payload['route_prefixes'] = self::stringList($payload['route_prefixes'] ?? []);
        $payload['admin_menu'] = is_array($payload['admin_menu'] ?? null) ? $payload['admin_menu'] : [];
        $payload['provider_capabilities'] = is_array($payload['provider_capabilities'] ?? null) ? $payload['provider_capabilities'] : [];
        ksort($payload);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function canonicalPayload(array $payload): string
    {
        return self::canonicalJson(self::normalizePayload($payload));
    }

    /** @param array<string,mixed> $payload */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', self::canonicalPayload($payload));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function stripEnvelopeFields(array $payload): array
    {
        unset($payload['signature'], $payload['grant_fingerprint'], $payload['fingerprint'], $payload['key_id']);

        return $payload;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== '')));
    }

    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::sortCanonical($item);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function sortCanonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortCanonical($item);
        }

        return $value;
    }
}
