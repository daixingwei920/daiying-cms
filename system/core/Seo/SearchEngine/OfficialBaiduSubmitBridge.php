<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchEngine;

use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Queue\QueueService;
use PDO;

final class OfficialBaiduSubmitBridge
{
    private const PLUGIN_ID = 'official.seo.baidu-submit';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $rootPath,
        private readonly string $encryptionKey,
        private readonly mixed $transport = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $base = [
            'engine' => 'baidu',
            'plugin_id' => self::PLUGIN_ID,
            'plugin_files_present' => $this->pluginFilesPresent(),
            'plugin_installed' => false,
            'plugin_enabled' => false,
            'route_available' => false,
            'configured' => false,
            'connected' => false,
            'installed' => false,
            'enabled' => false,
            'site' => '',
            'status' => 'plugin_not_installed',
            'status_label' => 'Plugin Not Installed',
            'credential_status' => 'missing',
            'token_configured' => false,
            'last_sync_at' => '',
            'last_sync_result' => 'Not Available',
            'last_submit_at' => '',
            'last_submit_status' => '',
            'manage_url' => '',
            'management_available' => false,
            'message' => '百度推送插件未安装',
        ];
        $pluginStatus = $this->pluginStatus();
        $base['plugin_installed'] = $base['installed'] = $pluginStatus !== null && $pluginStatus !== PluginLifecycle::REMOVED && $base['plugin_files_present'];
        $base['plugin_enabled'] = $base['enabled'] = $base['plugin_installed'] && $pluginStatus === PluginLifecycle::ENABLED;
        $base['route_available'] = $base['plugin_enabled'] && $this->adminRouteDeclared('/admin/seo/baidu-submit') && is_file($this->pluginPath() . '/plugin.php');
        if (!$base['plugin_installed']) {
            return $base;
        }
        if (!$base['plugin_enabled']) {
            return array_merge($base, [
                'status' => 'plugin_disabled',
                'status_label' => 'Plugin Disabled',
                'message' => '百度推送插件未启用',
            ]);
        }
        if (!$base['route_available']) {
            return array_merge($base, [
                'status' => 'integration_error',
                'status_label' => 'Integration Error',
                'message' => '百度推送插件路由不可用',
            ]);
        }
        $base['manage_url'] = '/admin/seo/baidu-submit';
        $base['management_available'] = true;

        if (!$this->tableExists('baidu_url_submission_settings') || !$this->tableExists('baidu_url_submission_logs')) {
            return array_merge($base, [
                'status' => 'integration_error',
                'status_label' => 'Integration Error',
                'message' => '百度推送插件数据表不可用',
            ]);
        }

        $repo = $this->repo();
        $settings = $repo->settings();
        $tokenConfigured = is_string($repo->maskedToken()) && $repo->maskedToken() !== '';
        $latest = $repo->recentLogs(1)[0] ?? [];
        $configured = !empty($settings['enabled']) && $tokenConfigured && (string) ($settings['site_url'] ?? '') !== '';
        $status = $configured ? 'connected' : 'not_configured';

        return array_merge($base, [
            'site' => (string) ($settings['site_url'] ?? ''),
            'status' => $status,
            'status_label' => $configured ? 'Connected' : 'Not Configured',
            'configured' => $configured,
            'connected' => $configured,
            'credential_status' => $tokenConfigured ? 'configured' : 'missing',
            'token_configured' => $tokenConfigured,
            'last_submit_at' => (string) ($latest['created_at'] ?? ''),
            'last_submit_status' => (string) ($latest['status'] ?? ''),
            'message' => $configured ? 'Connected' : '百度推送插件尚未配置 Token 或站点地址',
        ]);
    }

    /** @return list<array<string,string>> */
    public function recentLogs(int $limit = 10): array
    {
        $status = $this->status();
        if (empty($status['plugin_enabled']) || empty($status['route_available']) || !$this->tableExists('baidu_url_submission_logs')) {
            return [];
        }

        return array_map(static fn (array $row): array => array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, $row), $this->repo()->recentLogs($limit));
    }

    /** @return array<string,mixed> */
    public function submitUrl(string $url): array
    {
        $status = $this->status();
        if (empty($status['plugin_installed'])) {
            return ['status' => 'plugin_missing', 'message' => 'official.seo.baidu-submit plugin is not installed.'];
        }
        if (empty($status['plugin_enabled'])) {
            return ['status' => 'plugin_disabled', 'message' => 'official.seo.baidu-submit plugin is disabled.'];
        }
        if (empty($status['route_available'])) {
            return ['status' => 'integration_error', 'message' => 'official.seo.baidu-submit admin route is not available.'];
        }
        if (!$this->tableExists('baidu_url_submission_settings') || !$this->tableExists('baidu_url_submission_logs')) {
            return ['status' => 'plugin_schema_missing', 'message' => 'official.seo.baidu-submit plugin tables are not installed.'];
        }

        return $this->service()->submitUrls([$url], 'keyword_center');
    }

    private function pluginFilesPresent(): bool
    {
        return is_file($this->pluginPath() . '/plugin.json');
    }

    private function pluginStatus(): ?string
    {
        if (!$this->tableExists('cms_plugins')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT status FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
        $stmt->execute([':plugin_id' => self::PLUGIN_ID]);
        $status = $stmt->fetchColumn();
        return is_string($status) && $status !== '' ? $status : null;
    }

    private function adminRouteDeclared(string $route): bool
    {
        $manifest = $this->manifest();
        $routes = is_array($manifest['admin_routes'] ?? null) ? $manifest['admin_routes'] : [];
        return in_array($route, array_map('strval', $routes), true);
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $file = $this->pluginPath() . '/plugin.json';
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function pluginPath(): string
    {
        return rtrim($this->rootPath, '/') . '/content/plugins/' . self::PLUGIN_ID;
    }

    private function requirePluginClasses(): void
    {
        require_once $this->pluginPath() . '/src/BaiduUrlSubmissionResult.php';
        require_once $this->pluginPath() . '/src/BaiduUrlSubmissionTransportInterface.php';
        require_once $this->pluginPath() . '/src/BaiduUrlSubmissionRepository.php';
        require_once $this->pluginPath() . '/src/BaiduUrlSubmissionService.php';
        require_once $this->pluginPath() . '/src/BaiduUrlSubmissionHttpClient.php';
    }

    private function repo(): object
    {
        $this->requirePluginClasses();
        $class = 'Official\\Seo\\BaiduSubmit\\BaiduUrlSubmissionRepository';
        return new $class($this->pdo, new PluginSecretStore($this->pdo, $this->encryptionKey));
    }

    private function service(): object
    {
        $this->requirePluginClasses();
        $transport = $this->transport;
        if (!is_object($transport)) {
            $class = 'Official\\Seo\\BaiduSubmit\\BaiduUrlSubmissionHttpClient';
            $transport = new $class();
        }
        $class = 'Official\\Seo\\BaiduSubmit\\BaiduUrlSubmissionService';
        return new $class($this->repo(), $transport, new QueueService($this->pdo));
    }

    private function tableExists(string $table): bool
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute([':table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
