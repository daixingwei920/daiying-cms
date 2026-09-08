<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

use PDO;

final class SiteMailSettingsRepository
{
    private const TABLE = 'cms_core_mail_settings';

    public function __construct(private readonly PDO $pdo, private readonly string $encryptionKey)
    {
        $this->ensureSchema();
        $this->ensureDefaultRow();
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        $row = $this->row();
        $passwordConfigured = (string) ($row['smtp_password_ciphertext'] ?? '') !== '';

        return [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'provider_id' => (string) ($row['provider_id'] ?? 'smtp'),
            'smtp_host' => (string) ($row['smtp_host'] ?? ''),
            'smtp_port' => (int) ($row['smtp_port'] ?? 587),
            'smtp_encryption' => (string) ($row['smtp_encryption'] ?? 'tls'),
            'smtp_username' => (string) ($row['smtp_username'] ?? ''),
            'smtp_password_configured' => $passwordConfigured,
            'smtp_password_masked' => $passwordConfigured ? '********' : '',
            'from_name' => (string) ($row['from_name'] ?? ''),
            'from_email' => (string) ($row['from_email'] ?? ''),
            'reply_to' => (string) ($row['reply_to'] ?? ''),
            'timeout_seconds' => (int) ($row['timeout_seconds'] ?? 20),
            'queue_enabled' => (int) ($row['queue_enabled'] ?? 1) === 1,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function runtimeConfig(): array
    {
        $row = $this->row();
        $config = $this->current();
        $config['smtp_password'] = $this->decrypt((string) ($row['smtp_password_ciphertext'] ?? ''));

        return $config;
    }

    /** @param array<string,mixed> $config */
    public function save(array $config, string $smtpPassword = '', bool $clearPassword = false): void
    {
        $existing = $this->row();
        $ciphertext = (string) ($existing['smtp_password_ciphertext'] ?? '');
        if ($clearPassword) {
            $ciphertext = '';
        } elseif ($smtpPassword !== '') {
            $ciphertext = $this->encrypt($smtpPassword);
        } elseif ($ciphertext !== '') {
            $this->decrypt($ciphertext);
        }
        $providerId = (string) ($config['provider_id'] ?? 'smtp');
        if (!preg_match('/^[a-z0-9._-]{2,120}$/', $providerId)) {
            throw new MailException('Mail provider id is invalid.');
        }
        $this->assertPublicConfig($config);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET enabled = :enabled, provider_id = :provider_id, smtp_host = :smtp_host, smtp_port = :smtp_port,
                 smtp_encryption = :smtp_encryption, smtp_username = :smtp_username,
                 smtp_password_ciphertext = :smtp_password_ciphertext, from_name = :from_name,
                 from_email = :from_email, reply_to = :reply_to, timeout_seconds = :timeout_seconds,
                 queue_enabled = :queue_enabled, updated_at = :updated_at
             WHERE id = 1'
        )->execute([
            ':enabled' => !empty($config['enabled']) ? 1 : 0,
            ':provider_id' => $providerId,
            ':smtp_host' => (string) ($config['smtp_host'] ?? ''),
            ':smtp_port' => (int) ($config['smtp_port'] ?? 587),
            ':smtp_encryption' => (string) ($config['smtp_encryption'] ?? 'tls'),
            ':smtp_username' => (string) ($config['smtp_username'] ?? ''),
            ':smtp_password_ciphertext' => $ciphertext,
            ':from_name' => (string) ($config['from_name'] ?? ''),
            ':from_email' => (string) ($config['from_email'] ?? ''),
            ':reply_to' => (string) ($config['reply_to'] ?? ''),
            ':timeout_seconds' => (int) ($config['timeout_seconds'] ?? 20),
            ':queue_enabled' => !empty($config['queue_enabled']) ? 1 : 0,
            ':updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $config */
    public static function addressFromConfig(array $config): MailAddress
    {
        return new MailAddress((string) ($config['from_email'] ?? ''), (string) ($config['from_name'] ?? ''));
    }

    private function ensureSchema(): void
    {
        $longText = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 0,
                provider_id VARCHAR(120) NOT NULL DEFAULT "smtp",
                smtp_host VARCHAR(255) NOT NULL DEFAULT "",
                smtp_port INTEGER NOT NULL DEFAULT 587,
                smtp_encryption VARCHAR(16) NOT NULL DEFAULT "tls",
                smtp_username VARCHAR(191) NOT NULL DEFAULT "",
                smtp_password_ciphertext ' . $longText . ' NULL,
                from_name VARCHAR(191) NOT NULL DEFAULT "",
                from_email VARCHAR(191) NOT NULL DEFAULT "",
                reply_to VARCHAR(191) NOT NULL DEFAULT "",
                timeout_seconds INTEGER NOT NULL DEFAULT 20,
                queue_enabled INTEGER NOT NULL DEFAULT 1,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        foreach ($this->expectedColumns($longText) as $column => $definition) {
            if (!in_array($column, $this->columns(self::TABLE), true)) {
                $this->pdo->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
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
             (id, enabled, provider_id, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_ciphertext, from_name, from_email, reply_to, timeout_seconds, queue_enabled, created_at, updated_at)
             VALUES (1, 0, "smtp", "", 587, "tls", "", "", "", "", "", 20, 1, :created_at, :updated_at)'
        )->execute([':created_at' => $now, ':updated_at' => $now]);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /** @return array<string,string> */
    private function expectedColumns(string $longText): array
    {
        return [
            'enabled' => 'INTEGER NOT NULL DEFAULT 0',
            'provider_id' => 'VARCHAR(120) NOT NULL DEFAULT "smtp"',
            'smtp_host' => 'VARCHAR(255) NOT NULL DEFAULT ""',
            'smtp_port' => 'INTEGER NOT NULL DEFAULT 587',
            'smtp_encryption' => 'VARCHAR(16) NOT NULL DEFAULT "tls"',
            'smtp_username' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'smtp_password_ciphertext' => $longText . ' NULL',
            'from_name' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'from_email' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'reply_to' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'timeout_seconds' => 'INTEGER NOT NULL DEFAULT 20',
            'queue_enabled' => 'INTEGER NOT NULL DEFAULT 1',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'updated_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ];
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC));
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $config */
    private function assertPublicConfig(array $config): void
    {
        $host = (string) ($config['smtp_host'] ?? '');
        if ($host !== '' && (strlen($host) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $host) === 1)) {
            throw new MailException('SMTP host is invalid.');
        }
        $port = (int) ($config['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            throw new MailException('SMTP port is invalid.');
        }
        if (!in_array((string) ($config['smtp_encryption'] ?? 'tls'), ['none', 'tls', 'ssl'], true)) {
            throw new MailException('SMTP encryption mode is invalid.');
        }
        foreach (['from_email', 'reply_to'] as $key) {
            $email = (string) ($config[$key] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new MailException('Mail address is invalid.');
            }
        }
        foreach (['smtp_username', 'from_name'] as $key) {
            $value = (string) ($config[$key] ?? '');
            if (strlen($value) > 191 || preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1) {
                throw new MailException('Mail setting value is invalid.');
            }
        }
    }

    private function encrypt(string $value): string
    {
        if ($this->encryptionKey === '') {
            throw new MailException('Mail secret encryption key is not configured.');
        }
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', $this->encryptionKey, true), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($cipher)) {
            throw new MailException('Unable to encrypt mail secret.');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    private function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 28) {
            throw new MailException('Mail secret ciphertext is invalid.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', $this->encryptionKey, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if (!is_string($plain)) {
            throw new MailException('Unable to decrypt mail secret.');
        }

        return $plain;
    }
}
