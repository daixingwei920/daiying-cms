<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Plugin\PluginSecretStore;
use PDO;
use RuntimeException;

final class MailAccountRepository
{
    private const PLUGIN_ID = 'official.mail';
    private const PROVIDERS = ['gmail', 'outlook'];

    public function __construct(private readonly PDO $pdo, private readonly PluginSecretStore $secrets)
    {
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return self::PROVIDERS;
    }

    /** @return array<string,mixed> */
    public function oauthConfig(string $provider): array
    {
        $this->assertProvider($provider);
        $stmt = $this->pdo->prepare('SELECT * FROM mail_oauth_configs WHERE provider = :provider LIMIT 1');
        $stmt->execute([':provider' => $provider]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'provider' => $provider,
                'status' => 'disabled',
                'client_id' => '',
                'tenant' => $provider === 'outlook' ? 'common' : '',
                'redirect_uri' => '',
                'scopes' => MailOAuthService::defaultScopes($provider),
                'client_secret_configured' => false,
                'client_secret_masked' => '',
            ];
        }
        $row['scopes'] = $this->jsonList($row['scopes_json'] ?? '[]');
        $masked = $this->secrets->masked(self::PLUGIN_ID, 'oauth_config:' . $provider . ':client_secret');
        $row['client_secret_configured'] = is_string($masked);
        $row['client_secret_masked'] = $masked ?? '';

