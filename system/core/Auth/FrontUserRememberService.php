<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Security\SessionManager;
use PDO;
use RuntimeException;

final class FrontUserRememberService
{
    public const COOKIE = 'cms_front_remember';
    // Browsers require a finite cookie expiry; active use renews it before that date.
    public const LIFETIME = 365 * 86400;
    private const RENEW_WINDOW = 30 * 86400;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issue(int $userId): void
    {
        if (headers_sent()) {
            throw new RuntimeException('Cannot set member remember cookie after headers were sent.');
        }
        $this->clear(false);
        $expired = $this->pdo->prepare('DELETE FROM cms_front_remember_tokens WHERE expires_at <= :now');
        $expired->execute([':now' => time()]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = time() + self::LIFETIME;
        $stmt = $this->pdo->prepare('INSERT INTO cms_front_remember_tokens (token_hash, user_id, expires_at, created_at) VALUES (:token_hash, :user_id, :expires_at, :created_at)');
        $stmt->execute([':token_hash' => hash('sha256', $token), ':user_id' => $userId, ':expires_at' => $expires, ':created_at' => gmdate('c')]);
        if (!$this->cookie($token, $expires)) {
            $this->revoke($token);
            throw new RuntimeException('Cannot set member remember cookie.');
        }
        $_COOKIE[self::COOKIE] = $token;
    }

    /** @return array{id:int,email:string,display_name:string}|null */
    public function restore(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $token = is_string($_COOKIE[self::COOKIE] ?? null) ? $_COOKIE[self::COOKIE] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT u.id, u.email, u.display_name, u.status, t.expires_at FROM cms_front_remember_tokens t JOIN cms_front_users u ON u.id = t.user_id WHERE t.token_hash = :token_hash LIMIT 1');
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int) $row['expires_at'] <= time() || (string) $row['status'] !== 'active') {
            $this->clear();
            return null;
        }
        SessionManager::regenerate();
        $_SESSION['front_user'] = [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
        ];
        $this->renewIfNeeded();
        return $_SESSION['front_user'];
    }

    public function renewIfNeeded(): void
    {
        if (headers_sent()) {
            return;
        }
        $token = is_string($_COOKIE[self::COOKIE] ?? null) ? $_COOKIE[self::COOKIE] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return;
        }
        if ((int) ($_SESSION['front_remember_checked_at'] ?? 0) > time() - 86400) {
            return;
        }
        $_SESSION['front_remember_checked_at'] = time();
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare('SELECT expires_at FROM cms_front_remember_tokens WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute([':token_hash' => $hash]);
        $expires = $stmt->fetchColumn();
        if ($expires === false) {
            $this->clear();
            return;
        }
        if ((int) $expires <= time()) {
            $this->clear();
            return;
        }
        if ((int) $expires > time() + self::RENEW_WINDOW) {
            return;
        }
        $newExpires = time() + self::LIFETIME;
        $update = $this->pdo->prepare('UPDATE cms_front_remember_tokens SET expires_at = :expires_at WHERE token_hash = :token_hash');
        $update->execute([':expires_at' => $newExpires, ':token_hash' => $hash]);
        $this->cookie($token, $newExpires);
    }

    public function clear(bool $expireCookie = true): void
    {
        $token = is_string($_COOKIE[self::COOKIE] ?? null) ? $_COOKIE[self::COOKIE] : '';
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            $this->revoke($token);
        }
        if ($expireCookie && !headers_sent()) {
            $this->cookie('', time() - 3600);
        }
        unset($_COOKIE[self::COOKIE]);
        unset($_SESSION['front_remember_checked_at']);
    }

    private function revoke(string $token): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_front_remember_tokens WHERE token_hash = :token_hash');
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
    }

    private function cookie(string $value, int $expires): bool
    {
        $params = session_get_cookie_params();
        return setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
