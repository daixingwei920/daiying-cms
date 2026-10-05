<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchMetrics;

use Cms\Core\Plugin\PluginSecretStore;
use PDO;

final class GoogleSearchConsoleConnectionRepository
{
    public const PLUGIN_ID = 'core.seo.search_metrics.google';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PluginSecretStore $secrets,
    ) {
    }

    /** @return array<string,string|bool> */
    public function config(): array
    {
        $row = $this->latestSetting();
        $payload = [];
        if (is_array($row)) {
            $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'client_id' => (string) ($payload['client_id'] ?? ''),
            'property_url' => (string) ($payload['property_url'] ?? ''),
            'last_sync_at' => (string) ($payload['last_sync_at'] ?? ''),
            'last_sync_result' => (string) ($payload['last_sync_result'] ?? ''),
            'last_error' => (string) ($payload['last_error'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function savePublicConfig(string $clientId, string $propertyUrl, bool $enabled): void
    {
        $config = $this->config();
        $config['client_id'] = $this->cleanClientId($clientId);
        $config['property_url'] = $this->cleanPropertyUrl($propertyUrl);
        $config['enabled'] = $enabled;
        $this->saveConfig($config);
    }

    public function saveClientSecret(string $clientSecret): void
    {
        $clientSecret = trim($clientSecret);
        if ($clientSecret !== '') {
            $this->secrets->set(self::PLUGIN_ID, 'client_secret', $clientSecret);
        }
    }

    public function saveRefreshToken(string $refreshToken): void
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken !== '') {
            $this->secrets->set(self::PLUGIN_ID, 'refresh_token', $refreshToken);
        }
    }

    public function clientSecret(): string
    {
        return (string) ($this->secrets->get(self::PLUGIN_ID, 'client_secret') ?? '');
    }

    public function refreshToken(): string
    {
        return (string) ($this->secrets->get(self::PLUGIN_ID, 'refresh_token') ?? '');
    }

    public function clientSecretConfigured(): bool
    {
        return $this->secrets->masked(self::PLUGIN_ID, 'client_secret') !== null;
    }

    public function refreshTokenConfigured(): bool
    {
        return $this->secrets->masked(self::PLUGIN_ID, 'refresh_token') !== null;
    }

    public function recordSync(bool $ok, string $result): void
    {
        $config = $this->config();
        $config['last_sync_at'] = gmdate('c');
        $config['last_sync_result'] = $ok ? substr($result, 0, 191) : 'failed';
        $config['last_error'] = $ok ? '' : substr($result, 0, 500);
        $this->saveConfig($config);
    }

    /** @return array{ok:bool,status:string,message:string,client_secret_configured:bool,refresh_token_configured:bool,property_url:string,last_sync_at:string,last_sync_result:string,last_error:string} */
    public function status(): array
    {
        $config = $this->config();
        $clientId = (string) ($config['client_id'] ?? '');
        $propertyUrl = (string) ($config['property_url'] ?? '');
        $clientSecret = $this->clientSecretConfigured();
        $refreshToken = $this->refreshTokenConfigured();
        $enabled = (bool) ($config['enabled'] ?? false);
        $ok = $enabled && $clientId !== '' && $propertyUrl !== '' && $clientSecret && $refreshToken;
        $status = $ok ? 'connected' : 'not_connected';
        if ($enabled && ($clientId === '' || $propertyUrl === '' || !$clientSecret || !$refreshToken)) {
            $status = 'not_configured';
        }
        if ((string) ($config['last_sync_result'] ?? '') === 'failed') {
            $status = 'auth_error';
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'message' => match ($status) {
                'connected' => '已连接',
                'auth_error' => '授权失败',
                'not_configured' => '未配置',
                default => '未连接',
            },
            'client_secret_configured' => $clientSecret,
            'refresh_token_configured' => $refreshToken,
            'property_url' => $propertyUrl,
            'last_sync_at' => (string) ($config['last_sync_at'] ?? ''),
            'last_sync_result' => (string) ($config['last_sync_result'] ?? ''),
            'last_error' => (string) ($config['last_error'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $config */
    private function saveConfig(array $config): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("DELETE FROM cms_plugin_data WHERE plugin_id = :plugin_id AND data_type = 'setting' AND data_key = 'connection'")
            ->execute([':plugin_id' => self::PLUGIN_ID]);
        $this->pdo->prepare('INSERT INTO cms_plugin_data (plugin_id, data_type, data_key, payload_json, created_at, updated_at) VALUES (:plugin_id, :data_type, :data_key, :payload_json, :created_at, :updated_at)')
            ->execute([
                ':plugin_id' => self::PLUGIN_ID,
                ':data_type' => 'setting',
                ':data_key' => 'connection',
                ':payload_json' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
    }

    /** @return array<string,mixed>|null */
    private function latestSetting(): ?array
    {
        $stmt = $this->pdo->prepare("SELECT payload_json, created_at, updated_at FROM cms_plugin_data WHERE plugin_id = :plugin_id AND data_type = 'setting' AND data_key = 'connection' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':plugin_id' => self::PLUGIN_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function cleanClientId(string $clientId): string
    {
        $clientId = trim($clientId);
        if ($clientId !== '' && (strlen($clientId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $clientId) === 1)) {
            throw new SearchMetricsException('Google client id is invalid.');
        }
        return $clientId;
    }

    private function cleanPropertyUrl(string $propertyUrl): string
    {
        $propertyUrl = trim($propertyUrl);
        if ($propertyUrl === '') {
            return '';
        }
        if (str_starts_with($propertyUrl, 'sc-domain:')) {
            return strlen($propertyUrl) <= 255 ? $propertyUrl : '';
        }
        $scheme = strtolower((string) parse_url($propertyUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || strlen($propertyUrl) > 512 || preg_match('/[\x00-\x1F\x7F]/', $propertyUrl) === 1) {
            throw new SearchMetricsException('Google property URL is invalid.');
        }
        return $propertyUrl;
    }
}
