<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Security\PasswordHasher;
use PDO;

final class FrontUserService
{
    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly PDO $pdo,
        private readonly FrontUserAuthenticator $authenticator,
        private readonly EventDispatcher $events,
    ) {
    }

    /** @return array{id:int,email:string,display_name:string}|null */
    public function current(): ?array
    {
        $this->assertCapability('auth.read');

        return $this->authenticator->user();
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        $this->assertCapability('auth.read');
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, email, display_name, status, created_at, updated_at, last_login_at FROM cms_front_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->publicUser($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $this->assertCapability('auth.read');
        $stmt = $this->pdo->prepare('SELECT id, email, display_name, status, created_at, updated_at, last_login_at FROM cms_front_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->publicUser($row) : null;
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    public function verifyCredentials(string $email, string $password, array $context = []): ?array
    {
        $this->assertCapability('auth.read');
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash, display_name, status, created_at, updated_at, last_login_at FROM cms_front_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!is_array($user) || (string) ($user['status'] ?? '') !== 'active') {
            return null;
        }
        if (!PasswordHasher::verify($password, (string) ($user['password_hash'] ?? ''))) {
            return null;
        }

        return $this->publicUser($user);
    }

    /** @param array<string,mixed> $context */
    public function loginById(int $userId, array $context = []): void
    {
        $this->assertCapability('auth.login');
        $this->assertTrustedLoginGrant();
        $user = $this->findForLogin($userId);
        if ($user === null) {
            throw new PluginException('Front user not found or inactive.');
        }

        $this->authenticator->loginUser([
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
        ]);
        $this->pdo->prepare('UPDATE cms_front_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => (int) $user['id'], ':last_login_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
        $provider = $this->cleanProvider((string) ($context['provider'] ?? 'plugin'));
        $subject = isset($context['external_subject']) ? $this->cleanSubject((string) $context['external_subject']) : null;
        $this->events->dispatch(new FrontUserLoggedInEvent((int) $user['id'], (string) $user['email'], $provider, $subject, gmdate('c')));
    }

    /** @return array<string,mixed>|null */
    public function findByExternalIdentity(string $provider, string $subject): ?array
    {
        $this->assertCapability('auth.external_identity');
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.status, u.created_at, u.updated_at, u.last_login_at
             FROM cms_front_user_external_identities e
             INNER JOIN cms_front_users u ON u.id = e.front_user_id
             WHERE e.provider = :provider AND e.subject = :subject LIMIT 1'
        );
        $stmt->execute([':provider' => $this->cleanProvider($provider), ':subject' => $this->cleanSubject($subject)]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->publicUser($row) : null;
    }

    /** @param array<string,mixed> $profile */
    public function bindExternalIdentity(int $userId, string $provider, string $subject, array $profile = []): void
    {
        $this->assertCapability('auth.external_identity');
        if ($this->findForLogin($userId) === null) {
            throw new PluginException('Front user not found or inactive.');
        }
        $provider = $this->cleanProvider($provider);
        $subject = $this->cleanSubject($subject);
        $now = gmdate('c');
        $profileJson = json_encode($this->safeProfile($profile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_front_user_external_identities (provider, subject, front_user_id, profile_json, created_at, updated_at)
             VALUES (:provider, :subject, :front_user_id, :profile_json, :created_at, :updated_at)'
        );
        try {
            $stmt->execute([
                ':provider' => $provider,
                ':subject' => $subject,
                ':front_user_id' => $userId,
                ':profile_json' => is_string($profileJson) ? $profileJson : '{}',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            throw new PluginException('External identity is already bound.', 0, $exception);
        }
    }

    public function unbindExternalIdentity(int $userId, string $provider, string $subject): void
    {
        $this->assertCapability('auth.external_identity');
        $stmt = $this->pdo->prepare('DELETE FROM cms_front_user_external_identities WHERE front_user_id = :front_user_id AND provider = :provider AND subject = :subject');
        $stmt->execute([
            ':front_user_id' => $userId,
            ':provider' => $this->cleanProvider($provider),
            ':subject' => $this->cleanSubject($subject),
        ]);
    }

    private function assertCapability(string $capability): void
    {
        if (!in_array($capability, $this->manifest->capabilities, true)) {
            throw new PluginException('Plugin does not declare ' . $capability . ' capability.');
        }
    }

    private function assertTrustedLoginGrant(): void
    {
        if ($this->manifest->trustLevel !== 'trusted_php' && !$this->manifest->bundled) {
            throw new PluginException('auth.login is restricted to trusted or bundled plugins.');
        }
    }

    /** @return array<string,mixed>|null */
    private function findForLogin(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, email, display_name, status FROM cms_front_users WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicUser(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'last_login_at' => isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        ];
    }

    private function cleanProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $provider) !== 1) {
            throw new PluginException('Invalid external identity provider.');
        }

        return $provider;
    }

    private function cleanSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '' || strlen($subject) > 191 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new PluginException('Invalid external identity subject.');
        }

        return $subject;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function safeProfile(array $profile): array
    {
        $allowed = [];
        foreach (['nickname', 'avatar_url', 'locale'] as $key) {
            if (isset($profile[$key]) && is_scalar($profile[$key])) {
                $allowed[$key] = substr(trim((string) $profile[$key]), 0, 500);
            }
        }

        return $allowed;
    }
}
