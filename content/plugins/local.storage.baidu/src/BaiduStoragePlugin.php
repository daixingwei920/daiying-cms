<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Media\RemoteMediaProviderRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;

final class BaiduStoragePlugin
{
    private BaiduTokenRepository $tokens;
    private BaiduApiClient $api;
    private BaiduOAuthService $oauth;
    private BaiduStorageProvider $provider;

    public function __construct(private readonly PluginContext $context)
    {
        $this->tokens = new BaiduTokenRepository($context->data(), $context->secrets());
        $this->api = new BaiduApiClient($this->tokens, new BaiduHttpTransport());
        $this->oauth = new BaiduOAuthService($this->tokens, $this->api);
        $this->provider = new BaiduStorageProvider($this->api, new BaiduFileBrowser(), $this->downloadSecret(), $this->tokens);
    }

    public function register(): void
    {
        RemoteMediaProviderRegistry::register($this->provider);
        $this->context->adminRoute('GET', '/admin/baidu-storage', [$this, 'settings'], 'baidu_storage.manage', false);
        $this->context->adminRoute('POST', '/admin/baidu-storage/save', [$this, 'saveSettings'], 'baidu_storage.manage', true);
        $this->context->adminRoute('POST', '/admin/baidu-storage/disconnect', [$this, 'disconnect'], 'baidu_storage.manage', true);
        $this->context->adminRoute('GET', '/admin/baidu-storage/connect', [$this, 'connect'], 'baidu_storage.manage', false);
        $this->context->adminRoute('GET', '/admin/baidu-storage/test', [$this, 'testConnection'], 'baidu_storage.manage', false);
        $this->context->adminRoute('GET', '/admin/baidu-storage/diagnostics', [$this, 'diagnostics'], 'baidu_storage.manage', false);
        $this->context->adminRoute('GET', '/admin/baidu-storage/browser', [$this, 'browser'], 'baidu_storage.manage', false);
        $this->context->frontRoute('GET', BaiduOAuthService::CALLBACK_PATH, [$this, 'callback']);
        $this->context->frontRoute('GET', '/baidu-storage/media/{id}', [$this, 'download']);
        $this->context->adminMenu('百度网盘', '/admin/baidu-storage', 'baidu_storage.manage');
    }

    public function settings(Request $request): Response
    {
        $config = $this->tokens->config();
        $callback = $this->oauth->callbackUrl($request);
        $connected = !empty($config['connected']);
        $status = $this->statusLabel($config);
        $message = $this->notice($request);
        $lastError = trim((string) ($config['last_error'] ?? ''));
        $secretText = $this->tokens->appSecretConfigured() ? '已配置（' . View::escape($this->tokens->maskedSecret()) . '）' : '未配置';
        $body = '<h1>百度网盘</h1>' . $message .
            '<p><strong>连接状态：</strong><span class="admin-tag">' . View::escape($status) . '</span></p>' .
            ($lastError !== '' ? '<p class="error">最近错误：' . View::escape($lastError) . '</p>' : '') .
            '<form method="post" action="/admin/baidu-storage/save">' . CsrfToken::field() .
            '<label>App Key<input name="app_key" value="' . View::escape((string) ($config['app_key'] ?? '')) . '" autocomplete="off"></label>' .
            '<label>Secret Key<input name="app_secret" type="password" value="" autocomplete="new-password" placeholder="' . $secretText . '"></label>' .
            '<p class="muted">Secret Key 保存后不会回显。留空表示保持现有 Secret Key 不变。</p>' .
            '<label>OAuth 回调地址<input id="baidu-callback-url" readonly value="' . View::escape($callback) . '"></label>' .
            '<button type="button" class="admin-button-secondary" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'baidu-callback-url\').value)">复制回调地址</button>' .
            '<p class="muted">请将此地址填写到百度网盘开放平台的 OAuth 授权回调地址。当前插件不会写死任何站点域名。</p>' .
            '<button type="submit">保存配置</button> ' .
            '<a class="button admin-button-secondary" href="/admin/baidu-storage/connect">' . ($connected ? '重新授权' : '连接百度网盘') . '</a> ' .
            '<a class="button admin-button-secondary" href="/admin/baidu-storage/test">测试连接</a> ' .
            '<a class="button admin-button-secondary" href="/admin/baidu-storage/diagnostics">查看诊断</a> ' .
            '<a class="button admin-button-secondary" href="/admin/baidu-storage/browser">浏览网盘</a></form>' .
            '<form method="post" action="/admin/baidu-storage/disconnect" onsubmit="return confirm(\'确定断开百度网盘授权吗？媒体引用记录会保留。\');">' . CsrfToken::field() . '<button class="admin-danger" type="submit">断开连接</button></form>' .
            ($connected ? '<p class="muted">Token 到期时间：' . View::escape((string) ($config['token_expires_at'] ?? '')) . '</p>' : '');
        return Response::html(View::page('百度网盘', $body));
    }

