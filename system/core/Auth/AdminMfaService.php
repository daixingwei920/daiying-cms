<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Security\PasswordHasher;
use PDO;

final class AdminMfaService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        for ($i = 0, $length = strlen($bits); $i < $length; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $secret .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $secret;
    }

    /** @return list<string> */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < max(1, min(20, $count)); $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    public static function totpCode(string $secret, ?int $time = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $counter = intdiv($time ?? time(), 30);
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::totpCode($secret, $now + ($i * 30)), $code)) {
                return true;
            }
        }

        return false;
    }

    public function isEnabled(int $adminId): bool
    {
        $this->ensureColumns();
        $stmt = $this->pdo->prepare('SELECT mfa_enabled_at, mfa_totp_secret FROM cms_admin_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && (string) ($row['mfa_enabled_at'] ?? '') !== '' && (string) ($row['mfa_totp_secret'] ?? '') !== '';
    }

    public function secretForAdmin(int $adminId): string
    {
        $this->ensureColumns();
        $stmt = $this->pdo->prepare('SELECT mfa_totp_secret FROM cms_admin_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $adminId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @param list<string> $recoveryCodes */
    public function enableTotp(int $adminId, string $secret, array $recoveryCodes): void
    {
        $this->ensureColumns();
        if (self::base32Decode($secret) === '') {
            throw new \InvalidArgumentException('MFA secret is invalid.');
        }
        $hashes = [];
        foreach ($recoveryCodes as $code) {
            $code = $this->normalizeRecoveryCode($code);
            if ($code !== '') {
                $hashes[] = PasswordHasher::hash($code);
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE cms_admin_users
             SET mfa_totp_secret = :secret, mfa_enabled_at = :enabled_at, mfa_recovery_codes_json = :codes, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $adminId,
            ':secret' => strtoupper($secret),
            ':enabled_at' => gmdate('c'),
            ':codes' => json_encode($hashes, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate('c'),
        ]);
    }

    public function disable(int $adminId): void
    {
        $this->ensureColumns();
        $stmt = $this->pdo->prepare(
            "UPDATE cms_admin_users
             SET mfa_totp_secret = '', mfa_enabled_at = '', mfa_recovery_codes_json = '[]', updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([':id' => $adminId, ':updated_at' => gmdate('c')]);
    }

    public function verifyChallenge(int $adminId, string $code): bool
    {
        $this->ensureColumns();
        $secret = $this->secretForAdmin($adminId);
        if ($secret !== '' && self::verifyTotp($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($adminId, $code);
    }

    private function consumeRecoveryCode(int $adminId, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT mfa_recovery_codes_json FROM cms_admin_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $adminId]);
        $decoded = json_decode((string) ($stmt->fetchColumn() ?: '[]'), true);
        if (!is_array($decoded)) {
            return false;
        }
        $remaining = [];
        $matched = false;
        foreach ($decoded as $hash) {
            if (is_string($hash) && !$matched && PasswordHasher::verify($normalized, $hash)) {
                $matched = true;
                continue;
            }
            if (is_string($hash) && $hash !== '') {
                $remaining[] = $hash;
            }
        }
        if (!$matched) {
            return false;
        }
        $update = $this->pdo->prepare('UPDATE cms_admin_users SET mfa_recovery_codes_json = :codes, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':id' => $adminId,
            ':codes' => json_encode($remaining, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate('c'),
        ]);

        return true;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }
        $bits = '';
        for ($i = 0, $length = strlen($secret); $i < $length; $i++) {
            $value = strpos(self::BASE32_ALPHABET, $secret[$i]);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        for ($i = 0, $length = strlen($bits); $i + 8 <= $length; $i += 8) {
            $decoded .= chr(bindec(substr($bits, $i, 8)));
        }

        return $decoded;
    }

    private function ensureColumns(): void
    {
        $columns = $this->columns('cms_admin_users');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        foreach ([
            'mfa_totp_secret' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'mfa_enabled_at' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'mfa_recovery_codes_json' => $text . ' NULL',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $this->pdo->exec('ALTER TABLE cms_admin_users ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $this->pdo->exec("UPDATE cms_admin_users SET mfa_recovery_codes_json = '[]' WHERE mfa_recovery_codes_json IS NULL OR mfa_recovery_codes_json = ''");
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $stmt->execute([':table' => $table]);

            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $rows = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows);
    }
}
