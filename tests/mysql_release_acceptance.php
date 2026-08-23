<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Install\InstallController;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Recovery\RestorePointService;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateService;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function mysql_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function mysql_remove(string $path): void
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

function mysql_copy(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = substr((string) $item->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, 'storage/') || str_ends_with($relative, '.zip')) {
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

/** @param list<string> $args @param array<string,string> $env */
function mysql_process(array $args, array $env = [], string $stdin = ''): string
{
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $baseEnv = [];
    foreach ($_SERVER as $key => $value) {
        if (is_scalar($value)) {
            $baseEnv[(string) $key] = (string) $value;
        }
    }
    $process = proc_open($args, $descriptor, $pipes, CMS_SOURCE_ROOT, array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start MySQL client.');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('MySQL command failed: ' . trim((string) $stderr));
    }

    return (string) $stdout;
}

function mysql_update_package(string $path, string $privateKey, string $fromVersion): string
{
    $marker = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cms\\Core\\Update;\n\nfinal class MysqlAcceptanceUpdatedMarker { public const VERSION = '1.2.5-mysql'; }\n";
    $manifest = [
        'package_type' => 'core',
        'release_id' => 'mysql-acceptance-release',
        'version' => '1.2.5',
        'build' => 'mysql-acceptance',
        'source_versions' => ['min' => $fromVersion, 'max' => $fromVersion],
        'from_version' => $fromVersion,
        'to_version' => '1.2.5',
        'php' => ['min' => '8.0.0', 'max' => '99.0.0'],
        'required_extensions' => ['openssl', 'pdo_mysql'],
        'database_types' => ['mysql'],
        'core_schema_version' => 'mysql-acceptance',
        'migrations' => [],
        'files' => ['system/core/Update/MysqlAcceptanceUpdatedMarker.php' => hash('sha256', $marker)],
        'signature_algorithm' => 'rsa-sha256',
        'key_id' => 'mysql-acceptance-key',
        'created_at' => gmdate('c'),
        'security_update' => false,
        'notes' => 'MySQL release acceptance update.',
    ];
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    openssl_sign($json, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('update.json', $json);
    $zip->addFromString('signature.bin', $signature);
    $zip->addFromString('system/core/Update/MysqlAcceptanceUpdatedMarker.php', $marker);
    $zip->close();

    return $path;
}

function mysql_quote(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function mysql_identifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

if (!extension_loaded('pdo_mysql')) {
    echo "[SKIP] pdo_mysql is unavailable.\n";
    exit(0);
}

$mysql = trim((string) (shell_exec('command -v mysql') ?: ''));
$mysqldump = trim((string) (shell_exec('command -v mysqldump') ?: ''));
if ($mysql === '' || $mysqldump === '') {
    echo "[SKIP] mysql or mysqldump client is unavailable.\n";
    exit(0);
}

try {
    mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e', 'SELECT 1']);
} catch (Throwable $exception) {
    echo "[SKIP] local MySQL/MariaDB is unavailable: " . $exception->getMessage() . "\n";
    exit(0);
}

SessionManager::start(false);

$db = 'cms_accept_' . bin2hex(random_bytes(4));
$dbUser = 'cmsu_' . bin2hex(random_bytes(5));
$dbPassword = 'CmsAccept-' . bin2hex(random_bytes(8)) . '!';
$root = sys_get_temp_dir() . '/cms-mysql-acceptance-' . bin2hex(random_bytes(4));
mysql_remove($root);
mysql_copy(CMS_SOURCE_ROOT, $root);
foreach (['storage/logs', 'storage/cache', 'storage/tmp', 'storage/database', 'storage/updates/incoming', 'storage/recovery', 'content/uploads', 'system/admin', 'system/recovery'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        mkdir($root . '/' . $dir, 0755, true);
    }
}

try {
    mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e', 'CREATE DATABASE ' . mysql_identifier($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci']);
    mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e',
        'CREATE USER ' . mysql_quote($dbUser) . "@'127.0.0.1' IDENTIFIED BY " . mysql_quote($dbPassword) . '; ' .
        'GRANT ALL PRIVILEGES ON ' . mysql_identifier($db) . '.* TO ' . mysql_quote($dbUser) . "@'127.0.0.1';"
    ]);
    $nonRootProbe = trim(mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=' . $dbUser, $db, '-N', '-e', 'SELECT DATABASE();'], ['MYSQL_PWD' => $dbPassword]));
    mysql_check($nonRootProbe === $db, 'creates temporary non-root MySQL/MariaDB user limited to acceptance database');

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    $publicKey = (string) ($details['key'] ?? '');

    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => '', 'username' => '', 'password' => '', 'options' => []];
    $config['updates']['public_key'] = $publicKey;
    $config['updates']['mysql_binary'] = $mysql;
    $config['updates']['mysql_dump_binary'] = $mysqldump;
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($root . '/system/core-manifest.json', json_encode(ManifestBuilder::build($root . '/system/core'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $install = new InstallController($root, Settings::load($root), new FileLogger($root . '/storage/logs/app.log'));
    $response = $install->store(new Request('POST', '/install', [], [
        '_csrf' => CsrfToken::get(),
        'db_driver' => 'mysql',
        'mysql_host' => '127.0.0.1',
        'mysql_port' => '3306',
        'mysql_database' => $db,
        'mysql_username' => $dbUser,
        'mysql_password' => $dbPassword,
        'site_name' => 'MySQL Acceptance',
        'site_url' => 'https://mysql-acceptance.example.test',
        'email' => 'admin@example.test',
        'display_name' => 'MySQL Admin',
        'password' => 'mysql-acceptance-secret',
        'site_id' => 'mysql-acceptance',
        'site_secret' => 'mysql-acceptance-secret-key',
        'install_action' => 'install',
    ]));
    preg_match('/<p class="error">(.+?)<\/p>/s', $response->body(), $match);
    $installError = isset($match[1]) ? html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8') : substr(strip_tags($response->body()), 0, 500);
    mysql_check($response->status() === 302 && is_file($root . '/storage/installed.lock'), 'installs CMS into a real empty MySQL/MariaDB database using non-root credentials; status=' . $response->status() . ' error=' . $installError);
    if ($response->status() !== 302) {
        throw new RuntimeException('MySQL install failed.');
    }
    $settings = Settings::load($root);
    $pdo = ConnectionFactory::make($settings);
    mysql_check((string) $settings->get('database.username', '') === $dbUser && (string) $settings->get('database.password', '') === $dbPassword, 'persists non-root MySQL/MariaDB credentials for install, restore and update operations');
    mysql_check((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && (int) $pdo->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn() === 1, 'created administrator exists in real MySQL/MariaDB database');

    $pdo->exec("INSERT INTO cms_core_settings (setting_key, setting_value, updated_at) VALUES ('mysql.acceptance', 'before', 'now') ON DUPLICATE KEY UPDATE setting_value = 'before'");
    $restore = new RestorePointService($root);
    $restorePoint = $restore->create('mysql acceptance');
    $manifest = $restore->verify($restorePoint);
    mysql_check(($manifest['database']['driver'] ?? '') === 'mysql' && is_file($restorePoint), 'creates verified MySQL/MariaDB recovery restore point');
    $pdo->exec("UPDATE cms_core_settings SET setting_value = 'after' WHERE setting_key = 'mysql.acceptance'");
    $restore->restoreDatabase($restorePoint);
    mysql_check((string) $pdo->query("SELECT setting_value FROM cms_core_settings WHERE setting_key = 'mysql.acceptance'")->fetchColumn() === 'before', 'restores MySQL/MariaDB database from recovery restore point');

    $package = mysql_update_package($root . '/storage/updates/incoming/mysql-update.zip', $privateKey, (string) $settings->get('app.version', '0.0.0'));
    $result = (new UpdateService($root, (string) $settings->get('app.version', '0.0.0'), new SignatureVerifier($publicKey)))->execute($package, 1, 'UPDATE CORE');
    mysql_check(($result['status'] ?? '') === 'Completed' && is_file($root . '/storage/updates/current-release.json'), 'executes signed Core update against real MySQL/MariaDB database with restore point');
    $historyRestore = glob($root . '/storage/updates/restore-points/*/database.mysql.sql') ?: [];
    mysql_check($historyRestore !== [] && filesize($historyRestore[0]) > 0, 'Core update creates verified MySQL/MariaDB logical backup artifact');
} finally {
    try {
        mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e', 'DROP USER IF EXISTS ' . mysql_quote($dbUser) . "@'127.0.0.1'"]);
        mysql_process([$mysql, '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--user=root', '-e', 'DROP DATABASE IF EXISTS `' . $db . '`']);
    } catch (Throwable) {
    }
    mysql_remove($root);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " MySQL acceptance checks failed.\n");
    exit(1);
}

echo "MySQL release acceptance tests passed.\n";
