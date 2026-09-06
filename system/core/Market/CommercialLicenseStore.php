<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use PDO;

final class CommercialLicenseStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload */
    public function saveActivation(array $payload): void
    {
        $license = is_array($payload['license'] ?? null) ? $payload['license'] : [];
        $productId = (string) ($license['product_id'] ?? ($payload['product']['product_id'] ?? ''));
        if ($productId === '') {
            throw new MarketException('License activation payload is missing product_id.');
        }
        $now = gmdate('c');
        $this->pdo->prepare('DELETE FROM cms_site_licenses WHERE product_id = :product_id')->execute([':product_id' => $productId]);
        $this->pdo->prepare('INSERT INTO cms_site_licenses (product_id, license_key_hash, license_key_mask, license_key_credential, status, update_until, activation_payload_json, activated_at, updated_at) VALUES (:product_id, :hash, :mask, :credential, :status, :update_until, :payload, :activated_at, :updated_at)')->execute([
            ':product_id' => $productId,
            ':hash' => (string) ($license['license_key_hash'] ?? ''),
            ':mask' => (string) ($license['license_key_mask'] ?? ''),
            ':credential' => (string) ($license['license_key_credential'] ?? ''),
            ':status' => (string) ($license['status'] ?? 'ACTIVE'),
            ':update_until' => (string) ($license['update_until'] ?? ''),
            ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':activated_at' => (string) ($license['activated_at'] ?? $now),
            ':updated_at' => $now,
        ]);
    }

    /** @return array<string,mixed> */
    public function licenseForProduct(string $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_site_licenses WHERE product_id = :product_id LIMIT 1');
        $stmt->execute([':product_id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }
}
