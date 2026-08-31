<?php

declare(strict_types=1);

namespace Cms\Core\Support;

use Cms\Core\Config\Settings;
use Cms\Core\Recovery\IntegrityChecker;
use PDO;
use Throwable;

final class SystemHealthDoctor
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
        private readonly PDO $pdo,
    ) {
    }

    /** @return array<string,mixed> */
    public function diagnose(): array
    {
        $checks = array_merge(
            $this->security(),
            $this->performance(),
            $this->dataIntegrity(),
            $this->seo(),
            $this->pluginHealth(),
            $this->themeHealth(),
            $this->updateHealth(),
            $this->backupHealth(),
            $this->environmentHealth(),
        );
        $overall = 'PASS';
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'FAIL') {
                $overall = 'FAIL';
                break;
            }
            if (($check['status'] ?? '') === 'WARNING') {
                $overall = 'WARNING';
            }
        }

        return ['status' => $overall, 'score' => $this->score($checks), 'checks' => $checks];
    }

    /** @return array<string,mixed> */
    public function previewSafeFix(string $checkId): array
    {
        return match ($checkId) {
            'logs.size' => ['status' => 'preview', 'action' => 'rotate oversized logs', 'rollback' => 'preserve archived copy'],
            'cache.runtime' => ['status' => 'preview', 'action' => 'clear runtime cache', 'rollback' => 'not required; cache is regenerated'],
            default => ['status' => 'blocked', 'reason' => 'No safe automatic fix is registered for this check.'],
        };
    }

    /** @return list<array<string,string>> */
    private function security(): array
    {
        return [
            $this->check('security.https', str_starts_with((string) $this->settings->get('site.url', ''), 'https://') ? 'PASS' : 'WARNING', 'Production site URL should use HTTPS.', 'Configure HTTPS certificate and site.url.'),
            $this->check('security.cookies', (bool) $this->settings->get('security.secure_cookies', false) ? 'PASS' : 'WARNING', 'Secure cookies are enabled when configured for HTTPS.', 'Enable secure_cookies on production HTTPS.'),
            $this->check('security.mfa', (bool) $this->settings->get('security.admin_mfa.runtime_enforcement', false) ? 'PASS' : 'WARNING', 'Admin MFA runtime enforcement should be available.', 'Enable TOTP/Passkey for administrators.'),
            $this->check('security.core_integrity', (new IntegrityChecker())->check($this->rootPath)['status'] === 'ok' ? 'PASS' : 'FAIL', 'Core manifest integrity must match installed files.', 'Reinstall trusted Core package or restore from backup.'),
        ];
    }

    /** @return list<array<string,string>> */
    private function performance(): array
    {
        return [
            $this->check('performance.php_opcache', function_exists('opcache_get_status') ? 'PASS' : 'WARNING', 'OPcache improves production PHP performance.', 'Enable OPcache in PHP.'),
            $this->check('logs.size', $this->directorySize($this->rootPath . '/storage/logs') < 20 * 1024 * 1024 ? 'PASS' : 'WARNING', 'Runtime logs should not grow without rotation.', 'Rotate logs or configure log retention.'),
        ];
    }

    /** @return list<array<string,string>> */
    private function dataIntegrity(): array
    {
        $checks = [];
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $checks[] = $this->check('data.database', 'PASS', 'Database responds to a basic integrity probe.', 'Check database credentials and server health.');
        } catch (Throwable $exception) {
            $checks[] = $this->check('data.database', 'FAIL', 'Database probe failed: ' . $exception->getMessage(), 'Repair database connectivity.');
        }
        try {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_media WHERE relative_path IS NOT NULL')->fetchColumn();
            $checks[] = $this->check('data.media_refs', 'PASS', 'Media reference table is readable; media rows: ' . $count, 'Run Site Vault or media repair diagnostics.');
        } catch (Throwable) {
            $checks[] = $this->check('data.media_refs', 'WARNING', 'Media reference table could not be inspected.', 'Run migrations and media diagnostics.');
        }

        return $checks;
    }

    /** @return list<array<string,string>> */
    private function seo(): array
    {
        return [
            $this->check('seo.site_url', (string) $this->settings->get('site.url', '') !== '' ? 'PASS' : 'WARNING', 'Site URL should be configured for canonical links.', 'Set site.url.'),
            $this->check('seo.sitemap', is_file($this->rootPath . '/public/sitemap.xml') ? 'PASS' : 'WARNING', 'Sitemap can be generated or served by theme/plugin.', 'Generate sitemap if SEO indexing is required.'),
        ];
    }

    /** @return list<array<string,string>> */
    private function pluginHealth(): array
    {
        try {
            $broken = (int) $this->pdo->query("SELECT COUNT(*) FROM cms_plugins WHERE status IN ('Quarantined', 'AutoDisabled') OR runtime_status IN ('Degraded', 'AutoDisabled')")->fetchColumn();
            return [$this->check('plugins.runtime', $broken > 0 ? 'FAIL' : 'PASS', 'Broken or isolated plugins: ' . $broken, 'Open Plugin Management and inspect View Error / Retry / Disable.')];
        } catch (Throwable) {
            return [$this->check('plugins.runtime', 'WARNING', 'Plugin health table is unavailable.', 'Run plugin migrations.')];
        }
    }

    /** @return list<array<string,string>> */
    private function themeHealth(): array
    {
        $active = (string) $this->settings->get('theme.active', 'default');
        return [$this->check('theme.active', is_dir($this->rootPath . '/content/themes/' . $active) || $active === 'default' ? 'PASS' : 'FAIL', 'Active theme: ' . $active, 'Switch to a valid theme or safe fallback.')];
    }

    /** @return list<array<string,string>> */
    private function updateHealth(): array
    {
        return [
            $this->check('update.server', (string) $this->settings->get('updates.server_url', '') !== '' ? 'PASS' : 'WARNING', 'Official update server URL is configured.', 'Set updates.server_url.'),
            $this->check('update.public_key', (string) $this->settings->get('updates.public_key', '') !== '' ? 'PASS' : 'FAIL', 'Official update public key is configured.', 'Install official Daiying release package.'),
        ];
    }

    /** @return list<array<string,string>> */
    private function backupHealth(): array
    {
        $latest = glob($this->rootPath . '/storage/recovery/restore-*.zip') ?: [];
        return [$this->check('backup.restore_points', $latest === [] ? 'WARNING' : 'PASS', 'Restore point count: ' . count($latest), 'Create a Site Vault package or restore point before changes.')];
    }

    /** @return list<array<string,string>> */
    private function environmentHealth(): array
    {
        $checks = [
            $this->check('environment.php', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'PASS' : 'FAIL', 'PHP version: ' . PHP_VERSION, 'Upgrade PHP to 8.3 or newer.'),
        ];
        foreach (['pdo', 'openssl', 'curl', 'fileinfo', 'zip', 'mbstring'] as $extension) {
            $checks[] = $this->check('environment.ext.' . $extension, extension_loaded($extension) ? 'PASS' : 'FAIL', 'PHP extension ' . $extension, 'Enable PHP extension ' . $extension . '.');
        }

        return $checks;
    }

    /** @param list<array<string,string>> $checks */
    private function score(array $checks): int
    {
        if ($checks === []) {
            return 0;
        }
        $points = 0;
        foreach ($checks as $check) {
            $points += match ($check['status'] ?? '') {
                'PASS' => 100,
                'WARNING' => 60,
                default => 0,
            };
        }

        return (int) floor($points / count($checks));
    }

    /** @return array{id:string,status:string,message:string,remediation:string} */
    private function check(string $id, string $status, string $message, string $remediation): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message, 'remediation' => $remediation];
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        foreach (glob($path . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $size += filesize($file) ?: 0;
            }
        }

        return $size;
    }
}
