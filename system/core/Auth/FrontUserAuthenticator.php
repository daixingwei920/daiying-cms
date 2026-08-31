<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Security\PasswordHasher;
use Cms\Core\Security\SessionManager;
use PDO;

final class FrontUserAuthenticator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(string $email, string $password, string $displayName): int
    {
        $email = strtolower(trim($email));
        $displayName = $this->cleanDisplayName($displayName);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('邮箱格式不正确。');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('密码至少需要 8 位。');
        }
        if ($displayName === '') {
            throw new \InvalidArgumentException('昵称不能为空。');
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_front_users (email, password_hash, display_name, status, created_at, updated_at)
             VALUES (:email, :password_hash, :display_name, :status, :created_at, :updated_at)'
        );
        try {
            $stmt->execute([
                ':email' => $email,
                ':password_hash' => PasswordHasher::hash($password),
                ':display_name' => $displayName,
                ':status' => 'active',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            throw new \InvalidArgumentException('这个邮箱已经注册。', 0, $exception);
        }

        $id = (int) $this->pdo->lastInsertId();
        $this->loginUser(['id' => $id, 'email' => $email, 'display_name' => $displayName]);

        return $id;
    }

    public function attempt(string $email, string $password, string $ip): bool
    {
        $email = strtolower(trim($email));
        if ($this->isRateLimited($email, $ip)) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT id, email, password_hash, display_name, status FROM cms_front_users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        $ok = is_array($user)
            && (string) ($user['status'] ?? '') === 'active'
            && PasswordHasher::verify($password, (string) ($user['password_hash'] ?? ''));
        $this->recordAttempt($email, $ip, $ok);
        if (!$ok || !is_array($user)) {
            return false;
        }

        $this->loginUser([
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
        ]);
        $stmt = $this->pdo->prepare('UPDATE cms_front_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':id' => (int) $user['id'], ':last_login_at' => gmdate('c'), ':updated_at' => gmdate('c')]);

        return true;
    }

    /** @param array{id:int,email:string,display_name:string} $user */
    public function loginUser(array $user): void
    {
        SessionManager::regenerate();
        $_SESSION['front_user'] = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
        ];
    }

    public function logout(): void
    {
        unset($_SESSION['front_user']);
        SessionManager::regenerate();
    }

    /** @return array{id:int,email:string,display_name:string}|null */
    public function user(): ?array
    {
        $user = $_SESSION['front_user'] ?? null;
        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) ($user['email'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
        ];
    }

    private function cleanDisplayName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?: '');
        return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
    }

    private function isRateLimited(string $email, string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_front_login_attempts
             WHERE email = :email AND ip_address = :ip AND success = 0 AND attempted_at >= :since'
        );
        $stmt->execute([':email' => $email, ':ip' => $ip, ':since' => gmdate('c', time() - 900)]);

        return (int) $stmt->fetchColumn() >= 5;
    }

    private function recordAttempt(string $email, string $ip, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_front_login_attempts (email, ip_address, success, attempted_at)
             VALUES (:email, :ip, :success, :attempted_at)'
        );
        $stmt->execute([':email' => $email, ':ip' => $ip, ':success' => $success ? 1 : 0, ':attempted_at' => gmdate('c')]);
    }
}
