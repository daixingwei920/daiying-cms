<?php

declare(strict_types=1);

use Cms\Core\Integrity\ManifestBuilder;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$action = (string) ($argv[1] ?? '');

function browser_fixture_remove(string $path): void
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

function browser_fixture_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/') || str_starts_with($relative, '.git/')) {
            continue;
        }
        $dest = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
        } else {
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            copy((string) $item->getPathname(), $dest);
        }
    }
}

function browser_fixture_plugin_zip(string $path): string
{
    $manifest = [
        'package_type' => 'plugin',
        'plugin_id' => 'browser_demo',
        'name' => 'Browser Demo',
        'version' => '1.0.0',
        'author' => 'Release E2E',
        'core' => ['min' => '1.0.0'],
        'php' => '8.0.0',
        'entry' => 'plugin.php',
        'trust_level' => 'api',
        'capabilities' => ['blocks.register'],
        'required_plugins' => [],
        'optional_dependencies' => [],
        'migrations' => [],
        'data_policy' => ['uninstall' => 'retain'],
        'data_schema_version' => '1.0.0',
    ];
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('browser_demo/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('browser_demo/plugin.php', "<?php\nreturn static function (\$context): void { \$context->registerBlock('browser_demo_block', 'Browser Demo Block'); };\n");
    $zip->close();

    return $path;
}

function browser_fixture_update_package(string $path, string $privateKey, string $fromVersion): string
{
    $marker = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cms\\Core\\Update;\n\nfinal class BrowserE2eUpdatedMarker { public const VERSION = '1.2.3-browser-e2e'; }\n";
    $manifest = [
        'package_type' => 'core',
        'release_id' => 'browser-e2e-release',
        'version' => '1.2.3',
        'build' => 'browser-e2e',
        'source_versions' => ['min' => $fromVersion, 'max' => $fromVersion],
        'from_version' => $fromVersion,
        'to_version' => '1.2.3',
        'php' => ['min' => '8.0.0', 'max' => '99.0.0'],
        'required_extensions' => ['openssl', 'pdo_sqlite'],
        'database_types' => ['sqlite'],
        'core_schema_version' => 'browser-e2e',
        'migrations' => [],
        'files' => ['system/core/Update/BrowserE2eUpdatedMarker.php' => hash('sha256', $marker)],
        'signature_algorithm' => 'rsa-sha256',
        'key_id' => 'browser-e2e-key',
        'created_at' => gmdate('c'),
        'security_update' => false,
        'notes' => 'Browser E2E update package.',
    ];
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    openssl_sign($json, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('update.json', $json);
    $zip->addFromString('signature.bin', $signature);
    $zip->addFromString('system/core/Update/BrowserE2eUpdatedMarker.php', $marker);
    $zip->close();

    return $path;
}

function browser_fixture_assets(string $dir): array
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $png = $dir . '/release-image.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

    $mp3 = $dir . '/release-audio.mp3';
    file_put_contents($mp3, "ID3\x04\x00\x00\x00\x00\x00\x0fTIT2\x00\x00\x00\x05\x00\x00\x03E2E\x00\xff\xfb\x90\x64\x00\x00\x00\x00\x00\x00\x00\x00");

    $mp4 = $dir . '/release-video.mp4';
    file_put_contents($mp4, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");

    $pdf = $dir . '/release-file.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

    return ['image' => $png, 'audio' => $mp3, 'video' => $mp4, 'pdf' => $pdf];
}

if ($action === 'create') {
    $root = sys_get_temp_dir() . '/cms-browser-release-e2e-' . bin2hex(random_bytes(4));
    browser_fixture_remove($root);
    browser_fixture_copy(CMS_SOURCE_ROOT, $root);
    foreach (['storage/logs', 'storage/database', 'storage/tmp', 'storage/plugin-installs/uploads', 'storage/updates/incoming', 'storage/recovery', 'content/uploads'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            mkdir($root . '/' . $dir, 0755, true);
        }
    }
    @unlink($root . '/storage/installed.lock');
    @unlink($root . '/storage/database/browser.sqlite');

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    $publicKey = (string) ($details['key'] ?? '');

    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => '', 'username' => '', 'password' => '', 'options' => []];
    $config['site'] = ['name' => 'Browser Release E2E', 'url' => 'http://127.0.0.1', 'id' => 'browser-release-e2e', 'secret' => 'browser-secret'];
    $config['updates']['public_key'] = $publicKey;
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($root . '/system/core-manifest.json', json_encode(ManifestBuilder::build($root . '/system/core'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $pluginZip = browser_fixture_plugin_zip($root . '/storage/tmp/browser-demo.zip');
    $updatePackage = browser_fixture_update_package($root . '/storage/updates/incoming/browser-core.zip', $privateKey, (string) ($config['app']['version'] ?? '1.2.0'));
    $assets = browser_fixture_assets($root . '/storage/tmp/assets');
    file_put_contents($root . '/storage/browser-fixture.json', json_encode([
        'root' => $root,
        'plugin_zip' => $pluginZip,
        'update_package' => $updatePackage,
        'public_key' => $publicKey,
        'assets' => $assets,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo json_encode(['root' => $root, 'port' => random_int(8300, 8999)], JSON_UNESCAPED_SLASHES);
    exit(0);
}

if ($action === 'prepare-update') {
    $root = (string) ($argv[2] ?? '');
    $fixture = json_decode((string) file_get_contents($root . '/storage/browser-fixture.json'), true);
    $config = require $root . '/config/app.php';
    $config['updates']['public_key'] = (string) ($fixture['public_key'] ?? '');
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    echo json_encode(['package' => (string) ($fixture['update_package'] ?? '')], JSON_UNESCAPED_SLASHES);
    exit(0);
}

if ($action === 'info') {
    $root = (string) ($argv[2] ?? '');
    echo (string) file_get_contents($root . '/storage/browser-fixture.json');
    exit(0);
}

if ($action === 'cleanup') {
    browser_fixture_remove((string) ($argv[2] ?? ''));
    exit(0);
}

fwrite(STDERR, "Usage: php browser_release_e2e_fixture.php create|prepare-update|info|cleanup [root]\n");
exit(1);
