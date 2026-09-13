<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class OpenClawProvider implements AiProviderInterface, AiModelDiscoveryInterface
{
    /** @param list<AiModel> $models */
    public function __construct(
        private readonly array $models,
        private readonly AiProviderClientInterface $client = new OpenClawProviderClient(),
    ) {
    }

    public function getId(): string
    {
        return 'openclaw';
    }

    public function getLabel(): string
    {
        return 'OpenClaw';
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
        $config['provider'] = 'openclaw';
        if ($request->model !== null && $request->model !== '' && ($request->provider === null || AiProviderPresets::normalize($request->provider) === 'openclaw')) {
            $config['model'] = $request->model;
        }

        return AiResponse::fromChatResult($this->client->chat($request->messages, $config), $request->requestId);
    }

    public function testConnection(array $config): array
    {
        $response = $this->execute(AiRequest::chat([['role' => 'user', 'content' => 'Reply with OK only.']], [
            'max_tokens' => 16,
            'temperature' => 0.0,
        ]), $config + ['max_tokens' => 16, 'temperature' => 0.0]);

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
        $config['provider'] = 'openclaw';

        return $this->client->models($config);
    }
}
