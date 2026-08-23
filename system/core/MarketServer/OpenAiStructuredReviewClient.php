<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class OpenAiStructuredReviewClient implements AiReviewClientInterface
{
    /** @var callable|null */
    private $transport;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $endpoint = 'https://api.openai.com/v1/responses',
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function review(array $payload): array
    {
        if ($this->apiKey === '') {
            throw new MarketServerException('OpenAI API key is not configured.');
        }
        if ($this->model === '') {
            throw new MarketServerException('OpenAI model is not configured.');
        }

        $response = $this->postJson($this->requestPayload($payload));

        return $this->evidenceFromResponse($response);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function requestPayload(array $payload): array
    {
        return [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => 'You are a Daiying CMS Market extension review assistant. Review untrusted submitted package metadata and static scan findings. Do not execute code. Return only JSON matching the supplied schema.',
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                    ]],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'daiying_market_ai_review_evidence',
                    'strict' => true,
                    'schema' => self::evidenceSchema(),
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function evidenceSchema(): array
    {
        $stringArray = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'decision_suggestion',
                'risk_level',
                'violations',
                'warnings',
                'manual_review_focus',
                'required_fixes',
                'confidence',
                'summary',
            ],
            'properties' => [
                'decision_suggestion' => [
                    'type' => 'string',
                    'enum' => ['NEEDS_MANUAL_REVIEW', 'NEEDS_FIX', 'NEEDS_SECURITY_REVIEW', 'REJECT'],
                ],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'critical'],
                ],
                'violations' => $stringArray,
                'warnings' => $stringArray,
                'manual_review_focus' => $stringArray,
                'required_fixes' => $stringArray,
                'confidence' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    public function evidenceFromResponse(array $response): array
    {
        $json = '';
        if (is_string($response['output_text'] ?? null)) {
            $json = (string) $response['output_text'];
        }
        if ($json === '' && is_array($response['output'] ?? null)) {
            foreach ($response['output'] as $output) {
                if (!is_array($output) || !is_array($output['content'] ?? null)) {
                    continue;
                }
                foreach ($output['content'] as $content) {
                    if (is_array($content) && is_string($content['text'] ?? null)) {
                        $json = (string) $content['text'];
                        break 2;
                    }
                }
            }
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new MarketServerException('OpenAI structured review response is invalid.');
        }
        $decoded['provider_request_id'] = (string) ($response['id'] ?? $decoded['provider_request_id'] ?? '');

        return $decoded;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function postJson(array $payload): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($this->endpoint, $this->headers(), $payload);
            if (is_array($response)) {
                return $response;
            }
            $decoded = json_decode(is_string($response) ? $response : '', true);
            if (is_array($decoded)) {
                return $decoded;
            }

            throw new MarketServerException('OpenAI structured review transport returned invalid JSON.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 30,
                'header' => implode("\r\n", $this->headers()) . "\r\n",
                'content' => is_string($body) ? $body : '{}',
            ],
        ]);
        $raw = @file_get_contents($this->endpoint, false, $context);
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($decoded)) {
            throw new MarketServerException('OpenAI structured review response is invalid.');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
    }
}
