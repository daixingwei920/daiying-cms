<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentScheduler;
use Cms\Core\Audit\AuditLogger;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Export\CorePaymentLedgerImporter;
use Cms\Core\Export\ExportMediaFileRestorer;
use Cms\Core\Export\ExportPackageBuilder;
use Cms\Core\Export\ExportPackageReader;
use Cms\Core\Export\OfficialExportContentImporter;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Market\DatabaseMarketJobRepository;
use Cms\Core\Market\ExtensionRemovalService;
use Cms\Core\Market\ExtensionUpdateJobRunner;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketJobMigrator;
use Cms\Core\Market\MarketJobRepository;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\Market\MarketWorkerLock;
use Cms\Core\MarketServer\MarketServerRepository;
use Cms\Core\MarketServer\NotificationDispatcher;
use Cms\Core\MarketServer\NullNotificationChannel;
use Cms\Core\MarketServer\WebhookNotificationChannel;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Update\SignatureVerifier;
use Cms\Core\Update\UpdateService;

define('CMS_SOURCE_ROOT', __DIR__);
define('CMS_ROOT', getenv('CMS_ROOT_OVERRIDE') !== false ? (string) getenv('CMS_ROOT_OVERRIDE') : __DIR__);

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$command = $argv[1] ?? 'help';

function cms_cli_positive_limit(?string $value, int $default = 100): int
{
    if ($value === null) {
        return $default;
    }

    if ($value === '' || $value !== trim($value) || preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1 || (int) $value > 1000) {
        throw new InvalidArgumentException('Limit must be a positive integer within Core bounds.');
    }

    return (int) $value;
}

function cms_cli_safe_diagnostic_text(string $value, int $maxBytes = 191): string
{
    $secretPattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
    $decodedValue = rawurldecode($value);
    if (
        $value === ''
        || $value !== trim($value)
        || strlen($value) > $maxBytes
        || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        || preg_match($secretPattern, $value) === 1
        || $decodedValue !== trim($decodedValue)
        || preg_match('/[\x00-\x1F\x7F]/', $decodedValue) === 1
        || preg_match($secretPattern, $decodedValue) === 1
    ) {
        return '[invalid]';
    }

    return $value;
}

function cms_cli_safe_package_name(string $path): string
{
    return cms_cli_safe_diagnostic_text(basename($path));
}

function cms_cli_safe_error(Throwable $exception): string
{
    return cms_cli_safe_diagnostic_text($exception->getMessage(), 500);
}

/** @return array<string,string|bool> */
function cms_cli_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
        } else {
            $options[$arg] = true;
        }
    }

    return $options;
}

function cms_cli_prompt(string $label, string $default = ''): string
{
    if ($default !== '') {
        return $default;
    }
    if (function_exists('readline')) {
        $value = readline($label . ': ');
        return is_string($value) ? trim($value) : '';
    }
    echo $label . ': ';
    $value = fgets(STDIN);
    return is_string($value) ? trim($value) : '';
}

function cms_cli_prompt_secret(string $label, string $default = ''): string
{
    if ($default !== '') {
        return $default;
    }
    echo $label . ': ';
    $sttyMode = null;
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        $sttyMode = shell_exec('stty -g 2>/dev/null');
        shell_exec('stty -echo 2>/dev/null');
    }
    $value = fgets(STDIN);
    if (is_string($sttyMode) && $sttyMode !== '') {
        shell_exec('stty ' . escapeshellarg(trim($sttyMode)) . ' 2>/dev/null');
    }
    echo PHP_EOL;

    return is_string($value) ? rtrim($value, "\r\n") : '';
}

