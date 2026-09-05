<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginSecretStore;

final class BaiduTokenRepository
{
    public const PLUGIN_ID = 'local.storage.baidu';

    public function __construct(
        private readonly PluginDataStore $data,
        private readonly PluginSecretStore $secrets,
    ) {
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->latest('config') + [
            'app_key' => '',
            'connected' => false,
            'token_expires_at' => '',
            'last_error' => '',
            'updated_at' => '',
        ];
    }

    public function appSecretConfigured(): bool
    {
        return $this->secrets->get(self::PLUGIN_ID, 'app_secret') !== null;
    }

    public function maskedSecret(): string
    {
        return $this->secrets->masked(self::PLUGIN_ID, 'app_secret') ?? '';
    }

    public function appSecret(): string
    {
        return (string) ($this->secrets->get(self::PLUGIN_ID, 'app_secret') ?? '');
    }

    public function saveConfig(string $appKey, ?string $appSecret): void
    {
        $config = $this->config();
        $config['app_key'] = trim($appKey);
        $config['updated_at'] = gmdate('c');
        $config['last_error'] = '';
        $this->data->put('baidu_storage_config', 'config', $config);
        if ($appSecret !== null && trim($appSecret) !== '') {
            $this->secrets->set(self::PLUGIN_ID, 'app_secret', trim($appSecret));
        }
    }

    /** @param array<string,mixed> $token */
    public function saveToken(array $token): void
    {
        $access = (string) ($token['access_token'] ?? '');
        $refresh = (string) ($token['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new \RuntimeException('百度授权返回缺少 Token。');
        }
        $expiresIn = max(60, (int) ($token['expires_in'] ?? 0));
        $expiresAt = gmdate('c', time() + $expiresIn);
        $this->secrets->set(self::PLUGIN_ID, 'access_token', $access);
        $this->secrets->set(self::PLUGIN_ID, 'refresh_token', $refresh);
        $config = $this->config();
        $config['connected'] = true;
        $config['token_expires_at'] = $expiresAt;
        $config['scope'] = (string) ($token['scope'] ?? '');
        $config['last_error'] = '';
        $config['updated_at'] = gmdate('c');
        $this->data->put('baidu_storage_config', 'config', $config);
    }

    /** @return array{access_token:string,refresh_token:string,expires_at:string} */
    public function token(): array
    {
        $config = $this->config();
        return [
            'access_token' => (string) ($this->secrets->get(self::PLUGIN_ID, 'access_token') ?? ''),
            'refresh_token' => (string) ($this->secrets->get(self::PLUGIN_ID, 'refresh_token') ?? ''),
            'expires_at' => (string) ($config['token_expires_at'] ?? ''),
        ];
    }

    public function markDisconnected(string $message = ''): void
    {
        $config = $this->config();
        $config['connected'] = false;
        $config['token_expires_at'] = '';
        $config['last_error'] = $message;
        $config['updated_at'] = gmdate('c');
        $this->data->put('baidu_storage_config', 'config', $config);
        $this->secrets->set(self::PLUGIN_ID, 'access_token', '');
        $this->secrets->set(self::PLUGIN_ID, 'refresh_token', '');
    }

    public function saveOAuthState(string $state, string $returnTo): void
    {
        $this->secrets->set(self::PLUGIN_ID, 'oauth_state_' . $state, json_encode([
            'state' => $state,
            'return_to' => $returnTo,
            'expires_at' => time() + 600,
            'created_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /** @return array{return_to:string} */
    public function consumeOAuthState(string $state): array
    {
        if ($state === '' || !preg_match('/^[a-f0-9]{64}$/', $state)) {
            throw new \RuntimeException('百度授权 state 无效，请重新连接。');
        }
        $key = 'oauth_state_' . $state;
        $raw = $this->secrets->get(self::PLUGIN_ID, $key);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('百度授权 state 不存在或已被使用，请重新连接。');
        }
        $payload = json_decode($raw, true);
        $this->secrets->set(self::PLUGIN_ID, $key, '');
        if (!is_array($payload) || (string) ($payload['state'] ?? '') !== $state || time() > (int) ($payload['expires_at'] ?? 0)) {
            throw new \RuntimeException('百度授权 state 已过期，请重新连接。');
        }
        return ['return_to' => (string) ($payload['return_to'] ?? '/admin/baidu-storage')];
    }

    public function setLastError(string $message): void
    {
        $config = $this->config();
        $config['last_error'] = $message;
        $config['updated_at'] = gmdate('c');
        $this->data->put('baidu_storage_config', 'config', $config);
    }

    /** @return array<string,mixed>|null */
    public function cacheGet(string $key): ?array
    {
        foreach ($this->data->all('baidu_storage_cache') as $row) {
            if ((string) ($row['data_key'] ?? '') !== $key) {
                continue;
            }
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                return null;
            }
            if ((int) ($payload['expires_at'] ?? 0) < time()) {
                return null;
            }
            $value = $payload['value'] ?? null;
            return is_array($value) ? $value : null;
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    public function cachePut(string $key, array $payload, int $ttlSeconds): void
    {
        $this->data->put('baidu_storage_cache', $key, [
            'value' => $this->stripTransientLinks($payload),
            'expires_at' => time() + max(10, min(600, $ttlSeconds)),
            'created_at' => gmdate('c'),
        ]);
    }

    /** @return array<string,mixed> */
    private function latest(string $key): array
    {
        foreach ($this->data->all('baidu_storage_config') as $row) {
            if ((string) ($row['data_key'] ?? '') !== $key) {
                continue;
            }
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
            return is_array($payload) ? $payload : [];
        }
        return [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function stripTransientLinks(array $payload): array
    {
        foreach (['dlink', 'download_url', 'access_token'] as $key) {
            unset($payload[$key]);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripTransientLinks($value);
            }
        }

        return $payload;
    }
}
