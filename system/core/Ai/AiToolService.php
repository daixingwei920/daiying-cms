<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Security\SecretRedactor;

final class AiToolService
{
    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $grantedCapabilities
     * @return array<string,mixed>
     */
    public function execute(string $toolId, array $payload, array $grantedCapabilities = [], ?int $actorId = null, bool $confirmed = false): array
    {
        $definition = AiToolRegistry::get($toolId);
        if ($definition === null) {
            throw new AiException('AI tool is not registered.', 'tool_not_found');
        }
        foreach ($definition->requiredCapabilities as $capability) {
            if (!in_array($capability, $grantedCapabilities, true)) {
                throw new AiException('AI tool capability is not granted.', 'permission_denied');
            }
        }
        if (in_array($definition->riskLevel, [AiToolRisk::WRITE, AiToolRisk::DESTRUCTIVE, AiToolRisk::SENSITIVE], true) && !$confirmed) {
            throw new AiException('AI tool requires explicit confirmation.', 'confirmation_required');
        }

        try {
            $result = AiToolRegistry::execute($toolId, $payload);
            $this->audit?->record('user', $actorId, 'ai.tool.executed', [
                'tool_id' => $toolId,
                'risk_level' => $definition->riskLevel,
                'owner_plugin' => $definition->ownerPlugin,
                'result' => 'success',
            ]);

            return $result;
        } catch (AiException $exception) {
            $this->audit?->record('user', $actorId, 'ai.tool.failed', [
                'tool_id' => $toolId,
                'risk_level' => $definition->riskLevel,
                'owner_plugin' => $definition->ownerPlugin,
                'reason' => $exception->reason(),
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->audit?->record('user', $actorId, 'ai.tool.failed', [
                'tool_id' => $toolId,
                'risk_level' => $definition->riskLevel,
                'owner_plugin' => $definition->ownerPlugin,
                'reason' => 'tool_error',
            ]);
            throw new AiException('AI tool failed safely: ' . (string) SecretRedactor::redact($exception->getMessage()), 'tool_error');
        }
    }
}
