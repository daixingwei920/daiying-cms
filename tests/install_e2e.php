<?php

declare(strict_types=1);

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Install\InstallController;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;
use Cms\Core\Support\RuntimeRequirements;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function e2e_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function e2e_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function e2e_copy(string $source, string $target): void
{
    if (is_file($source)) {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        copy($source, $target);
        return;
    }
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) {
            continue;
        }
        e2e_copy($item->getPathname(), $target . '/' . $item->getBasename());
    }
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-install-e2e-' . bin2hex(random_bytes(4));
e2e_remove($root);
mkdir($root . '/config', 0755, true);
mkdir($root . '/storage/logs', 0755, true);
mkdir($root . '/storage/database', 0755, true);
e2e_copy(CMS_SOURCE_ROOT . '/system/migrations', $root . '/system/migrations');
e2e_copy(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');

$baseConfig = require CMS_SOURCE_ROOT . '/config/app.php';
$baseConfig['database'] = ['dsn' => '', 'username' => '', 'password' => '', 'options' => []];
$baseConfig['payment']['fixture_provider_enabled'] = true;
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($baseConfig, true) . ";\n");
chmod($root . '/config/app.php', 0600);

$settings = Settings::load($root);
$controller = new InstallController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$csrf = CsrfToken::get();
$installPage = $controller->show();
e2e_check($installPage->status() === 200 && str_contains($installPage->body(), 'PHP Fileinfo') && str_contains($installPage->body(), '媒体上传 MIME 类型安全检测'), 'installer environment check reports PHP Fileinfo for secure media MIME detection');
e2e_check($installPage->status() === 200 && str_contains($installPage->body(), 'PHP ZipArchive') && str_contains($installPage->body(), '主题/插件 ZIP 安装') && str_contains($installPage->body(), 'Core 更新包'), 'installer environment check reports PHP ZipArchive for package, update and recovery handling');
$extensionLabels = ['pdo' => 'PDO', 'json' => 'JSON', 'openssl' => 'OpenSSL', 'fileinfo' => 'PHP Fileinfo', 'zip' => 'PHP ZipArchive'];
$installerListsAllSharedExtensions = true;
foreach (RuntimeRequirements::requiredExtensions() as $extension) {
    $installerListsAllSharedExtensions = $installerListsAllSharedExtensions && str_contains($installPage->body(), $extensionLabels[$extension] ?? strtoupper($extension));
}
e2e_check($installerListsAllSharedExtensions, 'installer environment check lists every extension from shared RuntimeRequirements');
$body = [
    '_csrf' => $csrf,
    'db_driver' => 'sqlite',
    'sqlite_path' => 'storage/database/cms.sqlite',
    'site_name' => 'Install E2E Site',
    'site_url' => 'https://install-e2e.example.test',
    'email' => 'admin@example.test',
    'display_name' => 'Install Admin',
    'password' => 'install-e2e-secret',
    'site_id' => 'site-install-e2e',
    'site_secret' => 'install-e2e-secret-key',
];

$testResponse = $controller->store(new Request('POST', '/install', [], array_replace($body, ['install_action' => 'test_database'])));
e2e_check($testResponse->status() === 200 && str_contains($testResponse->body(), '数据库连接测试通过'), 'tests database connection before install');
e2e_check(!is_file($root . '/storage/installed.lock'), 'database test does not create install lock');

$badResponse = $controller->store(new Request('POST', '/install', [], array_replace($body, ['site_url' => 'not-a-url', 'install_action' => 'install'])));
e2e_check($badResponse->status() === 422 && !is_file($root . '/storage/installed.lock'), 'rejects invalid site URL without leaving install lock');
$badSchemeResponse = $controller->store(new Request('POST', '/install', [], array_replace($body, ['site_url' => 'ftp://install-e2e.example.test', 'install_action' => 'install'])));
e2e_check($badSchemeResponse->status() === 422 && !is_file($root . '/storage/installed.lock'), 'rejects non-HTTP site URL schemes without leaving install lock');

$preFailureConfig = (string) file_get_contents($root . '/config/app.php');
$preFailureMode = fileperms($root . '/config/app.php') & 0777;
mkdir($root . '/storage/installed.lock');
$failedLockResponse = $controller->store(new Request('POST', '/install', [], array_replace($body, [
    'install_action' => 'install',
    'sqlite_path' => 'storage/database/failed-lock.sqlite',
    'email' => 'rollback@example.test',
])));
e2e_check($failedLockResponse->status() === 500 && str_contains($failedLockResponse->body(), '安装失败'), 'fails safely when installed lock cannot be written');
e2e_check(!file_exists($root . '/storage/installed.lock'), 'cleans invalid installed lock path after failed install');
e2e_check(!is_file($root . '/storage/installing.lock'), 'cleans transient installing lock after failed install');
e2e_check((string) file_get_contents($root . '/config/app.php') === $preFailureConfig, 'restores config contents after post-config install failure');
e2e_check((fileperms($root . '/config/app.php') & 0777) === $preFailureMode && (fileperms($root . '/config/app.php') & 0077) === 0, 'restores config permissions after post-config install failure');