    public function saveSettings(Request $request): Response
    {
        try {
            $secret = trim((string) $request->input('app_secret', ''));
            $this->tokens->saveConfig((string) $request->input('app_key', ''), $secret !== '' ? $secret : null);
            return Response::redirect('/admin/baidu-storage?saved=1');
        } catch (\Throwable $exception) {
            return Response::html(View::page('百度网盘', '<h1>百度网盘</h1><p class="error">' . View::escape($this->friendly($exception)) . '</p><p><a class="button" href="/admin/baidu-storage">返回设置</a></p>'), 400);
        }
    }

    public function diagnostics(Request $request): Response
    {
        $config = $this->tokens->config();
        $checks = [
            '插件 ID' => $this->provider->id(),
            '插件版本' => $this->context->manifest->version,
            'OAuth 回调地址' => $this->oauth->callbackUrl($request),
            'App Key' => (string) ($config['app_key'] ?? '') !== '' ? '已配置' : '未配置',
            'Secret Key' => $this->tokens->appSecretConfigured() ? '已配置' : '未配置',
            '连接状态' => $this->statusLabel($config),
            'Token 到期时间' => (string) ($config['token_expires_at'] ?? '') !== '' ? (string) $config['token_expires_at'] : '-',
            'PHP curl' => extension_loaded('curl') ? '可用' : '不可用',
            'PHP openssl' => extension_loaded('openssl') ? '可用' : '不可用',
        ];
        $rows = '';
        foreach ($checks as $name => $value) {
            $rows .= '<tr><th>' . View::escape($name) . '</th><td>' . View::escape((string) $value) . '</td></tr>';
        }
        $lastError = trim((string) ($config['last_error'] ?? ''));
        $body = '<h1>百度网盘诊断</h1><p><a class="button admin-button-secondary" href="/admin/baidu-storage">返回设置</a></p>' .
            '<table><tbody>' . $rows . '</tbody></table>' .
            ($lastError !== '' ? '<p class="error">最近错误：' . View::escape($lastError) . '</p>' : '') .
            '<p class="muted">此页面不会显示 Secret Key、Access Token、Refresh Token 或 Authorization Header。</p>';
        return Response::html(View::page('百度网盘诊断', $body));
    }

    public function connect(Request $request): Response
    {
        try {
            return Response::redirect($this->oauth->authorizationUrl($request));
        } catch (\Throwable $exception) {
            return Response::html(View::page('百度网盘连接失败', '<h1>百度网盘连接失败</h1><p class="error">' . View::escape($this->friendly($exception)) . '</p><p><a class="button" href="/admin/baidu-storage">返回设置</a></p>'), 400);
        }
    }

    public function callback(Request $request): Response
    {
        try {
            $result = $this->oauth->handleCallback($request);
            return Response::redirect((string) ($result['return_to'] ?? '/admin/baidu-storage?connected=1'));
        } catch (\Throwable $exception) {
            $this->tokens->setLastError($this->friendly($exception));
            return Response::html(View::page('百度网盘授权失败', '<h1>百度网盘授权失败</h1><p class="error">' . View::escape($this->friendly($exception)) . '</p><p><a class="button" href="/admin/baidu-storage">返回设置</a></p>'), 400);
        }
    }

    public function disconnect(): Response
    {
        $this->tokens->markDisconnected('管理员已断开连接。');
        return Response::redirect('/admin/baidu-storage?disconnected=1');
    }

    public function testConnection(): Response
    {
        try {
            $info = $this->api->userInfo();
            $name = (string) ($info['baidu_name'] ?? $info['netdisk_name'] ?? $info['username'] ?? '百度网盘账号');
            return Response::html(View::page('百度网盘连接正常', '<h1>百度网盘连接正常</h1><p>已连接：' . View::escape($name) . '</p><p><a class="button" href="/admin/baidu-storage">返回设置</a> <a class="button admin-button-secondary" href="/admin/baidu-storage/browser">浏览网盘</a></p>'));
        } catch (\Throwable $exception) {
            return Response::html(View::page('百度网盘连接异常', '<h1>百度网盘连接异常</h1><p class="error">' . View::escape($this->friendly($exception)) . '</p><p><a class="button" href="/admin/baidu-storage">返回设置</a></p>'), 503);
        }
    }

