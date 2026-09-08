<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiToolDefinition
{
    /** @param list<string> $requiredCapabilities @param array<string,mixed> $schema */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $riskLevel = AiToolRisk::READ,
        public readonly array $requiredCapabilities = [],
        public readonly string $ownerPlugin = 'core',
        public readonly string $description = '',
        public readonly array $schema = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $id)) {
            throw new AiException('AI tool id is invalid.', 'tool_invalid');
        }
        if (!AiToolRisk::isValid($riskLevel)) {
            throw new AiException('AI tool risk level is invalid.', 'tool_invalid');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'risk_level' => $this->riskLevel,
            'required_capabilities' => $this->requiredCapabilities,
            'owner_plugin' => $this->ownerPlugin,
            'description' => $this->description,
            'schema' => $this->schema,
        ];
    }
}
