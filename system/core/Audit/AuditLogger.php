<?php

declare(strict_types=1);

namespace Cms\Core\Audit;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $actorType, ?int $actorId, string $action, array $context = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_audit_logs (actor_type, actor_id, action, context_json, created_at)
             VALUES (:actor_type, :actor_id, :action, :context_json, :created_at)'
        );
        $stmt->execute([
            ':actor_type' => $actorType,
            ':actor_id' => $actorId,
            ':action' => $action,
            ':context_json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => gmdate('c'),
        ]);
    }
}
