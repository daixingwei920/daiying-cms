<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Payment\PaymentTransaction;
use PDO;
use Throwable;

final class PluginPermissionDecisionService
{
    private const SCOPED_GRANT = 'granted_scoped';

    private const PAYMENT_CAPABILITIES = [
        'payment.subject.register',
        'payment.checkout',
        'payment.entitlement.read',
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $pluginsPath = '')
    {
    }

    /** @return array{state:string,version:string,scope_sha256:string,changed:bool} */
    public function grant(string $pluginId, string $expectedVersion, int $adminId): array
    {
        return $this->decide($pluginId, $expectedVersion, $adminId, 'granted');
    }

    /** @return array{state:string,version:string,scope_sha256:string,changed:bool} */
    public function revoke(string $pluginId, string $expectedVersion, int $adminId): array
    {
        return $this->decide($pluginId, $expectedVersion, $adminId, 'revoked');
    }

    public static function scopeDigest(PluginManifest $manifest): string
    {
        $permissions = array_values(array_unique($manifest->permissions));
        $capabilities = array_values(array_intersect($manifest->capabilities, self::PAYMENT_CAPABILITIES));
        sort($permissions, SORT_STRING);
        sort($capabilities, SORT_STRING);
        return hash('sha256', json_encode([
            $manifest->id, $manifest->version, $permissions, $capabilities,
        ], JSON_THROW_ON_ERROR));
    }

    public function assertEffective(PluginManifest $manifest, bool $lock = false): void
    {
        $plugin = $this->pluginRow($manifest->id, $lock);
        if ($plugin === null || (string) $plugin['status'] !== PluginLifecycle::ENABLED
            || (string) $plugin['version'] !== $manifest->version
            || (string) $plugin['permission_grant_status'] !== self::SCOPED_GRANT
            || !in_array('payment.access', $manifest->permissions, true)) {
            throw new PluginException('capability_denied');
        }
        $latest = $this->latestDecision($manifest->id, $manifest->version);
        if ($latest === null || (string) $latest['status'] !== self::SCOPED_GRANT
            || (string) ($latest['scope_sha256'] ?? '') !== self::scopeDigest($manifest)
            || !in_array('payment.access', $this->permissions($latest), true)) {
            throw new PluginException('capability_denied');
        }
    }

    /** @return array{state:string,version:string,scope_sha256:string,changed:bool} */
    public function inspect(string $pluginId): array
    {
        $manifest = $this->installedManifest($pluginId);
        $plugin = $this->pluginRow($pluginId, false);
        if ($plugin === null || (string) $plugin['version'] !== $manifest->version) {
            throw new PluginException('Plugin installation does not match its manifest.');
        }
        $latest = $this->latestDecision($pluginId, $manifest->version);
        $granted = (string) ($plugin['permission_grant_status'] ?? '') === self::SCOPED_GRANT
            && (string) ($latest['status'] ?? '') === self::SCOPED_GRANT
            && (string) ($latest['scope_sha256'] ?? '') === self::scopeDigest($manifest)
            && in_array('payment.access', $this->permissions($latest), true);
        return [
            'state' => $granted ? 'granted' : ((string) ($latest['status'] ?? '') === 'revoked' ? 'revoked' : 'not_granted'),
            'version' => $manifest->version,
            'scope_sha256' => self::scopeDigest($manifest),
            'changed' => false,
        ];
    }

