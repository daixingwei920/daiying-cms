<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use PDO;

final class PluginManager
{
    public function __construct(
        private readonly string $pluginsPath,
        private readonly PDO $pdo,
        private readonly FileLogger $logger,
        private readonly EventDispatcher $events,
        private readonly BlockRegistry $blocks,
        private readonly ?PluginRuntimeRegistry $runtime = null,
        private readonly ?OfficialPluginRegistry $officialRegistry = null,
        private readonly ?PluginSecretStore $secrets = null,
    ) {
    }

    /** @return array<string, PluginManifest> */
    public function discover(): array
    {
        $plugins = [];
        foreach (glob($this->pluginsPath . '/*/plugin.json') ?: [] as $manifestFile) {
            try {
                $decoded = json_decode((string) file_get_contents($manifestFile), true);
                if (!is_array($decoded)) {
                    throw new PluginException('Invalid plugin JSON.');
                }

                $manifest = PluginManifest::fromArray($decoded);
                $trustedOfficial = $this->isTrustedOfficial($manifest, dirname($manifestFile));
                $this->assertManifestTrust($manifest, $trustedOfficial);
                Capability::assertPluginAllowed($manifest->id, $manifest->capabilities, $trustedOfficial ? $this->trustedCapabilityNamespaces($manifest, dirname($manifestFile)) : $manifest->capabilityNamespaces);
                if (basename(dirname($manifestFile)) !== $manifest->id) {
                    throw new PluginException('Plugin manifest id does not match directory.');
                }
                $plugins[$manifest->id] = $manifest;
            } catch (\Throwable $exception) {
                $this->logger->error('Plugin discovery skipped invalid plugin', [
                    'source' => 'Core',
                    'file' => $manifestFile,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        ksort($plugins);

        return $plugins;
    }

    public function syncDiscovered(): int
    {
        $count = 0;
        foreach ($this->discover() as $manifest) {
            $stmt = $this->pdo->prepare('SELECT plugin_id FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
            $stmt->execute([':plugin_id' => $manifest->id]);
            if ($stmt->fetch()) {
                continue;
            }

            $trustedOfficial = $this->isTrustedOfficial($manifest, $this->pluginsPath . '/' . $manifest->id);
            $prefixes = (new PluginTableOwnership($this->pdo, $this->officialRegistry))->prefixesFor([
                'plugin_id' => $manifest->id,
                'table_prefixes' => $trustedOfficial ? $this->officialRegistry?->tablePrefixes($manifest->id) : $manifest->tablePrefixes,
            ], $trustedOfficial);
            $columns = $this->columns('cms_plugins');
            $hasPrefixes = in_array('table_prefixes_json', $columns, true);
            $hasDependencies = in_array('dependencies_json', $columns, true);
            $hasDeclaredPermissions = in_array('declared_permissions_json', $columns, true);
            $hasPermissionGrant = in_array('permission_grant_status', $columns, true);
            $manifestData = $this->manifestData($manifest->id);
            $dependencies = is_array($manifestData)
                ? ($manifestData['required_plugins'] ?? $manifestData['dependencies'] ?? [])
                : [];
            $permissionEvaluation = in_array('cms_plugin_permission_grants', $this->tableNames(), true)
                ? (new PluginPermissionManifest($this->pdo))->evaluate($manifest)
                : ['status' => $manifest->permissions === [] ? 'LEGACY UNDECLARED PERMISSIONS' : 'declared', 'permissions' => $manifest->permissions];
            $sql = 'INSERT INTO cms_plugins
                    (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at, source, review_status'
                    . ($hasDependencies ? ', dependencies_json' : '')
                    . ($hasDeclaredPermissions ? ', declared_permissions_json' : '')
                    . ($hasPermissionGrant ? ', permission_grant_status' : '')
                    . ($hasPrefixes ? ', table_prefixes_json' : '') . ')
                 VALUES
                    (:plugin_id, :name, :version, :author, :status, :trust_level, :capabilities_json, :installed_at, :updated_at, :source, :review_status'
                    . ($hasDependencies ? ', :dependencies_json' : '')
                    . ($hasDeclaredPermissions ? ', :declared_permissions_json' : '')
                    . ($hasPermissionGrant ? ', :permission_grant_status' : '')
                    . ($hasPrefixes ? ', :table_prefixes_json' : '') . ')';
            $stmt = $this->pdo->prepare($sql);
            $now = gmdate('c');
            $params = [
                ':plugin_id' => $manifest->id,
                ':name' => $manifest->name,
                ':version' => $manifest->version,
                ':author' => $manifest->author,
                ':status' => PluginLifecycle::INSTALLED,
                ':trust_level' => $manifest->trustLevel,
                ':capabilities_json' => json_encode($manifest->capabilities, JSON_UNESCAPED_SLASHES),
                ':installed_at' => $now,
                ':updated_at' => $now,
                ':source' => $trustedOfficial ? $this->trustedOfficialSource($manifest, $this->pluginsPath . '/' . $manifest->id) : 'bundled',
                ':review_status' => $trustedOfficial ? $this->trustedOfficialReviewStatus($manifest, $this->pluginsPath . '/' . $manifest->id) : 'unknown',
            ];
            if ($hasPrefixes) {
                $params[':table_prefixes_json'] = json_encode($prefixes, JSON_UNESCAPED_SLASHES);
            }
            if ($hasDependencies) {
                $params[':dependencies_json'] = json_encode(is_array($dependencies) ? $dependencies : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($hasDeclaredPermissions) {
                $params[':declared_permissions_json'] = json_encode($permissionEvaluation['permissions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($hasPermissionGrant) {
                $params[':permission_grant_status'] = (string) ($permissionEvaluation['status'] ?? 'legacy');
            }
            $stmt->execute($params);
            $count++;
        }

        return $count;
    }

    public function setStatus(string $pluginId, string $status): void
    {
        if (!in_array($status, PluginLifecycle::ALL, true)) {
            throw new PluginException('Invalid plugin lifecycle status.');
        }

        $stmt = $this->pdo->prepare('UPDATE cms_plugins SET status = :status, updated_at = :updated_at WHERE plugin_id = :plugin_id');
        $stmt->execute([
            ':plugin_id' => $pluginId,
            ':status' => $status,
            ':updated_at' => gmdate('c'),
        ]);
    }

    public function bootEnabled(): int
    {
        $count = 0;
        $manifests = $this->discover();
        $stmt = $this->pdo->query('SELECT plugin_id, status FROM cms_plugins');
        foreach ($stmt->fetchAll() as $row) {
            $pluginId = (string) $row['plugin_id'];
            if ((string) $row['status'] !== PluginLifecycle::ENABLED || !isset($manifests[$pluginId])) {
                continue;
            }
            if (!$this->runtimeDependenciesSatisfied($manifests[$pluginId])) {
                $this->logger->error('Plugin boot skipped because dependencies are not satisfied', [
                    'source' => 'Plugin',
                    'plugin_id' => $pluginId,
                ]);
                $this->recordLastError($pluginId, 'Required plugin is missing, disabled, or outside the supported version range.');
                continue;
            }

            try {
                $this->boot($manifests[$pluginId]);
                $this->recordLastError($pluginId, null);
                if (in_array('runtime_status', $this->columns('cms_plugins'), true) && in_array('cms_plugin_runtime_failures', $this->tableNames(), true)) {
                    (new PluginCircuitBreaker($this->pdo, $this->logger))->recordSuccess($pluginId);
                }
                $count++;
            } catch (\Throwable $exception) {
                $this->logger->error('Plugin boot failed; quarantining plugin', [
                    'source' => 'Plugin',
                    'plugin_id' => $pluginId,
                    'error' => $exception->getMessage(),
                ]);
                if (in_array('runtime_status', $this->columns('cms_plugins'), true) && in_array('cms_plugin_runtime_failures', $this->tableNames(), true)) {
                    (new PluginCircuitBreaker($this->pdo, $this->logger))->recordFailure($pluginId, $manifests[$pluginId]->version, 'bootstrap', 'plugin.php', $exception);
                } else {
                    $this->setStatus($pluginId, PluginLifecycle::QUARANTINED);
                }
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        try {
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $rows = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll();
                return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
            }
            $rows = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
            return array_values(array_map(static fn (array $row): string => (string) $row[0], $rows));
        } catch (\Throwable) {
            return [];
        }
    }

    public function blockRegistry(): BlockRegistry
    {
        return $this->blocks;
    }

    private function boot(PluginManifest $manifest): void
    {
        $entry = $this->pluginsPath . '/' . $manifest->id . '/' . $manifest->entry;
        if (!is_file($entry)) {
            throw new PluginException('Plugin entry not found.');
        }

        $context = new PluginContext(
            $manifest,
            $this->events,
            $this->blocks,
            new PluginDataStore($this->pdo, $manifest->id),
            $this->trustedDatabaseAccess($manifest) ? $this->pdo : null,
            $this->runtime,
            $this->secrets,
            $this->trustedDatabaseAccess($manifest),
            dirname($entry),
        );

        $register = require $entry;
        if (!is_callable($register)) {
            throw new PluginException('Plugin entry must return a callable.');
        }

        $register($context);
    }

    private function isTrustedOfficial(PluginManifest $manifest, string $pluginRoot): bool
    {
        if ($this->officialRegistry !== null && $this->officialRegistry->isTrustedBundled($manifest->id, $pluginRoot)) {
            return true;
        }

        return $this->isTrustedOfficialMarketSource($manifest, $pluginRoot);
    }

    private function assertManifestTrust(PluginManifest $manifest, bool $trustedOfficial): void
    {
        if ($manifest->type === 'system-plugin' || $manifest->bundled || $manifest->trustLevel === 'trusted_php' || str_starts_with($manifest->id, 'official.')) {
            if (!$trustedOfficial) {
                throw new PluginException('Plugin claims a trusted or official identity without a trusted bundled source.');
            }
        }
    }

    private function trustedDatabaseAccess(PluginManifest $manifest): bool
    {
        if ($manifest->trustLevel !== 'trusted_php') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT source, review_status FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
            $stmt->execute([':plugin_id' => $manifest->id]);
            $row = $stmt->fetch();
            if (!is_array($row) || !$this->isTrustedOfficial($manifest, $this->pluginsPath . '/' . $manifest->id)) {
                return false;
            }

            $source = (string) ($row['source'] ?? '');
            $review = (string) ($row['review_status'] ?? '');
            if ($source === 'bundled_official' && $review === 'official_trusted') {
                return true;
            }

            return in_array($source, ['official_market', 'official'], true)
                && in_array($review, ['published', 'approved', 'official_trusted'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function officialNamespaces(string $pluginId): array
    {
        return $this->officialRegistry?->capabilityNamespaces($pluginId) ?? [];
    }

    /** @return list<string> */
    private function trustedCapabilityNamespaces(PluginManifest $manifest, string $pluginRoot): array
    {
        if ($this->officialRegistry !== null && $this->officialRegistry->isTrustedBundled($manifest->id, $pluginRoot)) {
            return $this->officialNamespaces($manifest->id);
        }

        return $manifest->capabilityNamespaces;
    }

    private function trustedOfficialSource(PluginManifest $manifest, string $pluginRoot): string
    {
        if ($this->officialRegistry !== null && $this->officialRegistry->isTrustedBundled($manifest->id, $pluginRoot)) {
            return 'bundled_official';
        }

        $row = $this->latestOfficialMarketSource($manifest);
        return is_array($row) ? (string) ($row['source'] ?? 'official_market') : 'official_market';
    }

    private function trustedOfficialReviewStatus(PluginManifest $manifest, string $pluginRoot): string
    {
        if ($this->officialRegistry !== null && $this->officialRegistry->isTrustedBundled($manifest->id, $pluginRoot)) {
            return 'official_trusted';
        }

        $row = $this->latestOfficialMarketSource($manifest);
        $metadata = $this->jsonArray((string) ($row['metadata_json'] ?? '{}'));
        $review = (string) ($metadata['review_status'] ?? '');

        return $review !== '' ? $review : 'published';
    }

    private function isTrustedOfficialMarketSource(PluginManifest $manifest, string $pluginRoot): bool
    {
        if (!str_starts_with($manifest->id, 'official.') || basename($pluginRoot) !== $manifest->id) {
            return false;
        }

        return $this->latestOfficialMarketSource($manifest) !== null;
    }

    /** @return array<string,mixed>|null */
    private function latestOfficialMarketSource(PluginManifest $manifest): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM cms_extension_sources
                 WHERE extension_id = :extension_id
                   AND extension_type IN ('plugin', 'payment_provider')
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->execute([':extension_id' => $manifest->id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }

            $source = (string) ($row['source'] ?? '');
            $metadata = $this->jsonArray((string) ($row['metadata_json'] ?? '{}'));
            $review = (string) ($metadata['review_status'] ?? '');
            $status = (string) ($metadata['status'] ?? 'Installed');
            if ($status === 'Uninstalled' || $source === 'uninstalled') {
                return null;
            }

            if (in_array($source, ['official_market', 'official'], true) && in_array($review, ['published', 'approved', 'official_trusted'], true)) {
                return $row;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function runtimeDependenciesSatisfied(PluginManifest $manifest): bool
    {
        $manifestData = $this->manifestData($manifest->id);
        $deps = is_array($manifestData) ? ($manifestData['required_plugins'] ?? $manifestData['dependencies'] ?? []) : $manifest->dependencies;
        if (!is_array($deps)) {
            return true;
        }
        foreach ($deps as $dep) {
            $depId = is_array($dep) ? (string) ($dep['plugin_id'] ?? '') : (string) $dep;
            if ($depId === '') {
                return false;
            }
            try {
                $stmt = $this->pdo->prepare('SELECT version, status FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
                $stmt->execute([':plugin_id' => $depId]);
                $row = $stmt->fetch();
            } catch (\Throwable) {
                return false;
            }
            if (!is_array($row) || (string) ($row['status'] ?? '') !== PluginLifecycle::ENABLED) {
                return false;
            }
            $min = is_array($dep) ? (string) ($dep['min_version'] ?? $dep['min'] ?? '') : '';
            $max = is_array($dep) ? (string) ($dep['max_version'] ?? $dep['max'] ?? '') : '';
            $version = (string) ($row['version'] ?? '');
            if ($min !== '' && version_compare($version, $min, '<')) {
                return false;
            }
            if ($max !== '' && version_compare($version, $max, '>=')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed>|null */
    private function manifestData(string $pluginId): ?array
    {
        $path = $this->pluginsPath . '/' . $pluginId . '/plugin.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,mixed> */
    private function jsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function recordLastError(string $pluginId, ?string $message): void
    {
        try {
            if (!in_array('last_error', $this->columns('cms_plugins'), true)) {
                return;
            }
            $stmt = $this->pdo->prepare('UPDATE cms_plugins SET last_error = :last_error, updated_at = :updated_at WHERE plugin_id = :plugin_id');
            $stmt->execute([
                ':plugin_id' => $pluginId,
                ':last_error' => $message,
                ':updated_at' => gmdate('c'),
            ]);
        } catch (\Throwable) {
            // Last-error recording is diagnostic only; plugin isolation must not depend on it.
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return array_map(static fn (array $row): string => (string) $row['name'], $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
            }
            return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
        } catch (\Throwable) {
            return [];
        }
    }
}
