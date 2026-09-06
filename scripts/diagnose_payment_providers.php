<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\HostedRedirectPaymentProvider;
use Cms\Core\Payment\ManualPaymentProvider;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;

require_once dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

/**
 * @return array{status:string,errors:int,warnings:int,checks:list<array{id:string,severity:string,ok:bool,message:string,detail:string>>,providers:array<string,array<string,mixed>>,next_actions:list<string>}
 */
function cms_diagnose_payment_providers(string $rootPath): array
{
    $rootPath = rtrim($rootPath, '/');
    $settings = Settings::load($rootPath);
    cms_payment_diagnostics_register_core_providers($settings);
    $checks = [];
    $providers = [];

    $add = static function (string $id, string $severity, bool $ok, string $message, string $detail = '') use (&$checks): void {
        $checks[] = [
            'id' => $id,
            'severity' => $severity,
            'ok' => $ok,
            'message' => $message,
            'detail' => cms_payment_diagnostics_redact($detail),
        ];
    };

    try {
        $pdo = ConnectionFactory::make($settings);
        $add('database.connection', 'error', true, '支付诊断已连接数据库');
    } catch (Throwable $exception) {
        $add('database.connection', 'error', false, '支付诊断无法连接数据库', $exception->getMessage());
        return cms_payment_diagnostics_result($checks, $providers);
    }

    if (!cms_payment_diagnostics_table_exists($pdo, 'cms_payment_provider_settings')) {
        $add('provider_settings.table', 'warning', false, '支付 Provider 设置表不存在，当前没有可用 Provider 配置');
        return cms_payment_diagnostics_result($checks, $providers);
    }
    $add('provider_settings.table', 'error', true, '支付 Provider 设置表存在');

    $columns = cms_payment_diagnostics_columns($pdo, 'cms_payment_provider_settings');
    $requiredColumns = ['id', 'provider_id', 'display_name', 'status', 'public_config_json', 'secret_config_ciphertext', 'created_at', 'updated_at'];
    $missingColumns = array_values(array_diff($requiredColumns, $columns));
    $add(
        'provider_settings.columns',
        'error',
        $missingColumns === [],
        '支付 Provider 设置表字段完整',
        $missingColumns === [] ? '' : implode(', ', $missingColumns)
    );
    if ($missingColumns !== []) {
        return cms_payment_diagnostics_result($checks, $providers);
    }

    $rawRows = cms_payment_diagnostics_provider_rows($pdo);
    $add('provider_settings.rows', 'warning', $rawRows !== [], '支付 Provider 设置至少保存过一条配置', $rawRows === [] ? 'no rows' : (string) count($rawRows));
    $legacyStorageDebt = cms_payment_diagnostics_legacy_storage_debt($columns, $rawRows);
    $add(
        'provider_settings.legacy_plugin_storage',
        'warning',
        $legacyStorageDebt === [],
        '旧支付插件 Provider 字段已迁移到 Core 标准配置',
        $legacyStorageDebt === [] ? '' : implode(', ', $legacyStorageDebt)
    );

    $rowsByProvider = [];
    foreach ($rawRows as $row) {
        $providerId = (string) ($row['provider_id'] ?? '');
        if ($providerId === '') {
            continue;
        }
        $rowsByProvider[$providerId][] = $row;
    }

    $duplicateIds = [];
    foreach ($rowsByProvider as $providerId => $rows) {
        if (count($rows) > 1) {
            $duplicateIds[] = $providerId . ' x' . count($rows);
        }
    }
    $add('provider_settings.unique_provider_id', 'error', $duplicateIds === [], '每个 Provider 只有一条有效设置记录', implode(', ', $duplicateIds));

    foreach (array_unique(array_merge(PaymentProviderRegistry::ids(), array_keys($rowsByProvider))) as $providerId) {
        $providerRows = $rowsByProvider[$providerId] ?? [];
        usort($providerRows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)));
        $latest = $providerRows[0] ?? null;
        try {
            $provider = PaymentProviderRegistry::get($providerId);
            $providerIdValid = true;
        } catch (Throwable) {
            $provider = null;
            $providerIdValid = false;
        }
        $public = null;
        $publicError = '';
        if (is_array($latest)) {
            [$public, $publicError] = cms_payment_diagnostics_public_config($latest);
        }
        $enabled = is_array($latest) && (string) ($latest['status'] ?? '') === 'enabled';
        $default = is_array($public) && ($public['default_provider'] ?? null) === true;
        $supportsCheckout = $provider !== null && in_array('payment.create', $provider->capabilities(), true);
        $checkoutAvailable = $enabled
            && $supportsCheckout
            && is_array($public)
            && cms_payment_diagnostics_checkout_available($providerId, $public);
        $diagnostics = cms_payment_diagnostics_provider_messages($providerId, $provider, $latest, $public, $publicError);
        $providers[$providerId] = [
            'provider_id' => $providerId,
            'row_count' => count($providerRows),
            'provider_id_valid' => $providerIdValid,
            'registered' => $provider !== null,
            'display_name' => is_array($latest) ? (string) ($latest['display_name'] ?? '') : ($provider !== null ? $provider->displayName() : ''),
            'status' => is_array($latest) ? (string) ($latest['status'] ?? '') : 'missing',
            'configured' => is_array($latest) && $publicError === '',
            'enabled' => $enabled,
            'default_provider' => $default,
            'supports_checkout' => $supportsCheckout,
            'checkout_available' => $checkoutAvailable,
            'diagnostics' => $diagnostics,
        ];
    }
    ksort($providers);

    $enabledCheckout = array_values(array_filter($providers, static fn (array $provider): bool => ($provider['checkout_available'] ?? false) === true));
    $defaultCheckout = array_values(array_filter($enabledCheckout, static fn (array $provider): bool => ($provider['default_provider'] ?? false) === true));
    $add('provider_settings.enabled_checkout_provider', 'error', $enabledCheckout !== [], '至少有一个已启用且可创建支付的 Provider');
    $add('provider_settings.default_checkout_provider', 'warning', count($defaultCheckout) === 1, '恰好有一个可用的默认支付 Provider', count($defaultCheckout) === 0 ? 'no default checkout Provider' : (string) count($defaultCheckout));
    $add(
        'provider_settings.manual_payment_ready',
        'warning',
        ($providers[ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) === true,
        'core.manual-payment 可用于人工确认支付闭环'
    );
    $runtimeProbe = cms_payment_diagnostics_runtime_probe($settings, $rootPath);
    if (($runtimeProbe['supported'] ?? false) === true) {
        $expectedEnabledIds = array_values(array_map(static fn (array $provider): string => (string) ($provider['provider_id'] ?? ''), $enabledCheckout));
        sort($expectedEnabledIds);
        $runtimeEnabledIds = $runtimeProbe['enabled_provider_ids'] ?? [];
        sort($runtimeEnabledIds);
        $add(
            'provider_settings.payment_service_enabled_provider',
            'error',
            $runtimeEnabledIds === $expectedEnabledIds && $runtimeEnabledIds !== [],
            'PaymentService 能发现已启用支付 Provider',
            'expected: ' . implode(', ', $expectedEnabledIds) . '; actual: ' . implode(', ', $runtimeEnabledIds)
        );
        $expectedDefaultId = count($defaultCheckout) === 1 ? (string) ($defaultCheckout[0]['provider_id'] ?? '') : ($expectedEnabledIds[0] ?? '');
        $runtimeDefaultId = (string) ($runtimeProbe['default_provider_id'] ?? '');
        $add(
            'provider_settings.selector_default_provider',
            'warning',
            $runtimeDefaultId !== '' && $runtimeDefaultId === $expectedDefaultId,
            'PaymentProviderSelector 能解析默认支付 Provider',
            'expected: ' . $expectedDefaultId . '; actual: ' . $runtimeDefaultId
        );
    } else {
        $add(
            'provider_settings.runtime_probe',
            'warning',
            true,
            '真实 PaymentService 链路探测已跳过',
            (string) ($runtimeProbe['reason'] ?? 'unsupported database driver')
        );
    }

    return cms_payment_diagnostics_result($checks, $providers);
}

