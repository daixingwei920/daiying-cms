<?php

declare(strict_types=1);

namespace Cms\Core\Timeline;

use PDO;

final class SiteTimelineService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function record(
        string $actorType,
        ?int $actorId,
        string $operation,
        string $targetType,
        string $targetId,
        ?string $beforeRef = null,
        ?string $afterRef = null,
        string $recoverability = 'none',
        ?string $snapshotId = null,
        array $metadata = [],
    ): string {
        $eventId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_site_timeline
                (event_id, actor_type, actor_id, operation, target_type, target_id, before_ref, after_ref, recoverability, related_snapshot_id, metadata_json, created_at)
             VALUES
                (:event_id, :actor_type, :actor_id, :operation, :target_type, :target_id, :before_ref, :after_ref, :recoverability, :related_snapshot_id, :metadata_json, :created_at)'
        );
        $stmt->execute([
            ':event_id' => $eventId,
            ':actor_type' => $this->short($actorType, 32),
            ':actor_id' => $actorId,
            ':operation' => $this->short($operation, 96),
            ':target_type' => $this->short($targetType, 96),
            ':target_id' => $this->short($targetId, 191),
            ':before_ref' => $beforeRef,
            ':after_ref' => $afterRef,
            ':recoverability' => $this->short($recoverability, 32),
            ':related_snapshot_id' => $snapshotId,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => gmdate('c'),
        ]);

        return $eventId;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query('SELECT * FROM cms_site_timeline ORDER BY id DESC LIMIT ' . $limit);

        return array_values(array_map([$this, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string,mixed> */
    public function previewRestore(string $eventId): array
    {
        $row = $this->find($eventId);
        if ($row === null) {
            return ['status' => 'blocked', 'reason' => 'timeline-event-not-found'];
        }
        if (($row['recoverability'] ?? '') === 'none') {
            return ['status' => 'blocked', 'reason' => 'event-is-not-recoverable', 'event' => $row];
        }

        return [
            'status' => 'preview',
            'event' => $row,
            'requires_snapshot' => $this->isHighRisk((string) ($row['operation'] ?? '')),
            'impact' => $this->impactFor((string) ($row['target_type'] ?? '')),
        ];
    }

    /** @return array<string,mixed> */
    public function compare(string $eventId): array
    {
        $row = $this->find($eventId);
        if ($row === null) {
            return ['status' => 'blocked', 'reason' => 'timeline-event-not-found'];
        }

        return [
            'status' => 'compare',
            'before_ref' => $row['before_ref'] ?? null,
            'after_ref' => $row['after_ref'] ?? null,
            'metadata' => $row['metadata'] ?? [],
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_site_timeline WHERE event_id = :event_id LIMIT 1');
        $stmt->execute([':event_id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $row['metadata'] = is_array($metadata) ? $metadata : [];

        return $row;
    }

    private function short(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }

        return substr($value, 0, $limit);
    }

    private function isHighRisk(string $operation): bool
    {
        return str_contains($operation, 'delete')
            || str_contains($operation, 'restore')
            || str_contains($operation, 'update')
            || str_contains($operation, 'migration');
    }

    /** @return list<string> */
    private function impactFor(string $targetType): array
    {
        return match ($targetType) {
            'site_vault', 'database' => ['database', 'config', 'content', 'media', 'plugins', 'theme'],
            'plugin' => ['plugin-runtime', 'admin', 'routes', 'blocks'],
            'theme' => ['frontend-rendering'],
            'content', 'block' => ['content-rendering', 'search', 'routes'],
            default => [$targetType],
        };
    }
}
