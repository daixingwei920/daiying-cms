<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';
require __DIR__ . '/../content/plugins/official.storage.baidu/src/BaiduTokenRepository.php';
require __DIR__ . '/../content/plugins/official.storage.baidu/src/BaiduHttpTransport.php';
require __DIR__ . '/../content/plugins/official.storage.baidu/src/BaiduApiClient.php';
require __DIR__ . '/../content/plugins/official.storage.baidu/src/BaiduOAuthService.php';
require __DIR__ . '/../content/plugins/official.storage.baidu/src/BaiduFileBrowser.php';
require __DIR__ . '/../content/plugins/official.storage.baidu/src/BaiduStorageProvider.php';

use Cms\Core\Http\Request;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginSecretStore;
use Official\Storage\Baidu\BaiduApiClient;
use Official\Storage\Baidu\BaiduFileBrowser;
use Official\Storage\Baidu\BaiduHttpTransport;
use Official\Storage\Baidu\BaiduOAuthService;
use Official\Storage\Baidu\BaiduStorageProvider;
use Official\Storage\Baidu\BaiduTokenRepository;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

final class FakeBaiduTransport extends BaiduHttpTransport
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string,url:string} */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
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
            default => str_contains($url, '/oauth/2.0/token')
                ? ['access_token' => 'access-test-token', 'refresh_token' => 'refresh-test-token', 'expires_in' => 3600, 'scope' => 'basic netdisk']
                : ['errno' => 0],
        };

        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', 'url' => $url];
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE cms_plugin_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, secret_key TEXT, ciphertext TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX idx_plugin_secrets_plugin_key ON cms_plugin_secrets (plugin_id, secret_key)');

$repo = new BaiduTokenRepository(new PluginDataStore($pdo, BaiduTokenRepository::PLUGIN_ID), new PluginSecretStore($pdo, 'unit-test-master-key'));
$repo->saveConfig('app-key-123', 'secret-456');
$api = new BaiduApiClient($repo, new FakeBaiduTransport());
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
$assert($token['access_token'] === 'access-test-token', 'OAuth callback stores access token.');
$assert($repo->config()['connected'] === true, 'OAuth callback marks connection as connected.');

try {
    $oauth->handleCallback($callbackRequest);
    $assert(false, 'OAuth callback state must be single-use.');
} catch (Throwable) {
    $assert(true, 'OAuth callback state is single-use.');
}

$provider = new BaiduStorageProvider($api, new BaiduFileBrowser(), 'download-secret');
$list = $provider->list('baidu://root');
$assert(count($list['items']) === 2, 'Provider lists Baidu files.');
$assert($list['items'][0]->type === 'image', 'Provider maps jpg to image.');
$assert($list['items'][1]->type === 'folder', 'Provider maps directories to folder.');
$search = $provider->search('a', 'baidu://root');
$assert($search['items'][0]->mimeType === 'audio/mpeg', 'Provider search maps mp3 MIME.');
$resolved = $provider->resolveUrl(['id' => 9, 'metadata' => ['remote_id' => '1001']]);
$assert(str_starts_with($resolved['url'], '/baidu-storage/media/9?'), 'Provider resolves to controlled CMS media route.');
$assert(!str_contains($resolved['url'], 'access_token'), 'Resolved browser URL must not contain Baidu access token.');

$assert($api->isSafeDownloadUrl('https://d.pcs.baidu.com/file/test.jpg'), 'Baidu official download host is allowed.');
$assert($api->isSafeDownloadUrl('https://example.baidupcs.com/file/test.jpg'), 'Baidu PCS subdomain is allowed.');
$assert(!$api->isSafeDownloadUrl('http://d.pcs.baidu.com/file/test.jpg'), 'HTTP download URL is rejected.');
$assert(!$api->isSafeDownloadUrl('https://127.0.0.1/file'), 'Loopback IP download URL is rejected.');
$assert(!$api->isSafeDownloadUrl('https://evil.example/file'), 'Non-Baidu download URL is rejected.');
$assert(!$api->isSafeDownloadUrl('https://d.pcs.baidu.com:8443/file'), 'Non-443 download port is rejected.');

$pluginRows = $pdo->query('SELECT payload_json FROM cms_plugin_data')->fetchAll(PDO::FETCH_COLUMN);
$assert(!str_contains(implode("\n", array_map('strval', $pluginRows)), 'secret-456'), 'Secret Key is not written to plugin data.');

$adminController = file_get_contents(__DIR__ . '/../system/core/Admin/AdminController.php') ?: '';
$assert(str_contains($adminController, 'CMS_REMOTE_MEDIA_PROVIDERS'), 'Media picker exposes registered remote provider metadata.');
$assert(!str_contains($adminController, "source==='cloudreve'"), 'Media picker is not hard-coded to Cloudreve source checks.');

if ($failures > 0) {
    fwrite(STDERR, $failures . " Baidu storage provider contract checks failed.\n");
    exit(1);
}

echo "baidu_storage_provider_contract: PASS\n";