/** @return array{ok:bool,message:string,before_provider_rows:array<string,int>,after_provider_rows:array<string,int>} */
function cms_repair_payment_provider_settings(string $rootPath): array
{
    $rootPath = rtrim($rootPath, '/');
    $settings = Settings::load($rootPath);
    cms_payment_diagnostics_register_core_providers($settings);
    $pdo = ConnectionFactory::make($settings);
    $before = cms_payment_diagnostics_provider_row_counts($pdo);

    $repository = new PaymentProviderSettingsRepository($pdo, (string) $settings->get('security.encryption_key', ''));
    $repository->all();

    return [
        'ok' => true,
        'message' => '支付 Provider 设置存储已通过 Repository 修复：已补齐字段、清理重复行并重建唯一约束。',
        'before_provider_rows' => $before,
        'after_provider_rows' => cms_payment_diagnostics_provider_row_counts($pdo),
    ];
}

function cms_payment_diagnostics_register_core_providers(Settings $settings): void
{
    PaymentProviderRegistry::clear();
    PaymentProviderRegistry::register(ManualPaymentProvider::PROVIDER_ID, new ManualPaymentProvider());
    PaymentProviderRegistry::register(HostedRedirectPaymentProvider::PROVIDER_ID, new HostedRedirectPaymentProvider());
    if ((bool) $settings->get('payment.fixture_provider_enabled', false) === true) {
        PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
    }
}

