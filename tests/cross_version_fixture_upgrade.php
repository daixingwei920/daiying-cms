<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fixtureDir = $root . '/outputs/cross-version-fixtures-20260908';
$targetVersion = targetVersion($root);
$updateZip = first(glob($fixtureDir . '/update-' . $targetVersion . '-rsa-local-test/daiying-cms-core-update-' . $targetVersion . '-*.zip') ?: []);
$metadataPath = first(glob($fixtureDir . '/update-' . $targetVersion . '-rsa-local-test/*.metadata.json') ?: []);
$fixtures = [
    '1.2.0' => first(glob($fixtureDir . '/daiying-cms-1.2.0-stable-exact-*.zip') ?: []),
    '1.2.22' => first(glob($fixtureDir . '/daiying-cms-1.2.22-stable-exact-*.zip') ?: []),
];

if ($updateZip === '' || $metadataPath === '' || in_array('', $fixtures, true)) {
    echo "SKIP: required local fixture artifacts are missing in {$fixtureDir}.\n";
    exit(0);
}
$metadata = json_decode((string) file_get_contents($metadataPath), true);
$publicKey = is_array($metadata) ? (string) ($metadata['public_key'] ?? '') : '';
if ($publicKey === '' || !str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
    echo "SKIP: RSA public key is missing from local update metadata.\n";
    exit(0);
}

$failures = 0;
foreach ($fixtures as $version => $zip) {
    try {
        runFixtureUpgrade($zip, $version, $targetVersion, $updateZip, $publicKey);
        echo "[PASS] {$version} fixture upgrades to {$targetVersion} through old UpdateService\n";
    } catch (Throwable $exception) {
        $failures++;
        echo "[FAIL] {$version} fixture upgrade failed: " . $exception->getMessage() . "\n";
    }
}

if ($failures > 0) {
    exit(1);
}

echo "Cross-version fixture upgrade tests passed.\n";

function first(array $items): string
{
    sort($items, SORT_STRING);

    return (string) ($items[0] ?? '');
}

function targetVersion(string $root): string
{
    $config = require $root . '/config/app.example.php';
    $version = is_array($config) ? (string) ($config['app']['version'] ?? '') : '';
    if (!preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+){1,3}$/', $version)) {
        fwrite(STDERR, "Unable to detect target version from config/app.example.php.\n");
        exit(2);
    }

    return $version;
}

function runFixtureUpgrade(string $fixtureZip, string $version, string $targetVersion, string $updateZip, string $publicKey): void
{
    $site = sys_get_temp_dir() . '/daiying-fixture-upgrade-' . str_replace('.', '-', $version) . '-' . bin2hex(random_bytes(4));
    mkdir($site, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($fixtureZip) !== true) {
        throw new RuntimeException('Unable to open fixture ZIP.');
    }
    $zip->extractTo($site);
    $zip->close();
    foreach (['storage/database', 'storage/updates', 'storage/recovery', 'storage/logs', 'content/uploads'] as $dir) {
        if (!is_dir($site . '/' . $dir)) {
            mkdir($site . '/' . $dir, 0755, true);
        }
    }
    $configPath = $site . '/config/app.php';
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Fixture config is invalid.');
    }
    $config['database'] = [
        'dsn' => 'sqlite:' . $site . '/storage/database/cms.sqlite',
        'username' => '',
        'password' => '',
        'options' => [],
    ];
    $config['updates']['public_key'] = $publicKey;
    $config['updates']['server_url'] = 'https://updates.local.test';
    $config['security']['encryption_key'] = 'fixture-upgrade-' . $version;
    file_put_contents($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

    $runner = $site . '/fixture-upgrade-runner.php';
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateService;

$updateZip = $argv[1];
$version = $argv[2];
$publicKey = $argv[3];
$settings = Settings::load(CMS_ROOT);
$pdo = ConnectionFactory::make($settings);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$migrations = [];
$files = glob(CMS_ROOT . '/system/migrations/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $migration = require $file;
    if (is_object($migration) && method_exists($migration, 'id') && method_exists($migration, 'up')) {
        $migrations[] = $migration;
    }
}
(new MigrationRunner($pdo, $migrations))->run();
$service = new UpdateService(CMS_ROOT, $version, new SignatureVerifier($publicKey));
$result = $service->execute($updateZip, 1, 'UPDATE CORE');
$pointer = json_decode((string) file_get_contents(CMS_ROOT . '/storage/updates/current-release.json'), true);
$targetVersion = $argv[4];
if (($result['status'] ?? '') !== 'Completed' || ($pointer['version'] ?? '') !== $targetVersion) {
    throw new RuntimeException('Update did not complete to ' . $targetVersion . '.');
}
foreach (['cms_core_queue_jobs', 'cms_ai_jobs', 'cms_ai_usage_ledger', 'cms_ai_prompts'] as $table) {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
    if (!$stmt || !$stmt->fetchColumn()) {
        throw new RuntimeException('Expected table missing after upgrade: ' . $table);
    }
}
echo json_encode(['status' => 'ok', 'version' => $pointer['version']], JSON_UNESCAPED_SLASHES) . "\n";
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($updateZip) . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($publicKey) . ' ' . escapeshellarg($targetVersion);
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException(implode("\n", $output));
    }
    removeDirectory($site);
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
