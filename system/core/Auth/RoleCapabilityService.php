<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use PDO;

final class RoleCapabilityService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function grant(string $role, string $capability): void
    {
        CapabilityRegistry::register($capability, $capability);
        if ($this->has($role, $capability)) {
            return;
        }
        $now = gmdate('c');
        $this->pdo->prepare('INSERT INTO cms_role_capabilities (role_id, capability, created_at) VALUES (:role_id, :capability, :created_at)')
            ->execute([':role_id' => $role, ':capability' => $capability, ':created_at' => $now]);
    }

    public function has(string $role, string $capability): bool
    {
        if ($role === 'super_admin') {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM cms_role_capabilities WHERE role_id = :role_id AND capability = :capability LIMIT 1');
        $stmt->execute([':role_id' => $role, ':capability' => $capability]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<string> */
    public function capabilitiesFor(string $role): array
    {
        if ($role === 'super_admin') {
            return array_keys(CapabilityRegistry::all());
        }
        $stmt = $this->pdo->prepare('SELECT capability FROM cms_role_capabilities WHERE role_id = :role_id ORDER BY capability');
        $stmt->execute([':role_id' => $role]);

        return array_values(array_map(static fn (array $row): string => (string) $row['capability'], $stmt->fetchAll()));
    }
}
