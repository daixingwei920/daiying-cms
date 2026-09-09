<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Plugin\PluginSecretStore;
use PDO;
use RuntimeException;

final class MailRepository
{
    private const PLUGIN_ID = 'official.mail';
    private const SMTP_PASSWORD_KEY = 'smtp_password';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PluginSecretStore $secrets,
    ) {
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $this->ensureDefaultSettings();
        $row = $this->pdo->query('SELECT * FROM mail_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        $row['password_configured'] = $this->secrets->get(self::PLUGIN_ID, self::SMTP_PASSWORD_KEY) !== null;
        $row['password_masked'] = $row['password_configured'] ? (string) $this->secrets->masked(self::PLUGIN_ID, self::SMTP_PASSWORD_KEY) : '';

        return $row;
    }

    /** @param array<string,mixed> $input */
    public function saveSettings(array $input): void
    {
        $this->ensureDefaultSettings();
        $status = $this->choice((string) ($input['status'] ?? 'disabled'), ['enabled', 'disabled'], 'disabled');
        $encryption = $this->choice((string) ($input['encryption'] ?? 'starttls'), ['starttls', 'tls', 'ssl', 'none'], 'starttls');
        $authMode = $this->choice((string) ($input['auth_mode'] ?? 'auto'), ['auto', 'login', 'plain', 'none'], 'auto');
        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $port = max(1, min(65535, (int) ($input['port'] ?? 587)));
        $timeout = max(3, min(60, (int) ($input['timeout_seconds'] ?? 10)));
        $username = trim((string) ($input['username'] ?? ''));
        $fromEmail = strtolower(trim((string) ($input['from_email'] ?? '')));
        $fromName = $this->cleanHeaderValue((string) ($input['from_name'] ?? ''));
        $replyTo = strtolower(trim((string) ($input['reply_to'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($host !== '' && (!$this->validHost($host) || preg_match('/[\x00-\x20\x7F]/', $host) === 1)) {
            throw new RuntimeException('SMTP Host 格式无效。');
        }
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('发件邮箱格式无效。');
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('回复邮箱格式无效。');
        }
        if ($username !== '' && preg_match('/[\r\n]/', $username) === 1) {
            throw new RuntimeException('SMTP 用户名格式无效。');
        }
        if ($password !== '') {
            if (strlen($password) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password) === 1) {
                throw new RuntimeException('SMTP 密码格式无效。');
            }
            $this->secrets->set(self::PLUGIN_ID, self::SMTP_PASSWORD_KEY, $password);
        }

        $now = gmdate('c');
        $this->pdo->prepare('UPDATE mail_settings
            SET status = :status, transport = :transport, host = :host, port = :port, encryption = :encryption,
                username = :username, auth_mode = :auth_mode, from_email = :from_email, from_name = :from_name,
                reply_to = :reply_to, timeout_seconds = :timeout, updated_at = :updated_at
            WHERE id = (SELECT id FROM mail_settings ORDER BY id ASC LIMIT 1)')
            ->execute([
                ':status' => $status,
                ':transport' => 'smtp',
                ':host' => $host,
                ':port' => $port,
                ':encryption' => $encryption,
                ':username' => $username,
                ':auth_mode' => $authMode,
                ':from_email' => $fromEmail,
                ':from_name' => $fromName,
                ':reply_to' => $replyTo,
                ':timeout' => $timeout,
                ':updated_at' => $now,
            ]);
    }

    public function smtpPassword(): string
    {
        return (string) ($this->secrets->get(self::PLUGIN_ID, self::SMTP_PASSWORD_KEY) ?? '');
    }

    public function recordTest(string $status, string $message): void
    {
        $this->ensureDefaultSettings();
        $safe = $this->redact($message);
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE mail_settings
            SET last_test_status = :status, last_test_message = :message, last_tested_at = :tested_at, updated_at = :updated_at
            WHERE id = (SELECT id FROM mail_settings ORDER BY id ASC LIMIT 1)')
            ->execute([
                ':status' => $this->choice($status, ['success', 'failed'], 'failed'),
                ':message' => mb_substr($safe, 0, 500),
                ':tested_at' => $now,
                ':updated_at' => $now,
            ]);
    }

    /** @param array<string,mixed> $message */
    public function createMessage(array $message): int
    {
        $now = gmdate('c');
        $uuid = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO mail_messages
            (uuid, recipient_email, recipient_name, subject, status, transport, error_message, provider_message_id, body_hash, sent_at, created_at, updated_at)
            VALUES (:uuid, :recipient_email, :recipient_name, :subject, :status, :transport, :error_message, :provider_message_id, :body_hash, :sent_at, :created_at, :updated_at)')
            ->execute([
                ':uuid' => $uuid,
                ':recipient_email' => strtolower((string) ($message['to_email'] ?? '')),
                ':recipient_name' => mb_substr($this->cleanHeaderValue((string) ($message['to_name'] ?? '')), 0, 191),
                ':subject' => mb_substr($this->cleanHeaderValue((string) ($message['subject'] ?? '')), 0, 255),
                ':status' => (string) ($message['status'] ?? 'queued'),
                ':transport' => 'smtp',
                ':error_message' => $message['error_message'] ?? null,
                ':provider_message_id' => $message['provider_message_id'] ?? null,
                ':body_hash' => hash('sha256', (string) ($message['body_text'] ?? '') . "\n" . (string) ($message['body_html'] ?? '')),
                ':sent_at' => $message['sent_at'] ?? null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markMessageSent(int $id, string $providerMessageId = ''): void
    {
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE mail_messages
            SET status = :status, provider_message_id = :provider_message_id, error_message = NULL, sent_at = :sent_at, updated_at = :updated_at
            WHERE id = :id')
            ->execute([
                ':id' => $id,
                ':status' => 'sent',
                ':provider_message_id' => $providerMessageId !== '' ? mb_substr($providerMessageId, 0, 255) : null,
                ':sent_at' => $now,
                ':updated_at' => $now,
            ]);
    }

    public function markMessageFailed(int $id, string $error): void
    {
        $this->pdo->prepare('UPDATE mail_messages SET status = :status, error_message = :error, updated_at = :updated_at WHERE id = :id')
            ->execute([
                ':id' => $id,
                ':status' => 'failed',
                ':error' => mb_substr($this->redact($error), 0, 500),
                ':updated_at' => gmdate('c'),
            ]);
    }

    /** @return list<array<string,mixed>> */
    public function recentMessages(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT id, recipient_email, recipient_name, subject, status, transport, error_message, provider_message_id, sent_at, created_at FROM mail_messages ORDER BY created_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function redact(string $value): string
    {
        return preg_replace('/(?:bearer\s+|smtp[_-]?pass(?:word)?=|password=|api[_-]?key=|secret=|token=|authorization=)[^\s"\']*/i', '$1[redacted]', $value) ?: $value;
    }

    private function ensureDefaultSettings(): void
    {
        $exists = $this->pdo->query('SELECT COUNT(*) FROM mail_settings')->fetchColumn();
        if ((int) $exists > 0) {
            return;
        }
        $now = gmdate('c');
        $this->pdo->prepare('INSERT INTO mail_settings (status, transport, host, port, encryption, username, auth_mode, from_email, from_name, reply_to, timeout_seconds, created_at, updated_at)
            VALUES (:status, :transport, :host, :port, :encryption, :username, :auth_mode, :from_email, :from_name, :reply_to, :timeout, :created_at, :updated_at)')
            ->execute([
                ':status' => 'disabled',
                ':transport' => 'smtp',
                ':host' => '',
                ':port' => 587,
                ':encryption' => 'starttls',
                ':username' => '',
                ':auth_mode' => 'auto',
                ':from_email' => '',
                ':from_name' => '',
                ':reply_to' => '',
                ':timeout' => 10,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
    }

    /** @param list<string> $allowed */
    private function choice(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    private function cleanHeaderValue(string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $value) ?: '');
    }
}