        return $row;
    }

    /** @param array<string,mixed> $input */
    public function saveOAuthConfig(string $provider, array $input): void
    {
        $this->assertProvider($provider);
        $now = gmdate('c');
        $clientId = trim((string) ($input['client_id'] ?? ''));
        $tenant = $provider === 'outlook' ? trim((string) ($input['tenant'] ?? 'common')) : '';
        $tenant = $tenant !== '' ? $tenant : 'common';
        $redirect = trim((string) ($input['redirect_uri'] ?? ''));
        $status = (string) ($input['status'] ?? 'disabled');
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new RuntimeException('OAuth status is invalid.');
        }
        if ($clientId !== '' && preg_match('/[\r\n\x00-\x1F]/', $clientId) === 1) {
            throw new RuntimeException('OAuth client id is invalid.');
        }
        if ($redirect !== '') {
            $this->assertSafeRedirectUri($redirect);
        }
        if ($provider === 'outlook' && !preg_match('/^[A-Za-z0-9._-]{1,191}$/', $tenant)) {
            throw new RuntimeException('Outlook tenant value is invalid.');
        }
        $secret = (string) ($input['client_secret'] ?? '');
        if ($secret !== '') {
            $this->secrets->set(self::PLUGIN_ID, 'oauth_config:' . $provider . ':client_secret', $secret);
        } elseif (!empty($input['clear_client_secret'])) {
            $this->secrets->set(self::PLUGIN_ID, 'oauth_config:' . $provider . ':client_secret', '');
        }
        $scopes = MailOAuthService::defaultScopes($provider);
        $sql = 'INSERT INTO mail_oauth_configs (provider, status, client_id, tenant, scopes_json, redirect_uri, created_at, updated_at)
                VALUES (:provider, :status, :client_id, :tenant, :scopes_json, :redirect_uri, :created_at, :updated_at)';
        try {
            $this->pdo->prepare($sql)->execute([
                ':provider' => $provider,
                ':status' => $status,
                ':client_id' => $clientId,
                ':tenant' => $tenant,
                ':scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
                ':redirect_uri' => $redirect,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\Throwable) {
            $this->pdo->prepare('UPDATE mail_oauth_configs SET status = :status, client_id = :client_id, tenant = :tenant, scopes_json = :scopes_json, redirect_uri = :redirect_uri, updated_at = :updated_at WHERE provider = :provider')
                ->execute([
                    ':provider' => $provider,
                    ':status' => $status,
                    ':client_id' => $clientId,
                    ':tenant' => $tenant,
                    ':scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
                    ':redirect_uri' => $redirect,
                    ':updated_at' => $now,
                ]);
        }
    }

    public function clientSecret(string $provider): string
    {
        $this->assertProvider($provider);

        return (string) ($this->secrets->get(self::PLUGIN_ID, 'oauth_config:' . $provider . ':client_secret') ?? '');
    }

    /** @return list<array<string,mixed>> */
    public function accounts(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM mail_accounts ORDER BY provider, email');
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function account(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mail_accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function connectedAccounts(string $provider): array
    {
        $this->assertProvider($provider);
        $stmt = $this->pdo->prepare('SELECT * FROM mail_accounts WHERE provider = :provider AND status = "connected" ORDER BY email');
        $stmt->execute([':provider' => $provider]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function connectedAccountForEmail(string $provider, string $email): ?array
    {
        $this->assertProvider($provider);
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM mail_accounts WHERE provider = :provider AND email = :email AND status = "connected" LIMIT 1');
        $stmt->execute([':provider' => $provider, ':email' => $email]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $profile @param list<string> $scopes */
    public function upsertAccount(string $provider, array $profile, array $scopes, string $accessToken, string $refreshToken, ?int $expiresIn): array
    {
        $this->assertProvider($provider);
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('OAuth profile did not return a valid email address.');
        }
        $now = gmdate('c');
        $uuid = $this->uuid();
        $expiresAt = $expiresIn !== null && $expiresIn > 0 ? gmdate('c', time() + max(60, $expiresIn - 60)) : null;
        $displayName = trim((string) ($profile['display_name'] ?? ''));
        try {
            $this->pdo->prepare('INSERT INTO mail_accounts (uuid, provider, email, display_name, status, scopes_json, access_token_expires_at, last_connected_at, created_at, updated_at)
                VALUES (:uuid, :provider, :email, :display_name, "connected", :scopes_json, :expires, :connected, :created, :updated)')
                ->execute([
                    ':uuid' => $uuid,
                    ':provider' => $provider,
                    ':email' => $email,
                    ':display_name' => $displayName,
                    ':scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
                    ':expires' => $expiresAt,
                    ':connected' => $now,
                    ':created' => $now,
                    ':updated' => $now,
                ]);
            $id = (int) $this->pdo->lastInsertId();
        } catch (\Throwable) {
            $this->pdo->prepare('UPDATE mail_accounts SET display_name = :display_name, status = "connected", scopes_json = :scopes_json, access_token_expires_at = :expires, last_connected_at = :connected, last_error = NULL, updated_at = :updated WHERE provider = :provider AND email = :email')
                ->execute([
                    ':provider' => $provider,
                    ':email' => $email,
                    ':display_name' => $displayName,
                    ':scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
                    ':expires' => $expiresAt,
                    ':connected' => $now,
                    ':updated' => $now,
                ]);
            $id = (int) $this->pdo->query('SELECT id FROM mail_accounts WHERE provider = ' . $this->pdo->quote($provider) . ' AND email = ' . $this->pdo->quote($email))->fetchColumn();
        }
        $account = $this->account($id);
        if ($account === null) {
            throw new RuntimeException('Unable to save mail account.');
        }
        $this->saveTokens($account, $accessToken, $refreshToken, $expiresIn);

        return $account;
    }

    /** @param array<string,mixed> $account */
    public function saveTokens(array $account, string $accessToken, string $refreshToken = '', ?int $expiresIn = null): void
    {
        $uuid = (string) ($account['uuid'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('Mail account token target is invalid.');
        }
        if ($accessToken !== '') {
            $this->secrets->set(self::PLUGIN_ID, 'account:' . $uuid . ':access_token', $accessToken);
        }
        if ($refreshToken !== '') {
            $this->secrets->set(self::PLUGIN_ID, 'account:' . $uuid . ':refresh_token', $refreshToken);
        }
        $expiresAt = $expiresIn !== null && $expiresIn > 0 ? gmdate('c', time() + max(60, $expiresIn - 60)) : ($account['access_token_expires_at'] ?? null);
        $this->pdo->prepare('UPDATE mail_accounts SET access_token_expires_at = :expires, status = "connected", last_error = NULL, updated_at = :updated WHERE id = :id')
            ->execute([':id' => (int) $account['id'], ':expires' => $expiresAt, ':updated' => gmdate('c')]);
    }

    /** @param array<string,mixed> $account */
    public function accessToken(array $account): string
    {
        return (string) ($this->secrets->get(self::PLUGIN_ID, 'account:' . (string) $account['uuid'] . ':access_token') ?? '');
    }

    /** @param array<string,mixed> $account */
    public function refreshToken(array $account): string
    {
        return (string) ($this->secrets->get(self::PLUGIN_ID, 'account:' . (string) $account['uuid'] . ':refresh_token') ?? '');
    }

    public function disconnectAccount(int $id): void
    {
        $this->pdo->prepare('UPDATE mail_accounts SET status = "disconnected", updated_at = :updated WHERE id = :id')
            ->execute([':id' => $id, ':updated' => gmdate('c')]);
    }

    public function recordAccountError(int $id, string $message): void
    {
        $this->pdo->prepare('UPDATE mail_accounts SET status = "error", last_error = :error, updated_at = :updated WHERE id = :id')
            ->execute([':id' => $id, ':error' => $this->redact($message), ':updated' => gmdate('c')]);
    }

    /** @param list<array<string,mixed>> $messages */
    public function cacheMessages(int $accountId, array $messages): void
    {
        $now = gmdate('c');
        foreach ($messages as $message) {
            $remoteId = trim((string) ($message['id'] ?? ''));
            if ($remoteId === '') {
                continue;
            }
            $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $params = [
                ':account_id' => $accountId,
                ':remote_id' => $remoteId,
                ':thread_id' => (string) ($message['thread_id'] ?? ''),
                ':folder' => (string) ($message['folder'] ?? 'inbox'),
                ':sender_name' => (string) ($message['sender_name'] ?? ''),
                ':sender_email' => (string) ($message['sender_email'] ?? ''),
                ':subject' => (string) ($message['subject'] ?? ''),
                ':snippet' => (string) ($message['snippet'] ?? ''),
                ':received_at' => (string) ($message['received_at'] ?? ''),
                ':is_read' => !empty($message['is_read']) ? 1 : 0,
                ':has_attachments' => !empty($message['has_attachments']) ? 1 : 0,
                ':payload_json' => $payload,
                ':cached_at' => $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ];
            try {
                $this->pdo->prepare('INSERT INTO mail_messages (account_id, remote_id, thread_id, folder, sender_name, sender_email, subject, snippet, received_at, is_read, has_attachments, payload_json, cached_at, created_at, updated_at)
                    VALUES (:account_id, :remote_id, :thread_id, :folder, :sender_name, :sender_email, :subject, :snippet, :received_at, :is_read, :has_attachments, :payload_json, :cached_at, :created_at, :updated_at)')
                    ->execute($params);
            } catch (\Throwable) {
                $this->pdo->prepare('UPDATE mail_messages SET thread_id = :thread_id, folder = :folder, sender_name = :sender_name, sender_email = :sender_email, subject = :subject, snippet = :snippet, received_at = :received_at, is_read = :is_read, has_attachments = :has_attachments, payload_json = :payload_json, cached_at = :cached_at, updated_at = :updated_at WHERE account_id = :account_id AND remote_id = :remote_id')
                    ->execute($params);
            }
        }
        $this->pdo->prepare('UPDATE mail_accounts SET last_sync_at = :synced, updated_at = :updated WHERE id = :id')
            ->execute([':id' => $accountId, ':synced' => $now, ':updated' => $now]);
    }

    /** @return list<array<string,mixed>> */
    public function cachedMessages(int $accountId, string $search = '', int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        if ($search !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM mail_messages WHERE account_id = :account_id AND (subject LIKE :q OR sender_email LIKE :q OR snippet LIKE :q) ORDER BY received_at DESC, id DESC LIMIT :limit');
            $stmt->bindValue(':q', '%' . $search . '%');
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM mail_messages WHERE account_id = :account_id ORDER BY received_at DESC, id DESC LIMIT :limit');
        }
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param list<mixed>|string|null $value @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn (string $item): bool => $item !== ''));
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('Mail provider is not supported.');
        }
    }

    private function assertSafeRedirectUri(string $uri): void
    {
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('OAuth redirect URI must be an HTTPS URL.');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function redact(string $message): string
    {
        return preg_replace('/(access_token|refresh_token|client_secret|Authorization|Bearer)\s*[=:]\s*[^&\s]+/i', '$1=[redacted]', $message) ?? 'Mail provider failed.';
    }
}