    public function browser(Request $request): Response
    {
        $path = (string) ($request->query['path'] ?? 'baidu://root');
        $query = trim((string) ($request->query['q'] ?? ''));
        try {
            $result = $query !== '' ? $this->provider->search($query, $path) : $this->provider->list($path);
            $rows = '';
            $parent = $result['parent'] ?? null;
            if (is_array($parent)) {
                $rows .= '<tr><td><a href="/admin/baidu-storage/browser?path=' . rawurlencode((string) $parent['path']) . '">..</a></td><td>目录</td><td></td><td></td><td>上级目录</td></tr>';
            }
            foreach ($result['items'] as $item) {
                $action = $item->type === 'folder'
                    ? '<a class="button admin-button-secondary" href="/admin/baidu-storage/browser?path=' . rawurlencode($item->path) . '">打开</a>'
                    : '<form method="post" action="/admin/media/provider/select" style="display:inline">' . CsrfToken::field() .
                        '<input type="hidden" name="provider" value="' . View::escape($this->provider->id()) . '">' .
                        '<input type="hidden" name="id" value="' . View::escape($item->id) . '">' .
                        '<input type="hidden" name="path" value="' . View::escape($item->path) . '">' .
                        '<input type="hidden" name="mode" value="reference">' .
                        '<input type="hidden" name="return_to" value="/admin/media?source=' . View::escape($this->provider->id()) . '">' .
                        '<button type="submit">引用到媒体库</button></form>';
                $rows .= '<tr><td>' . View::escape($item->name) . '</td><td>' . View::escape($item->type) . '</td><td>' . View::escape($item->mimeType) . '</td><td>' . View::escape(number_format($item->byteSize / 1024, 1) . ' KB') . '</td><td>' . $action . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">当前目录没有文件。</td></tr>';
            $body = '<h1>百度网盘浏览器</h1><p><a class="button admin-button-secondary" href="/admin/baidu-storage">返回设置</a></p>' .
                '<form class="admin-filter-bar" method="get" action="/admin/baidu-storage/browser"><label>目录<input name="path" value="' . View::escape($path) . '"></label><label>搜索<input name="q" value="' . View::escape($query) . '"></label><button type="submit">读取</button></form>' .
                '<table><thead><tr><th>名称</th><th>类型</th><th>MIME</th><th>大小</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';
            return Response::html(View::page('百度网盘浏览器', $body));
        } catch (\Throwable $exception) {
            return Response::html(View::page('百度网盘暂不可用', '<h1>百度网盘暂不可用</h1><p class="error">' . View::escape($this->friendly($exception)) . '</p><p><a class="button" href="/admin/baidu-storage">返回设置</a></p>'), 503);
        }
    }

    public function download(Request $request): Response
    {
        $mediaId = (int) basename($request->path);
        $remoteId = trim((string) ($request->query['remote_id'] ?? ''));
        $expires = (int) ($request->query['expires'] ?? 0);
        $sig = (string) ($request->query['sig'] ?? '');
        if (!$this->provider->validateDownloadSignature($mediaId, $remoteId, $expires, $sig)) {
            return Response::text('百度网盘媒体访问链接已过期，请刷新后重试。', 403)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/baidu-storage-' . bin2hex(random_bytes(10)) . '.tmp';
        try {
            $download = $this->provider->downloadTo($remoteId, '', $tmp, 52428800);
            $body = file_get_contents($tmp);
            if (!is_string($body)) {
                throw new \RuntimeException('百度网盘媒体读取失败。');
            }
            $filename = $this->safeDownloadName((string) ($download['filename'] ?? 'baidu-media'));
            return new Response($body, 200, [
                'Content-Type' => (string) ($download['mime_type'] ?? 'application/octet-stream'),
                'Content-Length' => (string) strlen($body),
                'Content-Disposition' => 'inline; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
                'Cache-Control' => 'private, no-store',
                'X-Daiying-Media-Provider' => $this->provider->id(),
            ]);
        } catch (\Throwable) {
            return Response::text('百度网盘媒体暂不可用。', 503)->withHeaders(['Cache-Control' => 'private, no-store']);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function safeDownloadName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", '"', '\\'], '', $name));
        return $name !== '' ? $name : 'baidu-media';
    }

    private function downloadSecret(): string
    {
        $secret = $this->tokens->appSecret();
        return $secret !== '' ? hash('sha256', $secret . ':media-download') : hash('sha256', __DIR__);
    }

    /** @param array<string,mixed> $config */
    private function statusLabel(array $config): string
    {
        if ((string) ($config['app_key'] ?? '') === '' || !$this->tokens->appSecretConfigured()) {
            return '配置不完整';
        }
        if (!empty($config['connected'])) {
            return '已连接';
        }
        if ((string) ($config['last_error'] ?? '') !== '') {
            return 'Token 已失效';
        }
        return '未连接';
    }

    private function notice(Request $request): string
    {
        if ((string) ($request->query['saved'] ?? '') === '1') {
            return '<p class="notice">百度网盘配置已保存。</p>';
        }
        if ((string) ($request->query['connected'] ?? '') === '1') {
            return '<p class="notice">百度网盘已连接。</p>';
        }
        if ((string) ($request->query['disconnected'] ?? '') === '1') {
            return '<p class="notice">百度网盘已断开，历史媒体引用已保留。</p>';
        }
        return '';
    }

    private function friendly(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'master key') || str_contains($message, 'encrypt plugin secret')) {
            return '当前站点未配置安全密钥，无法保存百度 Secret Key。';
        }
        return $message !== '' ? $message : '百度网盘暂不可用，请稍后重试。';
    }
}