    /** @return array{state:string,version:string,scope_sha256:string,changed:bool} */
    private function decide(string $pluginId, string $expectedVersion, int $adminId, string $target): array
    {
        if ($adminId <= 0 || $expectedVersion === '' || !in_array($target, ['granted', 'revoked'], true)) {
            throw new PluginException('Invalid permission decision.');
        }
        $ownTransaction = !PaymentTransaction::isActive($this->pdo);
        if ($ownTransaction) {
            PaymentTransaction::beginImmediate($this->pdo);
        }
        try {
            $plugin = $this->pluginRow($pluginId, true);
            $manifest = $this->installedManifest($pluginId);
            if ($plugin === null || (string) $plugin['version'] !== $expectedVersion || $manifest->version !== $expectedVersion
                || (string) $plugin['status'] === PluginLifecycle::REMOVED) {
                throw new PluginException('Plugin installation or version changed.');
            }
            if (!in_array('payment.access', $manifest->permissions, true)
                || array_intersect($manifest->capabilities, self::PAYMENT_CAPABILITIES) === []) {
                throw new PluginException('Plugin does not declare payment access and capabilities.');
            }
            if ($target === 'granted' && (string) $plugin['status'] !== PluginLifecycle::ENABLED) {
                throw new PluginException('Enable the installed plugin before granting payment access.');
            }
            $digest = self::scopeDigest($manifest);
            $latest = $this->latestDecision($pluginId, $expectedVersion);
            $effective = (string) ($plugin['permission_grant_status'] ?? '') === self::SCOPED_GRANT
                && (string) ($latest['status'] ?? '') === self::SCOPED_GRANT
                && (string) ($latest['scope_sha256'] ?? '') === $digest
                && in_array('payment.access', $this->permissions($latest), true);
            $changed = $target === 'granted'
                ? !$effective
                : (in_array((string) ($plugin['permission_grant_status'] ?? ''), ['granted', self::SCOPED_GRANT], true)
                    || in_array((string) ($latest['status'] ?? ''), ['granted', self::SCOPED_GRANT], true));
            if ($changed) {
                $now = gmdate('c');
                $this->pdo->prepare('INSERT INTO cms_plugin_permission_grants
                    (plugin_id, plugin_version, permissions_json, scope_sha256, status, granted_by, granted_at, created_at, updated_at)
                    VALUES (:plugin, :version, :permissions, :scope, :status, :admin, :at, :at, :at)')
                    ->execute([
                        ':plugin' => $pluginId, ':version' => $expectedVersion,
                        ':permissions' => '["payment.access"]', ':scope' => $digest,
                        ':status' => $target === 'granted' ? self::SCOPED_GRANT : $target,
                        ':admin' => $adminId, ':at' => $now,
                    ]);
                $this->pdo->prepare('UPDATE cms_plugins SET declared_permissions_json = :declared, permission_grant_status = :status, updated_at = :at WHERE plugin_id = :plugin AND version = :version')
                    ->execute([
                        ':declared' => json_encode($manifest->permissions, JSON_THROW_ON_ERROR),
                        ':status' => $target === 'granted' ? self::SCOPED_GRANT : $target,
                        ':at' => $now, ':plugin' => $pluginId, ':version' => $expectedVersion,
                    ]);
            }
            (new AuditLogger($this->pdo))->record('admin', $adminId,
                'plugin.permission.' . ($target === 'granted' ? 'grant' : 'revoke'),
                ['plugin_id' => $pluginId, 'version' => $expectedVersion, 'permission' => 'payment.access',
                    'scope_sha256' => $digest, 'outcome' => $changed ? 'changed' : 'no_change']);
            if ($ownTransaction) {
                PaymentTransaction::commit($this->pdo);
            }
            return ['state' => $target, 'version' => $expectedVersion, 'scope_sha256' => $digest, 'changed' => $changed];
        } catch (Throwable $exception) {
            if ($ownTransaction && PaymentTransaction::isActive($this->pdo)) {
                PaymentTransaction::rollback($this->pdo);
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    private function pluginRow(string $pluginId, bool $lock): ?array
    {
        $sql = 'SELECT plugin_id, version, status, permission_grant_status FROM cms_plugins WHERE plugin_id = :plugin LIMIT 1';
        if ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':plugin' => $pluginId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function latestDecision(string $pluginId, string $version): ?array
    {
        $stmt = $this->pdo->prepare('SELECT status, permissions_json, scope_sha256 FROM cms_plugin_permission_grants WHERE plugin_id = :plugin AND plugin_version = :version ORDER BY id DESC LIMIT 1');
        $stmt->execute([':plugin' => $pluginId, ':version' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $decision @return list<string> */
    private function permissions(?array $decision): array
    {
        $value = json_decode((string) ($decision['permissions_json'] ?? '[]'), true);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function installedManifest(string $pluginId): PluginManifest
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $pluginId)) {
            throw new PluginException('Invalid plugin id.');
        }
        $root = realpath($this->pluginsPath);
        $file = realpath($this->pluginsPath . '/' . $pluginId . '/plugin.json');
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new PluginException('Installed plugin manifest unavailable.');
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new PluginException('Installed plugin manifest unavailable.');
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $manifest = PluginManifest::fromArray(is_array($data) ? $data : []);
        if ($manifest->id !== $pluginId) {
            throw new PluginException('Installed plugin identity mismatch.');
        }
        return $manifest;
    }
}
