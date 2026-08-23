<?php

declare(strict_types=1);

use Cms\Core\Bootstrap\Application;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Install\InstallController;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/scripts/build_release_package.php';

$failures = 0;

function package_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function package_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function package_build(string $zipPath): void
{
    cms_build_release_package(CMS_SOURCE_ROOT, $zipPath);
}

function package_extract(string $zipPath, string $target): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Unable to open package.');
    }
    if (!$zip->extractTo($target)) {
        $zip->close();
        throw new RuntimeException('Unable to extract package.');
    }
    $zip->close();
}

function package_update_zip(string $path, string $privateKey, string $fromVersion): string
{
    $marker = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cms\\Core\\Update;\n\nfinal class PackageSmokeUpdatedMarker { public const VERSION = '1.2.4-package-smoke'; }\n";
    $manifest = [
        'package_type' => 'core',
        'release_id' => 'package-smoke-release',
        'version' => '1.2.4',
        'build' => 'package-smoke',
        'source_versions' => ['min' => $fromVersion, 'max' => $fromVersion],
        'from_version' => $fromVersion,
        'to_version' => '1.2.4',
        'php' => ['min' => '8.0.0', 'max' => '99.0.0'],
        'required_extensions' => ['openssl', 'pdo_sqlite'],
        'database_types' => ['sqlite'],
        'core_schema_version' => 'package-smoke',
        'migrations' => [],
        'files' => ['system/core/Update/PackageSmokeUpdatedMarker.php' => hash('sha256', $marker)],
        'signature_algorithm' => 'rsa-sha256',
        'key_id' => 'package-smoke-key',
        'created_at' => gmdate('c'),
        'security_update' => false,
        'notes' => 'Package fresh-extract smoke update.',
    ];
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    openssl_sign($json, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('update.json', $json);
    $zip->addFromString('signature.bin', $signature);
    $zip->addFromString('system/core/Update/PackageSmokeUpdatedMarker.php', $marker);
    $zip->close();

    return $path;
}

SessionManager::start(false);

$work = sys_get_temp_dir() . '/cms-package-smoke-' . bin2hex(random_bytes(4));
package_remove($work);
mkdir($work, 0755, true);
$zipPath = $work . '/cms-package.zip';
$extractRoot = $work . '/site';

package_build($zipPath);
package_check(is_file($zipPath) && filesize($zipPath) > 100000, 'builds a non-empty distributable ZIP package from current source');
package_extract($zipPath, $extractRoot);
package_check(is_file($extractRoot . '/public/index.php') && is_file($extractRoot . '/system/core/Bootstrap/Application.php') && is_file($extractRoot . '/config/app.php'), 'fresh package extracts required Core, public and config files');
package_check(!is_dir($extractRoot . '/storage/tmp') || count(glob($extractRoot . '/storage/tmp/*') ?: []) === 0, 'fresh package excludes transient storage/tmp contents');

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $privateKey);
$details = openssl_pkey_get_details($key);
$publicKey = (string) ($details['key'] ?? '');

$config = require $extractRoot . '/config/app.php';
$config['database'] = ['dsn' => '', 'username' => '', 'password' => '', 'options' => []];
$config['updates']['public_key'] = $publicKey;
file_put_contents($extractRoot . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
file_put_contents($extractRoot . '/system/core-manifest.json', json_encode(ManifestBuilder::build($extractRoot . '/system/core'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

$settings = Settings::load($extractRoot);
$install = new InstallController($extractRoot, $settings, new FileLogger($extractRoot . '/storage/logs/app.log'));
$installResponse = $install->store(new Request('POST', '/install', [], [
    '_csrf' => CsrfToken::get(),
    'db_driver' => 'sqlite',
    'sqlite_path' => 'storage/database/package-smoke.sqlite',
    'site_name' => 'Package Smoke Site',
    'site_url' => 'https://package-smoke.example.test',
    'email' => 'admin@example.test',
    'display_name' => 'Package Admin',
    'password' => 'package-smoke-secret',
    'site_id' => 'package-smoke',
    'site_secret' => 'package-smoke-secret-key',
    'install_action' => 'install',
]));
package_check($installResponse->status() === 302 && is_file($extractRoot . '/storage/installed.lock'), 'fresh extracted package installs from empty SQLite database');
$installedPdo = new PDO('sqlite:' . $extractRoot . '/storage/database/package-smoke.sqlite');
$marketTableCount = (int) $installedPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cms_market%'")->fetchColumn();
package_check($marketTableCount === 0, 'fresh extracted package install does not create paused market or commercial tables');
package_check(is_dir($extractRoot . '/storage/updates/incoming') && is_dir($extractRoot . '/storage/recovery') && is_dir($extractRoot . '/storage/plugin-installs/uploads') && is_dir($extractRoot . '/storage/plugin-installs/staging'), 'fresh install creates update, recovery and local plugin install runtime directories');
$postInstallConfig = require $extractRoot . '/config/app.php';
package_check(($postInstallConfig['app']['secure_cookies'] ?? false) === true, 'fresh package HTTPS install enables secure cookies');

$installedSettings = Settings::load($extractRoot);
$app = Application::boot($extractRoot);
$health = $app->handle(new Request('GET', '/health'));
$login = $app->handle(new Request('GET', '/admin/login'));
$recovery = $app->handle(new Request('GET', '/recovery'));
package_check($health->status() === 200 && str_contains($health->body(), '"status":"ok"'), 'fresh installed package serves health endpoint');
package_check($login->status() === 200 && str_contains($login->body(), '管理员登录'), 'fresh installed package serves admin login page');
package_check($recovery->status() === 200 && str_contains($recovery->body(), '恢复'), 'fresh installed package serves Recovery entry');

try {
    if (!is_dir($extractRoot . '/storage/updates/incoming')) {
        mkdir($extractRoot . '/storage/updates/incoming', 0755, true);
    }
    $updatePackage = package_update_zip($extractRoot . '/storage/updates/incoming/package-smoke-update.zip', $privateKey, (string) $installedSettings->get('app.version', '0.0.0'));
    $result = (new UpdateService($extractRoot, (string) $installedSettings->get('app.version', '0.0.0'), new SignatureVerifier($publicKey)))->execute($updatePackage, 1, 'UPDATE CORE');
    package_check(($result['status'] ?? '') === 'Completed' && is_file($extractRoot . '/storage/updates/current-release.json'), 'fresh installed package executes signed Core update');
    $postUpdate = Application::boot($extractRoot)->handle(new Request('GET', '/health'));
    package_check($postUpdate->status() === 200 && str_contains($postUpdate->body(), '1.2.4'), 'fresh package remains healthy after Core update');
    package_check(is_dir($extractRoot . '/storage/updates/restore-points') && count(glob($extractRoot . '/storage/updates/restore-points/*') ?: []) >= 1, 'Core update from fresh package creates recovery point');
} catch (Throwable $exception) {
    package_check(false, 'fresh package Core update smoke failed: ' . $exception->getMessage());
}

package_remove($work);

if ($failures > 0) {
    fwrite(STDERR, $failures . " package smoke checks failed.\n");
    exit(1);
}

echo "Package fresh-extract smoke tests passed.\n";
