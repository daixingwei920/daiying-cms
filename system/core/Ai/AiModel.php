<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiModel
{
    /**
     * @param list<string> $capabilities
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $capabilities = ['text_generation'],
        public readonly ?int $contextWindow = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly array $metadata = [],
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/', $id)) {
            throw new AiException('AI model id is invalid.', 'model_invalid');
        }
    }

    /** @param list<string> $required */
    public function supports(array $required): bool
    {
        foreach ($required as $capability) {
            if (!in_array($capability, $this->capabilities, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'capabilities' => $this->capabilities,
            'context_window' => $this->contextWindow,
            'max_output_tokens' => $this->maxOutputTokens,
            'metadata' => $this->metadata,
        ];
    }
}
