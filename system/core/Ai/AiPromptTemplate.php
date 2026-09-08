<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiPromptTemplate
{
    /** @param list<string> $variables */
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $template,
        public readonly array $variables = [],
        public readonly string $ownerPlugin = 'core',
        public readonly string $language = 'default',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.\/-]+$/', $id)) {
            throw new AiException('AI prompt id is invalid.', 'prompt_invalid');
        }
    }

    /** @param array<string,string|int|float|bool|null> $values */
    public function render(array $values): string
    {
        $rendered = $this->template;
        foreach ($this->variables as $variable) {
            $value = $values[$variable] ?? '';
            $rendered = str_replace('{{' . $variable . '}}', (string) $value, $rendered);
        }

        return $rendered;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'owner_plugin' => $this->ownerPlugin,
            'language' => $this->language,
            'variables' => $this->variables,
            'template' => $this->template,
        ];
    }
}
