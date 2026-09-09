<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class GeminiProvider implements AiProviderInterface
{
    /** @param list<AiModel> $models */
    public function __construct(
        private readonly array $models,
        private readonly AiProviderClientInterface $client = new GeminiProviderClient(),
    ) {
    }

    public function getId(): string
    {
        return 'gemini';
    }

    public function getLabel(): string
    {
        return 'Google Gemini';
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
        $config['provider'] = 'gemini';
        if ($request->model !== null && $request->model !== '') {
            $config['model'] = $request->model;
        }

        return AiResponse::fromChatResult($this->client->chat($request->messages, $config), $request->requestId);
    }

    public function testConnection(array $config): array
    {
        $testConfig = array_replace($config, [
            'max_tokens' => 1024,
            'temperature' => 0.0,
        ]);
        $response = $this->execute(AiRequest::chat([['role' => 'user', 'content' => 'Reply with exactly OK.']], [
            'max_tokens' => 1024,
            'temperature' => 0.0,
        ]), $testConfig);

        return [
            'provider' => $response->provider,
            'model' => $response->model,
            'status' => 'success',
            'message' => '连接成功',
        ];
    }
}
