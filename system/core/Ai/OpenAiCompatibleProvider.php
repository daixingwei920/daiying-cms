<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class OpenAiCompatibleProvider implements AiProviderInterface, AiModelDiscoveryInterface
{
    /** @param list<AiModel> $models */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly array $models,
        private readonly AiProviderClientInterface $client = new OpenAiCompatibleProviderClient(),
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getModels(): array
    {
        return $this->models;
    }

    public function getCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->models as $model) {
            $capabilities = array_merge($capabilities, $model->capabilities);
        }

        return array_values(array_unique($capabilities));
    }

    public function execute(AiRequest $request, array $config): AiResponse
    {
        $config['provider'] = $this->id;
        if ($request->model !== null && $request->model !== '' && ($request->provider === null || AiProviderPresets::normalize($request->provider) === $this->id)) {
            $config['model'] = $request->model;
        }

        return AiResponse::fromChatResult($this->client->chat($request->messages, $config), $request->requestId);
    }

    public function testConnection(array $config): array
    {
        $testMaxTokens = $this->testConnectionMaxTokens($config);
        $testConfig = array_replace($config, [
            'max_tokens' => $testMaxTokens,
            'temperature' => 0.0,
        ]);
        if ($this->shouldHideReasoning($testConfig) && !array_key_exists('include_reasoning', $testConfig)) {
            $testConfig['include_reasoning'] = false;
        }
        $response = $this->execute(AiRequest::chat([['role' => 'user', 'content' => 'Reply with OK only.']], [
            'max_tokens' => $testMaxTokens,
            'temperature' => 0.0,
        ]), $testConfig);

        return [
            'provider' => $response->provider,
            'model' => $response->model,
            'status' => 'success',
            'message' => '连接成功',
        ];
    }

    public function detectModels(array $config): array
    {
        if (!$this->client instanceof AiModelDiscoveryClientInterface) {
            throw new AiException('AI provider does not support model discovery.', 'model_discovery_unsupported');
        }
        $config['provider'] = $this->id;

        return $this->client->models($config);
    }

    /** @param array<string,mixed> $config */
    private function testConnectionMaxTokens(array $config): int
    {
        return $this->shouldHideReasoning($config) ? 1024 : 64;
    }

    /** @param array<string,mixed> $config */
    private function shouldHideReasoning(array $config): bool
    {
        $provider = AiProviderPresets::normalize((string) ($config['provider'] ?? $this->id));
        $providerName = strtolower((string) ($config['provider_name'] ?? ''));
        $baseUrl = (string) ($config['base_url'] ?? '');
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $model = strtolower((string) ($config['model'] ?? ''));

        return $provider === 'groq'
            || str_contains($providerName, 'groq')
            || $host === 'api.groq.com'
            || str_contains($model, 'gpt-oss')
            || str_contains($model, 'reasoning')
            || str_contains($model, 'qwen3');
    }
}
