<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Security\SecretRedactor;
use PDO;

final class AiUsageLedger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordSuccess(AiRequest $request, AiResponse $response): void
    {
        $this->insert($request, $response->provider, $response->model, $response->usage, 'success', '');
    }

    public function recordFailure(AiRequest $request, string $provider, string $model, string $reason): void
    {
        $this->insert($request, $provider, $model, ['input_tokens' => 0, 'output_tokens' => 0], 'failed', $reason);
    }

    /** @return array{requests:int,input_tokens:int,output_tokens:int} */
    public function dailyUsage(string $pluginId, string $date): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS requests, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens
             FROM cms_ai_usage_ledger WHERE plugin_id = :plugin_id AND substr(created_at, 1, 10) = :date'
        );
        $stmt->execute([':plugin_id' => $pluginId, ':date' => $date]);
        $row = $stmt->fetch() ?: [];

        return [
            'requests' => (int) ($row['requests'] ?? 0),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
        ];
    }

    /** @param array<string,int> $usage */
    private function insert(AiRequest $request, string $provider, string $model, array $usage, string $status, string $errorReason): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_ai_usage_ledger
             (request_id, provider, model, plugin_id, operation, input_tokens, output_tokens, request_count, estimated_cost, status, error_reason, created_at)
             VALUES (:request_id, :provider, :model, :plugin_id, :operation, :input_tokens, :output_tokens, 1, 0, :status, :error_reason, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $request->requestId,
            ':provider' => $provider,
            ':model' => $model,
            ':plugin_id' => $request->pluginId,
            ':operation' => $request->operation,
            ':input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
            ':output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
            ':status' => $status,
            ':error_reason' => substr((string) SecretRedactor::redact($errorReason), 0, 191),
            ':created_at' => gmdate('c'),
        ]);
    }
}
