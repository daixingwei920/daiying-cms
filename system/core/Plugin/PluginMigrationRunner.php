<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;
use Throwable;

final class PluginMigrationRunner
{
    private const MIGRATION_STATUSES = ['pending', 'running', 'applied', 'rollback_running', 'rolled_back', 'failed_recoverable', 'rollback_failed'];

    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $pdo,
    ) {
    }

    /** @param array<string,mixed> $manifest */
    public function validate(string $root, array $manifest, bool $trustedOfficial = false, bool $requireReversible = false): void
    {
        $seen = [];
        foreach (($manifest['migrations'] ?? []) as $migration) {
            $spec = $this->loadMigrationSpec($root, (string) $migration, $manifest);
            if (isset($seen[$spec['id']])) {
                throw new PluginException('Plugin migration IDs must be unique.');
            }
            $seen[$spec['id']] = true;
            $this->assertPluginOwnedObjects($manifest, $spec['affected_objects'], $trustedOfficial);
            if ($requireReversible && !$spec['reversible']) {
                throw new PluginException('Irreversible plugin migrations require a database restore point; none is available for local ZIP install.');
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    public function run(string $target, array $manifest, bool $trustedOfficial = false): void
    {
        $appliedThisBatch = [];
        try {
            foreach (($manifest['migrations'] ?? []) as $migration) {
                $spec = $this->loadMigrationSpec($target, (string) $migration, $manifest);
                $this->assertPluginOwnedObjects($manifest, $spec['affected_objects'], $trustedOfficial);
                $row = $this->migrationRow((string) $manifest['plugin_id'], $spec['id']);
                if ($row !== null && (string) $row['status'] === 'applied') {
                    if ((string) $row['checksum'] !== $spec['checksum']) {
                        throw new PluginException('Plugin migration checksum changed: ' . $spec['id']);
                    }
                    continue;
                }
                $recordId = $this->startMigrationRecord($manifest, $spec);
                $appliedThisBatch[] = ['record_id' => $recordId, 'spec' => $spec];
                ($spec['up'])($this->pdo);
                $this->finishMigrationRecord($recordId, 'applied');
            }
        } catch (Throwable $exception) {
            $rollbackErrors = [];
            foreach (array_reverse($appliedThisBatch) as $item) {
                try {
                    $this->rollbackMigration($item['spec'], (int) $item['record_id'], $exception);
                } catch (Throwable $rollbackException) {
                    $rollbackErrors[] = $this->sanitizeError($rollbackException);
                }
            }
            if ($rollbackErrors !== []) {
                $this->setPluginRecoverable((string) $manifest['plugin_id'], implode('; ', $rollbackErrors));
            }
            throw $exception;
        }
    }

    public function hasRecoverableFailure(string $pluginId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = :plugin_id AND status IN ('rollback_failed','failed_recoverable','rollback_running','running')");
        $stmt->execute([':plugin_id' => $pluginId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array{id:string,path:string,checksum:string,up:callable,down:?callable,reversible:bool,affected_objects:list<string>} */
    private function loadMigrationSpec(string $target, string $migration, array $manifest): array
    {
        $file = $target . '/' . str_replace('\\', '/', $migration);
        $targetReal = realpath($target) ?: $target;
        $fileReal = realpath($file) ?: '';
        if (!is_file($file) || !str_starts_with($fileReal, $targetReal)) {
            throw new PluginException('Plugin migration file is invalid.');
        }
        $definition = require $file;
        $id = pathinfo($file, PATHINFO_FILENAME);
        $affected = [];
        $up = null;
        $down = null;
        if (is_array($definition)) {
            $id = (string) ($definition['id'] ?? $id);
            $up = $definition['up'] ?? null;
            $down = $definition['down'] ?? null;
            $affected = is_array($definition['affected_objects'] ?? null) ? array_values($definition['affected_objects']) : [];
        } elseif (is_object($definition)) {
            $id = method_exists($definition, 'id') ? (string) $definition->id() : $id;
            $up = method_exists($definition, 'up') ? [$definition, 'up'] : (method_exists($definition, 'apply') ? [$definition, 'apply'] : null);
            $down = method_exists($definition, 'down') ? [$definition, 'down'] : (method_exists($definition, 'rollback') ? [$definition, 'rollback'] : null);
            if (method_exists($definition, 'affectedObjects')) {
                $objects = $definition->affectedObjects();
                $affected = is_array($objects) ? array_values($objects) : [];
            }
        } elseif (is_callable($definition)) {
            $up = $definition;
        }
        if ($id === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            throw new PluginException('Plugin migration ID is invalid.');
        }
        if (!is_callable($up)) {
            throw new PluginException('Plugin migration must define an up/apply callable.');
        }
        $affected = array_map(static fn (mixed $object): string => (string) $object, $affected);

        return [
            'id' => $id,
            'path' => $file,
            'checksum' => hash_file('sha256', $file) ?: '',
            'up' => $up,
            'down' => is_callable($down) ? $down : null,
            'reversible' => is_callable($down),
            'affected_objects' => $affected,
        ];
    }

    /** @param list<string> $objects */
    private function assertPluginOwnedObjects(array $manifest, array $objects, bool $trustedOfficial = false): void
    {
        $ownership = new PluginTableOwnership($this->pdo, new OfficialPluginRegistry($this->rootPath));
        $ownership->assertOwnsObjects($objects, $ownership->prefixesFor($manifest, $trustedOfficial));
    }

    /** @param array<string,mixed> $spec */
    private function startMigrationRecord(array $manifest, array $spec): int
    {
        $existing = $this->migrationRow((string) $manifest['plugin_id'], (string) $spec['id']);
        $now = gmdate('c');
        if ($existing !== null) {
            if (!in_array((string) $existing['status'], ['pending', 'failed_recoverable', 'rollback_failed', 'rolled_back'], true)) {
                throw new PluginException('Plugin migration is already active or applied: ' . $spec['id']);
            }
            if ((string) $existing['checksum'] !== (string) $spec['checksum'] && (string) $existing['status'] !== 'rolled_back') {
                throw new PluginException('Plugin migration checksum changed: ' . $spec['id']);
            }
            $this->pdo->prepare("UPDATE cms_plugin_migrations SET plugin_version = :plugin_version, checksum = :checksum, status = 'running', affected_objects_json = :affected_objects_json, started_at = :started_at, completed_at = NULL, rollback_at = NULL, error_code = NULL, error_summary = NULL, updated_at = :updated_at WHERE id = :id")
                ->execute([
                    ':id' => (int) $existing['id'],
                    ':plugin_version' => (string) $manifest['version'],
                    ':checksum' => (string) $spec['checksum'],
                    ':affected_objects_json' => json_encode($spec['affected_objects'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':started_at' => $now,
                    ':updated_at' => $now,
                ]);

            return (int) $existing['id'];
        }
        $stmt = $this->pdo->prepare("INSERT INTO cms_plugin_migrations (plugin_id, plugin_version, migration_id, checksum, status, affected_objects_json, started_at, created_at, updated_at) VALUES (:plugin_id, :plugin_version, :migration_id, :checksum, 'running', :affected_objects_json, :started_at, :created_at, :updated_at)");
        $stmt->execute([
            ':plugin_id' => (string) $manifest['plugin_id'],
            ':plugin_version' => (string) $manifest['version'],
            ':migration_id' => (string) $spec['id'],
            ':checksum' => (string) $spec['checksum'],
            ':affected_objects_json' => json_encode($spec['affected_objects'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':started_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $row = $this->migrationRow((string) $manifest['plugin_id'], (string) $spec['id']);
        if ($row === null) {
            throw new PluginException('Unable to record plugin migration state.');
        }

        return (int) $row['id'];
    }

    private function finishMigrationRecord(int $recordId, string $status): void
    {
        if (!in_array($status, self::MIGRATION_STATUSES, true)) {
            throw new PluginException('Invalid plugin migration status.');
        }
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE cms_plugin_migrations SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $recordId, ':status' => $status, ':completed_at' => $now, ':updated_at' => $now]);
    }

    /** @param array<string,mixed> $spec */
    private function rollbackMigration(array $spec, int $recordId, Throwable $cause): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("UPDATE cms_plugin_migrations SET status = 'rollback_running', error_code = :error_code, error_summary = :error_summary, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $recordId, ':error_code' => substr(get_class($cause), 0, 64), ':error_summary' => $this->sanitizeError($cause), ':updated_at' => $now]);
        try {
            if (!is_callable($spec['down'] ?? null)) {
                throw new PluginException('Plugin migration has no rollback callable: ' . $spec['id']);
            }
            ($spec['down'])($this->pdo);
            $this->pdo->prepare("UPDATE cms_plugin_migrations SET status = 'rolled_back', rollback_at = :rollback_at, updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $recordId, ':rollback_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
        } catch (Throwable $exception) {
            $this->pdo->prepare("UPDATE cms_plugin_migrations SET status = 'rollback_failed', rollback_at = :rollback_at, error_code = :error_code, error_summary = :error_summary, updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $recordId, ':rollback_at' => gmdate('c'), ':error_code' => substr(get_class($exception), 0, 64), ':error_summary' => $this->sanitizeError($exception), ':updated_at' => gmdate('c')]);
            throw $exception;
        }
    }

    private function migrationRow(string $pluginId, string $migrationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_plugin_migrations WHERE plugin_id = :plugin_id AND migration_id = :migration_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId, ':migration_id' => $migrationId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function setPluginRecoverable(string $pluginId, string $error): void
    {
        $this->pdo->prepare('UPDATE cms_plugins SET status = :status, last_error = :last_error, updated_at = :updated_at WHERE plugin_id = :plugin_id')
            ->execute([':plugin_id' => $pluginId, ':status' => PluginLifecycle::INSTALL_FAILED_RECOVERABLE, ':last_error' => $error, ':updated_at' => gmdate('c')]);
    }

    private function sanitizeError(Throwable $exception): string
    {
        $message = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $exception->getMessage());
        $message = preg_replace('/(api[_-]?key|token|secret|password)[=:]\S+/i', '$1=[redacted]', (string) $message);

        return substr((string) $message, 0, 1000);
    }
}
