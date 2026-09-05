<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduTokenRepository.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduTokenRefreshLock.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduHttpTransport.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduApiClient.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduOAuthService.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduFileBrowser.php';
require __DIR__ . '/../content/plugins/local.storage.baidu/src/BaiduStorageProvider.php';

use Cms\Core\Http\Request;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Media\RemoteMediaProxyProviderInterface;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginRiskBoundaryPolicy;
use Cms\Core\Plugin\PluginSecretStore;
use Local\Storage\Baidu\BaiduApiClient;
use Local\Storage\Baidu\BaiduFileBrowser;
use Local\Storage\Baidu\BaiduHttpTransport;
use Local\Storage\Baidu\BaiduOAuthService;
use Local\Storage\Baidu\BaiduStorageProvider;
use Local\Storage\Baidu\BaiduTokenRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

final class FakeBaiduTransport extends BaiduHttpTransport
{
    public int $tokenRequests = 0;

    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,url:string} */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (str_contains($url, '/oauth/2.0/token')) {
            $this->tokenRequests++;
            $payload = [
                'access_token' => 'access-test-token-' . $this->tokenRequests,
                'refresh_token' => 'refresh-test-token-' . $this->tokenRequests,
                'expires_in' => 3600,
                'scope' => 'basic netdisk',
            ];

            return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', 'url' => $url];
        }

        $payload = match ((string) ($query['method'] ?? '')) {
            'uinfo' => ['errno' => 0, 'baidu_name' => 'tester'],
            'list' => ['errno' => 0, 'list' => [
                ['fs_id' => 1001, 'path' => '/图片/test.jpg', 'server_filename' => 'test.jpg', 'isdir' => 0, 'size' => 2048, 'server_mtime' => 1700000000],
                ['fs_id' => 1002, 'path' => '/图片/子目录', 'server_filename' => '子目录', 'isdir' => 1, 'size' => 0],
            ]],
            'search' => ['errno' => 0, 'list' => [
                ['fs_id' => 1003, 'path' => '/音乐/a.mp3', 'server_filename' => 'a.mp3', 'isdir' => 0, 'size' => 4096],
            ]],
            'filemetas' => ['errno' => 0, 'list' => [
                ['fs_id' => 1001, 'path' => '/图片/test.jpg', 'server_filename' => 'test.jpg', 'isdir' => 0, 'size' => 2048, 'dlink' => 'https://d.pcs.baidu.com/file/test.jpg'],
            ]],
            default => ['errno' => 0],
        };

        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', 'url' => $url];
    }

    public function downloadTo(string $url, string $targetPath, int $maxBytes, int $timeout = 60): int
    {
        $body = 'baidu-file';
        file_put_contents($targetPath, $body);
        return strlen($body);
    }
}

final class InvalidJsonBaiduTransport extends BaiduHttpTransport
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,url:string} */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{broken', 'url' => $url];
    }
}

final class FailedRefreshBaiduTransport extends BaiduHttpTransport
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,url:string} */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        $payload = ['error' => 'invalid_grant', 'error_description' => 'expired refresh token'];
        return ['status' => 400, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload) ?: '{}', 'url' => $url];
    }
}

$manifest = json_decode((string) file_get_contents(__DIR__ . '/../content/plugins/local.storage.baidu/plugin.json'), true);
$assert(is_array($manifest), 'Local Baidu manifest is valid JSON.');
if (is_array($manifest)) {
    $parsedManifest = PluginManifest::fromArray($manifest);
    PluginRiskBoundaryPolicy::assertLocalManifestAllowed($manifest);
    $assert($parsedManifest->id === 'local.storage.baidu', 'Local Baidu plugin id uses local namespace.');
    $assert($parsedManifest->trustLevel === 'api', 'Local Baidu plugin uses restricted API trust level.');
    $assert(empty($manifest['official']), 'Local Baidu plugin does not claim official status.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE cms_plugin_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, secret_key TEXT, ciphertext TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX idx_plugin_secrets_plugin_key ON cms_plugin_secrets (plugin_id, secret_key)');
$pdo->exec('CREATE TABLE cms_media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    storage_provider TEXT NOT NULL,
    media_type TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    original_name TEXT NOT NULL,
    relative_path TEXT NOT NULL,
    storage_key TEXT,
    byte_size INTEGER NOT NULL,
    sha256_hash TEXT NOT NULL,
    metadata_json TEXT,
    extension TEXT,
    width INTEGER,
    height INTEGER,
    duration_seconds REAL,
    title TEXT,
    description TEXT,
    alt_text TEXT,
    uploaded_by INTEGER,
    status TEXT NOT NULL DEFAULT "Active",
    deleted_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE UNIQUE INDEX cms_media_hash_unique ON cms_media (sha256_hash)');

$dataStore = new PluginDataStore($pdo, BaiduTokenRepository::PLUGIN_ID);
$secretStore = new PluginSecretStore($pdo, 'unit-test-master-key');
$repo = new BaiduTokenRepository($dataStore, $secretStore);
$repo->saveConfig('app-key-123', 'secret-456');
$transport = new FakeBaiduTransport();
$api = new BaiduApiClient($repo, $transport);
$oauth = new BaiduOAuthService($repo, $api);
$request = new Request('GET', '/admin/baidu-storage', [], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'example.test']);

$authUrl = $oauth->authorizationUrl($request);
$assert(str_starts_with($authUrl, 'https://openapi.baidu.com/oauth/2.0/authorize?'), 'OAuth authorize URL uses Baidu official HTTPS host.');
$assert(str_contains($authUrl, 'client_id=app-key-123'), 'OAuth authorize URL includes App Key.');
$assert(!str_contains($authUrl, 'secret-456'), 'OAuth authorize URL must not expose Secret Key.');
parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $authQuery);
$state = (string) ($authQuery['state'] ?? '');
$assert(preg_match('/^[a-f0-9]{64}$/', $state) === 1, 'OAuth state is high entropy hex.');
$assert(($authQuery['redirect_uri'] ?? '') === 'https://example.test/oauth/baidu/callback', 'Callback URL is derived from current site.');

