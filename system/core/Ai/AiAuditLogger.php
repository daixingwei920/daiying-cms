<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Security\SecretRedactor;
use PDO;

final class AiAuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $event */
    public function record(array $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_ai_audit_events
             (request_id, agent_id, provider, model, tool_id, plugin_id, action, target_type, target_id, result, user_id, risk_level, before_json, after_json, created_at)
             VALUES (:request_id, :agent_id, :provider, :model, :tool_id, :plugin_id, :action, :target_type, :target_id, :result, :user_id, :risk_level, :before_json, :after_json, :created_at)'
        );
        $stmt->execute([
            ':request_id' => (string) ($event['request_id'] ?? ''),
            ':agent_id' => (string) ($event['agent_id'] ?? ''),
            ':provider' => (string) ($event['provider'] ?? ''),
            ':model' => (string) ($event['model'] ?? ''),
            ':tool_id' => (string) ($event['tool_id'] ?? ''),
            ':plugin_id' => (string) ($event['plugin_id'] ?? 'core'),
            ':action' => (string) ($event['action'] ?? 'ai.event'),
            ':target_type' => (string) ($event['target_type'] ?? ''),
            ':target_id' => (string) ($event['target_id'] ?? ''),
            ':result' => (string) ($event['result'] ?? ''),
            ':user_id' => isset($event['user_id']) ? (int) $event['user_id'] : null,
            ':risk_level' => (string) ($event['risk_level'] ?? ''),
            ':before_json' => $this->json($event['before'] ?? null),
            ':after_json' => $this->json($event['after'] ?? null),
            ':created_at' => gmdate('c'),
        ]);
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(SecretRedactor::redact($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
