<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use PDO;

final class PluginSecretStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $masterKey,
    ) {
    }

    public function set(string $pluginId, string $key, string $value): void
    {
        if ($value !== '' && $this->masterKey === '') {
            throw new PluginException('Plugin secret encryption master key is not configured.');
        }
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $this->keyBytes(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($cipher)) {
            throw new PluginException('Unable to encrypt plugin secret.');
        }
        $now = gmdate('c');
        $payload = base64_encode($nonce . $tag . $cipher);
        $sql = 'INSERT INTO cms_plugin_secrets (plugin_id, secret_key, ciphertext, created_at, updated_at)
                VALUES (:plugin_id, :secret_key, :ciphertext, :created_at, :updated_at)';
        try {
            $this->pdo->prepare($sql)->execute([
                ':plugin_id' => $pluginId,
                ':secret_key' => $key,
                ':ciphertext' => $payload,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\Throwable) {
            $this->pdo->prepare('UPDATE cms_plugin_secrets SET ciphertext = :ciphertext, updated_at = :updated_at WHERE plugin_id = :plugin_id AND secret_key = :secret_key')
                ->execute([':plugin_id' => $pluginId, ':secret_key' => $key, ':ciphertext' => $payload, ':updated_at' => $now]);
        }
    }

    public function masked(string $pluginId, string $key): ?string
    {
        $value = $this->get($pluginId, $key);
        if ($value === null) {
            return null;
        }
        $suffix = substr($value, -4);
        return str_repeat('*', max(8, strlen($value) - strlen($suffix))) . $suffix;
    }

    public function get(string $pluginId, string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT ciphertext FROM cms_plugin_secrets WHERE plugin_id = :plugin_id AND secret_key = :secret_key LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId, ':secret_key' => $key]);
        $payload = $stmt->fetchColumn();
        if (!is_string($payload)) {
            return null;
        }
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 28) {
            throw new PluginException('Plugin secret ciphertext is invalid.');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->keyBytes(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plain)) {
            throw new PluginException('Unable to decrypt plugin secret.');
        }

        return $plain;
    }

    public function purgePluginSecrets(string $pluginId, string $confirmation): void
    {
        if ($confirmation !== 'PURGE SECRETS ' . $pluginId) {
            throw new PluginException('Plugin secret purge requires separate confirmation.');
        }
        $this->pdo->prepare('DELETE FROM cms_plugin_secrets WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
    }

    private function keyBytes(): string
    {
        return hash('sha256', $this->masterKey, true);
    }
}
