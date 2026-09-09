<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;

final class MailOAuthService
{
    public function __construct(private readonly MailAccountRepository $repository, private readonly MailHttpClient $http)
    {
    }

    /** @return list<string> */
    public static function defaultScopes(string $provider): array
    {
        return match ($provider) {
            'gmail' => [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/gmail.modify',
                'https://www.googleapis.com/auth/gmail.send',
            ],
            'outlook' => [
                'openid',
                'email',
                'profile',
                'offline_access',
                'User.Read',
                'Mail.ReadWrite',
                'Mail.Send',
            ],
            default => [],
        };
    }

    /** @return array{url:string,state:string} */
    public function authorizationUrl(string $provider, string $callbackBase): array
    {
        $config = $this->enabledConfig($provider, $callbackBase);
        $state = bin2hex(random_bytes(24));
        $_SESSION['official_mail_oauth_state'] = [
            'state' => $state,
            'provider' => $provider,
            'created_at' => time(),
        ];
        $query = [
            'client_id' => (string) $config['client_id'],
            'redirect_uri' => (string) $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', self::defaultScopes($provider)),
            'state' => $state,
        ];
        if ($provider === 'gmail') {
            $query['access_type'] = 'offline';
            $query['prompt'] = 'consent';
            $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($query);
            $this->http->assertAllowedUrl($url);

            return ['url' => $url, 'state' => $state];
        }
        $tenant = (string) ($config['tenant'] ?? 'common');
        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . http_build_query($query);
        $this->http->assertAllowedUrl($url);

        return ['url' => $url, 'state' => $state];
    }

    /** @return array<string,mixed> */
    public function completeAuthorization(string $provider, string $code, string $state, string $callbackBase): array
    {
        $saved = $_SESSION['official_mail_oauth_state'] ?? null;
        if (!is_array($saved) || (string) ($saved['state'] ?? '') !== $state || (string) ($saved['provider'] ?? '') !== $provider || (time() - (int) ($saved['created_at'] ?? 0)) > 900) {
            throw new RuntimeException('OAuth state is invalid or expired.');
        }
        unset($_SESSION['official_mail_oauth_state']);
        $config = $this->enabledConfig($provider, $callbackBase);
        $tokens = $this->tokenRequest($provider, [
            'client_id' => (string) $config['client_id'],
            'client_secret' => $this->repository->clientSecret($provider),
            'code' => $code,
            'redirect_uri' => (string) $config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ], $config);
        $access = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new RuntimeException('OAuth provider did not return the required tokens.');
        }
        $profile = $this->profile($provider, $access);

        return $this->repository->upsertAccount($provider, $profile, self::defaultScopes($provider), $access, $refresh, isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : null);
    }

    /** @param array<string,mixed> $account */
    public function accessToken(array $account): string
    {
        $expires = strtotime((string) ($account['access_token_expires_at'] ?? ''));
        $token = $this->repository->accessToken($account);
        if ($token !== '' && $expires !== false && $expires > time() + 120) {
            return $token;
        }
        $refresh = $this->repository->refreshToken($account);
        if ($refresh === '') {
            throw new RuntimeException('Mail account authorization expired. Please reconnect.');
        }
        $provider = (string) $account['provider'];
        $config = $this->enabledConfig($provider, '');
        $tokens = $this->tokenRequest($provider, [
            'client_id' => (string) $config['client_id'],
            'client_secret' => $this->repository->clientSecret($provider),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ], $config);
        $access = (string) ($tokens['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException('OAuth refresh did not return an access token.');
        }
        $this->repository->saveTokens($account, $access, (string) ($tokens['refresh_token'] ?? ''), isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : null);

        return $access;
    }

    /** @return array<string,mixed> */
    public function enabledConfig(string $provider, string $callbackBase): array
    {
        $config = $this->repository->oauthConfig($provider);
        if (($config['status'] ?? 'disabled') !== 'enabled') {
            throw new RuntimeException('OAuth provider is not enabled.');
        }
        if (trim((string) ($config['client_id'] ?? '')) === '' || $this->repository->clientSecret($provider) === '') {
            throw new RuntimeException('OAuth client id and secret are required.');
        }
        if (trim((string) ($config['redirect_uri'] ?? '')) === '') {
            if ($callbackBase === '') {
                throw new RuntimeException('OAuth redirect URI is not configured.');
            }
            $config['redirect_uri'] = rtrim($callbackBase, '/') . '/admin/mail/oauth/callback?provider=' . rawurlencode($provider);
        }

        return $config;
    }

    /** @param array<string,string> $payload @param array<string,mixed> $config @return array<string,mixed> */
    private function tokenRequest(string $provider, array $payload, array $config): array
    {
        $url = $provider === 'gmail'
            ? 'https://oauth2.googleapis.com/token'
            : 'https://login.microsoftonline.com/' . rawurlencode((string) ($config['tenant'] ?? 'common')) . '/oauth2/v2.0/token';
        $response = $this->http->post($url, [], $payload);
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new RuntimeException('OAuth token request failed: HTTP ' . $response['status']);
        }

        return $data;
    }

    /** @return array{email:string,display_name:string} */
    private function profile(string $provider, string $accessToken): array
    {
        if ($provider === 'gmail') {
            $response = $this->http->get('https://gmail.googleapis.com/gmail/v1/users/me/profile', ['Authorization' => 'Bearer ' . $accessToken]);
            $data = json_decode($response['body'], true);
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
                throw new RuntimeException('Unable to read Gmail profile.');
            }

            return ['email' => (string) ($data['emailAddress'] ?? ''), 'display_name' => (string) ($data['emailAddress'] ?? '')];
        }
        $response = $this->http->get('https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName', ['Authorization' => 'Bearer ' . $accessToken]);
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new RuntimeException('Unable to read Outlook profile.');
        }
        $email = (string) (($data['mail'] ?? '') ?: ($data['userPrincipalName'] ?? ''));

        return ['email' => $email, 'display_name' => (string) ($data['displayName'] ?? $email)];
    }
}
