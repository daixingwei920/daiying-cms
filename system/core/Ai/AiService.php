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
        $config = $this->runtimeConfig($options);
        if (empty($config['enabled'])) {
            throw new AiException('Global AI is disabled.', 'disabled');
        }
        if ((string) ($config['api_key'] ?? '') === '') {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        $cleanMessages = $this->messages($messages);

        return ($this->client ?? new OpenAiCompatibleProviderClient())->chat($cleanMessages, $config);
    }

    /** @return array{provider:string,model:string,status:string,message:string} */
    public function testConnection(): array
    {
        $config = $this->runtimeConfig(['max_tokens' => 16, 'temperature' => 0.0]);
        $result = $this->chat([
            ['role' => 'user', 'content' => 'Reply with OK only.'],
        ], ['max_tokens' => 16, 'temperature' => 0.0]);

        return [
            'provider' => (string) ($result['provider'] ?? $config['provider']),
            'model' => (string) ($result['model'] ?? $config['model']),
            'status' => 'success',
            'message' => '连接成功',
        ];
    }

    private function repository(): SiteAiSettingsRepository
    {
        return new SiteAiSettingsRepository($this->pdo, (string) $this->settings->get('security.encryption_key', ''));
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function runtimeConfig(array $options): array
    {
        $config = $this->repository()->runtimeConfig();
        foreach (['model', 'base_url', 'max_tokens', 'temperature', 'timeout_seconds'] as $key) {
            if (array_key_exists($key, $options)) {
                $config[$key] = $options[$key];
            }
        }

        return $config;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @return list<array{role:string,content:string}>
     */
    private function messages(array $messages): array
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
