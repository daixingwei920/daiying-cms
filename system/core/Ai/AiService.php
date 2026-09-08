<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Config\Settings;
use PDO;

final class AiService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
        private readonly ?AiProviderClientInterface $client = null,
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            $config = $this->repository()->current();
            return !empty($config['enabled']) && !empty($config['api_key_configured']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return $this->repository()->current();
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @return array{provider:string,model:string,content:string,raw?:array<string,mixed>}
     */
    public function chat(array $messages, array $options = []): array
    {
        return $this->gateway()->chat($messages, $options);
    }

    public function request(AiRequest $request): AiResponse
    {
        return $this->gateway()->request($request);
    }

    /** @return array{provider:string,model:string,status:string,message:string} */
    public function testConnection(): array
    {
        return $this->gateway()->testConnection();
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return [
            'chat' => true,
            'test_connection' => true,
            'adapters' => ['openai_compatible', 'gemini'],
            'providers' => array_keys(AiProviderPresets::all()),
            'provider_registry' => AiProviderRegistry::describe(),
            'models' => array_merge(...array_values(array_map(static fn (array $provider): array => $provider['models'], AiProviderRegistry::describe()))),
            'gateway' => true,
            'usage_ledger' => true,
            'quota' => true,
            'jobs' => true,
            'tools' => true,
            'agents' => true,
            'prompts' => true,
        ];
    }

    private function repository(): SiteAiSettingsRepository
    {
        return new SiteAiSettingsRepository($this->pdo, (string) $this->settings->get('security.encryption_key', ''));
    }

    public function gateway(): AiGateway
    {
        return new AiGateway($this);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function runtimeConfigForGateway(array $options): array
    {
        $config = AiProviderPresets::applyDefaults($this->repository()->runtimeConfig());
        foreach (['model', 'base_url', 'max_tokens', 'temperature', 'timeout_seconds'] as $key) {
            if (array_key_exists($key, $options)) {
                $config[$key] = $options[$key];
            }
        }
        $config = AiProviderPresets::applyDefaults($config);

        return $config;
    }

    /** @param array<string,mixed> $config */
    public function providerClientForGateway(array $config): ?AiProviderClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        return null;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @return list<array{role:string,content:string}>
     */
    public function messagesForGateway(array $messages): array
    {
        $clean = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = (string) ($message['content'] ?? '');
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                throw new AiException('AI message role is invalid.', 'message_invalid');
            }
            if ($content === '' || strlen($content) > 262144 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) {
                throw new AiException('AI message content is invalid.', 'message_invalid');
            }
            $clean[] = ['role' => $role, 'content' => $content];
        }
        if ($clean === []) {
            throw new AiException('AI messages are empty.', 'message_invalid');
        }

        return $clean;
    }
}
