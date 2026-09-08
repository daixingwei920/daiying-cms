<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiAgentDefinition
{
    /** @param list<string> $requiredCapabilities @param list<string> $allowedTools */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description = '',
        public readonly array $requiredCapabilities = [],
        public readonly array $allowedTools = [],
        public readonly string $ownerPlugin = 'core',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $id)) {
            throw new AiException('AI agent id is invalid.', 'agent_invalid');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'required_capabilities' => $this->requiredCapabilities,
            'allowed_tools' => $this->allowedTools,
            'owner_plugin' => $this->ownerPlugin,
        ];
    }
}
