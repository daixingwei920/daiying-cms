<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class OpenAiCompatibleProvider implements AiProviderInterface
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
        if ($request->model !== null && $request->model !== '') {
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
}
