<?php

declare(strict_types=1);

namespace Cms\Core\Recovery;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\RuntimeRequirements;
use Cms\Core\Support\View;
use PDO;
use Throwable;

final class RecoveryController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Settings $settings,
        private readonly FileLogger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $adminContext = $this->isAdminRecoveryPath($request->path);
        if ($adminContext && $this->adminUserId() === null) {
            return Response::redirect('/admin/login');
        }
        $mode = RunMode::detect($this->rootPath, (string) $this->settings->get('app.mode', RunMode::NORMAL));
        $integrity = (new IntegrityChecker())->check($this->rootPath);

        $rows = '';
        if ($adminContext) {
            $restorePoints = (new RestorePointService($this->rootPath))->list();
            foreach ($restorePoints as $path) {
                $name = basename($path);
                $rows .= '<li>' . View::escape($name) .
                    '<form method="post" action="' . View::escape($this->actionPath($request->path)) . '" style="display:inline">' . CsrfToken::field() .
                    '<input type="hidden" name="restore_point" value="' . View::escape($name) . '">' .
                    '<input name="confirmation" placeholder="RESTORE ' . View::escape($name) . '">' .
                    '<button name="action" value="restore_database" type="submit">恢复数据库</button> ' .
                    '<button name="action" value="restore_core_database" type="submit">恢复Core+数据库</button></form></li>';
            }
        }
        $rows = $adminContext
            ? ($rows !== '' ? '<ul>' . $rows . '</ul>' : '<p class="muted">暂无恢复点</p>')
            : '<p class="muted">公开恢复入口仅显示只读状态。恢复、Safe Mode、禁用插件和清缓存操作必须从已登录后台执行。</p>';
        $actions = $adminContext
            ? '<form method="post" action="' . View::escape($this->actionPath($request->path)) . '">' . CsrfToken::field() .
                '<button name="action" value="create_restore_point" type="submit">创建恢复点</button> ' .
                '<button name="action" value="enable_safe" type="submit">进入 Safe Mode</button> ' .
                '<button name="action" value="disable_safe" type="submit">退出 Safe Mode</button> ' .
                '<button name="action" value="disable_plugins" type="submit">禁用第三方插件</button> ' .
                '<button name="action" value="clear_cache" type="submit">清缓存</button></form>'
            : '';

        $body = '<h1>恢复与诊断</h1>' .
            '<p>运行模式：<strong>' . View::escape($mode) . '</strong></p>' .
            '<p>Core 完整性：<strong>' . View::escape($integrity['status']) . '</strong></p>' .
            '<p class="muted">Changed: ' . count($integrity['changed']) . ' / Missing: ' . count($integrity['missing']) . ' / Unknown: ' . count($integrity['unknown']) . '</p>' .
            $actions .
            '<p><a class="button" href="' . View::escape($this->diagnosticsPath($request->path)) . '">诊断页</a></p>' .
            '<h2>恢复点</h2>' . $rows;

        return Response::html(View::page('恢复与诊断', $body));
    }

    public function diagnostics(Request $request): Response
    {
        $adminContext = $this->isAdminDiagnosticsPath($request->path);
        if ($adminContext && $this->adminUserId() === null) {
            return Response::redirect('/admin/login');
        }
        $mode = RunMode::detect($this->rootPath, (string) $this->settings->get('app.mode', RunMode::NORMAL));
        $integrity = (new IntegrityChecker())->check($this->rootPath);
        $db = ['status' => 'unavailable', 'driver' => ''];
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $db = ['status' => ((bool) $pdo->query('SELECT 1')->fetchColumn()) ? 'ok' : 'failed', 'driver' => (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)];
        } catch (Throwable $exception) {
            $db = ['status' => 'failed', 'driver' => '', 'error' => $this->redact($exception->getMessage())];
        }
        $logs = $this->recentLogs();
        $updates = glob($this->rootPath . '/storage/updates/history/*.json') ?: [];
        $restorePoints = (new RestorePointService($this->rootPath))->list();
        $payload = [
            'mode' => $mode,
            'maintenance' => is_file($this->rootPath . '/storage/maintenance.mode'),
            'safe_mode' => is_file($this->rootPath . '/storage/safe.mode'),
            'recovery_mode' => is_file($this->rootPath . '/storage/recovery.mode'),
            'installed' => is_file($this->rootPath . '/storage/installed.lock'),
            'core_integrity' => $integrity['status'],
            'core_integrity_counts' => ['changed' => count($integrity['changed']), 'missing' => count($integrity['missing']), 'unknown' => count($integrity['unknown'])],
            'database' => $adminContext ? $db : ['status' => $db['status']],
            'runtime_requirements' => [
                'php_min' => RuntimeRequirements::PHP_MIN,
                'required_extensions' => RuntimeRequirements::requiredExtensions(),
            ],
            'php_fileinfo' => [
                'status' => extension_loaded('fileinfo') && class_exists('finfo') && defined('FILEINFO_MIME_TYPE') ? '已启用' : '未启用',
                'purpose' => '媒体上传 MIME 类型安全检测',
                'suggestion' => extension_loaded('fileinfo') && class_exists('finfo') && defined('FILEINFO_MIME_TYPE') ? '无需处理' : '请在服务器 PHP 环境中启用 Fileinfo 扩展后重试媒体上传。',
            ],
            'php_ziparchive' => [
                'status' => extension_loaded('zip') && class_exists('ZipArchive') ? '已启用' : '未启用',
                'purpose' => '主题/插件 ZIP 安装、导出包、恢复点和 Core 更新包处理',
                'suggestion' => extension_loaded('zip') && class_exists('ZipArchive') ? '无需处理' : '请在服务器 PHP 环境中启用 Zip 扩展后重试相关包处理操作。',
            ],
            'restore_point_count' => count($restorePoints),
            'update_history_count' => count($updates),
        ];
        if ($adminContext) {
            $payload['restore_points'] = array_map('basename', $restorePoints);
            $payload['payment_providers'] = $this->paymentProviderDiagnostics($db['status'] === 'ok' ? $pdo ?? null : null);
            $payload['recent_log_summary'] = $this->recentLogSummary($logs);
            $payload['recent_startup_logs'] = $this->sanitizeDiagnosticValue($logs);
        }
        $body = '<h1>诊断</h1><pre>' . View::escape(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';

        return Response::html(View::page('诊断', $body));
    }

    public function action(Request $request): Response
    {
        if (!$this->isAdminRecoveryActionPath($request->path)) {
            $this->logger->error('Public recovery action rejected', ['source' => 'Core', 'path' => $request->path]);
            return Response::text('恢复操作只能从已登录后台执行。', 403);
        }
        $adminId = $this->adminUserId();
        if ($adminId === null) {
            return Response::text('请先登录后台再执行恢复操作。', 403);
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('CSRF 校验失败，请刷新页面重试。', 403);
        }

        try {
            $pdo = null;
            try {
                $pdo = ConnectionFactory::make($this->settings);
            } catch (Throwable) {
                $pdo = null;
            }

            $actions = new RecoveryActions($this->rootPath, $pdo);
            $action = (string) $request->input('action', '');
            $auditContext = ['action' => $action];
            if ($action === 'create_restore_point') {
                $restorePath = (new RestorePointService($this->rootPath))->create('manual');
                $auditContext['restore_point'] = basename($restorePath);
            } elseif ($action === 'restore_database' || $action === 'restore_core_database') {
                $name = basename((string) $request->input('restore_point', ''));
                if ($name === '' || (string) $request->input('confirmation', '') !== 'RESTORE ' . $name) {
                    return Response::text('恢复操作需要输入完整确认文本。', 400);
                }
                $restore = $this->rootPath . '/storage/recovery/' . $name;
                if ($action === 'restore_core_database') {
                    (new RestorePointService($this->rootPath))->restoreCore($restore);
                }
                (new RestorePointService($this->rootPath))->restoreDatabase($restore);
                $auditContext['restore_point'] = $name;
            } elseif ($action === 'enable_safe') {
                $actions->enableSafeMode();
            } elseif ($action === 'disable_safe') {
                $actions->disableSafeMode();
            } elseif ($action === 'disable_plugins') {
                $auditContext['disabled_plugins'] = $actions->disableThirdPartyPlugins();
            } elseif ($action === 'clear_cache') {
                $auditContext['cleared_files'] = $actions->clearCache();
            } else {
                return Response::text('未知恢复操作。', 400);
            }
            $this->auditRecoveryAction($pdo, $adminId, $action, $auditContext);
        } catch (Throwable $exception) {
            $this->logger->error('Recovery action failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
            return Response::text('恢复操作失败，请查看后台日志。', 500);
        }

        return Response::redirect('/admin/recovery');
    }

    private function actionPath(string $path): string
    {
        return $this->isAdminRecoveryPath($path) ? '/admin/recovery/action' : '/recovery/action';
    }

    private function diagnosticsPath(string $path): string
    {
        return $this->isAdminRecoveryPath($path) ? '/admin/diagnostics' : '/diagnostics';
    }

    private function isAdminRecoveryPath(string $path): bool
    {
        return Request::normalizePath($path) === '/admin/recovery';
    }

    private function isAdminRecoveryActionPath(string $path): bool
    {
        return Request::normalizePath($path) === '/admin/recovery/action';
    }

    private function isAdminDiagnosticsPath(string $path): bool
    {
        return Request::normalizePath($path) === '/admin/diagnostics';
    }

    private function adminUserId(): ?int
    {
        $user = $_SESSION['admin_user'] ?? null;
        if (!is_array($user)) {
            return null;
        }
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $context */
    private function auditRecoveryAction(?PDO $pdo, int $adminId, string $action, array $context): void
    {
        if ($pdo === null) {
            return;
        }
        try {
            (new AuditLogger($pdo))->record('admin', $adminId, 'recovery.' . $action, $this->sanitizeDiagnosticValue($context));
        } catch (Throwable $exception) {
            $this->logger->error('Recovery action audit failed', ['source' => 'Core', 'error' => $exception->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private function paymentProviderDiagnostics(?PDO $pdo): array
    {
        if ($pdo === null) {
            return [
                'status' => 'database_unavailable',
                'configured_provider_count' => 0,
                'enabled_checkout_provider_count' => 0,
                'default_checkout_provider_count' => 0,
                'duplicate_provider_ids' => [],
                'manual_payment_ready' => false,
                'providers' => [],
                'next_actions' => ['确认数据库连接恢复后重新打开 /admin/diagnostics。'],
            ];
        }
        try {
            $stmt = $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY provider_id ASC, updated_at ASC, id ASC');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $exception) {
            return [
                'status' => 'provider_settings_unavailable',
                'error' => $this->redact($exception->getMessage()),
                'configured_provider_count' => 0,
                'enabled_checkout_provider_count' => 0,
                'default_checkout_provider_count' => 0,
                'duplicate_provider_ids' => [],
                'manual_payment_ready' => false,
                'providers' => [],
                'next_actions' => ['确认 Core payment 数据表迁移完成后重新打开 /admin/diagnostics。'],
            ];
        }

        $byProvider = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $providerId = (string) ($row['provider_id'] ?? '');
            if ($providerId === '') {
                continue;
            }
            $byProvider[$providerId][] = $row;
        }

        $duplicates = [];
        foreach ($byProvider as $providerId => $providerRows) {
            if (count($providerRows) > 1) {
                $duplicates[] = $providerId;
            }
        }
        $legacyStorage = $this->paymentProviderLegacyStorageDebt($rows);
        $legacyProviderIds = array_keys($legacyStorage);

        $providerSummaries = [];
        foreach (array_unique(array_merge(PaymentProviderRegistry::ids(), array_keys($byProvider))) as $providerId) {
            $providerRows = $byProvider[$providerId] ?? [];
            usort($providerRows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)));
            $latest = $providerRows[0] ?? null;
            try {
                $provider = PaymentProviderRegistry::get($providerId);
                $providerIdValid = true;
            } catch (Throwable) {
                $provider = null;
                $providerIdValid = false;
            }
            [$public, $publicError] = is_array($latest) ? $this->paymentProviderPublicConfig($latest) : [[], 'not_configured'];
            $enabled = is_array($latest) && (string) ($latest['status'] ?? '') === 'enabled';
            $supportsCheckout = $provider !== null && in_array('payment.create', $provider->capabilities(), true);
            $checkoutAvailable = $enabled && $supportsCheckout && $publicError === '' && $this->paymentProviderCheckoutAvailable($providerId, $public);
            $defaultProvider = $publicError === '' && ($public['default_provider'] ?? null) === true;
            $diagnostics = [];
            if (!$providerIdValid) {
                $diagnostics[] = 'provider_id_invalid';
            }
            if ($latest === null) {
                $diagnostics[] = 'not_configured';
            }
            if (count($providerRows) > 1) {
                $diagnostics[] = 'duplicate_rows';
            }
            if (isset($legacyStorage[$providerId])) {
                $diagnostics[] = 'legacy_plugin_storage';
            }
            if ($provider === null) {
                $diagnostics[] = 'not_registered';
            }
            if ($publicError !== '') {
                $diagnostics[] = $publicError;
            }
            if ($latest !== null && !$enabled) {
                $diagnostics[] = 'disabled';
            }
            if ($provider !== null && !$supportsCheckout) {
                $diagnostics[] = 'payment_create_missing';
            }
            if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID && $publicError === '' && !$this->paymentProviderCheckoutAvailable($providerId, $public)) {
                $diagnostics[] = 'hosted_redirect_checkout_incomplete';
            }
            $legacyIssues = $legacyStorage[$providerId] ?? [];
            if ($diagnostics === []) {
                $diagnostics[] = 'ready';
            }
            $providerSummaries[$providerId] = [
                'row_count' => count($providerRows),
                'provider_id_valid' => $providerIdValid,
                'registered' => $provider !== null,
                'configured' => $latest !== null && $publicError === '',
                'enabled' => $enabled,
                'default_provider' => $defaultProvider,
                'supports_checkout' => $supportsCheckout,
                'checkout_available' => $checkoutAvailable,
                'legacy_storage_issues' => $legacyIssues,
                'diagnostics' => $diagnostics,
            ];
        }
        ksort($providerSummaries);

        $enabledCheckout = array_filter($providerSummaries, static fn (array $provider): bool => ($provider['checkout_available'] ?? false) === true);
        $defaultCheckout = array_filter($enabledCheckout, static fn (array $provider): bool => ($provider['default_provider'] ?? false) === true);
        $status = $duplicates !== [] || $legacyProviderIds !== [] ? 'needs_repair' : ($enabledCheckout === [] ? 'no_enabled_checkout_provider' : 'ok');

        return [
            'status' => $status,
            'configured_provider_count' => count($byProvider),
            'enabled_checkout_provider_count' => count($enabledCheckout),
            'default_checkout_provider_count' => count($defaultCheckout),
            'duplicate_provider_ids' => $duplicates,
            'legacy_storage_provider_ids' => $legacyProviderIds,
            'manual_payment_ready' => ($providerSummaries[ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) === true,
            'providers' => $providerSummaries,
            'next_actions' => $this->paymentProviderNextActions($status, $duplicates, $legacyProviderIds, $enabledCheckout, $providerSummaries),
        ];
    }

    /** @param list<string> $duplicates @param list<string> $legacyProviderIds @param array<string,array<string,mixed>> $providerSummaries @return list<string> */
    private function paymentProviderNextActions(string $status, array $duplicates, array $legacyProviderIds, array $enabledCheckout, array $providerSummaries): array
    {
        $actions = [];
        if ($duplicates !== [] || $legacyProviderIds !== []) {
            $actions[] = '打开 /admin/payments/providers，点击“修复 Provider 存储”清理旧重复行并迁移旧支付插件字段；后台不可用时运行 php scripts/diagnose_payment_providers.php --json --repair。';
        }
        if ($enabledCheckout === []) {
            $actions[] = '在 /admin/payments/providers 启用 core.manual-payment，勾选默认 Provider，并保存付款说明。';
        }
        if (($providerSummaries[ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) !== true) {
            $actions[] = '自动发卡验收前，请确认 core.manual-payment 显示为已配置、启用、默认。';
        }
        if ($status === 'ok') {
            $actions[] = 'Payment Provider 存储健康，可继续做前台 checkout 与后台 capture 验收。';
        }

        return array_values(array_unique($actions));
    }

    /** @param list<array<string,mixed>> $rows @return array<string,list<string>> */
    private function paymentProviderLegacyStorageDebt(array $rows): array
    {
        $debt = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $providerId = (string) ($row['provider_id'] ?? '');
            if ($providerId === '') {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $public = $this->paymentProviderLooseJsonObject((string) ($row['public_config_json'] ?? '{}'));
            $reasons = [];
            $expectedEnabled = $status === 'enabled';
            foreach (['enabled', 'is_enabled'] as $column) {
                if (array_key_exists($column, $row) && $this->paymentProviderTruthy($row[$column] ?? null) !== $expectedEnabled) {
                    $reasons[] = 'enabled';
                    break;
                }
            }
            if (($this->paymentProviderTruthy($row['enabled'] ?? null) || $this->paymentProviderTruthy($row['is_enabled'] ?? null)) && $status !== 'enabled') {
                $reasons[] = 'enabled';
            }
            $expectedDefault = $expectedEnabled && ($public['default_provider'] ?? null) === true;
            foreach (['is_default', 'default_provider'] as $column) {
                if (array_key_exists($column, $row) && $this->paymentProviderTruthy($row[$column] ?? null) !== $expectedDefault) {
                    $reasons[] = 'default';
                    break;
                }
            }
            if (($this->paymentProviderTruthy($row['is_default'] ?? null) || $this->paymentProviderTruthy($row['default_provider'] ?? null)) && $status === 'enabled' && ($public['default_provider'] ?? null) !== true) {
                $reasons[] = 'default';
            }
            foreach (['payment_instructions', 'instructions'] as $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $legacyInstructions = (string) ($row[$column] ?? '');
                if (($legacyInstructions !== '' && !array_key_exists('instructions', $public)) || ($legacyInstructions === '' && array_key_exists('instructions', $public))) {
                    $reasons[] = 'instructions';
                    break;
                }
            }
            foreach (['public_config', 'config_json', 'settings_json', 'public_settings_json'] as $column) {
                if (!array_key_exists($column, $row) || !is_string($row[$column]) || trim($row[$column]) === '') {
                    continue;
                }
                $legacyPublic = $this->paymentProviderLooseJsonObject((string) $row[$column]);
                foreach (['instructions', 'checkout_url', 'checkout_base_url', 'return_url_base', 'default_provider'] as $key) {
                    if (array_key_exists($key, $legacyPublic) !== array_key_exists($key, $public)
                        || (array_key_exists($key, $legacyPublic) && array_key_exists($key, $public) && $legacyPublic[$key] !== $public[$key])
                    ) {
                        $reasons[] = $column;
                        break 2;
                    }
                }
            }
            if ((string) ($row['display_name'] ?? '') === '' && (((string) ($row['name'] ?? '')) !== '' || ((string) ($row['title'] ?? '')) !== '')) {
                $reasons[] = 'display_name';
            }
            if ($reasons !== []) {
                $debt[$providerId] = array_values(array_unique(array_merge($debt[$providerId] ?? [], $reasons)));
            }
        }
        ksort($debt);

        return $debt;
    }

    private function paymentProviderTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function paymentProviderLooseJsonObject(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $row @return array{0:array<string,mixed>,1:string} */
    private function paymentProviderPublicConfig(array $row): array
    {
        $raw = (string) ($row['public_config_json'] ?? '{}');
        if ($raw === '') {
            $raw = '{}';
        }
        if ($raw !== trim($raw)) {
            return [[], 'public_config_not_canonical'];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], 'public_config_invalid_json'];
        }
        if (!is_array($decoded)) {
            return [[], 'public_config_not_object'];
        }

        return [$decoded, ''];
    }

    /** @param array<string,mixed> $public */
    private function paymentProviderCheckoutAvailable(string $providerId, array $public): bool
    {
        if ($providerId !== HostedRedirectPaymentProvider::PROVIDER_ID) {
            return true;
        }
        $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
        $returnUrlBase = (string) ($public['return_url_base'] ?? '');

        return $this->paymentProviderHttpsUrl($checkoutUrl)
            && !$this->paymentProviderUrlHasSensitiveQuery($checkoutUrl)
            && ($returnUrlBase === '' || ($this->paymentProviderHttpsUrl($returnUrlBase) && !$this->paymentProviderUrlHasQuery($returnUrlBase)));
    }

    private function paymentProviderHttpsUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private function paymentProviderUrlHasQuery(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['query']) && (string) $parts['query'] !== '';
    }

    private function paymentProviderUrlHasSensitiveQuery(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return false;
        }
        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if ((string) $key !== 'cms_signature' && preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
                return true;
            }
            if (!is_scalar($value)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string,mixed>> */
    private function recentLogs(): array
    {
        $file = $this->rootPath . '/storage/logs/app.log';
        if (!is_file($file)) {
            return [];
        }
        $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -20);
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $logs @return array{total:int,error_count:int,warning_count:int,severe_count:int,latest_error_time:string,source_counts:array<string,int>,invalid_source_count:int} */
    private function recentLogSummary(array $logs): array
    {
        $sourceCounts = [];
        $latestErrorTime = '';
        $invalidSourceCount = 0;
        $errorCount = 0;
        $warningCount = 0;
        foreach ($logs as $row) {
            $level = strtoupper((string) ($row['level'] ?? ''));
            if ($level === 'ERROR') {
                $errorCount++;
                $time = (string) ($row['time'] ?? '');
                if ($this->safeDiagnosticTimestamp($time) && strcmp($time, $latestErrorTime) > 0) {
                    $latestErrorTime = $time;
                }
            } elseif ($level === 'WARNING' || $level === 'WARN') {
                $warningCount++;
            }

            $source = (string) ($row['source'] ?? ($row['context']['source'] ?? 'unknown'));
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $source) !== 1) {
                $source = 'unknown';
                $invalidSourceCount++;
            }
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
        }
        ksort($sourceCounts);

        return [
            'total' => count($logs),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'severe_count' => $errorCount + $warningCount,
            'latest_error_time' => $latestErrorTime,
            'source_counts' => $sourceCounts,
            'invalid_source_count' => $invalidSourceCount,
        ];
    }

    private function safeDiagnosticTimestamp(string $time): bool
    {
        return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\+00:00|Z)$/', $time) === 1;
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/\b(password|secret|token|session|private_key|dsn)\b\s*[:=]\s*([^\s"\']+)/i', '$1=[redacted]', $message) ?: $message;
        return preg_replace('/[A-Z]:[\\\\\\/][^\s]+|\/[^\s]+/', '[path]', $message) ?: $message;
    }

    private function sanitizeDiagnosticValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (preg_match('/password|secret|token|session|private_key|dsn/i', (string) $key)) {
                    $clean[$key] = '[redacted]';
                    continue;
                }
                $clean[$key] = $this->sanitizeDiagnosticValue($item);
            }
            return $clean;
        }

        if (is_string($value)) {
            return $this->redact($value);
        }

        return $value;
    }
}
