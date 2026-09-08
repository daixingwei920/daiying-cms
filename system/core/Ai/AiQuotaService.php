<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use PDO;

final class AiQuotaService
{
    public function __construct(private readonly PDO $pdo, private readonly AiUsageLedger $usage)
    {
    }

    public function assertAllowed(AiRequest $request, string $provider, string $model): void
    {
        foreach ($this->matchingPolicies($request, $provider, $model) as $policy) {
            $dailyLimit = (int) ($policy['daily_request_limit'] ?? 0);
            if ($dailyLimit > 0) {
                $daily = $this->usage->dailyUsage($request->pluginId, gmdate('Y-m-d'));
                if ($daily['requests'] >= $dailyLimit) {
                    throw new AiException('AI daily request quota exceeded.', 'quota_exceeded');
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function matchingPolicies(AiRequest $request, string $provider, string $model): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cms_ai_quota_policies
             WHERE status = 'enabled'
               AND (scope_type = 'global' OR (scope_type = 'plugin' AND scope_id = :plugin_id))
               AND (provider = '' OR provider = :provider)
               AND (model = '' OR model = :model)
               AND (operation = '' OR operation = :operation)
             ORDER BY scope_type DESC, id ASC"
        );
        $stmt->execute([
            ':plugin_id' => $request->pluginId,
            ':provider' => $provider,
            ':model' => $model,
            ':operation' => $request->operation,
        ]);

        return $stmt->fetchAll() ?: [];
    }
}
