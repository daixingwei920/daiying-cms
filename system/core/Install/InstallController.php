<?php

declare(strict_types=1);

namespace Cms\Core\Install;

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\RuntimeRequirements;
use Cms\Core\Support\View;
use PDO;
use Throwable;

final class InstallController
{
    private const OFFICIAL_UPDATE_SERVER_URL = 'https://updates.daiyinggame.com';

    private const OFFICIAL_UPDATE_PUBLIC_KEY = 'h5vP/I/pAXWIz4GQ8h2LryHvyP+GW0Fc8AFEUHu0jms=';

    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
        private readonly FileLogger $logger,
    ) {
    }

    public function show(): Response
    {
        if ($this->isInstalled()) {
            return Response::redirect('/admin/login');
        }

        return Response::html(View::page('安装 PHP CMS', $this->form()));
    }

    public function store(Request $request): Response
    {
        if ($this->isInstalled()) {
            return Response::redirect('/admin/login');
        }

        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::html(View::page('安装 PHP CMS', $this->form('CSRF 校验失败，请刷新页面重试。')), 400);
        }

        try {
            $database = $this->databaseConfig($request);
        } catch (Throwable $exception) {
            return Response::html(View::page('安装 PHP CMS', $this->form('数据库配置无效：' . $exception->getMessage(), '', $request)), 422);
        }
        if ((string) $request->input('install_action', '') === 'test_database') {
            try {
                $this->testDatabase($database);
                return Response::html(View::page('安装 PHP CMS', $this->form('', '数据库连接测试通过，可以继续安装。', $request)));
            } catch (Throwable $exception) {
                $this->logger->error('Install database test failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
                return Response::html(View::page('安装 PHP CMS', $this->form('数据库连接测试失败：' . $exception->getMessage(), '', $request)), 422);
            }
        }

        if (!$this->environmentReady()) {
            return Response::html(View::page('安装 PHP CMS', $this->form('当前环境未满足安装要求，请按环境检查结果修复后重试。', '', $request)), 422);
        }

        $siteName = trim((string) $request->input('site_name', ''));
        $siteUrl = trim((string) $request->input('site_url', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $displayName = trim((string) $request->input('display_name', ''));

        if ($siteName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || $displayName === '') {
            return Response::html(View::page('安装 PHP CMS', $this->form('请填写站点名称、有效邮箱、显示名称，并使用至少 10 位密码。', '', $request)), 422);
        }
        if (!$this->validSiteUrl($siteUrl)) {
            return Response::html(View::page('安装 PHP CMS', $this->form('站点 URL 必须是有效 HTTP/HTTPS URL，或留空稍后配置。', '', $request)), 422);
        }

        $siteId = trim((string) $request->input('site_id', ''));
        $siteSecret = trim((string) $request->input('site_secret', ''));
        $siteId = $siteId !== '' ? $siteId : $this->generateSiteUuid();
        $siteSecret = $siteSecret !== '' ? $siteSecret : bin2hex(random_bytes(32));
        $lock = $this->acquireInstallLock();
        $wroteConfig = false;
        $configPath = $this->rootPath . '/config/app.php';
        $configBackup = is_file($configPath) ? (string) file_get_contents($configPath) : null;
        $configBackupMode = is_file($configPath) ? (fileperms($configPath) & 0777) : null;

        try {
            $pdo = $this->testDatabase($database);
            $migrations = [];
            foreach (glob($this->rootPath . '/system/migrations/*.php') ?: [] as $file) {
                $migrations[] = require $file;
            }
            (new MigrationRunner($pdo, $migrations))->run();

            $pdo->beginTransaction();
            try {
                (new AdminAuthenticator($pdo))->createAdmin($email, $password, $displayName);
                $this->saveCoreSetting($pdo, 'site.name', $siteName);
                $this->saveCoreSetting($pdo, 'site.url', $siteUrl);
                $this->saveCoreSetting($pdo, 'site.id', $siteId);
                $this->saveCoreSetting($pdo, 'site.secret_created_at', gmdate('c'));
                (new AuditLogger($pdo))->record('system', null, 'install.completed', ['site_name' => $siteName, 'site_id' => $siteId]);
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $this->writeConfig($database, $siteName, $siteUrl, $siteId, $siteSecret);
            $wroteConfig = true;
            $this->ensureRuntimeDirectories();
            $this->writeInstalledLock($siteId);
        } catch (Throwable $exception) {
            $this->cleanupInstalledLock();
            if ($wroteConfig) {
                $this->restoreConfigBackup($configBackup, $configBackupMode);
            }
            $this->logger->error('Install failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::html(View::page('安装 PHP CMS', $this->form('安装失败：' . $exception->getMessage(), '', $request)), 500);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            @unlink($this->installingLockPath());
        }

        return Response::redirect('/admin/login');
    }

    private function isInstalled(): bool
    {
        return is_file($this->rootPath . '/storage/installed.lock');
    }

    private function validSiteUrl(string $siteUrl): bool
    {
        if ($siteUrl === '') {
            return true;
        }
        if (filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) (parse_url($siteUrl, PHP_URL_SCHEME) ?: ''));

        return in_array($scheme, ['http', 'https'], true);
    }

    private function form(string $error = '', string $success = '', ?Request $request = null): string
    {
        $errorHtml = $error === '' ? '' : '<p class="error">' . View::escape($error) . '</p>';
        $successHtml = $success === '' ? '' : '<p class="muted"><strong>' . View::escape($success) . '</strong></p>';
        $envRows = '';
        foreach ($this->environmentChecks() as $check) {
            $envRows .= '<tr><td>' . View::escape($check['label']) . '</td><td>' . ($check['ok'] ? '通过' : '失败') . '</td><td>' . View::escape($check['detail']) . '</td></tr>';
        }

        $driver = $this->value($request, 'db_driver', 'sqlite');
        $sqlitePath = $this->value($request, 'sqlite_path', 'storage/database/cms.sqlite');
        $mysqlHost = $this->value($request, 'mysql_host', '127.0.0.1');
        $mysqlPort = $this->value($request, 'mysql_port', '3306');
        $mysqlDatabase = $this->value($request, 'mysql_database', 'php_cms');
        $mysqlUsername = $this->value($request, 'mysql_username', '');
        return '<h1>安装 PHP CMS</h1><p class="muted">安装向导会完成环境检查、数据库连接测试、站点身份、管理员、Core migrations、默认主题和安装锁。</p>' .
            $errorHtml .
            $successHtml .
            '<form method="post" action="/install">' . CsrfToken::field() .
            '<h2>1. 环境检查</h2><table><thead><tr><th>项目</th><th>状态</th><th>说明</th></tr></thead><tbody>' . $envRows . '</tbody></table>' .
            '<h2>2. 数据库配置</h2>' .
            '<label>数据库类型<select name="db_driver"><option value="sqlite"' . ($driver === 'sqlite' ? ' selected' : '') . '>SQLite</option><option value="mysql"' . ($driver === 'mysql' ? ' selected' : '') . '>MySQL / MariaDB</option></select></label>' .
            '<label>SQLite 文件路径<input name="sqlite_path" value="' . View::escape($sqlitePath) . '"></label>' .
            '<label>MySQL Host<input name="mysql_host" value="' . View::escape($mysqlHost) . '"></label>' .
            '<label>MySQL Port<input name="mysql_port" value="' . View::escape($mysqlPort) . '"></label>' .
            '<label>MySQL Database<input name="mysql_database" value="' . View::escape($mysqlDatabase) . '"></label>' .
            '<label>MySQL Username<input name="mysql_username" value="' . View::escape($mysqlUsername) . '"></label>' .
            '<label>MySQL Password<input name="mysql_password" type="password" value="' . View::escape($this->value($request, 'mysql_password', '')) . '"></label>' .
            '<button type="submit" name="install_action" value="test_database">测试数据库连接</button>' .
            '<h2>3. 站点信息</h2>' .
            '<label>站点名称<input name="site_name" value="' . View::escape($this->value($request, 'site_name', 'PHP CMS')) . '" required></label>' .
            '<label>站点 URL<input name="site_url" value="' . View::escape($this->value($request, 'site_url', '')) . '" placeholder="https://example.com"></label>' .
            '<h2>4. 管理员</h2>' .
            '<label>管理员邮箱<input name="email" type="email" value="' . View::escape($this->value($request, 'email', '')) . '" required></label>' .
            '<label>显示名称<input name="display_name" value="' . View::escape($this->value($request, 'display_name', '')) . '" required></label>' .
            '<label>管理员密码<input name="password" type="password" minlength="10" required></label>' .
            '<p class="muted">站点 ID、站点密钥、本地加密密钥、官方更新服务器和官方公钥会自动生成或写入；普通用户无需手工配置。</p>' .
            '<button type="submit" name="install_action" value="install">开始安装</button></form>';
    }

    /** @return array{dsn:string,username:string,password:string,options:array<string, mixed>,driver:string} */
    private function databaseConfig(Request $request): array
    {
        $driver = (string) $request->input('db_driver', 'sqlite');
        if ($driver === 'mysql') {
            $host = trim((string) $request->input('mysql_host', '127.0.0.1'));
            $port = (int) $request->input('mysql_port', 3306);
            $database = trim((string) $request->input('mysql_database', ''));
            if ($host === '' || $database === '' || $port < 1) {
                throw new \InvalidArgumentException('MySQL host、port 和 database 必须填写。');
            }

            return [
                'dsn' => 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
                'username' => trim((string) $request->input('mysql_username', '')),
                'password' => (string) $request->input('mysql_password', ''),
                'options' => [],
                'driver' => 'mysql',
            ];
        }

        $path = trim((string) $request->input('sqlite_path', 'storage/database/cms.sqlite'));
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('SQLite 文件路径无效。');
        }
        $absolute = str_starts_with($path, '/') ? $path : $this->rootPath . '/' . ltrim($path, '/');
        $realRoot = realpath($this->rootPath);
        $parent = dirname($absolute);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new \RuntimeException('无法创建 SQLite 目录。');
        }
        $realParent = realpath($parent);
        if ($realRoot === false || $realParent === false || !str_starts_with($realParent, $realRoot)) {
            throw new \InvalidArgumentException('SQLite 文件必须位于 CMS 目录内。');
        }

        return [
            'dsn' => 'sqlite:' . $absolute,
            'username' => '',
            'password' => '',
            'options' => [],
            'driver' => 'sqlite',
        ];
    }

    /** @param array{dsn:string,username:string,password:string,options:array<string, mixed>,driver:string} $database */
    private function testDatabase(array $database): PDO
    {
        $pdo = ConnectionFactory::make(Settings::fromArray(['database' => $database]));
        $pdo->query('SELECT 1')->fetchColumn();

        return $pdo;
    }

    /** @return resource */
    private function acquireInstallLock()
    {
        $path = $this->installingLockPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建安装锁目录。');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('安装正在进行，请稍后重试。');
        }
        ftruncate($handle, 0);
        fwrite($handle, gmdate('c') . PHP_EOL);

        return $handle;
    }

    private function installingLockPath(): string
    {
        return $this->rootPath . '/storage/installing.lock';
    }

    private function writeInstalledLock(string $siteId): void
    {
        $payload = ['installed_at' => gmdate('c'), 'site_id' => $siteId, 'version' => (string) $this->settings->get('app.version', '0.0.0')];
        $target = $this->rootPath . '/storage/installed.lock';
        if (is_dir($target) || is_link($target)) {
            throw new \RuntimeException('安装完成锁路径不可写。');
        }
        if (file_put_contents($target, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入安装完成锁。');
        }
        @chmod($target, 0600);
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach ([
            'storage/logs',
            'storage/cache',
            'storage/tmp',
            'storage/database',
            'storage/updates/incoming',
            'storage/recovery',
            'storage/plugin-installs/uploads',
            'storage/plugin-installs/staging',
            'content/uploads',
        ] as $directory) {
            $path = $this->rootPath . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new \RuntimeException('无法创建运行目录：' . $directory);
            }
        }
    }

    private function cleanupInstalledLock(): void
    {
        $target = $this->rootPath . '/storage/installed.lock';
        if (is_file($target) || is_link($target)) {
            @unlink($target);
            return;
        }
        if (is_dir($target)) {
            @rmdir($target);
        }
    }

    private function restoreConfigBackup(?string $backup, ?int $mode): void
    {
        $target = $this->rootPath . '/config/app.php';
        if ($backup === null) {
            @unlink($target);
            return;
        }

        $tmp = $target . '.rollback-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $backup, LOCK_EX) === false) {
            throw new \RuntimeException('无法恢复安装前配置文件。');
        }
        @chmod($tmp, $mode ?? 0600);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('无法完成安装前配置恢复。');
        }
        @chmod($target, $mode ?? 0600);
    }

    /** @param array{dsn:string,username:string,password:string,options:array<string, mixed>,driver:string} $database */
    private function writeConfig(array $database, string $siteName, string $siteUrl, string $siteId, string $siteSecret): void
    {
        $items = $this->settings->all();
        $items['site'] = [
            'name' => $siteName,
            'url' => $siteUrl,
            'id' => $siteId,
            'secret' => $siteSecret,
        ];
        $items['app']['secure_cookies'] = $this->secureCookiesForSiteUrl($siteUrl);
        $items['database'] = [
            'dsn' => $database['dsn'],
            'username' => $database['username'],
            'password' => $database['password'],
            'options' => $database['options'],
        ];
        $items['updates'] = is_array($items['updates'] ?? null) ? $items['updates'] : [];
        $updatePublicKey = trim((string) ($items['updates']['public_key'] ?? ''));
        if ($updatePublicKey === '' || str_contains($updatePublicKey, "\n...\n")) {
            $items['updates']['public_key'] = self::OFFICIAL_UPDATE_PUBLIC_KEY;
        }
        $updateServerUrl = trim((string) ($items['updates']['server_url'] ?? ''));
        $items['updates']['server_url'] = $updateServerUrl !== '' ? rtrim($updateServerUrl, '/') : self::OFFICIAL_UPDATE_SERVER_URL;
        $items['theme']['active'] = $items['theme']['active'] ?? 'default';
        $items['theme']['settings']['default'] = $items['theme']['settings']['default'] ?? ['accent_color' => '#1f6feb'];
        $items['security'] = is_array($items['security'] ?? null) ? $items['security'] : [];
        $items['security']['encryption_key'] = $this->installedEncryptionKey((string) ($items['security']['encryption_key'] ?? ''));
        $items['security']['admin_mfa'] = is_array($items['security']['admin_mfa'] ?? null) ? $items['security']['admin_mfa'] : [];
        unset($items['security']['admin_mfa']['post_release_enforcement_planned']);
        $items['security']['admin_mfa']['runtime_enforcement'] = true;
        $items['security']['admin_mfa']['implemented_methods'] = ['totp', 'passkey', 'recovery_codes'];
        $items['security']['admin_mfa']['reserved_methods'] = ['totp', 'passkey', 'recovery_codes'];
        $items['payment'] = array_replace([
            'fixture_provider_enabled' => false,
            'paid_download_token_ttl_seconds' => 86400,
            'paid_download_token_max_uses' => 0,
            'paid_content_token_ttl_seconds' => 2592000,
        ], is_array($items['payment'] ?? null) ? $items['payment'] : []);
        $items['payment']['fixture_provider_enabled'] = false;
        $items['market'] = array_replace([
            'enabled' => true,
            'developer_mode' => false,
            'server_url' => self::OFFICIAL_UPDATE_SERVER_URL,
            'channel' => 'stable',
            'site_token' => '',
        ], is_array($items['market'] ?? null) ? $items['market'] : []);
        $items['market']['enabled'] = (bool) $items['market']['enabled'];
        $items['market']['developer_mode'] = (bool) $items['market']['developer_mode'] && (bool) $items['market']['enabled'];
        $marketServerUrl = trim((string) ($items['market']['server_url'] ?? ''));
        $items['market']['server_url'] = $marketServerUrl !== '' ? rtrim($marketServerUrl, '/') : self::OFFICIAL_UPDATE_SERVER_URL;
        $items['market']['channel'] = in_array((string) ($items['market']['channel'] ?? 'stable'), ['stable', 'rc', 'beta', 'dev'], true)
            ? (string) $items['market']['channel']
            : 'stable';
        $items['market']['site_token'] = trim((string) ($items['market']['site_token'] ?? ''));
        $items['review'] = array_replace([
            'server_url' => self::OFFICIAL_UPDATE_SERVER_URL,
            'max_zip_bytes' => 20971520,
        ], is_array($items['review'] ?? null) ? $items['review'] : []);
        $reviewServerUrl = trim((string) ($items['review']['server_url'] ?? ''));
        $items['review']['server_url'] = $reviewServerUrl !== '' ? rtrim($reviewServerUrl, '/') : $items['updates']['server_url'];
        $items['review']['max_zip_bytes'] = max(1048576, (int) ($items['review']['max_zip_bytes'] ?? 20971520));
        $items['comments'] = array_replace([
            'enabled' => true,
            'allow_guest' => true,
            'require_approval' => true,
        ], is_array($items['comments'] ?? null) ? $items['comments'] : []);
        $items['mail'] = array_replace([
            'from' => '',
            'smtp_host' => '',
        ], is_array($items['mail'] ?? null) ? $items['mail'] : []);
        $items['comments']['enabled'] = (bool) $items['comments']['enabled'];
        $items['comments']['allow_guest'] = (bool) $items['comments']['allow_guest'];
        $items['comments']['require_approval'] = (bool) $items['comments']['require_approval'];

        $config = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($items, true) . ";\n";
        $target = $this->rootPath . '/config/app.php';
        $tmp = $target . '.installing';
        if (file_put_contents($tmp, $config, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入配置文件。');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('无法完成配置文件写入。');
        }
        @chmod($target, 0600);
    }

    private function secureCookiesForSiteUrl(string $siteUrl): bool
    {
        $existing = (bool) $this->settings->get('app.secure_cookies', false);
        $scheme = strtolower((string) (parse_url($siteUrl, PHP_URL_SCHEME) ?: ''));

        return $existing || $scheme === 'https';
    }

    private function installedEncryptionKey(string $existing): string
    {
        if (
            strlen($existing) >= 32
            && $existing === trim($existing)
            && preg_match('/[\x00-\x1F\x7F]/', $existing) !== 1
            && !str_starts_with(strtolower($existing), 'change-me')
        ) {
            return $existing;
        }

        return bin2hex(random_bytes(32));
    }

    private function generateSiteUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function saveCoreSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO cms_core_settings (setting_key, setting_value, updated_at)
             VALUES (:key, :value, :updated_at)'
        );
        $stmt->execute([':key' => $key, ':value' => $value, ':updated_at' => gmdate('c')]);
    }

    /** @return list<array{label:string,ok:bool,detail:string}> */
    private function environmentChecks(): array
    {
        return array_merge([
            ['label' => 'PHP 版本', 'ok' => version_compare(PHP_VERSION, RuntimeRequirements::PHP_MIN, '>='), 'detail' => PHP_VERSION . '，要求 ' . RuntimeRequirements::PHP_MIN . '+'],
        ], $this->runtimeExtensionChecks(), [
            ['label' => '配置目录可写', 'ok' => is_writable($this->rootPath . '/config'), 'detail' => 'config/app.php 将在安装成功后原子写入'],
            ['label' => 'Storage 可写', 'ok' => is_writable($this->rootPath . '/storage'), 'detail' => '安装锁、SQLite、日志和运行数据写入 storage'],
            ['label' => '默认主题', 'ok' => is_file($this->rootPath . '/content/themes/default/theme.json'), 'detail' => '安装后默认启用 default 主题'],
        ]);
    }

    /** @return list<array{label:string,ok:bool,detail:string}> */
    private function runtimeExtensionChecks(): array
    {
        $checks = [];
        foreach (RuntimeRequirements::requiredExtensions() as $extension) {
            $extension = strtolower($extension);
            $ok = $this->runtimeExtensionAvailable($extension);
            $checks[] = [
                'label' => $this->runtimeExtensionLabel($extension),
                'ok' => $ok,
                'detail' => $ok ? $this->runtimeExtensionEnabledDetail($extension) : $this->runtimeExtensionMissingDetail($extension),
            ];
        }

        return $checks;
    }

    private function runtimeExtensionAvailable(string $extension): bool
    {
        return match ($extension) {
            'fileinfo' => extension_loaded('fileinfo') && class_exists('finfo') && defined('FILEINFO_MIME_TYPE'),
            'zip' => extension_loaded('zip') && class_exists('ZipArchive'),
            default => extension_loaded($extension),
        };
    }

    private function runtimeExtensionLabel(string $extension): string
    {
        return match ($extension) {
            'pdo' => 'PDO',
            'json' => 'JSON',
            'openssl' => 'OpenSSL',
            'fileinfo' => 'PHP Fileinfo',
            'zip' => 'PHP ZipArchive',
            default => strtoupper($extension),
        };
    }

    private function runtimeExtensionEnabledDetail(string $extension): string
    {
        return match ($extension) {
            'fileinfo' => '已启用，用于媒体上传 MIME 类型安全检测',
            'zip' => '已启用，用于主题/插件 ZIP 安装、导出包、恢复点和 Core 更新包处理',
            default => '已启用',
        };
    }

    private function runtimeExtensionMissingDetail(string $extension): string
    {
        return match ($extension) {
            'fileinfo' => '未启用。请在服务器 PHP 环境中启用 Fileinfo 扩展后重试媒体上传。',
            'zip' => '未启用。请在服务器 PHP 环境中启用 Zip 扩展后重试安装。',
            default => '未启用',
        };
    }

    private function environmentReady(): bool
    {
        foreach ($this->environmentChecks() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    private function value(?Request $request, string $key, string $default): string
    {
        if ($request === null) {
            return $default;
        }

        return (string) $request->input($key, $default);
    }
}