$subdirRequest = new Request('GET', '/cms/admin/baidu-storage', [], [], [
    'HTTPS' => 'on',
    'HTTP_HOST' => 'example.test',
    'SCRIPT_NAME' => '/cms/public/index.php',
]);
$assert($oauth->callbackUrl($subdirRequest) === 'https://example.test/cms/oauth/baidu/callback', 'Callback URL keeps CMS subdirectory base path.');

$callbackRequest = new Request('GET', '/oauth/baidu/callback', ['code' => 'code-ok', 'state' => $state], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'example.test']);
$oauth->handleCallback($callbackRequest);
$token = $repo->token();
$assert($token['access_token'] === 'access-test-token-1', 'OAuth callback stores access token.');
$assert($repo->config()['connected'] === true, 'OAuth callback marks connection as connected.');

try {
    $oauth->handleCallback($callbackRequest);
    $assert(false, 'OAuth callback state must be single-use.');
} catch (Throwable) {
    $assert(true, 'OAuth callback state is single-use.');
}

try {
    $oauth->handleCallback(new Request('GET', '/oauth/baidu/callback', ['code' => 'code-ok', 'state' => 'bad'], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'example.test']));
    $assert(false, 'OAuth callback must reject malformed state.');
} catch (Throwable) {
    $assert(true, 'OAuth callback rejects malformed state.');
}

$expiredState = str_repeat('a', 64);
$secretStore->set(BaiduTokenRepository::PLUGIN_ID, 'oauth_state_' . $expiredState, json_encode([
    'state' => $expiredState,
    'return_to' => '/admin/baidu-storage',
    'expires_at' => time() - 5,
], JSON_UNESCAPED_SLASHES) ?: '{}');
try {
    $oauth->handleCallback(new Request('GET', '/oauth/baidu/callback', ['code' => 'code-ok', 'state' => $expiredState], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'example.test']));
    $assert(false, 'OAuth callback must reject expired state.');
} catch (Throwable) {
    $assert(true, 'OAuth callback rejects expired state.');
}

$repo->saveToken(['access_token' => 'old-access-token', 'refresh_token' => 'old-refresh-token', 'expires_in' => 60, 'scope' => 'basic netdisk']);
$refreshed = $api->accessToken();
$assert($refreshed === 'access-test-token-2', 'Expiring access token is refreshed automatically.');
$assert($transport->tokenRequests === 2, 'Refresh endpoint was called exactly once after expiring token setup.');

$provider = new BaiduStorageProvider($api, new BaiduFileBrowser());
$assert($provider instanceof RemoteMediaProxyProviderInterface, 'Local Baidu provider uses Core remote media proxy interface.');
$list = $provider->list('baidu://root');
$assert(count($list['items']) === 2, 'Provider lists Baidu files.');
$assert($list['items'][0]->type === 'image', 'Provider maps jpg to image.');
$assert($list['items'][1]->type === 'folder', 'Provider maps directories to folder.');
$search = $provider->search('a', 'baidu://root');
$assert($search['items'][0]->mimeType === 'audio/mpeg', 'Provider search maps mp3 MIME.');
$resolved = $provider->resolveUrl(['id' => 9, 'metadata' => ['remote_id' => '1001']]);
$assert($resolved['url'] === '/media/9', 'Provider resolves to controlled CMS media route.');
$assert(!str_contains($resolved['url'], 'access_token'), 'Resolved browser URL must not contain Baidu access token.');