/** @return array{supported:bool,enabled_provider_ids?:list<string>,default_provider_id?:string,reason?:string} */
function cms_payment_diagnostics_runtime_probe(Settings $settings, string $rootPath): array
{
    $dsn = (string) $settings->get('database.dsn', '');
    if (!str_starts_with($dsn, 'sqlite:')) {
        return ['supported' => false, 'reason' => 'runtime probe is only non-destructive for SQLite databases'];
    }
    $databasePath = substr($dsn, 7);
    if ($databasePath === '' || $databasePath === ':memory:') {
        return ['supported' => false, 'reason' => 'SQLite database path is not copyable'];
    }
    if (!str_starts_with($databasePath, '/')) {
        $databasePath = rtrim($rootPath, '/') . '/' . $databasePath;
    }
    if (!is_file($databasePath) || !is_readable($databasePath)) {
        return ['supported' => false, 'reason' => 'SQLite database file is not readable'];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cms-provider-probe-');
    if ($tmp === false) {
        return ['supported' => false, 'reason' => 'temporary database file could not be created'];
    }
    try {
        if (!cms_payment_diagnostics_snapshot_sqlite_database($databasePath, $tmp)) {
            return ['supported' => false, 'reason' => 'SQLite database copy failed'];
        }
        $probeConfig = $settings->all();
        $probeConfig['database'] = is_array($probeConfig['database'] ?? null) ? $probeConfig['database'] : [];
        $probeConfig['database']['dsn'] = 'sqlite:' . $tmp;
        $probeConfig['database']['username'] = '';
        $probeConfig['database']['password'] = '';
        $probeSettings = Settings::fromArray($probeConfig);
        $pdo = ConnectionFactory::make($probeSettings);
        $enabledIds = array_column(
            (new PaymentService(
                $pdo,
                new PaymentRepository($pdo),
                (string) $probeSettings->get('security.encryption_key', ''),
            ))->enabledProviders(),
            'id',
        );
        $defaultProviderId = '';
        try {
            $defaultProviderId = (new PaymentProviderSelector($pdo, $probeSettings))->defaultProviderId();
        } catch (Throwable $exception) {
            $defaultProviderId = 'error:' . cms_payment_diagnostics_redact($exception->getMessage());
        }

        return [
            'supported' => true,
            'enabled_provider_ids' => array_values(array_map('strval', $enabledIds)),
            'default_provider_id' => $defaultProviderId,
        ];
    } catch (Throwable $exception) {
        return ['supported' => false, 'reason' => cms_payment_diagnostics_redact($exception->getMessage())];
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        foreach ([$tmp . '-wal', $tmp . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
    }
}

function cms_payment_diagnostics_snapshot_sqlite_database(string $sourcePath, string $targetPath): bool
{
    if (is_file($targetPath) && !@unlink($targetPath)) {
        return false;
    }
    try {
        $source = new PDO('sqlite:' . $sourcePath);
        $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $source->exec('VACUUM INTO ' . $source->quote($targetPath));

        return is_file($targetPath);
    } catch (Throwable) {
    }
    if (!copy($sourcePath, $targetPath)) {
        return false;
    }
    foreach (['-wal', '-shm'] as $suffix) {
        $sidecar = $sourcePath . $suffix;
        if (is_file($sidecar)) {
            @copy($sidecar, $targetPath . $suffix);
        }
    }

    return true;
}

/** @param list<array{id:string,severity:string,ok:bool,message:string,detail:string}> $checks @param array<string,array<string,mixed>> $providers @return array{status:string,errors:int,warnings:int,checks:list<array{id:string,severity:string,ok:bool,message:string,detail:string>>,providers:array<string,array<string,mixed>>,next_actions:list<string>} */
function cms_payment_diagnostics_result(array $checks, array $providers): array
{
    $errors = count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'error' && !$check['ok']));
    $warnings = count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'warning' && !$check['ok']));

    return [
        'status' => $errors === 0 ? ($warnings === 0 ? 'ok' : 'warning') : 'error',
        'errors' => $errors,
        'warnings' => $warnings,
        'checks' => $checks,
        'providers' => $providers,
        'next_actions' => cms_payment_diagnostics_next_actions($checks, $providers),
    ];
}

