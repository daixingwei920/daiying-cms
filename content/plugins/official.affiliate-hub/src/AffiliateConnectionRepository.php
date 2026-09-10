<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

use Cms\Core\Plugin\PluginSecretStore;
use PDO;

final class AffiliateConnectionRepository
{
    private const PLUGIN_ID = 'official.affiliate-hub';

    public function __construct(private readonly PDO $pdo, private readonly PluginSecretStore $secrets)
    {
    }

    /** @return array<string,mixed>|null */
    public function cjConnection(): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM affiliate_connections WHERE provider_id = 'affiliate.cj' ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['public_config'] = $this->decode((string) ($row['public_config_json'] ?? '{}'));
        $uuid = (string) $row['uuid'];
        $row['token_configured'] = $this->secrets->get(self::PLUGIN_ID, $this->secretKey($uuid)) !== null;
        $row['token_masked'] = $this->secrets->masked(self::PLUGIN_ID, $this->secretKey($uuid)) ?? '';

        return $row;
    }

    /** @param array<string,mixed> $input */
    public function saveCjConnection(array $input): int
    {
        $existing = $this->cjConnection();
        $uuid = is_array($existing) ? (string) $existing['uuid'] : $this->uuid();
        $config = [
            'company_id' => $this->text($input['company_id'] ?? '', 64),
            'website_id' => $this->text($input['website_id'] ?? '', 64),
            'advertiser_ids' => $this->text($input['advertiser_ids'] ?? '', 500),
            'timeout' => max(3, min(60, (int) ($input['timeout'] ?? 20))),
        ];
        if ($config['company_id'] === '' || $config['website_id'] === '') {
            throw new \InvalidArgumentException('CJ Company ID 和 Website ID / PID 不能为空。');
        }
        $status = ((string) ($input['status'] ?? 'disabled')) === 'enabled' ? 'enabled' : 'disabled';
        $now = gmdate('c');
        if (is_array($existing)) {
            $id = (int) $existing['id'];
            $this->pdo->prepare('UPDATE affiliate_connections SET name = :name, status = :status, capabilities_json = :capabilities, public_config_json = :config, updated_at = :now WHERE id = :id')
                ->execute([
                    ':id' => $id,
                    ':name' => 'CJ Affiliate',
                    ':status' => $status,
                    ':capabilities' => json_encode(['product_search', 'tracking_link', 'dedupe_sync'], JSON_UNESCAPED_SLASHES),
                    ':config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    ':now' => $now,
                ]);
        } else {
            $this->pdo->prepare('INSERT INTO affiliate_connections (uuid, provider_id, name, status, capabilities_json, public_config_json, created_at, updated_at) VALUES (:uuid, :provider_id, :name, :status, :capabilities, :config, :now, :now)')
                ->execute([
                    ':uuid' => $uuid,
                    ':provider_id' => 'affiliate.cj',
                    ':name' => 'CJ Affiliate',
                    ':status' => $status,
                    ':capabilities' => json_encode(['product_search', 'tracking_link', 'dedupe_sync'], JSON_UNESCAPED_SLASHES),
                    ':config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    ':now' => $now,
                ]);
            $id = (int) $this->pdo->lastInsertId();
        }
        $token = trim((string) ($input['personal_access_token'] ?? ''));
        if ($token !== '') {
            $this->secrets->set(self::PLUGIN_ID, $this->secretKey($uuid), $token);
        }

        return $id;
    }

    /** @return array<string,mixed> */
    public function cjRuntimeConfig(): array
    {
        $connection = $this->cjConnection();
        if (!is_array($connection)) {
            throw new \RuntimeException('请先配置 CJ Affiliate 连接。');
        }
        $config = is_array($connection['public_config'] ?? null) ? $connection['public_config'] : [];
        $token = (string) ($this->secrets->get(self::PLUGIN_ID, $this->secretKey((string) $connection['uuid'])) ?? '');
        if ($token === '') {
            throw new \RuntimeException('CJ Personal Access Token 未配置。');
        }
        $config['personal_access_token'] = $token;
        $config['connection_id'] = (int) $connection['id'];

        return $config;
    }

    public function updateCjTest(string $status, string $message): void
    {
        $connection = $this->cjConnection();
        if (!is_array($connection)) {
            return;
        }
        $this->pdo->prepare('UPDATE affiliate_connections SET last_test_status = :status, last_test_message = :message, last_tested_at = :now, updated_at = :now WHERE id = :id')
            ->execute([
                ':id' => (int) $connection['id'],
                ':status' => $status,
                ':message' => mb_substr($this->redact($message), 0, 500),
                ':now' => gmdate('c'),
            ]);
    }

    public function markCjRateLimited(string $message): void
    {
        $connection = $this->cjConnection();
        if (!is_array($connection)) {
            return;
        }
        $this->pdo->prepare('UPDATE affiliate_connections SET last_error = :message, last_rate_limited_at = :now, updated_at = :now WHERE id = :id')
            ->execute([':id' => (int) $connection['id'], ':message' => mb_substr($this->redact($message), 0, 500), ':now' => gmdate('c')]);
    }

    private function secretKey(string $uuid): string
    {
        return 'connection:' . $uuid . ':cj_personal_access_token';
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json !== '' ? $json : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function redact(string $message): string
    {
        return preg_replace('/(Authorization|Bearer|token|personal_access_token|api[_-]?key|secret)[^,\]\s]*/i', '$1=[redacted]', $message) ?: 'CJ error';
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
