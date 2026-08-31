<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Logging\FileLogger;
use PDO;
use Throwable;

final class PluginCircuitBreaker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileLogger $logger,
        private readonly int $degradeThreshold = 2,
        private readonly int $disableThreshold = 3,
    ) {
    }

    public function recordFailure(string $pluginId, string $version, string $kind, string $area, Throwable $exception): string
    {
        $summary = substr($exception->getMessage(), 0, 500);
        $count = $this->currentCount($pluginId) + 1;
        $status = $count >= $this->disableThreshold ? PluginLifecycle::AUTO_DISABLED : ($count >= $this->degradeThreshold ? PluginLifecycle::DEGRADED : 'OK');
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO cms_plugin_runtime_failures (plugin_id, plugin_version, failure_kind, affected_area, error_summary, failure_count, isolation_status, created_at, updated_at)
             VALUES (:plugin_id, :plugin_version, :failure_kind, :affected_area, :error_summary, :failure_count, :isolation_status, :created_at, :updated_at)'
        )->execute([
            ':plugin_id' => $pluginId,
            ':plugin_version' => $version,
            ':failure_kind' => $kind,
            ':affected_area' => $area,
            ':error_summary' => $summary,
            ':failure_count' => $count,
            ':isolation_status' => $status,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $this->pdo->prepare(
            'UPDATE cms_plugins
             SET runtime_status = :runtime_status, runtime_failure_count = :runtime_failure_count, runtime_last_failure_at = :runtime_last_failure_at, runtime_error_summary = :runtime_error_summary, updated_at = :updated_at
             WHERE plugin_id = :plugin_id'
        )->execute([
            ':runtime_status' => $status,
            ':runtime_failure_count' => $count,
            ':runtime_last_failure_at' => $now,
            ':runtime_error_summary' => $summary,
            ':updated_at' => $now,
            ':plugin_id' => $pluginId,
        ]);
        if ($status === PluginLifecycle::AUTO_DISABLED) {
            $this->pdo->prepare('UPDATE cms_plugins SET status = :status, updated_at = :updated_at WHERE plugin_id = :plugin_id')
                ->execute([':status' => PluginLifecycle::AUTO_DISABLED, ':updated_at' => $now, ':plugin_id' => $pluginId]);
        }
        $this->logger->error('Plugin runtime circuit breaker recorded failure', [
            'source' => 'Plugin',
            'plugin_id' => $pluginId,
            'failure_kind' => $kind,
            'affected_area' => $area,
            'failure_count' => $count,
            'isolation_status' => $status,
        ]);

        return $status;
    }

    public function recordSuccess(string $pluginId): void
    {
        $this->pdo->prepare(
            "UPDATE cms_plugins SET runtime_status = 'OK', runtime_failure_count = 0, runtime_error_summary = NULL WHERE plugin_id = :plugin_id AND runtime_status <> :auto_disabled"
        )->execute([':plugin_id' => $pluginId, ':auto_disabled' => PluginLifecycle::AUTO_DISABLED]);
    }

    /** @return list<array<string,mixed>> */
    public function failures(string $pluginId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM cms_plugin_runtime_failures WHERE plugin_id = :plugin_id ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([':plugin_id' => $pluginId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function retry(string $pluginId): void
    {
        $this->pdo->prepare("UPDATE cms_plugins SET status = :status, runtime_status = 'OK', runtime_failure_count = 0, runtime_error_summary = NULL, updated_at = :updated_at WHERE plugin_id = :plugin_id")
            ->execute([':status' => PluginLifecycle::ENABLED, ':updated_at' => gmdate('c'), ':plugin_id' => $pluginId]);
    }

    private function currentCount(string $pluginId): int
    {
        $stmt = $this->pdo->prepare('SELECT runtime_failure_count FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId]);

        return max(0, (int) ($stmt->fetchColumn() ?: 0));
    }
}