/** @param list<array{id:string,severity:string,ok:bool,message:string,detail:string}> $checks @param array<string,array<string,mixed>> $providers @return list<string> */
function cms_payment_diagnostics_next_actions(array $checks, array $providers): array
{
    $actions = [];
    foreach ($checks as $check) {
        if (($check['id'] ?? '') === 'provider_settings.unique_provider_id' && ($check['ok'] ?? true) === false) {
            $actions[] = '打开 /admin/payments/providers，进入重复的 Provider，确认配置后重新保存；保存会保留最新配置并清理旧重复行。';
        }
        if (($check['id'] ?? '') === 'provider_settings.legacy_plugin_storage' && ($check['ok'] ?? true) === false) {
            $actions[] = '旧支付插件字段仍未完全折叠进 Core 配置；请打开 /admin/payments/providers 使用修复功能，或运行 php scripts/diagnose_payment_providers.php --json --repair。';
        }
        if (($check['id'] ?? '') === 'provider_settings.columns' && ($check['ok'] ?? true) === false) {
            $actions[] = '运行最新 Core 迁移或打开 /admin/payments/providers，让 CMS 补齐 Provider 设置表字段。';
        }
        if (($check['id'] ?? '') === 'provider_settings.enabled_checkout_provider' && ($check['ok'] ?? true) === false) {
            $actions[] = '在 /admin/payments/providers 启用 core.manual-payment，勾选默认 Provider，并保存付款说明。';
        }
    }
    if (($providers[ManualPaymentProvider::PROVIDER_ID]['checkout_available'] ?? false) !== true) {
        $actions[] = '自动发卡验收前，请确认 core.manual-payment 显示为已配置、启用、默认。';
    }

    return array_values(array_unique($actions));
}

function cms_payment_diagnostics_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return $stmt !== false;
    } catch (Throwable) {
        return false;
    }
}

/** @return list<string> */
function cms_payment_diagnostics_columns(PDO $pdo, string $table): array
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows);
}

/** @return list<array<string,mixed>> */
function cms_payment_diagnostics_provider_rows(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY provider_id ASC, updated_at ASC, id ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return is_array($rows) ? $rows : [];
}

/** @param list<string> $columns @param list<array<string,mixed>> $rows @return list<string> */
function cms_payment_diagnostics_legacy_storage_debt(array $columns, array $rows): array
{
    $legacyColumns = array_values(array_intersect($columns, [
        'enabled',
        'is_enabled',
        'is_default',
        'default_provider',
        'config_json',
        'public_config',
        'settings_json',
        'public_settings_json',
        'instructions',
        'payment_instructions',
        'name',
        'title',
    ]));
    if ($legacyColumns === []) {
        return [];
    }

    $debt = [];
    foreach ($rows as $row) {
        $providerId = (string) ($row['provider_id'] ?? '');
        if ($providerId === '') {
            $providerId = '[unknown]';
        }
        $reasons = [];
        $status = (string) ($row['status'] ?? '');
        $expectedEnabled = $status === 'enabled';
        foreach (['enabled', 'is_enabled'] as $column) {
            if (array_key_exists($column, $row) && cms_payment_diagnostics_truthy($row[$column] ?? null) !== $expectedEnabled) {
                $reasons[] = 'enabled';
                break;
            }
        }
        if ((cms_payment_diagnostics_truthy($row['enabled'] ?? null) || cms_payment_diagnostics_truthy($row['is_enabled'] ?? null)) && $status !== 'enabled') {
            $reasons[] = 'enabled';
        }
        $public = cms_payment_diagnostics_loose_json_object((string) ($row['public_config_json'] ?? '{}'));
        $legacyDefault = cms_payment_diagnostics_truthy($row['is_default'] ?? null) || cms_payment_diagnostics_truthy($row['default_provider'] ?? null);
        $expectedDefault = $expectedEnabled && ($public['default_provider'] ?? null) === true;
        foreach (['is_default', 'default_provider'] as $column) {
            if (array_key_exists($column, $row) && cms_payment_diagnostics_truthy($row[$column] ?? null) !== $expectedDefault) {
                $reasons[] = 'default';
                break;
            }
        }
        if ($legacyDefault && $status === 'enabled' && ($public['default_provider'] ?? null) !== true) {
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
            $legacyPublic = cms_payment_diagnostics_loose_json_object((string) $row[$column]);
            foreach (['instructions', 'checkout_url', 'checkout_base_url', 'return_url_base', 'default_provider'] as $key) {
                if (array_key_exists($key, $legacyPublic) !== array_key_exists($key, $public)
                    || (array_key_exists($key, $legacyPublic) && array_key_exists($key, $public) && $legacyPublic[$key] !== $public[$key])
                ) {
                    $reasons[] = $column;
                    break 2;
                }
            }
        }
        $displayName = (string) ($row['display_name'] ?? '');
        if ($displayName === '' && (((string) ($row['name'] ?? '')) !== '' || ((string) ($row['title'] ?? '')) !== '')) {
            $reasons[] = 'display_name';
        }
        if ($reasons !== []) {
            $debt[] = $providerId . ':' . implode('+', array_values(array_unique($reasons)));
        }
    }

    return array_values(array_unique($debt));
}

