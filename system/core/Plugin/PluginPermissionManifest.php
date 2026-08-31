<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;

final class PluginPermissionManifest
{
    private const KNOWN = [
        'database.create_table',
        'database.read_plugin_data',
        'database.write_plugin_data',
        'media.read',
        'media.write',
        'members.read',
        'members.write',
        'comments.read',
        'comments.write',
        'network.outbound',
        'admin.menu',
        'admin.page',
        'cron.register',
        'block.register',
        'route.register',
        'filesystem.plugin_storage',
        'commerce.access',
        'payment.access',
        'cloud_storage.access',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{status:string,permissions:list<string>,added:list<string>,message:string} */
    public function evaluate(PluginManifest $manifest): array
    {
        $permissions = $manifest->permissions;
        $legacy = $permissions === [];
        if ($legacy) {
            return [
                'status' => 'LEGACY UNDECLARED PERMISSIONS',
                'permissions' => [],
                'added' => [],
                'message' => 'Plugin has no formal permission manifest and will run in legacy compatibility mode.',
            ];
        }
        foreach ($permissions as $permission) {
            if (!in_array($permission, self::KNOWN, true)) {
                throw new PluginException('Unknown plugin permission: ' . $permission);
            }
        }

        return [
            'status' => 'declared',
            'permissions' => array_values($permissions),
            'added' => $this->newPermissions($manifest->id, $permissions),
            'message' => 'Plugin declares formal permissions.',
        ];
    }

    /** @param list<string> $permissions */
    public function grant(string $pluginId, string $version, array $permissions, ?int $adminId): void
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO cms_plugin_permission_grants (plugin_id, plugin_version, permissions_json, status, granted_by, granted_at, created_at, updated_at)
             VALUES (:plugin_id, :plugin_version, :permissions_json, :status, :granted_by, :granted_at, :created_at, :updated_at)'
        )->execute([
            ':plugin_id' => $pluginId,
            ':plugin_version' => $version,
            ':permissions_json' => json_encode(array_values($permissions), JSON_UNESCAPED_SLASHES),
            ':status' => 'granted',
            ':granted_by' => $adminId,
            ':granted_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $this->pdo->prepare('UPDATE cms_plugins SET declared_permissions_json = :permissions, permission_grant_status = :status WHERE plugin_id = :plugin_id')
            ->execute([
                ':permissions' => json_encode(array_values($permissions), JSON_UNESCAPED_SLASHES),
                ':status' => 'granted',
                ':plugin_id' => $pluginId,
            ]);
    }

    /** @param list<string> $permissions @return list<string> */
    private function newPermissions(string $pluginId, array $permissions): array
    {
        $stmt = $this->pdo->prepare('SELECT permissions_json FROM cms_plugin_permission_grants WHERE plugin_id = :plugin_id AND status = :status ORDER BY id DESC LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId, ':status' => 'granted']);
        $previous = json_decode((string) ($stmt->fetchColumn() ?: '[]'), true);
        $previous = is_array($previous) ? array_values(array_map('strval', $previous)) : [];

        return array_values(array_diff($permissions, $previous));
    }
}
