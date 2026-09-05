<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

final class BaiduApiClient
{
    public const ALLOWED_API_HOSTS = [
        'openapi.baidu.com',
        'pan.baidu.com',
        'd.pcs.baidu.com',
    ];

    private const DOWNLOAD_HOSTS = [
        'd.pcs.baidu.com',
        'pcs.baidu.com',
        'pan.baidu.com',
    ];

    public function __construct(
        private readonly BaiduTokenRepository $tokens,
        private readonly BaiduHttpTransport $transport,
        private readonly ?BaiduTokenRefreshLock $refreshLock = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->tokens->config();
        $url = 'https://openapi.baidu.com/oauth/2.0/token?' . http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) ($config['app_key'] ?? ''),
            'client_secret' => $this->tokens->appSecret(),
            'redirect_uri' => $redirectUri,
        ]);

        return $this->json($this->transport->request('GET', $url), '百度授权换取 Token 失败。');
    }

    /** @return array<string,mixed> */
    public function refreshAccessToken(bool $force = true): array
    {
        $lock = $this->refreshLock ?? new BaiduTokenRefreshLock();
        return $lock->run(fn (): array => $this->refreshAccessTokenUnlocked($force));
    }

    /** @return array<string,mixed> */
    private function refreshAccessTokenUnlocked(bool $force): array
    {
        $config = $this->tokens->config();
        $token = $this->tokens->token();
        $expires = strtotime($token['expires_at']) ?: 0;
        if (!$force && $token['access_token'] !== '' && $expires > 0 && $expires - time() >= 300) {
            return [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'expires_at' => $token['expires_at'],
            ];
        }
        if ($token['refresh_token'] === '') {
            throw new \RuntimeException('百度网盘授权已失效，请重新连接。');
        }
        $url = 'https://openapi.baidu.com/oauth/2.0/token?' . http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => (string) ($config['app_key'] ?? ''),
            'client_secret' => $this->tokens->appSecret(),
        ]);
        try {
            $payload = $this->json($this->transport->request('GET', $url), '百度 Token 刷新失败，请重新授权。');
            $this->tokens->saveToken($payload);
        } catch (\Throwable $exception) {
            $this->tokens->markDisconnected('百度网盘授权已失效，请重新连接。');
            throw $exception;
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    public function userInfo(): array
    {
        return $this->getJson('https://pan.baidu.com/rest/2.0/xpan/nas', ['method' => 'uinfo']);
    }

    /** @return array<string,mixed> */
    public function listFiles(string $path, int $page = 0, int $pageSize = 50): array
    {
        $params = [
            'method' => 'list',
            'dir' => $this->normalizePath($path),
            'start' => max(0, $page) * max(1, min(100, $pageSize)),
            'limit' => max(1, min(100, $pageSize)),
            'web' => 1,
            'folder' => 0,
            'order' => 'name',
            'desc' => 0,
        ];

        return $this->cachedJson('list', $params, 60, fn (): array => $this->getJson('https://pan.baidu.com/rest/2.0/xpan/file', $params));
    }

    /** @return array<string,mixed> */
    public function searchFiles(string $query, string $path, int $page = 0, int $pageSize = 50): array
    {
        $params = [
            'method' => 'search',
            'key' => $query,
            'dir' => $this->normalizePath($path),
            'recursion' => 1,
            'page' => max(1, $page + 1),
            'num' => max(1, min(100, $pageSize)),
        ];

        return $this->cachedJson('search', $params, 60, fn (): array => $this->getJson('https://pan.baidu.com/rest/2.0/xpan/file', $params));
    }

    /** @return array<string,mixed> */
    public function fileMeta(string $fsId, bool $withDownload = false): array
    {
        $params = [
            'method' => 'filemetas',
            'fsids' => '[' . (string) (int) $fsId . ']',
            'dlink' => $withDownload ? 1 : 0,
        ];
        $payload = $withDownload
            ? $this->getJson('https://pan.baidu.com/rest/2.0/xpan/multimedia', $params)
            : $this->cachedJson('file-meta', $params, 300, fn (): array => $this->getJson('https://pan.baidu.com/rest/2.0/xpan/multimedia', $params));
        $list = $payload['list'] ?? [];
        if (!is_array($list) || !is_array($list[0] ?? null)) {
            throw new \RuntimeException('百度网盘文件不存在或已被移动。');
        }
        return $list[0];
    }

    public function resolveDownloadUrl(string $fsId): string
    {
        $meta = $this->fileMeta($fsId, true);
        $dlink = (string) ($meta['dlink'] ?? '');
        if (!$this->isSafeDownloadUrl($dlink)) {
            throw new \RuntimeException('百度网盘返回的下载地址不安全，已拒绝。');
        }
        $token = $this->accessToken();
        $join = str_contains($dlink, '?') ? '&' : '?';
        return $dlink . $join . 'access_token=' . rawurlencode($token);
    }

    public function downloadTo(string $fsId, string $targetPath, int $maxBytes): int
    {
        return $this->transport->downloadTo($this->resolveDownloadUrl($fsId), $targetPath, $maxBytes);
    }

    public function accessToken(): string
    {
        $token = $this->tokens->token();
        if ($token['access_token'] === '') {
            throw new \RuntimeException('百度网盘尚未连接。');
        }
        $expires = strtotime($token['expires_at']) ?: 0;
        if ($expires > 0 && $expires - time() < 300) {
            $this->refreshAccessToken(false);
            $token = $this->tokens->token();
        }
        return $token['access_token'];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function getJson(string $baseUrl, array $params): array
    {
        $params['access_token'] = $this->accessToken();
        $url = $baseUrl . '?' . http_build_query($params);
        try {
            return $this->json($this->transport->request('GET', $url), '百度网盘接口请求失败。');
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '授权') || str_contains($exception->getMessage(), 'Token')) {
                $this->refreshAccessToken(true);
                $params['access_token'] = $this->accessToken();
                return $this->json($this->transport->request('GET', $baseUrl . '?' . http_build_query($params)), '百度网盘接口请求失败。');
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $params @param callable():array<string,mixed> $loader @return array<string,mixed> */
    private function cachedJson(string $scope, array $params, int $ttlSeconds, callable $loader): array
    {
        $key = 'api:' . $scope . ':' . hash('sha256', json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($params));
        $cached = $this->tokens->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }

        $payload = $loader();
        $this->tokens->cachePut($key, $payload, $ttlSeconds);

        return $payload;
    }

    /** @param array{status:int,headers:array<string,string>,body:string,url:string} $response @return array<string,mixed> */
    private function json(array $response, string $fallbackMessage): array
    {
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('百度网盘接口返回格式异常。');
        }
        $error = $decoded['error'] ?? ($decoded['errno'] ?? 0);
        if ($response['status'] >= 400 || ($error !== 0 && $error !== '0' && $error !== null && $error !== '')) {
            throw new \RuntimeException($this->friendlyError($decoded, $fallbackMessage));
        }
        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function friendlyError(array $payload, string $fallback): string
    {
        $raw = strtolower((string) ($payload['error'] ?? $payload['errno'] ?? ''));
        $description = (string) ($payload['error_description'] ?? $payload['errmsg'] ?? '');
        if (in_array($raw, ['expired_token', 'invalid_grant', 'invalid_token', '-6', '111', '110'], true)) {
            return '百度网盘授权已失效，请重新连接。';
        }
        if (in_array($raw, ['429', '31045'], true) || str_contains($description, 'rate')) {
            return '百度接口访问频率过高，请稍后再试。';
        }
        if (in_array($raw, ['-9', '12'], true)) {
            return '文件不存在或已被移动。';
        }
        return $fallback;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === 'baidu://root') {
            return '/';
        }
        if (str_starts_with($path, 'baidu://')) {
            $path = substr($path, strlen('baidu://'));
            $path = $path === 'root' ? '/' : $path;
        }
        $path = '/' . ltrim($path, '/');
        return preg_replace('#/+#', '/', $path) ?: '/';
    }

    public function isSafeDownloadUrl(string $url): bool
    {
        return self::isSafeBaiduDownloadUrl($url);
    }

    public static function isSafeBaiduDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || preg_match('/[\x00-\x20]/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!in_array($host, self::DOWNLOAD_HOSTS, true) && !str_ends_with($host, '.baidupcs.com')) {
            return false;
        }
        $port = (int) ($parts['port'] ?? 443);
        return $port === 443;
    }
}
