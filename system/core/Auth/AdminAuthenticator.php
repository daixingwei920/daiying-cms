<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Security\PasswordHasher;
use Cms\Core\Security\SessionManager;
use PDO;

final class AdminAuthenticator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createAdmin(string $email, string $password, string $displayName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_admin_users (email, password_hash, display_name, created_at, updated_at)
             VALUES (:email, :password_hash, :display_name, :created_at, :updated_at)'
        );
        $now = gmdate('c');
        $stmt->execute([
            ':email' => strtolower($email),
            ':password_hash' => PasswordHasher::hash($password),
            ':display_name' => $displayName,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function attempt(string $email, string $password, string $ip): bool
    {
        $user = $this->verifyCredentials($email, $password, $ip);
        if ($user === null) {
            return false;
        }

        $this->loginUser($user);

        return true;
    }

    /** @return array{id:int,email:string,display_name:string}|null */
    public function verifyCredentials(string $email, string $password, string $ip): ?array
    {
        if ($this->isRateLimited($email, $ip)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, email, password_hash, display_name FROM cms_admin_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        $ok = is_array($user) && PasswordHasher::verify($password, (string) $user['password_hash']);
        $this->recordAttempt($email, $ip, $ok);

        if (!$ok || !is_array($user)) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
        ];
    }

    /** @param array{id:int,email:string,display_name:string} $user */
    public function loginUser(array $user): void
    {
        SessionManager::regenerate();
        unset($_SESSION['admin_mfa_pending']);
        $_SESSION['admin_user'] = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
        ];
    }

    public function logout(): void
    {
        unset($_SESSION['admin_user'], $_SESSION['admin_mfa_pending'], $_SESSION['admin_mfa_setup']);
        SessionManager::regenerate();
    }

    /** @return array{id:int,email:string,display_name:string}|null */
    public function user(): ?array
    {
        $user = $_SESSION['admin_user'] ?? null;
        if (!is_array($user)) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
        ];
    }

    private function isRateLimited(string $email, string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS count_failed FROM cms_login_attempts
             WHERE email = :email AND ip_address = :ip AND success = 0 AND attempted_at >= :since'
        );
        $stmt->execute([
            ':email' => strtolower($email),
            ':ip' => $ip,
            ':since' => gmdate('c', time() - 900),
        ]);

        $row = $stmt->fetch();

        return is_array($row) && (int) $row['count_failed'] >= 5;
    }

    private function recordAttempt(string $email, string $ip, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_login_attempts (email, ip_address, success, attempted_at)
             VALUES (:email, :ip, :success, :attempted_at)'
        );
        $stmt->execute([
            ':email' => strtolower($email),
            ':ip' => $ip,
            ':success' => $success ? 1 : 0,
            ':attempted_at' => gmdate('c'),
        ]);
    }
}
