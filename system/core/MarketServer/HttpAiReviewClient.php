<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class HttpAiReviewClient implements AiReviewClientInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey = '',
        private readonly string $model = '',
    ) {
    }

    public function review(array $payload): array
    {
        if ($this->endpoint === '') {
            throw new MarketServerException('AI review endpoint is not configured.');
        }

        $payload['model'] = $payload['model'] ?? $this->model;

        $decoded = $this->postJson($payload);
        $decoded['provider_request_id'] = (string) ($decoded['provider_request_id'] ?? '');

        return $decoded;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function postJson(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = "Content-Type: application/json\r\n";
        if ($this->apiKey !== '') {
            $headers .= 'Authorization: Bearer ' . $this->apiKey . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 10,
                'header' => $headers,
                'content' => is_string($body) ? $body : '{}',
            ],
        ]);
        $response = @file_get_contents($this->endpoint, false, $context);
        $decoded = json_decode(is_string($response) ? $response : '', true);
        if (!is_array($decoded)) {
            throw new MarketServerException('AI review response is invalid.');
        }

        return $decoded;
    }
}
