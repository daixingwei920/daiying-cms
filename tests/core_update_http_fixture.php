<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$action = (string) ($argv[1] ?? '');

function fixture_remove(string $path): void
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

function fixture_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/updates/') || str_starts_with($relative, 'storage/recovery/')) {
            continue;
        }
        $dest = $target . '/' . $relative;
        $item->isDir() ? (!is_dir($dest) && mkdir($dest, 0755, true)) : copy((string) $item->getPathname(), $dest);
    }
}

function fixture_package(string $path, string $privateKey, string $fromVersion): string
{
    $marker = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cms\\Core\\Update;\n\nfinal class HttpUpdatedMarker { public const VERSION = '1.2.2'; }\n";
    $manifest = [
        'package_type' => 'core',
        'package_id' => 'http-release',
        'release_id' => 'http-release',
        'version' => '1.2.2',
        'build' => 'http-smoke',
        'source_versions' => ['min' => $fromVersion, 'max' => $fromVersion],
        'from_version' => $fromVersion,
        'to_version' => '1.2.2',
        'php' => ['min' => '8.0.0', 'max' => '99.0.0'],
        'required_extensions' => ['openssl', 'pdo_sqlite'],
        'database_types' => ['sqlite'],
        'core_schema_version' => 'http',
        'migrations' => [],
        'files' => ['system/core/Update/HttpUpdatedMarker.php' => hash('sha256', $marker)],
        'signature_algorithm' => 'rsa-sha256',
        'key_id' => 'http-key',
        'created_at' => gmdate('c'),
        'security_update' => true,
        'notes' => 'HTTP smoke update.',
    ];
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    openssl_sign($json, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('update.json', $json);
    $zip->addFromString('signature.bin', $signature);
    $zip->addFromString('system/core/Update/HttpUpdatedMarker.php', $marker);
    $zip->close();
    return $path;
}

if ($action === 'create') {
    $root = sys_get_temp_dir() . '/cms-core-update-http-' . bin2hex(random_bytes(4));
    fixture_remove($root);
    fixture_copy(CMS_SOURCE_ROOT, $root);
    foreach (['storage/logs', 'storage/cache', 'storage/updates/incoming', 'storage/recovery', 'content/themes/default'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            mkdir($root . '/' . $dir, 0755, true);
        }
    }
    $config = require $root . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/http.sqlite', 'username' => '', 'password' => '', 'options' => []];
    $config['site'] = ['name' => 'HTTP Update Site', 'url' => 'http://127.0.0.1', 'id' => 'http-update', 'secret' => 'secret'];
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($root . '/storage/installed.lock', '{}');
    file_put_contents($root . '/system/core-manifest.json', json_encode(ManifestBuilder::build($root . '/system/core'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $settings = Settings::load($root);
    $pdo = ConnectionFactory::make($settings);
    $migrations = [];
    foreach (glob($root . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    $publicKey = (string) ($details['key'] ?? '');
    $package = fixture_package($root . '/storage/updates/incoming/http-release.zip', $privateKey, (string) $config['app']['version']);
    file_put_contents($root . '/storage/http-fixture.json', json_encode(['public_key' => $publicKey, 'package' => $package, 'version' => $config['app']['version']], JSON_UNESCAPED_SLASHES));
    echo $root;
    exit(0);
}

if ($action === 'update') {
    $root = (string) ($argv[2] ?? '');
    $fixture = json_decode((string) file_get_contents($root . '/storage/http-fixture.json'), true);
    (new UpdateService($root, (string) $fixture['version'], new SignatureVerifier((string) $fixture['public_key'])))
        ->execute((string) $fixture['package'], 1, 'UPDATE CORE');
    echo 'updated';
    exit(0);
}

if ($action === 'cleanup') {
    fixture_remove((string) ($argv[2] ?? ''));
    exit(0);
}

fwrite(STDERR, "Usage: php core_update_http_fixture.php create|update|cleanup [root]\n");
exit(1);
