<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiRequest
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param list<string> $capabilities
     * @param array<string,mixed> $options
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $capabilities = ['text_generation'],
        public readonly string $operation = 'chat',
        public readonly string $pluginId = 'core',
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly array $options = [],
        public readonly string $requestId = '',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $operation)) {
            throw new AiException('AI operation is invalid.', 'request_invalid');
        }
        if ($pluginId === '' || strlen($pluginId) > 96) {
            throw new AiException('AI plugin scope is invalid.', 'request_invalid');
        }
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     */
    public static function chat(array $messages, array $options = []): self
    {
        $capabilities = ['text_generation'];
        if (isset($options['capabilities']) && is_array($options['capabilities'])) {
            $capabilities = array_values(array_map('strval', $options['capabilities']));
        }

        return new self(
            $messages,
            $capabilities,
            (string) ($options['operation'] ?? 'chat'),
            (string) ($options['plugin_id'] ?? 'core'),
            isset($options['provider']) ? (string) $options['provider'] : null,
            isset($options['model']) ? (string) $options['model'] : null,
            $options,
            (string) ($options['request_id'] ?? self::newRequestId()),
        );
    }

    public static function newRequestId(): string
    {
        return 'ai_' . bin2hex(random_bytes(12));
    }
}
