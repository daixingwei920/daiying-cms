<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Security\PasswordHasher;
use PDO;

final class AdminAccountRecoveryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function requestReset(string $email, string $ip): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_admin_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => strtolower(trim($email))]);
        $adminId = (int) ($stmt->fetchColumn() ?: 0);
        if ($adminId <= 0) {
            return null;
        }

        $token = AdminPasskeyService::b64(random_bytes(32));
        $now = gmdate('c');
        $insert = $this->pdo->prepare(
            'INSERT INTO cms_admin_password_resets (admin_id, token_hash, requested_ip, used_at, expires_at, created_at)
             VALUES (:admin_id, :token_hash, :requested_ip, "", :expires_at, :created_at)'
        );
        $insert->execute([
            ':admin_id' => $adminId,
            ':token_hash' => hash('sha256', $token),
            ':requested_ip' => mb_substr($ip, 0, 64),
            ':expires_at' => gmdate('c', time() + 1800),
            ':created_at' => $now,
        ]);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'admin.password_reset_requested', ['ip' => mb_substr($ip, 0, 64)]);

        return $token;
    }

    public function resetPassword(string $token, string $newPassword, string $ip): bool
    {
        if (strlen($newPassword) < 10 || trim($token) === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, admin_id, expires_at, used_at FROM cms_admin_password_resets
             WHERE token_hash = :token_hash LIMIT 1'
        );
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) ($row['used_at'] ?? '') !== '' || strtotime((string) $row['expires_at']) < time()) {
            return false;
        }

        $adminId = (int) $row['admin_id'];
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE cms_admin_users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
            $update->execute([
                ':password_hash' => PasswordHasher::hash($newPassword),
                ':updated_at' => gmdate('c'),
                ':id' => $adminId,
            ]);
            $consume = $this->pdo->prepare('UPDATE cms_admin_password_resets SET used_at = :used_at WHERE id = :id');
            $consume->execute([':used_at' => gmdate('c'), ':id' => (int) $row['id']]);
            $sessions = $this->pdo->prepare('UPDATE cms_admin_sessions SET revoked_at = :revoked_at WHERE admin_id = :admin_id AND revoked_at = ""');
            $sessions->execute([':revoked_at' => gmdate('c'), ':admin_id' => $adminId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        (new AuditLogger($this->pdo))->record('admin', $adminId, 'admin.password_reset_completed', ['ip' => mb_substr($ip, 0, 64), 'sessions_revoked' => true]);
        return true;
    }
}