function cms_payment_diagnostics_truthy(mixed $value): bool
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
function cms_payment_diagnostics_loose_json_object(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

/** @return array<string,int> */
function cms_payment_diagnostics_provider_row_counts(PDO $pdo): array
{
    if (!cms_payment_diagnostics_table_exists($pdo, 'cms_payment_provider_settings')) {
        return [];
    }
    $columns = cms_payment_diagnostics_columns($pdo, 'cms_payment_provider_settings');
    if (!in_array('provider_id', $columns, true)) {
        return [];
    }
    $stmt = $pdo->query('SELECT provider_id, COUNT(*) AS row_count FROM cms_payment_provider_settings GROUP BY provider_id ORDER BY provider_id ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $counts = [];
    foreach ($rows as $row) {
        $providerId = (string) ($row['provider_id'] ?? '');
        if ($providerId !== '') {
            $counts[$providerId] = (int) ($row['row_count'] ?? 0);
        }
    }

    return $counts;
}

/** @param array<string,mixed> $row @return array{0:array<string,mixed>|null,1:string} */
function cms_payment_diagnostics_public_config(array $row): array
{
    $raw = (string) ($row['public_config_json'] ?? '{}');
    if ($raw === '') {
        $raw = '{}';
    }
    if ($raw !== trim($raw)) {
        return [null, '公共配置 JSON 前后有空白或不可规范化'];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [null, '公共配置 JSON 无法解析'];
    }
    if (!is_array($decoded)) {
        return [null, '公共配置 JSON 必须是对象'];
    }
    foreach ($decoded as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
            return [null, '公共配置包含非法字段名'];
        }
        if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
            return [null, '公共配置不能包含密钥字段'];
        }
        if (!(is_scalar($value) || $value === null)) {
            return [null, '公共配置值必须是标量'];
        }
        if (is_string($value) && ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
            return [null, '公共配置值不是安全字符串'];
        }
    }

    return [$decoded, ''];
}

/** @param array<string,mixed> $public */
function cms_payment_diagnostics_checkout_available(string $providerId, array $public): bool
{
    if ($providerId !== HostedRedirectPaymentProvider::PROVIDER_ID) {
        return true;
    }

    $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
    $returnUrlBase = (string) ($public['return_url_base'] ?? '');

    return cms_payment_diagnostics_https_url($checkoutUrl)
        && !cms_payment_diagnostics_url_has_sensitive_query($checkoutUrl)
        && ($returnUrlBase === '' || (cms_payment_diagnostics_https_url($returnUrlBase) && !cms_payment_diagnostics_url_has_query($returnUrlBase)));
}

