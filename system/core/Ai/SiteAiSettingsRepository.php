<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use PDO;

final class SiteAiSettingsRepository
{
    private const TABLE = 'cms_core_ai_settings';

    public function __construct(private readonly PDO $pdo, private readonly string $encryptionKey)
    {
        $this->ensureSchema();
        $this->ensureDefaultRow();
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        $row = $this->row();
        $apiKeyConfigured = (string) ($row['api_key_ciphertext'] ?? '') !== '';

        return [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'provider' => AiProviderPresets::normalize((string) ($row['provider'] ?? 'openai_compatible')),
            'adapter' => AiProviderPresets::adapter((string) ($row['provider'] ?? 'openai_compatible'), (string) ($row['adapter'] ?? '')),
            'base_url' => (string) ($row['base_url'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'timeout_seconds' => (int) ($row['timeout_seconds'] ?? 30),
            'max_tokens' => (int) ($row['max_tokens'] ?? 1024),
            'temperature' => (float) ($row['temperature'] ?? 0.7),
            'provider_name' => (string) ($row['provider_name'] ?? ''),
            'local_api_type' => (string) ($row['local_api_type'] ?? 'openai_compatible'),
            'context_window' => (int) ($row['context_window'] ?? 0),
            'openclaw_agent' => (string) ($row['openclaw_agent'] ?? ''),
            'allow_cloud_fallback' => (int) ($row['allow_cloud_fallback'] ?? 0) === 1,
            'fallback_provider' => trim((string) ($row['fallback_provider'] ?? '')) !== '' ? AiProviderPresets::normalize((string) $row['fallback_provider']) : '',
            'api_key_configured' => $apiKeyConfigured,
            'api_key_masked' => $apiKeyConfigured ? '********' : '',
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function runtimeConfig(): array
    {
        $row = $this->row();
        $config = AiProviderPresets::applyDefaults($this->current());
        $config['api_key'] = $this->decrypt((string) ($row['api_key_ciphertext'] ?? ''));

        return $config;
    }

    /** @param array<string,mixed> $config */
    public function save(array $config, string $apiKey, bool $clearApiKey = false): void
    {
        $existing = $this->row();
        $ciphertext = (string) ($existing['api_key_ciphertext'] ?? '');
        if ($clearApiKey) {
            $ciphertext = '';
        } elseif ($apiKey !== '') {
            $ciphertext = $this->encrypt($apiKey);
        } elseif ($ciphertext !== '') {
            $this->decrypt($ciphertext);
        }

        $provider = AiProviderPresets::normalize((string) ($config['provider'] ?? 'openai_compatible'));
        $adapter = AiProviderPresets::adapter($provider, (string) ($config['adapter'] ?? ''));
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET enabled = :enabled, provider = :provider, adapter = :adapter, base_url = :base_url, model = :model,
                 timeout_seconds = :timeout_seconds, max_tokens = :max_tokens, temperature = :temperature,
                 provider_name = :provider_name, local_api_type = :local_api_type, context_window = :context_window,
                 openclaw_agent = :openclaw_agent, allow_cloud_fallback = :allow_cloud_fallback, fallback_provider = :fallback_provider,
                 api_key_ciphertext = :api_key_ciphertext, updated_at = :updated_at
             WHERE id = 1'
        );
        $stmt->execute([
            ':enabled' => !empty($config['enabled']) ? 1 : 0,
            ':provider' => $provider,
            ':adapter' => $adapter,
            ':base_url' => (string) $config['base_url'],
            ':model' => (string) $config['model'],
            ':timeout_seconds' => (int) $config['timeout_seconds'],
            ':max_tokens' => (int) $config['max_tokens'],
            ':temperature' => (string) $config['temperature'],
            ':provider_name' => (string) ($config['provider_name'] ?? ''),
            ':local_api_type' => (string) ($config['local_api_type'] ?? 'openai_compatible'),
            ':context_window' => (int) ($config['context_window'] ?? 0),
            ':openclaw_agent' => (string) ($config['openclaw_agent'] ?? ''),
            ':allow_cloud_fallback' => !empty($config['allow_cloud_fallback']) ? 1 : 0,
            ':fallback_provider' => trim((string) ($config['fallback_provider'] ?? '')) !== '' ? AiProviderPresets::normalize((string) $config['fallback_provider']) : '',
            ':api_key_ciphertext' => $ciphertext,
            ':updated_at' => $now,
        ]);
    }

    public function apiKeyConfigured(): bool
    {
        return (string) ($this->row()['api_key_ciphertext'] ?? '') !== '';
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();

        return is_array($row) ? $row : $this->defaultRow();
    }

    private function ensureSchema(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $decimal = $driver === 'sqlite' ? 'REAL' : 'DECIMAL(4,2)';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 0,
                provider VARCHAR(64) NOT NULL DEFAULT "openai_compatible",
                adapter VARCHAR(64) NOT NULL DEFAULT "openai_compatible",
                base_url VARCHAR(2048) NOT NULL DEFAULT "",
                model VARCHAR(191) NOT NULL DEFAULT "",
                timeout_seconds INTEGER NOT NULL DEFAULT 30,
                max_tokens INTEGER NOT NULL DEFAULT 1024,
                temperature ' . $decimal . ' NOT NULL DEFAULT 0.7,
                api_key_ciphertext ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        foreach ($this->expectedColumns($longText, $decimal) as $column => $definition) {
            if (!in_array($column, $this->columns(), true)) {
                $this->pdo->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $this->backfillAdapters();
    }

    /** @return array<string,string> */
    private function expectedColumns(string $longText, string $decimal): array
    {
        return [
            'enabled' => 'INTEGER NOT NULL DEFAULT 0',
            'provider' => 'VARCHAR(64) NOT NULL DEFAULT "openai_compatible"',
            'adapter' => 'VARCHAR(64) NOT NULL DEFAULT "openai_compatible"',
            'base_url' => 'VARCHAR(2048) NOT NULL DEFAULT ""',
            'model' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'timeout_seconds' => 'INTEGER NOT NULL DEFAULT 30',
            'max_tokens' => 'INTEGER NOT NULL DEFAULT 1024',
            'temperature' => $decimal . ' NOT NULL DEFAULT 0.7',
            'provider_name' => 'VARCHAR(128) NOT NULL DEFAULT ""',
            'local_api_type' => 'VARCHAR(64) NOT NULL DEFAULT "openai_compatible"',
            'context_window' => 'INTEGER NOT NULL DEFAULT 0',
            'openclaw_agent' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'allow_cloud_fallback' => 'INTEGER NOT NULL DEFAULT 0',
            'fallback_provider' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'api_key_ciphertext' => $longText . ' NULL',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'updated_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ];
    }

    /** @return list<string> */
    private function columns(): array
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $this->pdo->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $this->pdo->query('SHOW COLUMNS FROM ' . self::TABLE)->fetchAll());
    }

    private function ensureDefaultRow(): void
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE id = 1');
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (id, enabled, provider, base_url, model, timeout_seconds, max_tokens, temperature, api_key_ciphertext, created_at, updated_at)
             VALUES (1, 0, "openai_compatible", "", "", 30, 1024, 0.7, "", :created_at, :updated_at)'
        )->execute([':created_at' => $now, ':updated_at' => $now]);
    }

    /** @return array<string,mixed> */
    private function defaultRow(): array
    {
        return [
            'id' => 1,
            'enabled' => 0,
            'provider' => 'openai_compatible',
            'adapter' => 'openai_compatible',
            'base_url' => '',
            'model' => '',
            'timeout_seconds' => 30,
            'max_tokens' => 1024,
            'temperature' => 0.7,
            'provider_name' => '',
            'local_api_type' => 'openai_compatible',
            'context_window' => 0,
            'openclaw_agent' => '',
            'allow_cloud_fallback' => 0,
            'fallback_provider' => '',
            'api_key_ciphertext' => '',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }

    private function encrypt(string $apiKey): string
    {
        $this->assertApiKey($apiKey);
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($apiKey, 'aes-256-gcm', $this->keyBytes(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($cipher) || $tag === '') {
            throw new AiException('Unable to encrypt AI API Key.', 'secret_encrypt_failed');
        }

        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    private function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        if (!str_starts_with($payload, 'v1:')) {
            throw new AiException('AI API Key ciphertext is invalid.', 'secret_invalid');
        }
        $raw = base64_decode(substr($payload, 3), true);
        if (!is_string($raw) || strlen($raw) < 28) {
            throw new AiException('AI API Key ciphertext is invalid.', 'secret_invalid');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->keyBytes(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plain)) {
            throw new AiException('Unable to decrypt AI API Key.', 'secret_decrypt_failed');
        }
        $this->assertApiKey($plain);

        return $plain;
    }

    private function keyBytes(): string
    {
        if ($this->encryptionKey === '') {
            throw new AiException('security.encryption_key is not configured, unable to store AI API Key safely.', 'encryption_key_missing');
        }

        return hash('sha256', $this->encryptionKey, true);
    }

    private function assertApiKey(string $apiKey): void
    {
        if ($apiKey === '' || $apiKey !== trim($apiKey) || strlen($apiKey) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
            throw new AiException('AI API Key format is invalid.', 'api_key_invalid');
        }
    }

    private function backfillAdapters(): void
    {
        $rows = $this->pdo->query('SELECT id, provider, adapter FROM ' . self::TABLE)->fetchAll();
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET provider = :provider, adapter = :adapter WHERE id = :id');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $provider = AiProviderPresets::normalize((string) ($row['provider'] ?? 'openai_compatible'));
            $adapter = AiProviderPresets::adapter($provider, (string) ($row['adapter'] ?? ''));
            $stmt->execute([
                ':provider' => $provider,
                ':adapter' => $adapter,
                ':id' => (int) ($row['id'] ?? 0),
            ]);
        }
    }
}
