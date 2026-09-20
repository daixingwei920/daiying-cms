<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaProviderItem;
use Cms\Core\Media\RemoteMediaProviderInterface;
use Cms\Core\Plugin\OfficialPluginRegistry;

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}\n");
        return;
    }
    echo "[PASS] {$message}\n";
};

$db = tempnam(sys_get_temp_dir(), 'daiying-capability-gate-');
if (!is_string($db)) {
    fwrite(STDERR, "[FAIL] unable to create sqlite fixture\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE cms_admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
    $pdo->exec("INSERT INTO cms_admin_users (email) VALUES ('founder@example.test'), ('editor@example.test')");

    $settings = Settings::fromArray(['database' => ['dsn' => 'sqlite:' . $db]]);
    $controller = new AdminController($settings, new FileLogger(sys_get_temp_dir() . '/daiying-capability-gate-test.log'));
    $ref = new ReflectionClass($controller);
    $adminHasCapability = $ref->getMethod('adminHasCapability');
    $canUseRemoteMediaProvider = $ref->getMethod('canUseRemoteMediaProvider');

    $provider = new class implements RemoteMediaProviderInterface {
        public function id(): string { return 'official.storage.fixture'; }
        public function label(): string { return 'Fixture Storage'; }
        public function requiredCapability(string $operation): ?string
        {
            return match ($operation) {
                'list' => 'storage_fixture.media.list',
                'select' => 'storage_fixture.media.select',
                'write' => 'storage_fixture.media.write',
                default => null,
            };
        }
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

    $_SESSION = [];
    $_SESSION['admin_user'] = ['id' => 1, 'email' => 'founder@example.test', 'display_name' => 'Founder'];
    $check($adminHasCapability->invoke($controller, 'storage_fixture.media.list') === true, 'legacy founder session keeps compatibility superadmin access');

    $_SESSION['admin_user'] = ['id' => 2, 'email' => 'editor@example.test', 'display_name' => 'Editor'];
    $check($adminHasCapability->invoke($controller, 'storage_fixture.media.list') === false, 'legacy non-founder session without capabilities is denied');

    $_SESSION['admin_user'] = ['id' => 2, 'email' => 'editor@example.test', 'display_name' => 'Editor', 'capabilities' => []];
    $check($canUseRemoteMediaProvider->invoke($controller, $provider, 'list') === false, 'provider list gate denies missing explicit capability');

    $_SESSION['admin_user'] = ['id' => 2, 'email' => 'editor@example.test', 'display_name' => 'Editor', 'capabilities' => ['storage_fixture.media.list']];
    $check($canUseRemoteMediaProvider->invoke($controller, $provider, 'list') === true, 'provider list gate allows explicit list capability');
    $check($canUseRemoteMediaProvider->invoke($controller, $provider, 'select') === false, 'provider select gate requires its own capability');

    $_SESSION['admin_user'] = ['id' => 2, 'email' => 'editor@example.test', 'display_name' => 'Editor', 'capabilities' => ['*']];
    $check($canUseRemoteMediaProvider->invoke($controller, $provider, 'write') === true, 'provider write gate allows superadmin wildcard');

    $registry = new OfficialPluginRegistry(dirname(__DIR__));
    $check(in_array('baidu_storage', $registry->capabilityNamespaces('official.storage.baidu'), true), 'official.storage.baidu registry exposes baidu_storage namespace');
    $check(in_array('storage_baidu', $registry->capabilityNamespaces('official.storage.baidu'), true), 'official.storage.baidu registry exposes storage_baidu namespace');
    $check(in_array('baidu_storage_', $registry->tablePrefixes('official.storage.baidu'), true), 'official.storage.baidu registry exposes table prefix');
} finally {
    @unlink($db);
}

if ($failures > 0) {
    exit(1);
}

echo "Provider capability gate hardening tests PASS\n";