$mediaLibrary = new MediaLibrary($pdo, sys_get_temp_dir() . '/daiying-baidu-media-test');
$mediaId = $mediaLibrary->registerRemoteReference($list['items'][0], 1);
$media = $mediaLibrary->find($mediaId);
$assert(is_array($media) && $media['storage_provider'] === BaiduTokenRepository::PLUGIN_ID, 'Remote media record stores Baidu provider id.');
$assert(is_array($media) && $media['storage_key'] === BaiduTokenRepository::PLUGIN_ID . ':1001', 'Remote media record stores stable provider key.');
$metadataJson = is_array($media) ? (string) ($media['metadata_json'] ?? '') : '';
$assert(str_contains($metadataJson, '"remote_id":"1001"'), 'Remote media metadata stores Baidu remote id.');
$assert(!str_contains($metadataJson, 'd.pcs.baidu.com') && !str_contains($metadataJson, 'access_token'), 'Remote media metadata does not store temporary download URL or token.');
$tmpProxy = tempnam(sys_get_temp_dir(), 'baidu-proxy-test-');
if (is_string($tmpProxy)) {
    $proxy = $provider->proxyDownload($media, $tmpProxy, 1024);
    $assert($proxy['byte_size'] === 10 && file_get_contents($tmpProxy) === 'baidu-file', 'Provider proxy download writes through server-side transport.');
    @unlink($tmpProxy);
} else {
    $assert(false, 'Unable to create proxy download test temp file.');
}

$assert($api->isSafeDownloadUrl('https://d.pcs.baidu.com/file/test.jpg'), 'Baidu download host is allowed.');
$assert($api->isSafeDownloadUrl('https://example.baidupcs.com/file/test.jpg'), 'Baidu PCS subdomain is allowed.');
$assert(!$api->isSafeDownloadUrl('http://d.pcs.baidu.com/file/test.jpg'), 'HTTP download URL is rejected.');
$assert(!$api->isSafeDownloadUrl('https://127.0.0.1/file'), 'Loopback IP download URL is rejected.');
$assert(!$api->isSafeDownloadUrl('https://evil.example/file'), 'Non-Baidu download URL is rejected.');
$assert(!$api->isSafeDownloadUrl('https://d.pcs.baidu.com.evil.example/file'), 'Lookalike Baidu download host is rejected.');
$assert(!$api->isSafeDownloadUrl('https://d.pcs.baidu.com:8443/file'), 'Non-443 download port is rejected.');

$realTransport = new BaiduHttpTransport();
foreach (['http://openapi.baidu.com/oauth/2.0/token', 'https://openapi.baidu.com:8443/oauth/2.0/token', 'https://evil.example/oauth/2.0/token'] as $badApiUrl) {
    try {
        $realTransport->request('GET', $badApiUrl);
        $assert(false, 'Unsafe Baidu API URL must be rejected before network request: ' . $badApiUrl);
    } catch (Throwable) {
        $assert(true, 'Unsafe Baidu API URL is rejected before network request.');
    }
}

$pluginRows = $pdo->query('SELECT payload_json FROM cms_plugin_data')->fetchAll(PDO::FETCH_COLUMN);
$assert(!str_contains(implode("\n", array_map('strval', $pluginRows)), 'secret-456'), 'Secret Key is not written to plugin data.');

$invalidJsonApi = new BaiduApiClient($repo, new InvalidJsonBaiduTransport());
try {
    $invalidJsonApi->userInfo();
    $assert(false, 'Invalid Baidu JSON must be rejected.');
} catch (Throwable $exception) {
    $assert(str_contains($exception->getMessage(), '返回格式异常'), 'Invalid Baidu JSON gets a friendly error.');
}

$failedRefreshRepo = new BaiduTokenRepository(new PluginDataStore($pdo, BaiduTokenRepository::PLUGIN_ID), $secretStore);
$failedRefreshRepo->saveConfig('app-key-123', 'secret-456');
$failedRefreshRepo->saveToken(['access_token' => 'stale-access-token', 'refresh_token' => 'stale-refresh-token', 'expires_in' => 60, 'scope' => 'basic netdisk']);
$failedRefreshApi = new BaiduApiClient($failedRefreshRepo, new FailedRefreshBaiduTransport());
try {
    $failedRefreshApi->accessToken();
    $assert(false, 'Refresh token failure must be reported.');
} catch (Throwable $exception) {
    $assert(str_contains($exception->getMessage(), '授权已失效'), 'Refresh token failure gets a reconnect message.');
    $assert($failedRefreshRepo->config()['connected'] === false, 'Refresh token failure marks Baidu connection disconnected.');
}

$adminController = file_get_contents(__DIR__ . '/../system/core/Admin/AdminController.php') ?: '';
$assert(str_contains($adminController, 'CMS_REMOTE_MEDIA_PROVIDERS'), 'Media picker exposes registered remote provider metadata.');
$assert(!str_contains($adminController, "source==='cloudreve'"), 'Media picker is not hard-coded to Cloudreve source checks.');

if ($failures > 0) {
    fwrite(STDERR, $failures . " Baidu storage provider contract checks failed.\n");
    exit(1);
}

echo "baidu_storage_provider_contract: PASS\n";
