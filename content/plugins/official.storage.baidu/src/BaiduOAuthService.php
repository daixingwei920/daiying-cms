<?php

declare(strict_types=1);

namespace Official\Storage\Baidu;

use Cms\Core\Http\Request;

final class BaiduOAuthService
{
    public const CALLBACK_PATH = '/oauth/baidu/callback';

    public function __construct(
        private readonly BaiduTokenRepository $tokens,
        private readonly BaiduApiClient $api,
    ) {
    }

    public function callbackUrl(Request $request): string
    {
        return $this->origin($request) . $this->basePath($request) . self::CALLBACK_PATH;
    }

    public function authorizationUrl(Request $request): string
    {
        $config = $this->tokens->config();
        $appKey = trim((string) ($config['app_key'] ?? ''));
        if ($appKey === '' || !$this->tokens->appSecretConfigured()) {
            throw new \RuntimeException('请先保存 App Key 和 Secret Key。');
        }
        $state = bin2hex(random_bytes(32));
        $this->tokens->saveOAuthState($state, '/admin/baidu-storage?connected=1');
        return 'https://openapi.baidu.com/oauth/2.0/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $appKey,
            'redirect_uri' => $this->callbackUrl($request),
            'scope' => 'basic,netdisk',
            'state' => $state,
            'display' => 'page',
            'force_login' => 1,
        ]);
    }

    /** @return array{return_to:string} */
    public function handleCallback(Request $request): array
    {
        $error = trim((string) ($request->query['error'] ?? ''));
        if ($error !== '') {
            throw new \RuntimeException('百度授权已取消或失败，请重新连接。');
        }
        $code = trim((string) ($request->query['code'] ?? ''));
        $state = trim((string) ($request->query['state'] ?? ''));
        if ($code === '') {
            throw new \RuntimeException('百度授权回调缺少 code。');
        }
        $stateInfo = $this->tokens->consumeOAuthState($state);
        $token = $this->api->exchangeCode($code, $this->callbackUrl($request));
        $this->tokens->saveToken($token);
        return $stateInfo;
    }

    private function origin(Request $request): string
    {
        $server = $request->server;
        $proto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto !== 'https' && $proto !== 'http') {
            $proto = (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') ? 'https' : 'http';
        }
        $host = (string) ($server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        $host = trim(explode(',', $host)[0]);
        if ($host === '') {
            $host = 'localhost';
        }
        return $proto . '://' . $host;
    }

    private function basePath(Request $request): string
    {
        $server = $request->server;
        $script = (string) ($server['SCRIPT_NAME'] ?? $server['PHP_SELF'] ?? '');
        if ($script === '') {
            return '';
        }

        $path = str_replace('\\', '/', $script);
        foreach (['/public/index.php', '/index.php'] as $suffix) {
            if (str_ends_with($path, $suffix)) {
                $path = substr($path, 0, -strlen($suffix));
                break;
            }
        }
        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }
}
