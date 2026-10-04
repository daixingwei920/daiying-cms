<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchEngine;

use Cms\Core\Plugin\PluginSecretStore;
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
            'installed' => $this->pluginInstalled(),
            'site' => '',
            'status' => 'not_connected',
            'credential_status' => 'missing',
            'token_configured' => false,
            'last_sync_at' => '',
            'last_sync_result' => 'Not Available',
            'last_submit_at' => '',
            'last_submit_status' => '',
        ];
        if (!$base['installed'] || !$this->tableExists('baidu_url_submission_settings') || !$this->tableExists('baidu_url_submission_logs')) {
            return $base;
        }

        $repo = $this->repo();
        $settings = $repo->settings();
        $tokenConfigured = is_string($repo->maskedToken()) && $repo->maskedToken() !== '';
        $latest = $repo->recentLogs(1)[0] ?? [];

        return array_merge($base, [
            'site' => (string) ($settings['site_url'] ?? ''),
            'status' => !empty($settings['enabled']) && $tokenConfigured ? 'connected' : 'not_connected',
            'credential_status' => $tokenConfigured ? 'configured' : 'missing',
            'token_configured' => $tokenConfigured,
            'last_submit_at' => (string) ($latest['created_at'] ?? ''),
            'last_submit_status' => (string) ($latest['status'] ?? ''),
        ]);
    }

    /** @return list<array<string,string>> */
    public function recentLogs(int $limit = 10): array
    {
        if (!$this->pluginInstalled() || !$this->tableExists('baidu_url_submission_logs')) {
            return [];
        }

        return array_map(static fn (array $row): array => array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, $row), $this->repo()->recentLogs($limit));
    }

    /** @return array<string,mixed> */
    public function submitUrl(string $url): array
    {
        if (!$this->pluginInstalled()) {
            return ['status' => 'plugin_missing', 'message' => 'official.seo.baidu-submit plugin is not installed.'];
        }
        if (!$this->tableExists('baidu_url_submission_settings') || !$this->tableExists('baidu_url_submission_logs')) {
            return ['status' => 'plugin_schema_missing', 'message' => 'official.seo.baidu-submit plugin tables are not installed.'];
        }

        return $this->service()->submitUrls([$url], 'keyword_center');
    }

    private function pluginInstalled(): bool
    {
        return is_file($this->pluginPath() . '/plugin.json');
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
