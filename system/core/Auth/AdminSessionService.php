<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class AdminSessionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordLogin(int $adminId, string $ip, string $userAgent, string $mfaMethod): void
    {
        $hash = $this->currentSessionHash();
        if ($adminId <= 0 || $hash === '') {
            return;
        }

        $now = gmdate('c');
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM cms_admin_sessions WHERE session_hash = :session_hash LIMIT 1'
            );
            $stmt->execute([':session_hash' => $hash]);
            if ($stmt->fetchColumn()) {
                $update = $this->pdo->prepare(
                    'UPDATE cms_admin_sessions SET admin_id = :admin_id, ip_address = :ip_address, user_agent = :user_agent, mfa_method = :mfa_method, last_seen_at = :last_seen_at, revoked_at = "" WHERE session_hash = :session_hash'
                );
                $update->execute([
                    ':admin_id' => $adminId,
                    ':ip_address' => $this->truncate($ip, 64),
                    ':user_agent' => $this->truncate($userAgent, 255),
                    ':mfa_method' => $this->truncate($mfaMethod, 32),
                    ':last_seen_at' => $now,
                    ':session_hash' => $hash,
                ]);
                return;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO cms_admin_sessions (admin_id, session_hash, ip_address, user_agent, mfa_method, created_at, last_seen_at, revoked_at)
                 VALUES (:admin_id, :session_hash, :ip_address, :user_agent, :mfa_method, :created_at, :last_seen_at, "")'
            );
            $insert->execute([
                ':admin_id' => $adminId,
                ':session_hash' => $hash,
                ':ip_address' => $this->truncate($ip, 64),
                ':user_agent' => $this->truncate($userAgent, 255),
                ':mfa_method' => $this->truncate($mfaMethod, 32),
                ':created_at' => $now,
                ':last_seen_at' => $now,
            ]);
        } catch (Throwable) {
            return;
        }

        $_SESSION['admin_session_hash'] = $hash;
    }

    public function touchCurrent(int $adminId): bool
    {
        $hash = (string) ($_SESSION['admin_session_hash'] ?? $this->currentSessionHash());
        if ($adminId <= 0 || $hash === '') {
            return true;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT revoked_at FROM cms_admin_sessions WHERE admin_id = :admin_id AND session_hash = :session_hash LIMIT 1');
            $stmt->execute([':admin_id' => $adminId, ':session_hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return true;
            }
            if ((string) ($row['revoked_at'] ?? '') !== '') {
                return false;
            }
            $update = $this->pdo->prepare('UPDATE cms_admin_sessions SET last_seen_at = :last_seen_at WHERE admin_id = :admin_id AND session_hash = :session_hash');
            $update->execute([':last_seen_at' => gmdate('c'), ':admin_id' => $adminId, ':session_hash' => $hash]);
            return true;
        } catch (Throwable) {
            return true;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listForAdmin(int $adminId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, session_hash, ip_address, user_agent, mfa_method, created_at, last_seen_at, revoked_at FROM cms_admin_sessions WHERE admin_id = :admin_id ORDER BY last_seen_at DESC, id DESC LIMIT 20');
        $stmt->execute([':admin_id' => $adminId]);
        $current = (string) ($_SESSION['admin_session_hash'] ?? $this->currentSessionHash());
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['current'] = $current !== '' && hash_equals($current, (string) ($row['session_hash'] ?? ''));
            unset($row['session_hash']);
            $rows[] = $row;
        }
        return $rows;
    }

    public function revokeOtherSessions(int $adminId): int
    {
        $hash = (string) ($_SESSION['admin_session_hash'] ?? $this->currentSessionHash());
        $stmt = $this->pdo->prepare('UPDATE cms_admin_sessions SET revoked_at = :revoked_at WHERE admin_id = :admin_id AND session_hash <> :session_hash AND revoked_at = ""');
        $stmt->execute([':revoked_at' => gmdate('c'), ':admin_id' => $adminId, ':session_hash' => $hash]);
        return $stmt->rowCount();
    }

    public function revokeCurrent(int $adminId): void
    {
        $hash = (string) ($_SESSION['admin_session_hash'] ?? $this->currentSessionHash());
        if ($adminId <= 0 || $hash === '') {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE cms_admin_sessions SET revoked_at = :revoked_at WHERE admin_id = :admin_id AND session_hash = :session_hash');
        $stmt->execute([':revoked_at' => gmdate('c'), ':admin_id' => $adminId, ':session_hash' => $hash]);
    }

    public function markReauthenticated(int $adminId): void
    {
        $_SESSION['admin_reauthenticated_at'] = time();
        try {
            (new AuditLogger($this->pdo))->record('admin', $adminId, 'admin.reauthenticated');
        } catch (Throwable) {
        }
    }

    public function hasRecentReauthentication(int $ttlSeconds = 600): bool
    {
        $at = (int) ($_SESSION['admin_reauthenticated_at'] ?? 0);
        return $at > 0 && $at >= time() - $ttlSeconds;
    }

    private function currentSessionHash(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $id = session_id();
        return $id === '' ? '' : hash('sha256', $id);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
