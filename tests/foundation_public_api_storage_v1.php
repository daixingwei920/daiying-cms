<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Foundation\FoundationVersions;
use Cms\Core\Media\LocalMediaStorageProvider;
use Cms\Core\Media\MediaProviderItem;
use Cms\Core\Media\RemoteMediaProviderInterface;
use Cms\Core\Media\RemoteMediaProviderRegistry;
use Cms\Core\Media\StorageProviderCapabilities;
use Cms\Core\Media\StorageProviderHealth;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Support\PublicApiRegistry;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};

$versions = FoundationVersions::all();
$check($versions['core_api_version'] === '1.0', 'Core API version is declared');
$check($versions['plugin_api_version'] === '1.0', 'Plugin API version is declared');
$check($versions['theme_api_version'] === '1.0', 'Theme API version is declared');
$check($versions['storage_api_version'] === '1.0', 'Storage API version is declared');
$check($versions['rest_api_version'] === '1.0', 'REST API version is declared');
$check($versions['update_protocol_version'] === '1.0', 'Update protocol version is declared');
$check(PublicApiRegistry::contract('storage.provider')['version'] === '1.0', 'Storage provider contract is in the public API registry');
$check(PublicApiRegistry::contract('remote.media.provider')['version'] === '1.0', 'Remote media provider contract is in the public API registry');
$check(PublicApiRegistry::contract('mail.service')['version'] === '1.0', 'Mail service contract is in the public API registry');

$root = sys_get_temp_dir() . '/daiying-storage-v1-' . bin2hex(random_bytes(4));
$local = new LocalMediaStorageProvider($root);
$check($local->apiVersion() === '1.0', 'Local storage implements Storage Provider API v1');
$check(in_array(StorageProviderCapabilities::UPLOAD, $local->capabilities(), true), 'Local storage declares upload capability');
$check($local->testConnection()->ok, 'Local storage health check succeeds');
$source = $root . '-source.txt';
file_put_contents($source, 'hello');
$local->put($source, '2026/09/test.txt', true);
$check($local->exists('2026/09/test.txt'), 'Local storage can put and check files');
$metadata = $local->metadata('2026/09/test.txt');
$check(($metadata['byte_size'] ?? 0) === 5, 'Local storage exposes metadata');
$stream = $local->readStream('2026/09/test.txt');
$check(is_resource($stream) && stream_get_contents($stream) === 'hello', 'Local storage exposes readStream');
if (is_resource($stream)) {
    fclose($stream);
}
$local->move('2026/09/test.txt', '2026/09/moved.txt');
$check(!$local->exists('2026/09/test.txt') && $local->exists('2026/09/moved.txt'), 'Local storage can move files');
$local->delete('2026/09/moved.txt');
$check(!$local->exists('2026/09/moved.txt'), 'Local storage can delete files');

$legacyProvider = new class implements RemoteMediaProviderInterface {
    public function id(): string { return 'legacy.remote'; }
    public function label(): string { return 'Legacy Remote'; }
    public function list(string $path = '', array $options = []): array { return ['items' => [], 'pagination' => []]; }
    public function search(string $query, string $path = '', array $options = []): array { return ['items' => [], 'pagination' => []]; }
    public function get(string $remoteId, string $path = ''): MediaProviderItem { return new MediaProviderItem($this->id(), $remoteId, $path, 'file.txt', 'attachment', 'text/plain', 1); }
    public function resolveUrl(array $media, array $options = []): array { return ['url' => '/media/remote', 'expires' => null]; }
    public function upload(string $sourcePath, string $filename, string $targetPath, string $mimeType): MediaProviderItem { return new MediaProviderItem($this->id(), 'id', $targetPath, $filename, 'attachment', $mimeType, 1); }
    public function delete(string $remoteId, string $path = ''): void {}
    public function move(string $remoteId, string $path, string $destinationPath): MediaProviderItem { return new MediaProviderItem($this->id(), $remoteId, $destinationPath, 'file.txt', 'attachment', 'text/plain', 1); }
    public function metadata(string $remoteId, string $path = ''): array { return ['remote_id' => $remoteId]; }
    public function downloadTo(string $remoteId, string $path, string $targetPath, int $maxBytes): array { file_put_contents($targetPath, 'x'); return ['filename' => 'file.txt', 'mime_type' => 'text/plain', 'byte_size' => 1]; }
};

RemoteMediaProviderRegistry::clear();
RemoteMediaProviderRegistry::register($legacyProvider);
$description = RemoteMediaProviderRegistry::descriptions()['legacy.remote'] ?? null;
$check(is_array($description) && $description['api_version'] === 'legacy', 'Legacy remote providers remain compatible through registry descriptions');
$check(in_array(StorageProviderCapabilities::UPLOAD, $description['capabilities'], true), 'Legacy remote provider capabilities are inferred');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');
$manifest = new PluginManifest('local.storage', 'Storage', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', ['storage.plugin'], [], [], 'plugin', false, [], [], '');
$context = new PluginContext($manifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.storage'), null);
$context->registerRemoteMediaProvider($legacyProvider);
$check(RemoteMediaProviderRegistry::get('legacy.remote') !== null, 'Plugins with storage.plugin capability can register remote media providers');

$blocked = false;
try {
    $blockedContext = new PluginContext(new PluginManifest('local.blocked', 'Blocked', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.blocked'), null);
    $blockedContext->registerRemoteMediaProvider($legacyProvider);
} catch (PluginException) {
    $blocked = true;
}
$check($blocked, 'Remote media provider registration requires storage.plugin capability');

$health = StorageProviderHealth::failed('Network unavailable.', ['provider' => 'legacy.remote']);
$check(!$health->ok && $health->toArray()['status'] === 'failed', 'Storage provider health result is serializable');

echo "Foundation public API and Storage Provider API v1 tests PASS\n";