/** @param array<string,mixed>|null $row @param array<string,mixed>|null $public @return list<string> */
function cms_payment_diagnostics_provider_messages(string $providerId, ?object $provider, ?array $row, ?array $public, string $publicError): array
{
    $messages = [];
    try {
        PaymentProviderRegistry::normalize($providerId);
    } catch (Throwable) {
        $messages[] = 'Provider ID 非法';
    }
    if ($row === null) {
        $messages[] = '未配置';
    }
    if ($provider === null) {
        $messages[] = '未注册';
    }
    if ($publicError !== '') {
        $messages[] = $publicError;
    }
    if ($row !== null && (string) ($row['status'] ?? '') !== 'enabled') {
        $messages[] = '未启用';
    }
    if ($provider !== null && !in_array('payment.create', $provider->capabilities(), true)) {
        $messages[] = '不支持创建支付';
    }
    if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID && is_array($public)) {
        $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
        if ($checkoutUrl === '') {
            $messages[] = '托管跳转收银台 URL 未配置';
        } elseif (!cms_payment_diagnostics_https_url($checkoutUrl)) {
            $messages[] = '托管跳转收银台 URL 必须使用 HTTPS';
        } elseif (cms_payment_diagnostics_url_has_sensitive_query($checkoutUrl)) {
            $messages[] = '托管跳转收银台 URL 不能包含敏感查询参数';
        }
        $returnUrlBase = (string) ($public['return_url_base'] ?? '');
        if ($returnUrlBase !== '' && (!cms_payment_diagnostics_https_url($returnUrlBase) || cms_payment_diagnostics_url_has_query($returnUrlBase))) {
            $messages[] = '托管跳转回跳域名必须是无查询参数的 HTTPS URL';
        }
    }

    return $messages === [] ? ['可用于 Core 支付'] : $messages;
}

function cms_payment_diagnostics_https_url(string $url): bool
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

function cms_payment_diagnostics_url_has_query(string $url): bool
{
    $parts = parse_url($url);

    return is_array($parts) && isset($parts['query']) && (string) $parts['query'] !== '';
}

function cms_payment_diagnostics_url_has_sensitive_query(string $url): bool
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
        if (is_string($value) && preg_match('/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i', rawurldecode($value)) === 1) {
            return true;
        }
    }

    return false;
}

function cms_payment_diagnostics_redact(string $detail): string
{
    if ($detail === '') {
        return '';
    }

    return preg_replace('/(password|secret|token|authorization|signature|api[_-]?key|access[_-]?key)(=|:)[^;\s,]+/i', '$1$2[redacted]', $detail) ?? '';
}

function cms_payment_diagnostics_cli_help(): string
{
    return <<<'TXT'
Usage: php scripts/diagnose_payment_providers.php [--root=/path/to/cms] [--json] [--repair]

Options:
  --root=/path/to/cms  CMS installation root. Defaults to the project root.
  --json               Print machine-readable diagnostics JSON.
  --repair             Repair Provider storage by running the Core repository migration path.
  --help, -h           Show this help without connecting to the database.

Checks cover Provider rows, legacy payment plugin field migration, enabled/default Provider discovery,
PaymentService eligibility and Card Delivery checkout readiness.
TXT;
}

/** @return array{root:string,json:bool,repair:bool,help:bool} */
function cms_payment_diagnostics_cli_args(array $argv): array
{
    $root = dirname(__DIR__);
    $json = false;
    $repair = false;
    $help = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $help = true;
            continue;
        }
        if ($arg === '--json') {
            $json = true;
            continue;
        }
        if ($arg === '--repair') {
            $repair = true;
            continue;
        }
        if (str_starts_with($arg, '--root=')) {
            $root = substr($arg, 7);
        }
    }

    return ['root' => $root, 'json' => $json, 'repair' => $repair, 'help' => $help];
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $args = cms_payment_diagnostics_cli_args($argv);
    if ($args['help']) {
        echo cms_payment_diagnostics_cli_help() . PHP_EOL;
        exit(0);
    }
    $repair = null;
    if ($args['repair']) {
        try {
            $repair = cms_repair_payment_provider_settings($args['root']);
        } catch (Throwable $exception) {
            $repair = [
                'ok' => false,
                'message' => cms_payment_diagnostics_redact($exception->getMessage()),
                'before_provider_rows' => [],
                'after_provider_rows' => [],
            ];
        }
    }
    $result = cms_diagnose_payment_providers($args['root']);
    if (is_array($repair)) {
        $result['repair'] = $repair;
    }
    if ($args['json']) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo 'Payment Provider diagnostics: ' . $result['status'] . PHP_EOL;
        if (is_array($repair)) {
            echo 'Repair: ' . (($repair['ok'] ?? false) ? 'OK' : 'ERROR') . ' - ' . ($repair['message'] ?? '') . PHP_EOL;
        }
        foreach ($result['checks'] as $check) {
            echo '[' . (($check['ok'] ?? false) ? 'OK' : strtoupper((string) ($check['severity'] ?? 'warning'))) . '] ' . ($check['id'] ?? '') . ' - ' . ($check['message'] ?? '') . (($check['detail'] ?? '') !== '' ? ' (' . $check['detail'] . ')' : '') . PHP_EOL;
        }
    }
    exit(($repair['ok'] ?? true) === true && $result['errors'] === 0 ? 0 : 1);
}