function cms_cli_run_migrations(Settings $settings): void
{
    $pdo = ConnectionFactory::make($settings);
    $migrations = [];
    foreach (glob(CMS_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

if ($command === 'migrate') {
    $settings = Settings::load(CMS_ROOT);
    $pdo = ConnectionFactory::make($settings);
    $migrations = [];

    foreach (glob(CMS_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migration = require $file;
        $migrations[] = $migration;
    }

    $runner = new MigrationRunner($pdo, $migrations);
    $count = $runner->run();
    echo "Applied {$count} migration(s)." . PHP_EOL;
    exit(0);
}

if ($command === 'market:platform:init') {
    $options = cms_cli_options($argv);
    $settings = Settings::load(CMS_ROOT);
    cms_cli_run_migrations($settings);
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    if ($repo->platformInitialized()) {
        echo "Platform already initialized." . PHP_EOL;
        echo "Use Platform Owner account management to create additional reviewers/accounts." . PHP_EOL;
        exit(1);
    }
    if (!isset($options['yes'])) {
        echo "This command initializes this instance as the Daiying official Market Platform." . PHP_EOL;
        echo "Run again with --yes to confirm server-side bootstrap." . PHP_EOL;
        exit(1);
    }
    $email = cms_cli_prompt('Platform Owner email', (string) ($options['email'] ?? ''));
    $name = cms_cli_prompt('Platform Owner display name', (string) ($options['name'] ?? ''));
    $password = cms_cli_prompt_secret('Platform Owner password', (string) ($options['password'] ?? ''));
    $confirm = cms_cli_prompt_secret('Confirm password', (string) ($options['password-confirm'] ?? ''));
    if ($password === '' || !hash_equals($password, $confirm)) {
        echo "Password confirmation does not match." . PHP_EOL;
        exit(1);
    }
    try {
        $owner = $repo->initializePlatformOwner($email, $name, $password, 'cli');
        $state = $repo->platformState();
        echo json_encode([
            'status' => 'Completed',
            'owner_email' => $owner->email,
            'owner_role' => $owner->role,
            'platform_instance_id' => $state['platform_instance_id'],
            'initialized_at' => $state['initialized_at'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'Failed', 'error' => cms_cli_safe_error($exception)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'market:platform-owner:reset-password') {
    $options = cms_cli_options($argv);
    $settings = Settings::load(CMS_ROOT);
    cms_cli_run_migrations($settings);
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    if (!$repo->platformInitialized()) {
        echo "Platform is not initialized." . PHP_EOL;
        exit(1);
    }
    $email = cms_cli_prompt('Platform Owner email', (string) ($options['email'] ?? ''));
    $password = cms_cli_prompt_secret('New Platform Owner password', (string) ($options['password'] ?? ''));
    $confirm = cms_cli_prompt_secret('Confirm password', (string) ($options['password-confirm'] ?? ''));
    if ($password === '' || !hash_equals($password, $confirm)) {
        echo "Password confirmation does not match." . PHP_EOL;
        exit(1);
    }
    try {
        $owner = $repo->resetPlatformOwnerPassword($email, $password, 'cli');
        echo json_encode([
            'status' => 'Completed',
            'owner_email' => $owner->email,
            'audit_event' => 'PLATFORM_OWNER_PASSWORD_RESET',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        echo json_encode(['status' => 'Failed', 'error' => cms_cli_safe_error($exception)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'export') {
    $settings = Settings::load(CMS_ROOT);
    $pdo = ConnectionFactory::make($settings);
    $path = (new ExportPackageBuilder(CMS_ROOT, $pdo, (string) $settings->get('app.version', '0.0.0')))->build('cli');
    echo "Created export package: {$path}" . PHP_EOL;
    exit(0);
}

if ($command === 'preflight-payment-ledger') {
    $package = $argv[2] ?? '';
    if ($package === '' || !is_file($package)) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => '',
            'error' => 'Official export package was not found.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    try {
        $reader = new ExportPackageReader();
        $summary = $reader->paymentLedgerSummary($package);
        $ledger = $reader->paymentLedger($package);
        echo json_encode([
            'status' => 'Verified',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => hash_file('sha256', $package) ?: '',
            'summary' => $summary,
            'counts' => $ledger['counts'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => hash_file('sha256', $package) ?: '',
            'error' => cms_cli_safe_error($exception),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'import-payment-ledger') {
    $package = $argv[2] ?? '';
    if ($package === '' || !is_file($package)) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => '',
            'error' => 'Official export package was not found.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    $settings = Settings::load(CMS_ROOT);
    $pdo = ConnectionFactory::make($settings);
    $audit = new AuditLogger($pdo);
    $packageContext = [
        'package_name' => cms_cli_safe_package_name($package),
        'package_sha256' => hash_file('sha256', $package) ?: '',
    ];
    try {
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        $result = (new CorePaymentLedgerImporter($pdo))->importPackage($package);
        $audit->record('cli', null, 'payment.ledger.imported', $packageContext + [
            'sections' => $result,
        ]);
        if ($started && $pdo->inTransaction()) {
            $pdo->commit();
        }
        echo json_encode(['status' => 'Completed'] + $packageContext + ['sections' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $safeError = cms_cli_safe_error($exception);
        $failurePayload = ['status' => 'Failed'] + $packageContext + ['error' => $safeError];
        try {
            $audit->record('cli', null, 'payment.ledger.import_failed', $packageContext + [
                'error' => $safeError,
            ]);
        } catch (Throwable $auditException) {
            $failurePayload['audit_error'] = cms_cli_safe_error($auditException);
        }
        echo json_encode($failurePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'preflight-media-files') {
    $package = $argv[2] ?? '';
    if ($package === '' || !is_file($package)) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => '',
            'error' => 'Official export package was not found.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    try {
        $reader = new ExportPackageReader();
        $files = $reader->mediaFiles($package);
        echo json_encode([
            'status' => 'Verified',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => hash_file('sha256', $package) ?: '',
            'media_files' => [
                'count' => count($files),
                'byte_size' => array_sum(array_map(static fn (array $file): int => (int) ($file['byte_size'] ?? 0), $files)),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => hash_file('sha256', $package) ?: '',
            'error' => cms_cli_safe_error($exception),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'import-media-files') {
    $package = $argv[2] ?? '';
    if ($package === '' || !is_file($package)) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => '',
            'error' => 'Official export package was not found.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    $packageContext = [
        'package_name' => cms_cli_safe_package_name($package),
        'package_sha256' => hash_file('sha256', $package) ?: '',
    ];
    try {
        $settings = Settings::load(CMS_ROOT);
        $pdo = ConnectionFactory::make($settings);
        $reader = new ExportPackageReader();
        $reader->mediaFiles($package);
        $result = (new ExportMediaFileRestorer(CMS_ROOT, $reader))->restore($package);
        $auditPayload = $packageContext + [
            'restored' => (int) ($result['restored'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
        ];
        $auditStatus = 'Recorded';
        try {
            (new AuditLogger($pdo))->record('cli', null, 'media.files.imported', $auditPayload);
        } catch (Throwable $auditException) {
            $auditStatus = 'Failed';
            $result['audit_error'] = cms_cli_safe_error($auditException);
        }
        echo json_encode(['status' => 'Completed'] + $packageContext + ['media_files' => $result, 'audit_status' => $auditStatus], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        $safeError = cms_cli_safe_error($exception);
        $failurePayload = ['status' => 'Failed'] + $packageContext + ['error' => $safeError];
        try {
            if (isset($pdo) && $pdo instanceof PDO) {
                (new AuditLogger($pdo))->record('cli', null, 'media.files.import_failed', $packageContext + [
                    'error' => $safeError,
                ]);
            }
        } catch (Throwable $auditException) {
            $failurePayload['audit_error'] = cms_cli_safe_error($auditException);
        }
        echo json_encode($failurePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'preflight-content-data') {
    $package = $argv[2] ?? '';
    if ($package === '' || !is_file($package)) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => '',
            'error' => 'Official export package was not found.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    try {
        $settings = Settings::load(CMS_ROOT);
        $result = (new OfficialExportContentImporter(ConnectionFactory::make($settings)))->preflight($package);
        echo json_encode([
            'status' => 'Verified',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => hash_file('sha256', $package) ?: '',
        ] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => hash_file('sha256', $package) ?: '',
            'error' => cms_cli_safe_error($exception),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'import-content-data') {
    $package = $argv[2] ?? '';
    if ($package === '' || !is_file($package)) {
        echo json_encode([
            'status' => 'Failed',
            'package_name' => cms_cli_safe_package_name($package),
            'package_sha256' => '',
            'error' => 'Official export package was not found.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
    $settings = Settings::load(CMS_ROOT);
    $pdo = ConnectionFactory::make($settings);
    $packageContext = [
        'package_name' => cms_cli_safe_package_name($package),
        'package_sha256' => hash_file('sha256', $package) ?: '',
    ];
    try {
        $result = (new OfficialExportContentImporter($pdo))->importPackage($package);
        $auditStatus = 'Recorded';
        try {
            (new AuditLogger($pdo))->record('cli', null, 'content.official_export.imported', $packageContext + $result);
        } catch (Throwable $auditException) {
            $auditStatus = 'Failed';
            $result['audit_error'] = cms_cli_safe_error($auditException);
        }
        echo json_encode(['status' => 'Completed'] + $packageContext + ['content_data' => $result, 'audit_status' => $auditStatus], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        $safeError = cms_cli_safe_error($exception);
        $failurePayload = ['status' => 'Failed'] + $packageContext + ['error' => $safeError];
        try {
            (new AuditLogger($pdo))->record('cli', null, 'content.official_export.import_failed', $packageContext + [
                'error' => $safeError,
            ]);
        } catch (Throwable $auditException) {
            $failurePayload['audit_error'] = cms_cli_safe_error($auditException);
        }
        echo json_encode($failurePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'publish-scheduled-content') {
    $settings = Settings::load(CMS_ROOT);
    $now = $argv[2] ?? null;
    $limit = max(1, (int) ($argv[3] ?? 50));
    $result = (new ContentScheduler(ConnectionFactory::make($settings)))->publishDue($now, $limit);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'expire-payment-authorizations') {
    try {
        $limit = cms_cli_positive_limit($argv[2] ?? null);
        $settings = Settings::load(CMS_ROOT);
        $pdo = ConnectionFactory::make($settings);
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        $expired = (new PaymentService($pdo, new PaymentRepository($pdo)))->expirePaymentAuthorizations($limit);
        (new AuditLogger($pdo))->record('cli', null, 'payment.authorization.expired_marked', ['count' => $expired, 'limit' => $limit]);
        if ($started && $pdo->inTransaction()) {
            $pdo->commit();
        }
        echo json_encode(['status' => 'Completed', 'expired' => $expired, 'limit' => $limit], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'Failed', 'error' => cms_cli_safe_error($exception)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'expire-payment-entitlements') {
    try {
        $limit = cms_cli_positive_limit($argv[2] ?? null);
        $settings = Settings::load(CMS_ROOT);
        $pdo = ConnectionFactory::make($settings);
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        $expired = (new PaymentService($pdo, new PaymentRepository($pdo)))->expirePaymentEntitlements($limit);
        (new AuditLogger($pdo))->record('cli', null, 'payment.entitlement.expired_marked', ['count' => $expired, 'limit' => $limit]);
        if ($started && $pdo->inTransaction()) {
            $pdo->commit();
        }
        echo json_encode(['status' => 'Completed', 'expired' => $expired, 'limit' => $limit], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'Failed', 'error' => cms_cli_safe_error($exception)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

if ($command === 'verify-update') {
    $package = $argv[2] ?? '';
    $settings = Settings::load(CMS_ROOT);
    $plan = (new UpdateService(
        CMS_ROOT,
        (string) $settings->get('app.version', '0.0.0'),
        new SignatureVerifier((string) $settings->get('updates.public_key', '')),
    ))->verifyAndPlan($package);
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($command === 'install-market') {
    $package = $argv[2] ?? '';
    $marketId = $argv[3] ?? 'cli';
    $settings = Settings::load(CMS_ROOT);
    $authorization = new InstallAuthorization('cli', $marketId, gmdate('c', time() + 300), is_file($package) ? (string) hash_file('sha256', $package) : '');
    $result = (new MarketPackageInstaller(CMS_ROOT))->install($package, $authorization, ConnectionFactory::make($settings));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'uninstall-extension') {
    $extensionId = $argv[2] ?? '';
    $type = $argv[3] ?? 'plugin';
    $settings = Settings::load(CMS_ROOT);
    (new ExtensionRemovalService(CMS_ROOT))->uninstall($extensionId, $type, ConnectionFactory::make($settings));
    echo "Uninstalled {$type}: {$extensionId}" . PHP_EOL;
    exit(0);
}

if ($command === 'run-market-jobs') {
    $settings = Settings::load(CMS_ROOT);
    $limit = max(1, (int) ($argv[2] ?? 1));
    $pdo = ConnectionFactory::make($settings);
    $jobs = new DatabaseMarketJobRepository($pdo);
    $runner = new ExtensionUpdateJobRunner(CMS_ROOT, $pdo, $jobs);
    $results = [];
    for ($i = 0; $i < $limit; $i++) {
        $result = $runner->runNext(new MarketWorkerLock(CMS_ROOT . '/storage/market/worker.lock'));
        $results[] = $result;
        if ($result === null || ($result['status'] ?? '') === 'Locked') {
            break;
        }
    }
    echo json_encode(['results' => $results, 'summary' => $jobs->summary()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'migrate-market-jobs') {
    $settings = Settings::load(CMS_ROOT);
    $pdo = ConnectionFactory::make($settings);
    $count = (new MarketJobMigrator(
        new MarketJobRepository(CMS_ROOT . '/storage/market/jobs.json'),
        new DatabaseMarketJobRepository($pdo),
    ))->migrateJsonToDatabase();
    echo "Migrated {$count} market job(s)." . PHP_EOL;
    exit(0);
}

if ($command === 'dispatch-market-notifications') {
    $settings = Settings::load(CMS_ROOT);
    $limit = max(1, (int) ($argv[2] ?? 50));
    $lock = new MarketWorkerLock(CMS_ROOT . '/storage/market/notification-worker.lock');
    if (!$lock->acquire()) {
        echo json_encode(['status' => 'Locked'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    try {
        $url = (string) $settings->get('market.notification_webhook_url', '');
        $channel = $url === ''
            ? new NullNotificationChannel()
            : new WebhookNotificationChannel($url, (string) $settings->get('market.notification_webhook_secret', ''));
        $sent = (new NotificationDispatcher())->dispatch(new MarketServerRepository(ConnectionFactory::make($settings)), $channel, $limit);
        echo json_encode(['status' => 'Completed', 'sent' => $sent], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } finally {
        $lock->release();
    }
    exit(0);
}

if ($command === 'retry-market-notifications') {
    $settings = Settings::load(CMS_ROOT);
    $count = (new MarketServerRepository(ConnectionFactory::make($settings)))->retryFailedNotifications();
    echo "Queued {$count} failed notification(s) for retry." . PHP_EOL;
    exit(0);
}

if ($command === 'run-notification-schedules') {
    $settings = Settings::load(CMS_ROOT);
    $lock = new MarketWorkerLock(CMS_ROOT . '/storage/market/notification-schedule.lock');
    if (!$lock->acquire()) {
        echo json_encode(['status' => 'Locked'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    $url = (string) $settings->get('market.notification_webhook_url', '');
    $channel = $url === ''
        ? new NullNotificationChannel()
        : new WebhookNotificationChannel($url, (string) $settings->get('market.notification_webhook_secret', ''));
    $dispatcher = new NotificationDispatcher();
    $runs = [];
    try {
        foreach ($repo->dueNotificationSchedules() as $schedule) {
            $sent = $dispatcher->dispatch($repo, $channel, 50);
            $repo->markNotificationScheduleRun((int) $schedule['id']);
            $runs[] = ['schedule_key' => (string) $schedule['schedule_key'], 'sent' => $sent];
        }
    } finally {
        $lock->release();
    }
    echo json_encode(['status' => 'Completed', 'runs' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'process-license-renewals') {
    $settings = Settings::load(CMS_ROOT);
    $count = (new MarketServerRepository(ConnectionFactory::make($settings)))->processAutoRenewals();
    echo "Processed {$count} license renewal(s)." . PHP_EOL;
    exit(0);
}

if ($command === 'run-subscription-actions') {
    $settings = Settings::load(CMS_ROOT);
    $limit = max(1, (int) ($argv[2] ?? 20));
    $lock = new MarketWorkerLock(CMS_ROOT . '/storage/market/subscription-actions.lock');
    if (!$lock->acquire()) {
        echo json_encode(['status' => 'Locked'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    try {
        $count = (new MarketServerRepository(ConnectionFactory::make($settings)))->processSubscriptionActionJobs($limit);
        echo json_encode(['status' => 'Completed', 'processed' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } finally {
        $lock->release();
    }
    exit(0);
}

if ($command === 'dispatch-webhook-dead-letter-alerts') {
    $settings = Settings::load(CMS_ROOT);
    $limit = max(1, (int) ($argv[2] ?? 20));
    $url = (string) $settings->get('market.notification_webhook_url', '');
    $channel = $url === ''
        ? new NullNotificationChannel()
        : new WebhookNotificationChannel($url, (string) $settings->get('market.notification_webhook_secret', ''));
    $sent = (new MarketServerRepository(ConnectionFactory::make($settings)))->dispatchWebhookDeadLetterAlerts($channel, $limit);
    echo json_encode(['status' => 'Completed', 'sent' => $sent], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'export-billing-summary') {
    $settings = Settings::load(CMS_ROOT);
    $marketId = (string) ($argv[2] ?? '');
    $format = strtolower((string) ($argv[3] ?? 'json'));
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    if ($format === 'csv') {
        echo $repo->exportBillingSummaryCsv($marketId, 'Paid', 'cli');
        exit(0);
    }
    echo json_encode($repo->billingSummary($marketId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'export-commercial-audits') {
    $settings = Settings::load(CMS_ROOT);
    $eventType = (string) ($argv[2] ?? '');
    $subjectKey = (string) ($argv[3] ?? '');
    echo (new MarketServerRepository(ConnectionFactory::make($settings)))->commercialAuditCsv($eventType, $subjectKey, 'cli');
    exit(0);
}

if ($command === 'purge-expired-portal-tokens') {
    $settings = Settings::load(CMS_ROOT);
    $count = (new MarketServerRepository(ConnectionFactory::make($settings)))->purgeExpiredPortalTokens();
    echo json_encode(['status' => 'Completed', 'purged' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'purge-commercial-audits') {
    $settings = Settings::load(CMS_ROOT);
    $count = (new MarketServerRepository(ConnectionFactory::make($settings)))->purgeCommercialAudits();
    echo json_encode(['status' => 'Completed', 'purged' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'commercial-audit-policy') {
    $settings = Settings::load(CMS_ROOT);
    echo json_encode((new MarketServerRepository(ConnectionFactory::make($settings)))->commercialAuditPolicy(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'notify-commercial-audit-risk') {
    $settings = Settings::load(CMS_ROOT);
    $windowHours = max(1, (int) ($argv[2] ?? 24));
    $minimum = (string) ($argv[3] ?? 'Medium');
    $cooldownMinutes = max(1, (int) ($argv[4] ?? 60));
    $count = (new MarketServerRepository(ConnectionFactory::make($settings)))->dispatchCommercialAuditRiskNotification($windowHours, $minimum, $cooldownMinutes);
    echo json_encode(['status' => 'Completed', 'queued_notifications' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'commercial-audit-risk-notifications') {
    $settings = Settings::load(CMS_ROOT);
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    echo json_encode([
        'summary' => $repo->commercialAuditRiskNotificationSummary(),
        'notifications' => $repo->recentCommercialAuditRiskNotifications((int) ($argv[2] ?? 10)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'commercial-audit-risk-notification-incidents') {
    $settings = Settings::load(CMS_ROOT);
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    echo json_encode([
        'summary' => $repo->commercialAuditRiskNotificationIncidentSummary(),
        'incidents' => $repo->recentCommercialAuditRiskNotificationIncidents((int) ($argv[2] ?? 10)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'export-commercial-audit-risk-notification-incidents') {
    $settings = Settings::load(CMS_ROOT);
    echo (new MarketServerRepository(ConnectionFactory::make($settings)))->exportCommercialAuditRiskNotificationIncidentCsv((int) ($argv[2] ?? 100), 'cli');
    exit(0);
}

if ($command === 'commercial-audit-risk-notification-incident-exports') {
    $settings = Settings::load(CMS_ROOT);
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    echo json_encode([
        'summary' => $repo->commercialAuditRiskNotificationIncidentExportActorSummary(),
        'exports' => $repo->recentCommercialAuditRiskNotificationIncidentExports((int) ($argv[2] ?? 10)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'export-commercial-audit-risk-notification-incident-exports') {
    $settings = Settings::load(CMS_ROOT);
    echo (new MarketServerRepository(ConnectionFactory::make($settings)))->commercialAuditRiskNotificationIncidentExportHistoryCsv((int) ($argv[2] ?? 100));
    exit(0);
}

if ($command === 'export-commercial-audit-risk-notification-incident-export-actors') {
    $settings = Settings::load(CMS_ROOT);
    echo (new MarketServerRepository(ConnectionFactory::make($settings)))->exportCommercialAuditRiskNotificationIncidentExportActorSummaryCsv('cli');
    exit(0);
}

if ($command === 'commercial-audit-risk-notification-incident-export-actor-exports') {
    $settings = Settings::load(CMS_ROOT);
    $repo = new MarketServerRepository(ConnectionFactory::make($settings));
    $pagination = $repo->commercialAuditRiskNotificationIncidentExportActorSummaryExportPagination((int) ($argv[2] ?? 10), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), (string) ($argv[5] ?? ''), (int) ($argv[6] ?? 0));
    echo json_encode([
        'filters' => ['actor' => (string) ($argv[3] ?? ''), 'since' => (string) ($argv[4] ?? ''), 'until' => (string) ($argv[5] ?? '')],
        'pagination' => ['limit' => $pagination['limit'], 'offset' => $pagination['offset'], 'page' => $pagination['page'], 'pages' => $pagination['pages'], 'page_label' => $pagination['page_label'], 'from' => $pagination['from'], 'to' => $pagination['to'], 'range_label' => $pagination['range_label'], 'filter_applied' => $pagination['filter_applied'], 'filter_label' => $pagination['filter_label'], 'filter_keys' => $pagination['filter_keys'], 'filter_values' => $pagination['filter_values'], 'filter_query' => $pagination['filter_query'], 'page_query' => $pagination['page_query'], 'next_page_query' => $pagination['next_page_query'], 'prev_page_query' => $pagination['prev_page_query'], 'first_page_query' => $pagination['first_page_query'], 'last_page_query' => $pagination['last_page_query'], 'page_queries' => $pagination['page_queries'], 'page_offsets' => $pagination['page_offsets'], 'page_availability' => $pagination['page_availability'], 'page_navigation' => $pagination['page_navigation'], 'page_counts' => $pagination['page_counts'], 'page_labels' => $pagination['page_labels'], 'page_flags' => $pagination['page_flags'], 'page_limits' => $pagination['page_limits'], 'page_sort' => $pagination['page_sort'], 'page_export' => $pagination['page_export'], 'page_download' => $pagination['page_download'], 'page_endpoint' => $pagination['page_endpoint'], 'page_auth_redirect' => $pagination['page_auth_redirect'], 'page_cli' => $pagination['page_cli'], 'page_links' => $pagination['page_links'], 'page_parameters' => $pagination['page_parameters'], 'page_defaults' => $pagination['page_defaults'], 'page_validation' => $pagination['page_validation'], 'page_cache' => $pagination['page_cache'], 'page_response' => $pagination['page_response'], 'page_csv_columns' => $pagination['page_csv_columns'], 'page_csv_dialect' => $pagination['page_csv_dialect'], 'page_csv_profile' => $pagination['page_csv_profile'], 'page_csv_schema' => $pagination['page_csv_schema'], 'page_csv_units' => $pagination['page_csv_units'], 'page_csv_labels' => $pagination['page_csv_labels'], 'page_csv_empty_values' => $pagination['page_csv_empty_values'], 'page_csv_safety' => $pagination['page_csv_safety'], 'page_csv_source' => $pagination['page_csv_source'], 'page_csv_row_shape' => $pagination['page_csv_row_shape'], 'page_csv_integrity' => $pagination['page_csv_integrity'], 'page_csv_lifecycle' => $pagination['page_csv_lifecycle'], 'page_csv_delivery' => $pagination['page_csv_delivery'], 'page_csv_scope' => $pagination['page_csv_scope'], 'page_csv_privacy' => $pagination['page_csv_privacy'], 'page_csv_handling' => $pagination['page_csv_handling'], 'page_csv_compliance' => $pagination['page_csv_compliance'], 'page_csv_provenance' => $pagination['page_csv_provenance'], 'page_csv_review' => $pagination['page_csv_review'], 'page_csv_observability' => $pagination['page_csv_observability'], 'page_csv_archive' => $pagination['page_csv_archive'], 'page_csv_custody' => $pagination['page_csv_custody'], 'page_csv_accessibility' => $pagination['page_csv_accessibility'], 'page_csv_localization' => $pagination['page_csv_localization'], 'page_csv_reconciliation' => $pagination['page_csv_reconciliation'], 'page_csv_recovery' => $pagination['page_csv_recovery'], 'page_csv_notification' => $pagination['page_csv_notification'], 'page_csv_actions' => $pagination['page_csv_actions'], 'page_csv_permissions' => $pagination['page_csv_permissions'], 'page_csv_error_handling' => $pagination['page_csv_error_handling'], 'page_csv_availability' => $pagination['page_csv_availability'], 'page_csv_audit_controls' => $pagination['page_csv_audit_controls'], 'page_csv_governance' => $pagination['page_csv_governance'], 'page_csv_attestation' => $pagination['page_csv_attestation'], 'page_csv_disclosure' => $pagination['page_csv_disclosure'], 'page_csv_revocation' => $pagination['page_csv_revocation'], 'page_csv_expiration' => $pagination['page_csv_expiration'], 'page_csv_rotation' => $pagination['page_csv_rotation'], 'page_csv_key_management' => $pagination['page_csv_key_management'], 'page_csv_signature' => $pagination['page_csv_signature'], 'page_csv_verification' => $pagination['page_csv_verification'], 'page_csv_monitoring' => $pagination['page_csv_monitoring'], 'page_csv_incident_response' => $pagination['page_csv_incident_response'], 'page_csv_postmortem' => $pagination['page_csv_postmortem'], 'page_csv_lessons' => $pagination['page_csv_lessons'], 'page_csv_training' => $pagination['page_csv_training'], 'page_csv_drill' => $pagination['page_csv_drill'], 'page_csv_evaluation' => $pagination['page_csv_evaluation'], 'page_csv_remediation' => $pagination['page_csv_remediation'], 'page_csv_exception' => $pagination['page_csv_exception'], 'page_csv_waiver' => $pagination['page_csv_waiver'], 'page_csv_waiver_review' => $pagination['page_csv_waiver_review'], 'page_csv_waiver_closure' => $pagination['page_csv_waiver_closure'], 'page_csv_waiver_notification' => $pagination['page_csv_waiver_notification'], 'page_csv_waiver_acknowledgement' => $pagination['page_csv_waiver_acknowledgement'], 'page_csv_waiver_escalation' => $pagination['page_csv_waiver_escalation'], 'page_csv_waiver_audit' => $pagination['page_csv_waiver_audit'], 'page_csv_waiver_audit_notification' => $pagination['page_csv_waiver_audit_notification'], 'page_csv_waiver_audit_acknowledgement' => $pagination['page_csv_waiver_audit_acknowledgement'], 'page_csv_waiver_audit_escalation' => $pagination['page_csv_waiver_audit_escalation'], 'page_csv_waiver_audit_resolution' => $pagination['page_csv_waiver_audit_resolution'], 'page_csv_waiver_audit_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_notification'], 'page_csv_waiver_audit_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_acknowledgement'], 'page_csv_waiver_audit_resolution_escalation' => $pagination['page_csv_waiver_audit_resolution_escalation'], 'page_csv_waiver_audit_resolution_closure' => $pagination['page_csv_waiver_audit_resolution_closure'], 'page_csv_waiver_audit_resolution_closure_notification' => $pagination['page_csv_waiver_audit_resolution_closure_notification'], 'page_csv_waiver_audit_resolution_closure_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition' => $pagination['page_csv_waiver_audit_resolution_closure_disposition'], 'page_csv_waiver_audit_resolution_closure_disposition_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_disposition_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_acknowledgement'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_notification' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_notification'], 'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_acknowledgement' => $pagination['page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_acknowledgement'], 'page_contract' => $pagination['page_contract'], 'page_request' => $pagination['page_request'], 'page_capabilities' => $pagination['page_capabilities'], 'page_security' => $pagination['page_security'], 'page_empty_state' => $pagination['page_empty_state'], 'page_refresh' => $pagination['page_refresh'], 'page_audit' => $pagination['page_audit'], 'page_summary' => $pagination['page_summary'], 'page_window' => $pagination['page_window'], 'page_filter' => $pagination['page_filter'], 'page_diagnostics' => $pagination['page_diagnostics'], 'page_status' => $pagination['page_status'], 'page_status_label' => $pagination['page_status_label'], 'page_state' => $pagination['page_state'], 'active_filter_count' => $pagination['active_filter_count'], 'remaining' => $pagination['remaining'], 'is_empty' => $pagination['is_empty'], 'is_out_of_range' => $pagination['is_out_of_range'], 'has_more' => $pagination['has_more'], 'has_previous' => $pagination['has_previous'], 'next_offset' => $pagination['next_offset'], 'prev_offset' => $pagination['prev_offset'], 'first_offset' => $pagination['first_offset'], 'last_offset' => $pagination['last_offset']],
        'total' => $pagination['total'],
        'exports' => $repo->recentCommercialAuditRiskNotificationIncidentExportActorSummaryExports($pagination['limit'], (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), (string) ($argv[5] ?? ''), $pagination['offset']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($command === 'export-commercial-audit-risk-notification-incident-export-actor-exports') {
    $settings = Settings::load(CMS_ROOT);
    echo (new MarketServerRepository(ConnectionFactory::make($settings)))->commercialAuditRiskNotificationIncidentExportActorSummaryExportHistoryCsv((int) ($argv[2] ?? 100), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), (string) ($argv[5] ?? ''), (int) ($argv[6] ?? 0));
    exit(0);
}

if ($command === 'retry-commercial-audit-risk-notifications') {
    $settings = Settings::load(CMS_ROOT);
    $retried = (new MarketServerRepository(ConnectionFactory::make($settings)))->retryFailedCommercialAuditRiskNotifications();
    echo json_encode(['status' => 'Completed', 'retried' => $retried], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

echo "Available commands:" . PHP_EOL;
echo "  migrate    Run core database migrations" . PHP_EOL;
echo "  market:platform:init --yes --email=<owner> --name=<name>  Initialize this server as the official Market Platform and create the first Platform Owner" . PHP_EOL;
echo "  market:platform-owner:reset-password --email=<owner>  Reset an existing Platform Owner password from the server CLI" . PHP_EOL;
echo "  export     Create official export package" . PHP_EOL;
echo "  preflight-payment-ledger <official-export-zip>  Verify Core payment ledger summary without writing data" . PHP_EOL;
echo "  import-payment-ledger <official-export-zip>  Restore Core payment ledger from an official export package" . PHP_EOL;
echo "  preflight-media-files <official-export-zip>  Verify official export media files without writing data" . PHP_EOL;
echo "  import-media-files <official-export-zip>  Restore official export media files into content/uploads" . PHP_EOL;
echo "  preflight-content-data <official-export-zip>  Verify official export content, media metadata and URL mappings" . PHP_EOL;
echo "  import-content-data <official-export-zip>  Restore official export content, media metadata and URL mappings" . PHP_EOL;
echo "  publish-scheduled-content [now] [limit]  Publish scheduled content that is due" . PHP_EOL;
echo "  expire-payment-authorizations [limit]  Mark expired Core payment authorizations" . PHP_EOL;
echo "  expire-payment-entitlements [limit]  Mark expired Core payment entitlements" . PHP_EOL;
echo "  verify-update <zip>  Verify and plan a manual update package" . PHP_EOL;
echo "  install-market <zip> <market-id>  Verify and atomically install a market package" . PHP_EOL;
echo "  uninstall-extension <id> <plugin|theme>  Remove extension files and keep data/history" . PHP_EOL;
echo "  run-market-jobs [limit]  Run queued market update job(s) with a worker lock" . PHP_EOL;
echo "  migrate-market-jobs  Import legacy JSON market jobs into the database queue" . PHP_EOL;
echo "  dispatch-market-notifications [limit]  Dispatch queued developer notifications" . PHP_EOL;
echo "  retry-market-notifications  Move failed developer notifications back to retry queue" . PHP_EOL;
echo "  run-notification-schedules  Run due developer notification schedules" . PHP_EOL;
echo "  process-license-renewals  Process auto-renewing commercial licenses" . PHP_EOL;
echo "  run-subscription-actions [limit]  Run queued commercial subscription provider actions" . PHP_EOL;
echo "  dispatch-webhook-dead-letter-alerts [limit]  Dispatch webhook dead letter alerts" . PHP_EOL;
echo "  export-billing-summary [market-id] [json|csv]  Export paid payment tax summary" . PHP_EOL;
echo "  export-commercial-audits [event-type] [subject-key]  Export commercial audit logs as CSV" . PHP_EOL;
echo "  purge-expired-portal-tokens  Delete expired customer portal tokens" . PHP_EOL;
echo "  purge-commercial-audits  Delete commercial audits outside retention policy" . PHP_EOL;
echo "  commercial-audit-policy  Show current commercial audit retention policy" . PHP_EOL;
echo "  notify-commercial-audit-risk [window-hours] [Low|Medium|High] [cooldown-minutes]  Queue commercial audit risk notifications" . PHP_EOL;
echo "  commercial-audit-risk-notifications [limit]  Show commercial audit risk notification status" . PHP_EOL;
echo "  commercial-audit-risk-notification-incidents [limit]  Show failed/retry commercial audit risk notification incidents" . PHP_EOL;
echo "  export-commercial-audit-risk-notification-incidents [limit]  Export failed/retry commercial audit risk notification incidents as CSV" . PHP_EOL;
echo "  commercial-audit-risk-notification-incident-exports [limit]  Show recent incident CSV export audit events" . PHP_EOL;
echo "  export-commercial-audit-risk-notification-incident-exports [limit]  Export incident CSV export audit events as CSV" . PHP_EOL;
echo "  export-commercial-audit-risk-notification-incident-export-actors  Export incident export actor summary as CSV" . PHP_EOL;
echo "  commercial-audit-risk-notification-incident-export-actor-exports [limit] [actor] [since] [until] [offset]  Show actor summary CSV export audit events" . PHP_EOL;
echo "  export-commercial-audit-risk-notification-incident-export-actor-exports [limit] [actor] [since] [until] [offset]  Export actor summary CSV export audit events as CSV" . PHP_EOL;
echo "  retry-commercial-audit-risk-notifications  Retry failed commercial audit risk notifications" . PHP_EOL;
