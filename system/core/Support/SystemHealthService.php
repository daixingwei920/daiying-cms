<?php

declare(strict_types=1);

namespace Cms\Core\Support;

use Cms\Core\Auth\AdminMfaService;
use Cms\Core\Auth\AdminPasskeyService;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Integrity\ManifestBuilder;
use Cms\Core\Market\HttpMarketClient;
use PDO;
use Throwable;

final class SystemHealthService
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
    ) {
    }

    /** @return list<array{id:string,label:string,status:string,message:string,remediation:string}> */
    public function checks(): array
    {
        $checks = [];
        $add = static function (string $id, string $label, string $status, string $message, string $remediation = '') use (&$checks): void {
            $checks[] = compact('id', 'label', 'status', 'message', 'remediation');
        };

        $siteUrl = (string) $this->settings->get('site.url', '');
        $siteParts = parse_url($siteUrl);
        $https = is_array($siteParts) && ($siteParts['scheme'] ?? '') === 'https' && !empty($siteParts['host']);
        $add('https', 'HTTPS', $https ? 'PASS' : 'FAIL', $https ? 'site.url 使用 HTTPS。' : 'site.url 不是有效 HTTPS 地址。', '在站点设置中配置正式 HTTPS 域名，并安装有效证书。');

        $add('php.version', 'PHP 版本', version_compare(PHP_VERSION, RuntimeRequirements::PHP_MIN, '>=') ? 'PASS' : 'FAIL', '当前 PHP ' . PHP_VERSION . '，要求 ' . RuntimeRequirements::PHP_MIN . '+。', '升级服务器 PHP 运行环境。');
        foreach (RuntimeRequirements::requiredExtensions() as $extension) {
            $add('php.extension.' . $extension, strtoupper($extension) . ' 扩展', extension_loaded($extension) ? 'PASS' : 'FAIL', extension_loaded($extension) ? '已加载。' : '未加载。', '在 PHP 中安装并启用 ' . $extension . ' 扩展。');
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $pdo->query('SELECT 1');
            $add('database', '数据库连接', 'PASS', '数据库连接正常。');
            $this->databaseBackedChecks($pdo, $add);
        } catch (Throwable $exception) {
            $add('database', '数据库连接', 'FAIL', '数据库不可用：' . $this->safe($exception->getMessage()), '检查 config/app.php 的数据库 DSN、账号、密码和数据库服务状态。');
        }

        foreach (['storage', 'storage/logs', 'storage/cache', 'storage/tmp', 'storage/recovery', 'storage/updates/incoming', 'content/uploads'] as $dir) {
            $path = $this->rootPath . '/' . $dir;
            $add('fs.' . str_replace('/', '.', $dir), $dir . ' 权限', is_dir($path) && is_writable($path) ? 'PASS' : 'FAIL', is_dir($path) && is_writable($path) ? '目录存在且可写。' : '目录不存在或不可写。', '创建目录并授予 PHP 运行用户写入权限。');
        }

        $cron = is_file($this->rootPath . '/scripts/publish_scheduled_content.php');
        $add('scheduler', '计划任务/cron', $cron ? 'WARNING' : 'FAIL', $cron ? '发布脚本存在，cron 是否已配置需要在正式服务器确认。' : '发布脚本不存在。', '在服务器 cron 中定时执行 php scripts/publish_scheduled_content.php。');

        $mailConfigured = (string) $this->settings->get('mail.from', '') !== '' || (string) $this->settings->get('mail.smtp_host', '') !== '';
        $add('mail', '邮件发送', $mailConfigured ? 'WARNING' : 'FAIL', $mailConfigured ? '检测到邮件配置，真实投递需外部验收。' : '未检测到邮件配置。', '配置 SMTP，并实际发送密码重置测试邮件。');

        $updateServer = trim((string) $this->settings->get('updates.server_url', ''));
        $add('update.service', '官方更新服务', $updateServer !== '' ? 'WARNING' : 'FAIL', $updateServer !== '' ? '已配置：' . $updateServer . '，连通性需正式网络验收。' : '未配置官方更新服务地址。', '配置 updates.server_url 并在生产域名下执行一次更新检查。');

        $marketServer = trim((string) $this->settings->get('market.server_url', ''));
        if ((bool) $this->settings->get('market.enabled', false) && $marketServer !== '') {
            try {
                $diagnostics = (new HttpMarketClient($marketServer, (string) $this->settings->get('market.site_token', ''), (string) $this->settings->get('app.version', '0.0.0'), 'stable', $this->rootPath . '/storage/cache'))->diagnostics(true);
                $ok = (string) ($diagnostics['status'] ?? '') === 'ok' && (int) ($diagnostics['http_status'] ?? 0) === 200;
                $add('market.connectivity', '官方市场连接', $ok ? 'PASS' : 'WARNING', $ok ? '市场 API 返回 HTTP 200。' : '市场 API 未返回明确成功状态。', '在后台市场诊断中点击测试官方市场连接。');
            } catch (Throwable $exception) {
                $add('market.connectivity', '官方市场连接', 'WARNING', '市场连接测试失败：' . $this->safe($exception->getMessage()), '检查 DNS、HTTPS、CDN、反向代理和 MARKET_SERVER_URL。');
            }
        } else {
            $add('market.connectivity', '官方市场连接', 'WARNING', '官方市场未启用或未配置。', '如需在线插件/主题市场，请启用 market.enabled 并配置 market.server_url。');
        }

        $cookieSecure = (bool) $this->settings->get('app.secure_cookies', false);
        $add('sessions.cookies', '会话 Cookie', $cookieSecure && $https ? 'PASS' : 'WARNING', $cookieSecure ? 'Secure/HttpOnly/SameSite Cookie 已由核心会话管理启用。' : 'secure_cookies 未启用。', 'HTTPS 生产站点应设置 app.secure_cookies=true。');
        $add('passkey.readiness', 'Passkey/WebAuthn', $https && extension_loaded('openssl') ? 'PASS' : 'FAIL', $https && extension_loaded('openssl') ? '满足浏览器 Passkey 基础要求。' : 'Passkey 需要 HTTPS 与 OpenSSL。', '使用正式 HTTPS 域名登录后台后注册 Passkey。');

        $backupDir = $this->rootPath . '/storage/recovery';
        $add('backups', '备份/恢复点', is_dir($backupDir) && is_writable($backupDir) ? 'PASS' : 'FAIL', is_dir($backupDir) && is_writable($backupDir) ? '恢复点目录可写。' : '恢复点目录不可写。', '创建 storage/recovery 并授予 PHP 写权限。');
        $add('install.lock', '安装锁', is_file($this->rootPath . '/storage/installed.lock') ? 'PASS' : 'FAIL', is_file($this->rootPath . '/storage/installed.lock') ? '安装锁存在。' : '安装锁不存在。', '完成安装后必须生成 storage/installed.lock。');

        $integrity = $this->coreIntegrity();
        $add('core.integrity', 'Core 完整性', $integrity['ok'] ? 'PASS' : 'FAIL', $integrity['message'], '重新发布包含最新 system/core-manifest.json 的官方包。');
        $add('security.config', '安全配置', ((bool) $this->settings->get('app.debug', true) === false && (string) $this->settings->get('app.mode', '') === 'NORMAL') ? 'PASS' : 'WARNING', 'debug=' . json_encode($this->settings->get('app.debug')) . ', mode=' . (string) $this->settings->get('app.mode', ''), '生产环境关闭 debug，并设置 app.mode=NORMAL。');

        return $checks;
    }

    private function databaseBackedChecks(PDO $pdo, callable $add): void
    {
        try {
            $pending = $this->pendingMigrationCount($pdo);
            $add('migrations.pending', '待执行迁移', $pending === 0 ? 'PASS' : 'FAIL', $pending === 0 ? '没有待执行迁移。' : '存在 ' . $pending . ' 个待执行迁移。', '在安装器或升级流程中运行迁移。');
        } catch (Throwable $exception) {
            $add('migrations.pending', '待执行迁移', 'WARNING', '无法检查迁移：' . $this->safe($exception->getMessage()), '确认 cms_migrations 表存在且数据库可写。');
        }

        try {
            $adminId = (int) ($pdo->query('SELECT id FROM cms_admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
            if ($adminId > 0) {
                $mfa = new AdminMfaService($pdo);
                $passkeys = new AdminPasskeyService($pdo, $this->settings);
                $enabled = $mfa->isEnabled($adminId) || $passkeys->hasPasskey($adminId);
                $add('admin.mfa', '管理员 MFA', $enabled ? 'PASS' : 'WARNING', $enabled ? '至少一名管理员已配置 MFA 或 Passkey。' : '未检测到管理员 MFA/Passkey。', '在 后台 -> 后台安全 启用 TOTP 或添加 Passkey。');
            } else {
                $add('admin.mfa', '管理员 MFA', 'WARNING', '尚未创建 Owner 管理员。', '安装时创建 Owner，并立即配置 MFA 或 Passkey。');
            }
        } catch (Throwable $exception) {
            $add('admin.mfa', '管理员 MFA', 'WARNING', '无法检查 MFA：' . $this->safe($exception->getMessage()), '确认管理员和 MFA 迁移已执行。');
        }
    }

    private function pendingMigrationCount(PDO $pdo): int
    {
        $applied = [];
        try {
            $rows = $pdo->query('SELECT migration_id FROM cms_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $applied = array_fill_keys(array_map('strval', $rows), true);
        } catch (Throwable) {
            return count(glob($this->rootPath . '/system/migrations/*.php') ?: []);
        }

        $pending = 0;
        foreach (glob($this->rootPath . '/system/migrations/*.php') ?: [] as $file) {
            $migration = require $file;
            if (method_exists($migration, 'id') && !isset($applied[(string) $migration->id()])) {
                $pending++;
            }
        }
        return $pending;
    }

    /** @return array{ok:bool,message:string} */
    private function coreIntegrity(): array
    {
        $manifest = $this->rootPath . '/system/core-manifest.json';
        $core = $this->rootPath . '/system/core';
        if (!is_file($manifest) || !is_dir($core)) {
            return ['ok' => false, 'message' => 'Core manifest 或 system/core 缺失。'];
        }
        try {
            $expected = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            $actual = ManifestBuilder::build($core);
            if ($expected === $actual) {
                return ['ok' => true, 'message' => 'Core manifest 与当前文件一致。'];
            }
            return ['ok' => false, 'message' => 'Core manifest 与当前文件不一致。'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Core 完整性检查失败：' . $this->safe($exception->getMessage())];
        }
    }

    private function safe(string $message): string
    {
        $message = preg_replace('/\b(password|secret|token|session|private_key|dsn)\b\s*[:=]\s*([^\s"\']+)/i', '$1=[redacted]', $message) ?: $message;
        return mb_substr($message, 0, 240);
    }
}