$installResponse = $controller->store(new Request('POST', '/install', [], array_replace($body, ['install_action' => 'install'])));
e2e_check($installResponse->status() === 302 && ($installResponse->headers()['Location'] ?? '') === '/admin/login', 'installs and redirects to admin login');
e2e_check(is_file($root . '/storage/installed.lock'), 'writes installed lock after successful install');
e2e_check(!is_file($root . '/storage/installing.lock'), 'cleans transient installing lock');
e2e_check((fileperms($root . '/storage/installed.lock') & 0077) === 0, 'writes installed lock with owner-only permissions');
e2e_check(is_dir($root . '/storage/updates/incoming') && is_dir($root . '/storage/recovery') && is_dir($root . '/storage/plugin-installs/uploads') && is_dir($root . '/storage/plugin-installs/staging') && is_dir($root . '/content/uploads'), 'creates first-release runtime directories during install');

$installedConfig = require $root . '/config/app.php';
e2e_check(($installedConfig['site']['name'] ?? '') === 'Install E2E Site' && ($installedConfig['site']['id'] ?? '') === 'site-install-e2e', 'writes site identity to config');
e2e_check(str_starts_with((string) ($installedConfig['database']['dsn'] ?? ''), 'sqlite:'), 'writes database configuration to config');
e2e_check(($installedConfig['app']['secure_cookies'] ?? false) === true, 'enables secure cookies when installed with HTTPS site URL');
e2e_check(
    ($installedConfig['payment']['fixture_provider_enabled'] ?? null) === false
    && ($installedConfig['payment']['paid_download_token_ttl_seconds'] ?? null) === 86400
    && ($installedConfig['payment']['paid_download_token_max_uses'] ?? null) === 0
    && ($installedConfig['payment']['paid_content_token_ttl_seconds'] ?? null) === 2592000,
    'writes Core payment defaults with fixture Provider disabled during install'
);
e2e_check(
    is_string($installedConfig['security']['encryption_key'] ?? null)
    && strlen((string) $installedConfig['security']['encryption_key']) >= 64
    && !str_starts_with((string) $installedConfig['security']['encryption_key'], 'change-me')
    && ($installedConfig['security']['encryption_key'] ?? '') !== ($baseConfig['security']['encryption_key'] ?? ''),
    'generates a unique Core encryption key for Provider secrets and Card Delivery during install'
);
e2e_check(
    ($installedConfig['security']['admin_mfa']['reserved_methods'] ?? []) === ['totp', 'passkey', 'recovery_codes'],
    'preserves admin MFA reserved methods during install'
);
e2e_check(
    ($installedConfig['security']['admin_mfa']['runtime_enforcement'] ?? false) === true
    && ($installedConfig['security']['admin_mfa']['implemented_methods'] ?? []) === ['totp', 'recovery_codes'],
    'writes Core admin MFA runtime enforcement defaults during install'
);
e2e_check(
    ($installedConfig['market']['enabled'] ?? true) === false
    && ($installedConfig['market']['developer_mode'] ?? true) === false,
    'writes market and developer mode disabled by default during install'
);
e2e_check((fileperms($root . '/config/app.php') & 0077) === 0, 'writes installed config with owner-only permissions');

$installedSettings = Settings::load($root);
$pdo = ConnectionFactory::make($installedSettings);
$migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_core_migrations')->fetchColumn();
$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_admin_users')->fetchColumn();
e2e_check($migrationCount === count(glob($root . '/system/migrations/*.php') ?: []), 'runs all core migrations during install');
e2e_check($adminCount === 1, 'creates exactly one administrator during install');

$auth = new AdminAuthenticator($pdo);
e2e_check($auth->attempt('admin@example.test', 'install-e2e-secret', '127.0.0.1'), 'created administrator can log in');

$postInstallShow = (new InstallController($root, $installedSettings, new FileLogger($root . '/storage/logs/app.log')))->show();
e2e_check($postInstallShow->status() === 302 && ($postInstallShow->headers()['Location'] ?? '') === '/admin/login', 'installed site cannot re-enter installer');

e2e_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " install E2E checks failed.\n");
    exit(1);
}

echo "Install E2E passed.\n";
