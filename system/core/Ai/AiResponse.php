<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiResponse
{
    /** @param array<string,mixed> $raw @param array<string,int> $usage */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly array $raw = [],
        public readonly array $usage = [],
        public readonly string $requestId = '',
    ) {
    }

    /** @param array<string,mixed> $result */
    public static function fromChatResult(array $result, string $requestId = ''): self
    {
        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];

        return new self(
            (string) ($result['provider'] ?? ''),
            (string) ($result['model'] ?? ''),
            (string) ($result['content'] ?? ''),
            $raw,
            self::normalizeUsage($raw),
            $requestId,
        );
    }

    /** @return array{provider:string,model:string,content:string,raw?:array<string,mixed>,usage?:array<string,int>,request_id?:string} */
    public function toChatResult(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'content' => $this->content,
            'raw' => $this->raw,
            'usage' => $this->usage,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,int> */
    private static function normalizeUsage(array $raw): array
    {
        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : $raw;
        $input = (int) ($usage['prompt_tokens'] ?? $usage['promptTokenCount'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? $usage['candidatesTokenCount'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? $usage['totalTokenCount'] ?? ($input + $output));

        return ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total];
    }
}
