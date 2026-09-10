<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;

final class OfficialExtensionTrustGrantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='cms_extension_trust_grants'");
                $stmt->execute();
                return (string) $stmt->fetchColumn() === 'cms_extension_trust_grants';
            }
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'cms_extension_trust_grants'");
            $stmt->execute();
            return (string) $stmt->fetchColumn() !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $envelope */
    public function save(OfficialExtensionTrustGrant $grant, array $envelope): void
    {
        if (!$this->tableExists()) {
            throw new PluginException('Official extension trust grants table is missing.');
        }
        $now = gmdate('c');
        $this->pdo->prepare("UPDATE cms_extension_trust_grants SET status = 'superseded', updated_at = :updated_at WHERE extension_id = :extension_id AND extension_type = :extension_type AND status = 'active' AND grant_fingerprint <> :grant_fingerprint")
            ->execute([
                ':extension_id' => $grant->extensionId,
                ':extension_type' => $grant->extensionType,
                ':grant_fingerprint' => $grant->fingerprint,
                ':updated_at' => $now,
            ]);

        $existing = $this->findByFingerprint($grant->fingerprint);
        $params = [
            ':extension_id' => $grant->extensionId,
            ':extension_type' => $grant->extensionType,
            ':publisher' => $grant->publisher,
            ':source' => $grant->source,
            ':trust_level' => $grant->trustLevel,
            ':capability_namespaces_json' => json_encode($grant->capabilityNamespaces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':table_prefixes_json' => json_encode($grant->tablePrefixes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':route_prefixes_json' => json_encode($grant->routePrefixes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':admin_menu_json' => json_encode($grant->adminMenu, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':provider_capabilities_json' => json_encode($grant->providerCapabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':status' => $grant->status,
            ':schema_version' => $grant->schemaVersion,
            ':issued_at' => $grant->issuedAt,
            ':expires_at' => $grant->expiresAt,
            ':grant_fingerprint' => $grant->fingerprint,
            ':signature' => $grant->signature,
            ':key_id' => $grant->keyId,
            ':payload_json' => json_encode(['payload' => $grant->payload, 'envelope' => $envelope], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':updated_at' => $now,
        ];

        if ($existing === null) {
            $stmt = $this->pdo->prepare('INSERT INTO cms_extension_trust_grants (extension_id, extension_type, publisher, source, trust_level, capability_namespaces_json, table_prefixes_json, route_prefixes_json, admin_menu_json, provider_capabilities_json, status, schema_version, issued_at, expires_at, grant_fingerprint, signature, key_id, payload_json, created_at, updated_at) VALUES (:extension_id, :extension_type, :publisher, :source, :trust_level, :capability_namespaces_json, :table_prefixes_json, :route_prefixes_json, :admin_menu_json, :provider_capabilities_json, :status, :schema_version, :issued_at, :expires_at, :grant_fingerprint, :signature, :key_id, :payload_json, :created_at, :updated_at)');
            $stmt->execute($params + [':created_at' => $now]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE cms_extension_trust_grants SET extension_id = :extension_id, extension_type = :extension_type, publisher = :publisher, source = :source, trust_level = :trust_level, capability_namespaces_json = :capability_namespaces_json, table_prefixes_json = :table_prefixes_json, route_prefixes_json = :route_prefixes_json, admin_menu_json = :admin_menu_json, provider_capabilities_json = :provider_capabilities_json, status = :status, schema_version = :schema_version, issued_at = :issued_at, expires_at = :expires_at, signature = :signature, key_id = :key_id, payload_json = :payload_json, updated_at = :updated_at WHERE grant_fingerprint = :grant_fingerprint');
        $stmt->execute($params);
    }

    public function activeGrant(string $extensionId, string $extensionType = ''): ?OfficialExtensionTrustGrant
    {
        if (!$this->tableExists()) {
            return null;
        }
        $sql = "SELECT * FROM cms_extension_trust_grants WHERE extension_id = :extension_id AND status = 'active'";
        $params = [':extension_id' => $extensionId];
        if ($extensionType !== '') {
            $sql .= ' AND extension_type = :extension_type';
            $params[':extension_type'] = $extensionType;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $grant = $this->grantFromRow($row);
            if ($grant->expiresAt !== '' && strtotime($grant->expiresAt) <= time()) {
                continue;
            }

            return $grant;
        }

        return null;
    }

    public function deleteFingerprint(string $fingerprint): void
    {
        if ($fingerprint === '' || !$this->tableExists()) {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM cms_extension_trust_grants WHERE grant_fingerprint = :grant_fingerprint');
        $stmt->execute([':grant_fingerprint' => $fingerprint]);
    }

    /** @return list<OfficialExtensionTrustGrant> */
    public function activeGrants(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $stmt = $this->pdo->query("SELECT * FROM cms_extension_trust_grants WHERE status = 'active' ORDER BY id");

        return array_values(array_filter(array_map(function (array $row): ?OfficialExtensionTrustGrant {
            $grant = $this->grantFromRow($row);
            if ($grant->expiresAt !== '' && strtotime($grant->expiresAt) <= time()) {
                return null;
            }

            return $grant;
        }, $stmt->fetchAll())));
    }

    /** @return list<string> */
    public function tablePrefixes(string $extensionId): array
    {
        $grant = $this->activeGrant($extensionId);

        return $grant?->tablePrefixes ?? [];
    }

    /** @return list<string> */
    public function capabilityNamespaces(string $extensionId): array
    {
        $grant = $this->activeGrant($extensionId);

        return $grant?->capabilityNamespaces ?? [];
    }

    /** @return array<string,mixed>|null */
    private function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_extension_trust_grants WHERE grant_fingerprint = :grant_fingerprint LIMIT 1');
        $stmt->execute([':grant_fingerprint' => $fingerprint]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function grantFromRow(array $row): OfficialExtensionTrustGrant
    {
        return new OfficialExtensionTrustGrant(
            (string) $row['extension_id'],
            (string) $row['extension_type'],
            (string) $row['publisher'],
            (string) $row['source'],
            (string) $row['trust_level'],
            $this->jsonList((string) ($row['capability_namespaces_json'] ?? '[]')),
            $this->jsonList((string) ($row['table_prefixes_json'] ?? '[]')),
            $this->jsonList((string) ($row['route_prefixes_json'] ?? '[]')),
            $this->jsonObject((string) ($row['admin_menu_json'] ?? '{}')),
            $this->jsonObject((string) ($row['provider_capabilities_json'] ?? '{}')),
            (string) $row['status'],
            (int) $row['schema_version'],
            (string) $row['issued_at'],
            (string) $row['expires_at'],
            (string) $row['grant_fingerprint'],
            (string) $row['signature'],
            (string) ($row['key_id'] ?? ''),
            $this->jsonObject((string) ($row['payload_json'] ?? '{}'))['payload'] ?? []
        );
    }

    /** @return list<string> */
    private function jsonList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
