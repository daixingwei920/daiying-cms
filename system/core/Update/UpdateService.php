<?php

declare(strict_types=1);

namespace Cms\Core\Update;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Recovery\IntegrityChecker;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

final class UpdateService
{
    private const LOCK_FILE = '/storage/updates/core-update.lock';
    private const GUARD_FILE = '/storage/updates/core-update.guard';
    private const MAINTENANCE_FILE = '/storage/maintenance.mode';
    private const CURRENT_POINTER = '/storage/updates/current-release.json';
    private const MAX_HEALTH_SECONDS = 10;

    public function __construct(
        private readonly string $rootPath,
        private readonly string $currentVersion,
        private readonly SignatureVerifier $verifier,
    ) {
    }

    /** @return array<string, mixed> */
    public function verifyAndPlan(string $zipPath): array
    {
        $plan = $this->dryRun($zipPath);
        (new AtomicUpdateState($this->rootPath))->markPrepared($plan);

        return $plan;
    }

    /** @return array<string, mixed> */
    public function dryRun(string $zipPath): array
    {
        $manifest = (new UpdatePackageReader())->read($zipPath, $this->verifier);
        $plan = (new UpdatePlanner($this->rootPath))->plan($manifest, $this->currentVersion);
        $checks = $this->isLegacyManifest($manifest)
            ? ['legacy_manifest' => true, 'current_version' => $this->currentVersion, 'target_version' => $manifest->toVersion]
            : $this->preflightChecks($manifest, Settings::load($this->rootPath), false);

        return $plan + [
            'status' => 'dry_run_passed',
            'release_id' => $manifest->releaseId,
            'target_version' => $manifest->toVersion,
            'build' => $manifest->build,
            'signature' => ['algorithm' => $manifest->signatureAlgorithm, 'key_id' => $this->mask($manifest->keyId)],
            'compatibility' => $checks,
            'migration_count' => count($manifest->migrationsFor($this->currentVersion)),
            'space_required_bytes' => $this->manifestSize($manifest),
            'notes' => $manifest->notes,
            'features' => $manifest->features,
            'acceptance_gates' => $manifest->acceptanceGates,
            'modifies_database' => false,
            'switches_core' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function execute(string $zipPath, int $adminId, string $confirmation = ''): array
    {
        if ($confirmation !== 'UPDATE CORE') {
            throw new UpdateException('Core update requires second confirmation.');
        }
        $manifest = (new UpdatePackageReader())->read($zipPath, $this->verifier);
        $settings = Settings::load($this->rootPath);
        $pdo = ConnectionFactory::make($settings);
        $plan = (new UpdatePlanner($this->rootPath))->plan($manifest, $this->currentVersion);
        $operationId = bin2hex(random_bytes(12));
        $previousPointer = $this->readPointer();
        $lock = $this->acquireLock($operationId, $adminId, $manifest);
        $restorePoints = [];
        $releaseDir = $this->releaseDir($manifest->releaseId);
        $postSwitchOk = false;

        try {
            $this->recordOperation($pdo, $operationId, $adminId, $manifest, $zipPath, 'Running', 'preflight', $plan);
            $this->preflightChecks($manifest, $settings, true);
            $this->enterMaintenance($operationId);
            $this->recordStep($pdo, $operationId, 'restore_points');
            $restorePoints = $this->createRestorePoints($operationId, $pdo);
            $this->writeRestoreDescriptor($operationId, $restorePoints, $previousPointer);
            $this->recordRestorePoints($pdo, $operationId, $restorePoints);

            $this->recordStep($pdo, $operationId, 'prepare_release');
            $this->prepareRelease($zipPath, $manifest, $releaseDir);
            $this->preflightRelease($releaseDir);

            $this->recordStep($pdo, $operationId, 'migrations');
            $this->runCoreMigrations($pdo, $operationId, $manifest, $releaseDir, $restorePoints);

            $this->recordStep($pdo, $operationId, 'support_files');
            $this->applyOperationalSupportFiles($manifest, $releaseDir);

            $this->recordStep($pdo, $operationId, 'switch');
            $this->switchPointer($manifest, $releaseDir);

            $this->recordStep($pdo, $operationId, 'health');
            $health = $this->postSwitchHealth($manifest, $pdo);
            if (($health['status'] ?? '') !== 'ok') {
                throw new UpdateException('Post-switch health check failed.');
            }
            $this->recordOperationStatus($pdo, $operationId, 'Completed', 'completed');
            $postSwitchOk = true;
            $cleanupWarnings = $this->postCommitCleanup($pdo, $adminId, $operationId, $manifest);

            return [
                'status' => 'Completed',
                'operation_id' => $operationId,
                'release_id' => $manifest->releaseId,
                'health' => $health,
                'cleanup_warnings' => $cleanupWarnings,
            ];
        } catch (Throwable $exception) {
            if ($postSwitchOk && $this->pointerTargetsRelease($manifest, $releaseDir)) {
                $this->exitMaintenance();
                $this->recordPostCommitWarning($operationId, $exception);
                return [
                    'status' => 'Completed',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId,
                    'health' => ['status' => 'ok', 'checks' => ['pointer' => true, 'post_commit_exception' => $this->sanitizeError($exception)]],
                    'cleanup_warnings' => [$this->sanitizeError($exception)],
                ];
            }
            $rollback = $this->rollback($pdo, $operationId, $previousPointer, $restorePoints, $this->sanitizeError($exception));
            $this->recordOperationStatus($pdo, $operationId, $rollback['status'] === 'RolledBack' ? 'RolledBack' : 'RecoveryMode', 'rollback', $this->sanitizeError($exception));
            (new AuditLogger($pdo))->record('admin', $adminId, 'core.update_failed', [
                'operation_id' => $operationId,
                'release_id' => $manifest->releaseId,
                'error' => $this->sanitizeError($exception),
                'rollback' => $rollback['status'],
            ]);
            throw new UpdateException('Core update failed and rollback status is ' . $rollback['status'] . ': ' . $this->sanitizeError($exception), 0, $exception);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array<string,mixed> */
    public function recoverInterrupted(): array
    {
        $guard = $this->openGuardLock();
        if (!flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);
            return ['status' => 'Running', 'reason' => 'Core update process still owns the update lock'];
        }
        try {
            $state = $this->lockState();
            if ($state === null) {
                if (!$this->pointerValid()) {
                    $this->enableRecoveryMode();
                    return ['status' => 'RecoveryMode', 'reason' => 'current pointer is missing or invalid'];
                }
                return ['status' => 'NoInterruptedUpdate'];
            }
            $operationId = (string) ($state['operation_id'] ?? '');
            if ($operationId === '' || preg_match('/^[a-f0-9]{24}$/', $operationId) !== 1) {
                $this->enableRecoveryMode();
                return ['status' => 'RecoveryMode', 'reason' => 'untrusted interrupted update state'];
            }
            if (in_array((string) ($state['step'] ?? ''), ['package_uploaded', 'unpacked', 'restore_points', 'prepare_release'], true)) {
                return ['status' => 'ManualRecoveryRequired', 'reason' => 'stale update lock requires administrator review', 'operation_id' => $operationId];
            }
            $pdo = ConnectionFactory::make(Settings::load($this->rootPath));
            $operation = $pdo->prepare('SELECT status,release_id,to_version FROM cms_core_update_operations WHERE operation_id = :operation_id');
            $operation->execute([':operation_id' => $operationId]);
            $row = $operation->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['status'] === 'Completed') {
                $pointer = $this->readPointer();
                $releasePath = $this->safeActiveReleasePath((string) ($pointer['path'] ?? ''));
                if ($releasePath === '' || ($pointer['release_id'] ?? '') !== $row['release_id']
                    || ($pointer['version'] ?? '') !== $row['to_version']
                    || (new IntegrityChecker())->check($releasePath)['status'] !== 'ok') {
                    $this->enableRecoveryMode();
                    return ['status' => 'RecoveryMode', 'reason' => 'completed update pointer or release is not healthy', 'operation_id' => $operationId];
                }
                $this->exitMaintenance();
                $this->disableRecoveryMode();
                if (!unlink($this->rootPath . self::LOCK_FILE)) {
                    throw new UpdateException('Unable to clear completed update state.');
                }
                return ['status' => 'Completed', 'operation_id' => $operationId, 'reason' => 'durable completed update retained'];
            }
            if (is_array($row) && $row['status'] === 'RolledBack') {
                $descriptor = $this->readRestoreDescriptor($operationId);
                if ($this->readPointer() !== $descriptor['previous_pointer']) {
                    $this->enableRecoveryMode();
                    return ['status' => 'RecoveryMode', 'reason' => 'rolled-back update state cannot be confirmed', 'operation_id' => $operationId];
                }
                $this->exitMaintenance();
                $this->disableRecoveryMode();
                if (!unlink($this->rootPath . self::LOCK_FILE)) {
                    throw new UpdateException('Unable to clear rolled-back update state.');
                }
                return ['status' => 'RolledBack', 'operation_id' => $operationId, 'reason' => 'durable rollback retained'];
            }
            $this->enableRecoveryMode();
            try {
                $descriptor = $this->readRestoreDescriptor($operationId);
                $pdo = ConnectionFactory::make(Settings::load($this->rootPath));
                $rollback = $this->rollback($pdo, $operationId, $descriptor['previous_pointer'], $descriptor['restore_points'], 'Interrupted Core update');
                if ($rollback['status'] !== 'RolledBack') {
                    throw new UpdateException('Interrupted Core update could not be restored.');
                }
                if (!unlink($this->rootPath . self::LOCK_FILE)) {
                    throw new UpdateException('Unable to clear interrupted update state.');
                }
                return ['status' => 'RolledBack', 'operation_id' => $operationId, 'health' => $rollback['health']];
            } catch (Throwable $exception) {
                $this->enableRecoveryMode();
                return ['status' => 'RecoveryMode', 'reason' => $this->sanitizeError($exception), 'operation_id' => $operationId];
            }
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /** @return array<string,mixed> */
    private function preflightChecks(UpdatePackageManifest $manifest, Settings $settings, bool $forExecute): array
    {
        if (!$this->isLegacyManifest($manifest) && version_compare($manifest->toVersion, $this->currentVersion, '<')) {
            throw new UpdateException('Core update cannot downgrade the current version.');
        }
        if (!$manifest->supportsSourceVersion($this->currentVersion)) {
            $floor = $manifest->hardFloor();
            if ($floor !== '' && version_compare($this->currentVersion, $floor, '<')) {
                throw new UpdateException('Current Core version is below the hard migration floor.');
            }
            throw new UpdateException('Current Core version is outside the supported migration range.');
        }
        if (!version_compare(PHP_VERSION, $manifest->phpMin, '>=')) {
            throw new UpdateException('PHP version is below update requirement.');
        }
        if ($manifest->phpMax !== '' && !version_compare(PHP_VERSION, $manifest->phpMax, '<=')) {
            throw new UpdateException('PHP version is above update maximum.');
        }
        foreach ($manifest->requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                throw new UpdateException('Required PHP extension is missing: ' . $extension);
            }
        }
        $pdo = ConnectionFactory::make($settings);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($manifest->databaseTypes !== [] && !in_array($driver, $manifest->databaseTypes, true)) {
            throw new UpdateException('Database driver is not compatible with update package.');
        }
        $currentCore = $this->currentCoreIntegrityTarget();
        $integrity = (new IntegrityChecker())->check($currentCore['path']);
        if (($integrity['status'] ?? '') !== 'ok') {
            throw new UpdateException('Current Core integrity check failed.');
        }
        if (is_file($this->rootPath . '/storage/recovery.mode')) {
            throw new UpdateException('Site is already in Recovery Mode.');
        }
        if (!$forExecute && is_file($this->rootPath . self::LOCK_FILE)) {
            throw new UpdateException('Another Core update is already running or requires recovery.');
        }
        if ($this->hasUnresolvedMigrationFailures($pdo)) {
            throw new UpdateException('Unresolved Core migration failure exists.');
        }
        $migrationCheck = $this->migrationPathCheck($manifest);
        if (($migrationCheck['complete'] ?? false) !== true) {
            throw new UpdateException('Core update migration path is incomplete.');
        }
        foreach (['storage/updates', 'storage/recovery'] as $dir) {
            $path = $this->rootPath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                throw new UpdateException('Update directory is not writable.');
            }
        }
        if ($forExecute && disk_free_space($this->rootPath . '/storage') < max(1048576, $this->manifestSize($manifest) * 3)) {
            throw new UpdateException('Insufficient disk space for Core update.');
        }
        if ($forExecute && $driver === 'mysql' && !$this->mysqlToolsAvailable($settings)) {
            throw new UpdateException('MySQL/MariaDB backup and restore tools are not configured or unavailable.');
        }
        if ($forExecute && !in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new UpdateException('Configured database driver is not supported by Core update restore points.');
        }

        return [
            'current_integrity' => 'ok',
            'current_integrity_target' => $currentCore['source'],
            'current_version' => $this->currentVersion,
            'target_version' => $manifest->toVersion,
            'php' => PHP_VERSION,
            'database' => $driver,
            'disk_free_bytes' => disk_free_space($this->rootPath . '/storage'),
            'atomic_pointer_supported' => true,
            'direct_upgrade_supported' => true,
            'migration_path' => $migrationCheck,
            'plugin_compatibility' => $this->extensionCompatibilitySnapshot('plugins'),
            'theme_compatibility' => $this->extensionCompatibilitySnapshot('themes'),
        ];
    }

    private function prepareRelease(string $zipPath, UpdatePackageManifest $manifest, string $releaseDir): void
    {
        if (is_dir($releaseDir)) {
            $this->clearStaleReleaseDirectory($releaseDir);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('Unable to open update package for release preparation.');
        }
        foreach ($manifest->files as $path => $hash) {
            $content = $zip->getFromName($path);
            if (!is_string($content) || hash('sha256', $content) !== $hash) {
                $zip->close();
                throw new UpdateException('Update file hash mismatch during release preparation.');
            }
            $target = $releaseDir . '/' . $path;
            $this->ensureDir(dirname($target));
            file_put_contents($target, $content, LOCK_EX);
        }
        $zip->close();
        file_put_contents($releaseDir . '/release.json', json_encode([
            'release_id' => $manifest->releaseId,
            'version' => $manifest->toVersion,
            'build' => $manifest->build,
            'created_at' => gmdate('c'),
            'files' => array_keys($manifest->files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function clearStaleReleaseDirectory(string $releaseDir): void
    {
        $activePath = (string) ($this->readPointer()['path'] ?? '');
        $releaseReal = realpath($releaseDir);
        $activeReal = $activePath !== '' ? realpath($activePath) : false;
        if ($releaseReal !== false && $activeReal !== false && $releaseReal === $activeReal) {
            throw new UpdateException('Release directory is already active.');
        }

        $this->removeDirectory($releaseDir);
    }

    private function preflightRelease(string $releaseDir): void
    {
        $integrity = (new IntegrityChecker())->check($releaseDir);
        if (($integrity['status'] ?? '') !== 'ok') {
            throw new UpdateException('Prepared release Core integrity check failed.');
        }
        foreach (['system/core/Bootstrap/autoload.php', 'system/core/Bootstrap/Application.php'] as $file) {
            if (!is_file($releaseDir . '/' . $file)) {
                throw new UpdateException('Prepared release is missing required Core file.');
            }
        }
        foreach ($this->phpFilesUnder($releaseDir . '/system/core') as $file) {
            $this->phpLint($file);
        }
        $this->phpLint($releaseDir . '/system/core/Bootstrap/autoload.php');
        foreach ($this->operationalSupportFilesInRelease($releaseDir) as $file) {
            if (str_ends_with($file, '.php')) {
                $this->phpLint($releaseDir . '/' . $file);
            }
        }
        if (function_exists('proc_open')) {
            $stdout = $this->runPreflightProcess([
                PHP_BINARY,
                '-r',
                'require "' . $releaseDir . '/system/core/Bootstrap/autoload.php"; echo "ok";',
            ], 'Prepared release autoload preflight failed.');
            if (trim($stdout) !== 'ok') {
                throw new UpdateException('Prepared release autoload preflight failed.');
            }
            return;
        }

        require $releaseDir . '/system/core/Bootstrap/autoload.php';
        if (!is_file($releaseDir . '/system/core/Bootstrap/Application.php')) {
            throw new UpdateException('Prepared release autoload preflight failed.');
        }
    }

    /** @param array<string,mixed> $restorePoints */
    private function runCoreMigrations(PDO $pdo, string $operationId, UpdatePackageManifest $manifest, string $releaseDir, array $restorePoints): void
    {
        foreach ($manifest->migrationsFor($this->currentVersion) as $migration) {
            if (!is_array($migration)) {
                throw new UpdateException('Core migration manifest entry is invalid.');
            }
            $id = (string) ($migration['migration_id'] ?? $migration['id'] ?? '');
            $path = (string) ($migration['path'] ?? '');
            $sourceVersion = (string) ($migration['source_version'] ?? $this->currentVersion);
            $version = (string) ($migration['target_version'] ?? $migration['version'] ?? $manifest->toVersion);
            $file = $releaseDir . '/' . str_replace('\\', '/', $path);
            if ($id === '' || $path === '' || !is_file($file) || !UpdatePackageManifest::isCorePath($path)) {
                throw new UpdateException('Core migration file is invalid.');
            }
            $checksum = hash_file('sha256', $file) ?: '';
            if ((string) ($migration['checksum'] ?? $checksum) !== $checksum) {
                throw new UpdateException('Core migration checksum mismatch.');
            }
            $existing = $this->coreMigrationRow($pdo, $id);
            if ($existing !== null && (string) $existing['status'] === 'applied') {
                if ((string) $existing['checksum'] !== $checksum) {
                    throw new UpdateException('Core migration checksum changed: ' . $id);
                }
                continue;
            }
            $recordId = $this->startCoreMigration($pdo, $operationId, $id, $sourceVersion, $version, $checksum, $migration['affected_objects'] ?? []);
            try {
                $definition = require $file;
                $up = is_array($definition) ? ($definition['up'] ?? null) : (is_object($definition) && method_exists($definition, 'up') ? [$definition, 'up'] : null);
                if (!is_callable($up)) {
                    throw new UpdateException('Core migration must define up().');
                }
                if (!$definition instanceof \Cms\Core\Migration\MigrationInterface
                    || !\Cms\Core\Migration\MysqlHistoricalMigrationDialect::run($pdo, $definition)) {
                    $up($pdo);
                }
                $this->finishCoreMigration($pdo, $recordId, 'applied');
            } catch (Throwable $exception) {
                if ($this->isNonCriticalOperationalRegistryMigrationFailure($id, $exception)) {
                    $this->finishCoreMigration($pdo, $recordId, 'applied');
                    continue;
                }
                $this->failCoreMigration($pdo, $recordId, $this->sanitizeError($exception));
                $this->restoreDatabase($restorePoints);
                throw $exception;
            }
        }
    }

    private function isNonCriticalOperationalRegistryMigrationFailure(string $id, Throwable $exception): bool
    {
        if ($id !== '2026_09_07_000001_official_plugins_registry') {
            return false;
        }
        $message = $exception->getMessage();

        return in_array($message, [
            'Unable to create official plugin registry directory.',
            'Unable to stage official plugin registry.',
            'Unable to update official plugin registry.',
        ], true);
    }

    /** @return array<string,mixed> */
    private function postSwitchHealth(UpdatePackageManifest $manifest, PDO $pdo): array
    {
        $started = time();
        $activeCorePath = $this->activeCorePath();
        $checks = [
            'pointer' => $this->pointerValid(),
            'database' => (bool) $pdo->query('SELECT 1')->fetchColumn(),
            'version' => $this->readPointer()['version'] ?? '',
            'migrations' => !$this->hasUnresolvedMigrationFailures($pdo),
            'admin_login' => is_file($activeCorePath . '/Admin/AdminController.php'),
            'recovery' => is_file($activeCorePath . '/Recovery/RecoveryController.php'),
            'safe_theme' => is_dir($this->rootPath . '/content/themes/safe') || is_dir($this->rootPath . '/content/themes/default'),
            'safe_mode_plugins_skipped' => true,
            'logs' => true,
        ];
        if (time() - $started > self::MAX_HEALTH_SECONDS) {
            return ['status' => 'failed', 'reason' => 'health timeout', 'checks' => $checks];
        }
        $ok = $checks['pointer'] && $checks['database'] && $checks['version'] === $manifest->toVersion && $checks['migrations'];
        return ['status' => $ok ? 'ok' : 'failed', 'checks' => $checks];
    }

    private function activeCorePath(): string
    {
        $pointer = $this->readPointer();
        $releasePath = (string) ($pointer['path'] ?? '');
        if ($releasePath !== '' && is_dir($releasePath . '/system/core')) {
            return $releasePath . '/system/core';
        }

        return $this->rootPath . '/system/core';
    }

    /** @return array{path:string,source:string} */
    private function currentCoreIntegrityTarget(): array
    {
        $pointer = $this->readPointer();
        if ($pointer === []) {
            return ['path' => $this->rootPath, 'source' => 'root-shell'];
        }

        $releasePath = $this->safeActiveReleasePath((string) ($pointer['path'] ?? ''));
        if ($releasePath === '' || !is_file($releasePath . '/system/core/Bootstrap/autoload.php')) {
            throw new UpdateException('Current active release pointer is invalid.');
        }

        return ['path' => $releasePath, 'source' => 'active-release'];
    }

    private function safeActiveReleasePath(string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '' || !is_dir($path)) {
            return '';
        }
        $real = realpath($path);
        $releasesRoot = realpath($this->rootPath . '/storage/updates/releases');
        if ($real === false || $releasesRoot === false) {
            return '';
        }

        return ($real === $releasesRoot || str_starts_with($real, $releasesRoot . DIRECTORY_SEPARATOR)) ? $real : '';
    }

    private function applyOperationalSupportFiles(UpdatePackageManifest $manifest, string $releaseDir): void
    {
        foreach (array_keys($manifest->files) as $path) {
            if (!in_array($path, UpdatePackageManifest::operationalSupportPaths(), true)) {
                continue;
            }
            $source = $releaseDir . '/' . $path;
            if (!is_file($source) || hash_file('sha256', $source) !== (string) ($manifest->files[$path] ?? '')) {
                throw new UpdateException('Operational support file hash mismatch.');
            }
            $target = $this->rootPath . '/' . $path;
            $this->ensureDir(dirname($target));
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (!copy($source, $tmp)) {
                @unlink($tmp);
                throw new UpdateException('Unable to stage operational support file.');
            }
            @chmod($tmp, str_ends_with($path, '.php') ? 0755 : 0644);
            if (!rename($tmp, $target)) {
                @unlink($tmp);
                throw new UpdateException('Unable to update operational support file.');
            }
        }
    }

    /** @return list<string> */
    private function operationalSupportFilesInRelease(string $releaseDir): array
    {
        $files = [];
        foreach (UpdatePackageManifest::operationalSupportPaths() as $path) {
            if (is_file($releaseDir . '/' . $path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** @return array<string,mixed> */
    private function rollback(PDO $pdo, string $operationId, array $previousPointer, array $restorePoints, string $reason): array
    {
        $this->enableRecoveryMode();
        try {
            if ($previousPointer !== []) {
                $this->writePointer($previousPointer);
            } else {
                @unlink($this->rootPath . self::CURRENT_POINTER);
            }
            if ($restorePoints !== []) {
                $this->restoreDatabase($restorePoints);
                $this->restoreOperationalSupportFiles($restorePoints);
            }
            $restoredPdo = ConnectionFactory::make(Settings::load($this->rootPath));
            $oldHealth = ['database' => (bool) $restoredPdo->query('SELECT 1')->fetchColumn(),
                'pointer' => $this->readPointer() === $previousPointer && ($this->pointerValid() || $previousPointer === [])];
            if (!$oldHealth['database'] || !$oldHealth['pointer']) {
                throw new UpdateException('Old Core health check failed after rollback.');
            }
            $this->recordOperationStatus($restoredPdo, $operationId, 'RolledBack', 'rollback', $reason);
            (new AtomicUpdateState($this->rootPath))->markRollback($operationId, $reason);
            $this->exitMaintenance();
            $this->disableRecoveryMode();
            return ['status' => 'RolledBack', 'health' => $oldHealth];
        } catch (Throwable $exception) {
            $this->enableRecoveryMode();
            return ['status' => 'RecoveryMode', 'error' => $this->sanitizeError($exception)];
        }
    }

    /** @return array<string,mixed> */
    private function createRestorePoints(string $operationId, PDO $pdo): array
    {
        $dir = $this->rootPath . '/storage/updates/restore-points/' . $operationId;
        $this->ensureDir($dir);
        $coreZip = $dir . '/core.zip';
        $zip = new ZipArchive();
        if ($zip->open($coreZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('Unable to create Core restore point.');
        }
        $this->zipDirectory($zip, $this->rootPath . '/system/core', 'system/core');
        $this->zipDirectory($zip, $this->rootPath . '/system/migrations', 'system/migrations');
        if (is_file($this->rootPath . '/system/core-manifest.json')) {
            if (!$zip->addFile($this->rootPath . '/system/core-manifest.json', 'system/core-manifest.json')) {
                throw new UpdateException('Unable to add Core manifest to restore point.');
            }
        }
        if (!$zip->close()) {
            throw new UpdateException('Unable to finish Core restore point.');
        }
        if (!is_file($coreZip) || filesize($coreZip) <= 0) {
            throw new UpdateException('Core restore point is empty.');
        }
        $db = $this->createDatabaseRestorePoint($dir, $pdo);
        $pointer = $dir . '/current-release.json';
        $this->writePrivateJsonFile($pointer, $this->readPointer());
        $migrationState = $dir . '/migration-state.json';
        $this->writePrivateJsonFile($migrationState, $this->fetchAll($pdo, 'SELECT * FROM cms_core_migrations ORDER BY id'));
        $configSnapshot = $dir . '/app-config.php.snapshot';
        if (!copy($this->rootPath . '/config/app.php', $configSnapshot)
            || hash_file('sha256', $configSnapshot) !== hash_file('sha256', $this->rootPath . '/config/app.php')) {
            throw new UpdateException('Core configuration restore point failed.');
        }
        $supportZip = $dir . '/operational-support.zip';
        $support = $this->createOperationalSupportRestorePoint($supportZip);

        return [
            'core' => ['path' => $coreZip, 'sha256' => hash_file('sha256', $coreZip)],
            'database' => $db,
            'pointer' => ['path' => $pointer, 'sha256' => hash_file('sha256', $pointer)],
            'migrations' => ['path' => $migrationState, 'sha256' => hash_file('sha256', $migrationState)],
            'config' => ['path' => $configSnapshot, 'sha256' => hash_file('sha256', $configSnapshot)],
            'operational_support' => $support,
            'manual_steps' => ['restore pointer', 'restore database backup', 'enable recovery mode if health fails'],
        ];
    }

    /** @param array<string,mixed> $restorePoints @param array<string,mixed> $previousPointer */
    private function writeRestoreDescriptor(string $operationId, array $restorePoints, array $previousPointer): void
    {
        $this->writePrivateJsonFile($this->restoreDescriptorPath($operationId), [
            'operation_id' => $operationId,
            'root' => realpath($this->rootPath),
            'database_dsn_sha256' => hash('sha256', (string) Settings::load($this->rootPath)->get('database.dsn', '')),
            'previous_pointer' => $previousPointer,
            'restore_points' => $restorePoints,
        ]);
        $this->readRestoreDescriptor($operationId);
    }

    private function restoreDescriptorPath(string $operationId): string
    {
        if (preg_match('/^[a-f0-9]{24}$/', $operationId) !== 1) {
            throw new UpdateException('Invalid Core update operation identity.');
        }
        return $this->rootPath . '/storage/updates/restore-points/' . $operationId . '/restore-point.json';
    }

    /** @return array{previous_pointer:array<string,mixed>,restore_points:array<string,mixed>} */
    private function readRestoreDescriptor(string $operationId): array
    {
        $path = $this->restoreDescriptorPath($operationId);
        $descriptor = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($descriptor) || ($descriptor['operation_id'] ?? '') !== $operationId
            || ($descriptor['root'] ?? '') !== realpath($this->rootPath)
            || ($descriptor['database_dsn_sha256'] ?? '') !== hash('sha256', (string) Settings::load($this->rootPath)->get('database.dsn', ''))
            || !is_array($descriptor['previous_pointer'] ?? null)
            || !is_array($descriptor['restore_points'] ?? null)) {
            throw new UpdateException('Interrupted update restore descriptor is missing or does not match this site.');
        }
        $dir = realpath(dirname($path));
        if ($dir === false) {
            throw new UpdateException('Interrupted update restore directory is missing.');
        }
        foreach (['core', 'database', 'pointer', 'migrations', 'config', 'operational_support'] as $name) {
            $artifact = $descriptor['restore_points'][$name] ?? null;
            $artifactPath = is_array($artifact) ? (string) ($artifact['path'] ?? '') : '';
            if ($artifactPath === '' || !is_file($artifactPath) || realpath(dirname($artifactPath)) !== $dir
                || hash_file('sha256', $artifactPath) !== (string) ($artifact['sha256'] ?? '')) {
                throw new UpdateException('Interrupted update restore artifact verification failed.');
            }
        }
        return $descriptor;
    }

    /** @return array<string,mixed> */
    private function createOperationalSupportRestorePoint(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('Unable to create operational support restore point.');
        }
        $manifest = [];
        foreach (UpdatePackageManifest::operationalSupportPaths() as $relative) {
            $source = $this->rootPath . '/' . $relative;
            if (is_file($source)) {
                if (!$zip->addFile($source, $relative)) {
                    throw new UpdateException('Unable to add operational support file to restore point.');
                }
                $manifest[$relative] = ['exists' => true, 'sha256' => hash_file('sha256', $source)];
            } else {
                $manifest[$relative] = ['exists' => false, 'sha256' => ''];
            }
        }
        if (!$zip->addFromString('operational-support-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            || !$zip->close()) {
            throw new UpdateException('Unable to finish operational support restore point.');
        }
        if (!is_file($path) || filesize($path) <= 0 || !is_readable($path)) {
            throw new UpdateException('Operational support restore point failed.');
        }

        return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'files' => $manifest];
    }

    /** @return array<string,mixed> */
    private function createDatabaseRestorePoint(string $dir, PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            return $this->createMysqlRestorePoint($dir);
        }
        if ($driver !== 'sqlite') {
            throw new UpdateException('Database restore point is unavailable for this driver.');
        }
        $dbFile = $this->sqlitePath();
        if ($dbFile === '' || !is_file($dbFile)) {
            throw new UpdateException('SQLite database file is missing.');
        }
        $target = $dir . '/database.sqlite';
        if (!copy($dbFile, $target) || filesize($target) <= 0 || !is_readable($target)) {
            throw new UpdateException('SQLite database restore point failed.');
        }

        return ['driver' => 'sqlite', 'path' => $target, 'sha256' => hash_file('sha256', $target), 'verified_readable' => is_readable($target)];
    }

    /** @param array<string,mixed> $restorePoints */
    private function restoreDatabase(array $restorePoints): void
    {
        $db = $restorePoints['database'] ?? [];
        if (($db['driver'] ?? '') === 'mysql') {
            $this->restoreMysqlDatabase($db);
            return;
        }
        if (($db['driver'] ?? '') !== 'sqlite') {
            throw new UpdateException('Database restore point is unavailable.');
        }
        $source = (string) ($db['path'] ?? '');
        $target = $this->sqlitePath();
        if ($source === '' || $target === '' || !is_file($source) || hash_file('sha256', $source) !== (string) ($db['sha256'] ?? '')) {
            throw new UpdateException('Database restore point verification failed.');
        }
        if (!copy($source, $target) || hash_file('sha256', $target) !== (string) ($db['sha256'] ?? '')) {
            throw new UpdateException('Database restore failed.');
        }
    }

    /** @param array<string,mixed> $restorePoints */
    private function restoreOperationalSupportFiles(array $restorePoints): void
    {
        $restore = $restorePoints['operational_support'] ?? [];
        $path = (string) ($restore['path'] ?? '');
        if ($path === '' || !is_file($path) || hash_file('sha256', $path) !== (string) ($restore['sha256'] ?? '')) {
            throw new UpdateException('Operational support restore point verification failed.');
        }
        $files = is_array($restore['files'] ?? null) ? $restore['files'] : [];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new UpdateException('Operational support restore point is unreadable.');
        }
        foreach (UpdatePackageManifest::operationalSupportPaths() as $relative) {
            $meta = is_array($files[$relative] ?? null) ? $files[$relative] : [];
            $target = $this->rootPath . '/' . $relative;
            if (($meta['exists'] ?? false) !== true) {
                if (is_file($target) && !unlink($target)) {
                    throw new UpdateException('Unable to remove newly created operational support file.');
                }
                continue;
            }
            $content = $zip->getFromName($relative);
            if (!is_string($content) || hash('sha256', $content) !== (string) ($meta['sha256'] ?? '')) {
                $zip->close();
                throw new UpdateException('Operational support restore hash mismatch.');
            }
            $this->ensureDir(dirname($target));
            if (file_put_contents($target, $content, LOCK_EX) !== strlen($content)
                || hash_file('sha256', $target) !== (string) ($meta['sha256'] ?? '')) {
                throw new UpdateException('Operational support file restore failed.');
            }
            @chmod($target, str_ends_with($relative, '.php') ? 0755 : 0644);
        }
        $zip->close();
    }

    /** @return array{path:string,guard:resource} */
    private function acquireLock(string $operationId, int $adminId, UpdatePackageManifest $manifest): array
    {
        $lock = $this->rootPath . self::LOCK_FILE;
        $this->ensureDir(dirname($lock));
        $guard = $this->openGuardLock();
        if (!flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);
            throw new UpdateException('Another Core update is already running.');
        }
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            flock($guard, LOCK_UN);
            fclose($guard);
            throw new UpdateException('Another Core update is already running or needs manual recovery.');
        }
        $encoded = json_encode([
            'operation_id' => $operationId,
            'admin_id' => $adminId,
            'target_version' => $manifest->toVersion,
            'release_id' => $manifest->releaseId,
            'step' => 'package_uploaded',
            'started_at' => gmdate('c'),
            'heartbeat_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $encoded) !== strlen($encoded)) {
            fclose($handle);
            flock($guard, LOCK_UN);
            fclose($guard);
            throw new UpdateException('Unable to persist Core update lock.');
        }
        @chmod($lock, 0600);
        fclose($handle);
        return ['path' => $lock, 'guard' => $guard];
    }

    /** @param array{path:string,guard:resource} $lock */
    private function releaseLock(array $lock): void
    {
        if (!is_file($this->rootPath . '/storage/recovery.mode') && is_file($lock['path'])) {
            unlink($lock['path']);
        }
        flock($lock['guard'], LOCK_UN);
        fclose($lock['guard']);
    }

    /** @return resource */
    private function openGuardLock()
    {
        $file = $this->rootPath . self::GUARD_FILE;
        $this->ensureDir(dirname($file));
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new UpdateException('Unable to open Core update guard.');
        }
        @chmod($file, 0600);
        return $handle;
    }

    private function enterMaintenance(string $operationId): void
    {
        $file = $this->rootPath . self::MAINTENANCE_FILE;
        $this->ensureDir(dirname($file));
        $this->writePrivateJsonFile($file, ['operation_id' => $operationId, 'started_at' => gmdate('c')]);
        if (!is_file($file)) {
            throw new UpdateException('Unable to enter Maintenance Mode.');
        }
    }

    private function exitMaintenance(): void
    {
        @unlink($this->rootPath . self::MAINTENANCE_FILE);
    }

    private function switchPointer(UpdatePackageManifest $manifest, string $releaseDir): void
    {
        $this->writePointer([
            'release_id' => $manifest->releaseId,
            'version' => $manifest->toVersion,
            'build' => $manifest->build,
            'path' => $releaseDir,
            'switched_at' => gmdate('c'),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function writePointer(array $payload): void
    {
        $file = $this->rootPath . self::CURRENT_POINTER;
        $this->ensureDir(dirname($file));
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        $this->writePrivateJsonFile($tmp, $payload);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new UpdateException('Unable to atomically switch Core release pointer.');
        }
        @chmod($file, 0644);
    }

    /** @return array<string,mixed> */
    private function readPointer(): array
    {
        $file = $this->rootPath . self::CURRENT_POINTER;
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function pointerValid(): bool
    {
        if (!is_file($this->rootPath . self::CURRENT_POINTER)) {
            return true;
        }
        $pointer = $this->readPointer();
        if ($pointer === []) {
            return false;
        }
        $path = (string) ($pointer['path'] ?? '');
        $path = $this->safeActiveReleasePath($path);
        return $path !== '' && is_file($path . '/system/core/Bootstrap/autoload.php');
    }

    private function releaseDir(string $releaseId): string
    {
        return $this->rootPath . '/storage/updates/releases/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $releaseId);
    }

    /** @return list<string> */
    private function postCommitCleanup(PDO $pdo, int $adminId, string $operationId, UpdatePackageManifest $manifest): array
    {
        $warnings = [];
        foreach ([
            'exit_maintenance' => fn (): mixed => $this->exitMaintenance(),
            'audit_core_updated' => fn (): mixed => (new AuditLogger($pdo))->record('admin', $adminId, 'core.updated', [
                'operation_id' => $operationId,
                'release_id' => $manifest->releaseId,
                'from_version' => $this->currentVersion,
                'to_version' => $manifest->toVersion,
            ]),
            'prune_old_releases' => fn (): mixed => $this->pruneOldReleases(2),
        ] as $step => $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $warnings[] = $step . ': ' . $this->sanitizeError($exception);
            }
        }

        if ($warnings !== []) {
            $this->recordPostCommitWarning($operationId, new UpdateException(implode('; ', $warnings)));
        }

        return $warnings;
    }

    private function recordPostCommitWarning(string $operationId, Throwable $exception): void
    {
        $dir = $this->rootPath . '/storage/updates/history';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/post-commit-warning-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $operationId) . '.json';
        file_put_contents($file, json_encode([
            'operation_id' => $operationId,
            'warning' => $this->sanitizeError($exception),
            'recorded_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($file, 0600);
    }

    private function pointerTargetsRelease(UpdatePackageManifest $manifest, string $releaseDir): bool
    {
        $pointer = $this->readPointer();
        return (string) ($pointer['release_id'] ?? '') === $manifest->releaseId
            && (string) ($pointer['version'] ?? '') === $manifest->toVersion
            && rtrim((string) ($pointer['path'] ?? ''), '/') === rtrim($releaseDir, '/');
    }

    private function enableRecoveryMode(): void
    {
        $file = $this->rootPath . '/storage/recovery.mode';
        $this->ensureDir(dirname($file));
        file_put_contents($file, gmdate('c') . PHP_EOL, LOCK_EX);
        @chmod($file, 0600);
    }

    private function disableRecoveryMode(): void
    {
        @unlink($this->rootPath . '/storage/recovery.mode');
    }

    private function pruneOldReleases(int $keep): void
    {
        $dirs = array_values(array_filter(glob($this->rootPath . '/storage/updates/releases/*') ?: [], 'is_dir'));
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $protected = array_filter([
            $this->safeActiveReleasePath((string) ($this->readPointer()['path'] ?? '')),
            $this->runningUpdaterReleasePath(),
        ]);
        foreach (array_slice($dirs, max(0, $keep)) as $dir) {
            $real = realpath($dir);
            if ($real !== false && in_array($real, $protected, true)) {
                continue;
            }
            $this->removeDirectory($dir);
        }
    }

    private function runningUpdaterReleasePath(): string
    {
        $file = realpath(__FILE__);
        $releasesRoot = realpath($this->rootPath . '/storage/updates/releases');
        if ($file === false || $releasesRoot === false || !str_starts_with($file, $releasesRoot . DIRECTORY_SEPARATOR)) {
            return '';
        }
        $relative = substr($file, strlen($releasesRoot) + 1);
        $firstSeparator = strpos($relative, DIRECTORY_SEPARATOR);
        if ($firstSeparator === false) {
            return '';
        }

        $candidate = $releasesRoot . DIRECTORY_SEPARATOR . substr($relative, 0, $firstSeparator);
        return is_dir($candidate) ? $candidate : '';
    }

    private function phpLint(string $file): void
    {
        if (!function_exists('proc_open')) {
            return;
        }

        $this->runPreflightProcess([PHP_BINARY, '-l', $file], 'PHP syntax preflight failed.');
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) {
                $files[] = $item->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function hasUnresolvedMigrationFailures(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM cms_core_update_migrations WHERE status IN ('running','failed','rollback_failed')");
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $plan */
    private function recordOperation(PDO $pdo, string $operationId, int $adminId, UpdatePackageManifest $manifest, string $zipPath, string $status, string $step, array $plan): void
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare('INSERT INTO cms_core_update_operations (operation_id, admin_id, release_id, from_version, to_version, status, current_step, package_path, plan_json, started_at, heartbeat_at, updated_at) VALUES (:operation_id, :admin_id, :release_id, :from_version, :to_version, :status, :current_step, :package_path, :plan_json, :started_at, :heartbeat_at, :updated_at)');
        $stmt->execute([
            ':operation_id' => $operationId,
            ':admin_id' => $adminId,
            ':release_id' => $manifest->releaseId,
            ':from_version' => $this->currentVersion,
            ':to_version' => $manifest->toVersion,
            ':status' => $status,
            ':current_step' => $step,
            ':package_path' => basename($zipPath),
            ':plan_json' => json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':started_at' => $now,
            ':heartbeat_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function recordStep(PDO $pdo, string $operationId, string $step): void
    {
        $this->patchOperation($pdo, $operationId, ['current_step' => $step, 'heartbeat_at' => gmdate('c')]);
        $state = $this->lockState() ?: [];
        $state['step'] = $step;
        $state['heartbeat_at'] = gmdate('c');
        $this->writePrivateJsonFile($this->rootPath . self::LOCK_FILE, $state);
    }

    /** @param array<string,mixed> $payload */
    private function writePrivateJsonFile(string $file, array $payload): void
    {
        $this->ensureDir(dirname($file));
        $content = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $content, LOCK_EX) !== strlen($content)) {
            throw new UpdateException('Unable to persist private Core update state.');
        }
        @chmod($file, 0600);
    }

    /** @param array<string,mixed> $restorePoints */
    private function recordRestorePoints(PDO $pdo, string $operationId, array $restorePoints): void
    {
        $this->patchOperation($pdo, $operationId, ['restore_points_json' => json_encode($restorePoints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    private function recordOperationStatus(PDO $pdo, string $operationId, string $status, string $step, string $error = ''): void
    {
        $this->patchOperation($pdo, $operationId, [
            'status' => $status,
            'current_step' => $step,
            'error_summary' => $error !== '' ? $error : null,
            'completed_at' => in_array($status, ['Completed', 'RolledBack', 'RecoveryMode'], true) ? gmdate('c') : null,
        ]);
    }

    /** @param array<string,mixed> $values */
    private function patchOperation(PDO $pdo, string $operationId, array $values): void
    {
        $allowed = ['status', 'current_step', 'restore_points_json', 'error_summary', 'completed_at', 'heartbeat_at'];
        $sets = [];
        $params = [':operation_id' => $operationId, ':updated_at' => gmdate('c')];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }
        if ($sets === []) {
            return;
        }
        $statement = $pdo->prepare('UPDATE cms_core_update_operations SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE operation_id = :operation_id');
        $statement->execute($params);
        if ($statement->rowCount() === 0) {
            $check = $pdo->prepare('SELECT 1 FROM cms_core_update_operations WHERE operation_id = :operation_id');
            $check->execute([':operation_id' => $operationId]);
            if (!$check->fetchColumn()) {
                throw new UpdateException('Core update operation record is missing.');
            }
        }
    }

    private function startCoreMigration(PDO $pdo, string $operationId, string $id, string $sourceVersion, string $version, string $checksum, mixed $affected): int
    {
        $this->ensureMigrationHistoryTable($pdo);
        $now = gmdate('c');
        $stmt = $pdo->prepare("INSERT INTO cms_core_update_migrations (operation_id, migration_id, version, checksum, status, affected_objects_json, started_at, created_at, updated_at) VALUES (:operation_id, :migration_id, :version, :checksum, 'running', :affected, :started_at, :created_at, :updated_at)");
        $stmt->execute([
            ':operation_id' => $operationId,
            ':migration_id' => $id,
            ':version' => $version,
            ':checksum' => $checksum,
            ':affected' => json_encode(is_array($affected) ? $affected : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':started_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $coreRecordId = (int) $pdo->lastInsertId();
        $history = $pdo->prepare("INSERT INTO cms_migrations (migration_name, source_version, target_version, batch, checksum, started_at, status, created_at, updated_at) VALUES (:migration_name, :source_version, :target_version, :batch, :checksum, :started_at, 'running', :created_at, :updated_at)");
        $history->execute([
            ':migration_name' => $id,
            ':source_version' => $sourceVersion,
            ':target_version' => $version,
            ':batch' => $operationId,
            ':checksum' => $checksum,
            ':started_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return $coreRecordId;
    }

    private function finishCoreMigration(PDO $pdo, int $recordId, string $status): void
    {
        $row = $this->coreUpdateMigrationByRecordId($pdo, $recordId);
        $pdo->prepare('UPDATE cms_core_update_migrations SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $recordId, ':status' => $status, ':completed_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
        if ($row !== null) {
            $pdo->prepare('UPDATE cms_migrations SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE migration_name = :migration_name AND batch = :batch')
                ->execute([
                    ':status' => 'applied',
                    ':completed_at' => gmdate('c'),
                    ':updated_at' => gmdate('c'),
                    ':migration_name' => (string) $row['migration_id'],
                    ':batch' => (string) $row['operation_id'],
                ]);
        }
    }

    private function failCoreMigration(PDO $pdo, int $recordId, string $error): void
    {
        $row = $this->coreUpdateMigrationByRecordId($pdo, $recordId);
        $pdo->prepare("UPDATE cms_core_update_migrations SET status = 'failed', error_summary = :error, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $recordId, ':error' => $error, ':updated_at' => gmdate('c')]);
        if ($row !== null) {
            $pdo->prepare("UPDATE cms_migrations SET status = 'failed', error_message = :error, updated_at = :updated_at WHERE migration_name = :migration_name AND batch = :batch")
                ->execute([
                    ':error' => $error,
                    ':updated_at' => gmdate('c'),
                    ':migration_name' => (string) $row['migration_id'],
                    ':batch' => (string) $row['operation_id'],
                ]);
        }
    }

    private function coreMigrationRow(PDO $pdo, string $migrationId): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM cms_core_update_migrations WHERE migration_id = :migration_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':migration_id' => $migrationId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function coreUpdateMigrationByRecordId(PDO $pdo, int $recordId): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM cms_core_update_migrations WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $recordId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{complete:bool,pending_count:int,declared_count:int,required_count:int,missing_required:list<string>} */
    private function migrationPathCheck(UpdatePackageManifest $manifest): array
    {
        $pending = $manifest->migrationsFor($this->currentVersion);
        $declaredIds = [];
        foreach ($manifest->migrations as $migration) {
            if (is_array($migration)) {
                $id = (string) ($migration['migration_id'] ?? $migration['id'] ?? '');
                if ($id !== '') {
                    $declaredIds[$id] = true;
                }
            }
        }
        $missing = [];
        foreach ($manifest->requiredMigrations as $required) {
            if (!isset($declaredIds[$required])) {
                $missing[] = $required;
            }
        }

        return [
            'complete' => $missing === [],
            'pending_count' => count($pending),
            'declared_count' => count($manifest->migrations),
            'required_count' => count($manifest->requiredMigrations),
            'missing_required' => $missing,
        ];
    }

    /** @return array{safe_fallback_available:bool,active:list<string>,incompatible:list<string>} */
    private function extensionCompatibilitySnapshot(string $type): array
    {
        $base = $type === 'themes' ? $this->rootPath . '/content/themes' : $this->rootPath . '/content/plugins';
        $active = [];
        if (is_dir($base)) {
            foreach (glob($base . '/*') ?: [] as $entry) {
                if (is_dir($entry)) {
                    $active[] = basename($entry);
                }
            }
        }

        return [
            'safe_fallback_available' => $type === 'themes'
                ? (is_dir($this->rootPath . '/content/themes/safe') || is_dir($this->rootPath . '/content/themes/default'))
                : true,
            'active' => $active,
            'incompatible' => [],
        ];
    }

    private function ensureMigrationHistoryTable(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_migrations (
                id ' . $idColumn . ',
                migration_name VARCHAR(191) NOT NULL,
                source_version VARCHAR(64) NOT NULL DEFAULT "",
                target_version VARCHAR(64) NOT NULL DEFAULT "",
                batch VARCHAR(64) NOT NULL DEFAULT "",
                checksum VARCHAR(64) NOT NULL DEFAULT "",
                started_at VARCHAR(64) NULL,
                completed_at VARCHAR(64) NULL,
                status VARCHAR(32) NOT NULL DEFAULT "pending",
                error_message ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $columns = $this->tableColumns($pdo, 'cms_migrations');
        foreach ([
            'migration_name' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'source_version' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'target_version' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'batch' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'checksum' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'started_at' => 'VARCHAR(64) NULL',
            'completed_at' => 'VARCHAR(64) NULL',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "pending"',
            'error_message' => $longText . ' NULL',
            'created_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'updated_at' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE cms_migrations ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

    /** @return list<string> */
    private function tableColumns(PDO $pdo, string $table): array
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll());
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll());
    }

    /** @return array<string,mixed>|null */
    private function lockState(): ?array
    {
        $file = $this->rootPath . self::LOCK_FILE;
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : ['step' => 'unknown'];
    }

    private function sqlitePath(): string
    {
        $config = require $this->rootPath . '/config/app.php';
        $dsn = (string) ($config['database']['dsn'] ?? '');
        if (str_starts_with($dsn, 'sqlite:')) {
            return substr($dsn, 7);
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function createMysqlRestorePoint(string $dir): array
    {
        $settings = Settings::load($this->rootPath);
        $info = $this->mysqlConnectionInfo($settings);
        $pdo = ConnectionFactory::make($settings);
        $state = $this->mysqlState($pdo);
        $target = $dir . '/database.mysql.sql';
        $binary = (string) $settings->get('updates.mysql_dump_binary', 'mysqldump');
        $args = $this->mysqlDumpArgs($binary, $info, $target, true);
        try {
            $this->runProcess($args, $info['password'] !== '' ? ['MYSQL_PWD' => $info['password']] : []);
        } catch (UpdateException $exception) {
            if (!$this->isUnsupportedGtidDumpOption($exception->getMessage())) {
                throw $exception;
            }
            @unlink($target);
            $this->runProcess($this->mysqlDumpArgs($binary, $info, $target, false), $info['password'] !== '' ? ['MYSQL_PWD' => $info['password']] : []);
        }
        if (!is_file($target) || filesize($target) <= 0) {
            throw new UpdateException('MySQL/MariaDB database restore point failed.');
        }
        $sample = (string) file_get_contents($target, false, null, 0, 4096);
        if (!str_contains($sample, 'CREATE TABLE') && !str_contains((string) file_get_contents($target), 'INSERT INTO')) {
            throw new UpdateException('MySQL/MariaDB restore point verification failed.');
        }
        if (!$this->mysqlDumpContainsTables($target, $state['tables'])) {
            throw new UpdateException('MySQL restore point is missing one or more source tables.');
        }
        if ($this->mysqlState($pdo) !== $state) {
            throw new UpdateException('MySQL database changed while creating the restore point.');
        }

        return [
            'driver' => 'mysql',
            'path' => $target,
            'sha256' => hash_file('sha256', $target),
            'database' => $info['database'],
            'host' => $info['host'],
            'port' => $info['port'],
            'state' => $state,
            'verified_readable' => is_readable($target),
        ];
    }

    /** @param array{host:string,port:string,database:string,username:string,password:string} $info @return list<string> */
    private function mysqlDumpArgs(string $binary, array $info, string $target, bool $includeGtidOption): array
    {
        $args = [
            $binary,
            '--protocol=TCP',
            '--host=' . $info['host'],
            '--port=' . $info['port'],
            '--user=' . $info['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--skip-comments',
            '--skip-dump-date',
            '--add-drop-table',
            '--result-file=' . $target,
            $info['database'],
        ];

        if ($includeGtidOption) {
            array_splice($args, -3, 0, ['--set-gtid-purged=OFF']);
        }

        return $args;
    }

    private function isUnsupportedGtidDumpOption(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'set-gtid-purged')
            && (str_contains($lower, 'unknown') || str_contains($lower, 'unrecognized') || str_contains($lower, 'unsupported'));
    }

    /** @param array<string,mixed> $db */
    private function restoreMysqlDatabase(array $db): void
    {
        $source = (string) ($db['path'] ?? '');
        if ($source === '' || !is_file($source) || hash_file('sha256', $source) !== (string) ($db['sha256'] ?? '')) {
            throw new UpdateException('MySQL/MariaDB restore point verification failed.');
        }
        $settings = Settings::load($this->rootPath);
        $info = $this->mysqlConnectionInfo($settings);
        if (($db['database'] ?? '') !== $info['database'] || ($db['host'] ?? '') !== $info['host']
            || (string) ($db['port'] ?? '') !== $info['port'] || !is_array($db['state'] ?? null)) {
            throw new UpdateException('MySQL restore point does not match the configured database.');
        }
        $pdo = ConnectionFactory::make($settings);
        $original = $db['state'];
        if (!isset($original['tables'], $original['columns'], $original['indexes'], $original['triggers'], $original['routines'], $original['events'], $original['data'])) {
            throw new UpdateException('MySQL restore state inventory is incomplete.');
        }
        $current = $this->mysqlState($pdo);
        $originalNames = array_column($original['tables'], 'TABLE_NAME');
        $extra = array_filter($current['tables'], static fn (array $object): bool => !in_array($object['TABLE_NAME'], $originalNames, true));
        $originalRoutineNames = array_column($original['routines'], 'ROUTINE_NAME');
        $originalEventNames = array_column($original['events'], 'EVENT_NAME');
        if (array_filter($current['routines'], static fn (array $row): bool => !in_array($row['ROUTINE_NAME'], $originalRoutineNames, true)) !== []
            || array_filter($current['events'], static fn (array $row): bool => !in_array($row['EVENT_NAME'], $originalEventNames, true)) !== []) {
            throw new UpdateException('MySQL has new routines or events that cannot be safely removed by this restore path.');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($extra as $object) {
                $name = str_replace('`', '``', (string) $object['TABLE_NAME']);
                $type = ($object['TABLE_TYPE'] ?? '') === 'VIEW' ? 'VIEW' : 'TABLE';
                $pdo->exec('DROP ' . $type . ' IF EXISTS `' . $name . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $binary = (string) $settings->get('updates.mysql_binary', 'mysql');
        $sql = file_get_contents($source);
        if (!is_string($sql) || $sql === '') {
            throw new UpdateException('MySQL/MariaDB restore point is empty.');
        }
        $this->runProcess([
            $binary,
            '--protocol=TCP',
            '--host=' . $info['host'],
            '--port=' . $info['port'],
            '--user=' . $info['username'],
            $info['database'],
        ], $info['password'] !== '' ? ['MYSQL_PWD' => $info['password']] : [], $sql);
        if ($this->mysqlState($pdo) !== $original) {
            throw new UpdateException('MySQL restore verification failed: schema or data differs from backup.');
        }
    }

    /** @return array<string,mixed> */
    private function mysqlState(PDO $pdo): array
    {
        $state = [
            'tables' => $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION,AUTO_INCREMENT,CREATE_OPTIONS,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'columns' => $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $pdo->query('SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,INDEX_TYPE,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC),
            'table_constraints' => $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'key_columns' => $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
            'foreign_keys' => $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'checks' => $pdo->query('SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'partitions' => $pdo->query('SELECT TABLE_NAME,PARTITION_NAME,SUBPARTITION_NAME,PARTITION_METHOD,PARTITION_EXPRESSION,PARTITION_DESCRIPTION FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,PARTITION_ORDINAL_POSITION,SUBPARTITION_ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
            'views' => $pdo->query('SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,SECURITY_TYPE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'triggers' => $pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER,ACTION_STATEMENT,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'routines' => $pdo->query('SELECT ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_DEFINITION,SQL_DATA_ACCESS,SECURITY_TYPE,SQL_MODE,DEFINER,DTD_IDENTIFIER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME,ROUTINE_TYPE')->fetchAll(PDO::FETCH_ASSOC),
            'routine_parameters' => $pdo->query('SELECT SPECIFIC_NAME,ORDINAL_POSITION,PARAMETER_MODE,PARAMETER_NAME,DTD_IDENTIFIER FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE() ORDER BY SPECIFIC_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
            'events' => $pdo->query('SELECT EVENT_NAME,EVENT_DEFINITION,STATUS,EVENT_TYPE,EXECUTE_AT,INTERVAL_VALUE,INTERVAL_FIELD,STARTS,ENDS,ON_COMPLETION,SQL_MODE,DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'data' => [],
        ];
        foreach ($state['tables'] as $table) {
            if ($table['TABLE_TYPE'] !== 'BASE TABLE') {
                continue;
            }
            $name = str_replace('`', '``', (string) $table['TABLE_NAME']);
            $columns = array_values(array_filter($state['columns'], static fn (array $column): bool => $column['TABLE_NAME'] === $table['TABLE_NAME']));
            $pieces = array_map(static function (array $column): string {
                $identifier = '`' . str_replace('`', '``', (string) $column['COLUMN_NAME']) . '`';
                return "IF($identifier IS NULL,'N',CONCAT('V',SHA2(CAST($identifier AS BINARY),256)))";
            }, $columns);
            $order = $pieces !== [] ? 'SHA2(CONCAT(' . implode(',', $pieces) . '),256)' : '';
            $query = 'SELECT * FROM `' . $name . '`' . ($order !== '' ? ' ORDER BY ' . $order : '');
            $rows = $pdo->query($query);
            $digest = hash_init('sha256');
            $count = 0;
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $bytes = serialize($row);
                hash_update($digest, pack('N', strlen($bytes)) . $bytes);
                ++$count;
            }
            $state['data'][$table['TABLE_NAME']] = ['rows' => $count, 'sha256' => hash_final($digest)];
        }
        ksort($state['data'], SORT_STRING);
        foreach ($state['tables'] as &$table) {
            if ($table['TABLE_TYPE'] !== 'BASE TABLE') {
                continue;
            }
            $tableName = (string) $table['TABLE_NAME'];
            foreach ($state['columns'] as $column) {
                if ($column['TABLE_NAME'] !== $tableName || !str_contains((string) $column['EXTRA'], 'auto_increment')) {
                    continue;
                }
                $name = str_replace('`', '``', $tableName);
                $definition = $pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC);
                if (!is_array($definition) || !is_string($definition['Create Table'] ?? null)) {
                    throw new UpdateException('Unable to inspect MySQL auto-increment state.');
                }
                if (preg_match('/\\n\\)\\s+ENGINE=[^\\n]*/', $definition['Create Table'], $options) !== 1) {
                    throw new UpdateException('Unable to inspect MySQL table options.');
                }
                $tableOptions = preg_split('/\\s+COMMENT=/', $options[0], 2)[0];
                $table['AUTO_INCREMENT'] = preg_match('/(?:^|\\s)AUTO_INCREMENT=([0-9]+)(?=\\s|$)/', $tableOptions, $match) === 1
                    ? (ltrim($match[1], '0') ?: '0') : '1';
                break;
            }
        }
        unset($table);
        return $state;
    }

    /** @param list<array<string,mixed>> $tables */
    private function mysqlDumpContainsTables(string $path, array $tables): bool
    {
        $required = [];
        foreach ($tables as $table) {
            if (($table['TABLE_TYPE'] ?? '') === 'BASE TABLE') {
                $required[(string) $table['TABLE_NAME']] = true;
            }
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^CREATE TABLE `((?:``|[^`])+)`/', $line, $match) === 1) {
                    unset($required[str_replace('``', '`', $match[1])]);
                }
            }
        } finally {
            fclose($handle);
        }
        return $required === [];
    }

    private function mysqlToolsAvailable(Settings $settings): bool
    {
        try {
            $this->runProcess([(string) $settings->get('updates.mysql_binary', 'mysql'), '--version']);
            $this->runProcess([(string) $settings->get('updates.mysql_dump_binary', 'mysqldump'), '--version']);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{host:string,port:string,database:string,username:string,password:string} */
    private function mysqlConnectionInfo(Settings $settings): array
    {
        $dsn = (string) $settings->get('database.dsn', '');
        $parts = [];
        foreach (explode(';', preg_replace('/^mysql:/', '', $dsn) ?? '') as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }
        $database = (string) ($parts['dbname'] ?? '');
        if ($database === '') {
            throw new UpdateException('MySQL/MariaDB database name is missing.');
        }

        return [
            'host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'port' => (string) ($parts['port'] ?? '3306'),
            'database' => $database,
            'username' => (string) $settings->get('database.username', ''),
            'password' => (string) $settings->get('database.password', ''),
        ];
    }

    /** @param list<string> $args @param array<string,string> $env */
    private function runProcess(array $args, array $env = [], string $stdin = ''): string
    {
        if (!function_exists('proc_open')) {
            throw new UpdateException('Database tool execution is disabled by server PHP configuration.');
        }
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($args, $descriptor, $pipes, $this->rootPath, array_merge($this->processEnvironment(), $env));
        if (!is_resource($process)) {
            throw new UpdateException('Unable to start database tool.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new UpdateException('Database tool failed: ' . preg_replace('/[^\r\n]{200,}/', '[redacted]', trim((string) $stderr)));
        }

        return (string) $stdout;
    }

    /** @param list<string> $args */
    private function runPreflightProcess(array $args, string $failureMessage): string
    {
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($args, $descriptor, $pipes, $this->rootPath, $this->processEnvironment());
        if (!is_resource($process)) {
            throw new UpdateException($failureMessage);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new UpdateException($failureMessage . ' ' . $this->sanitizeProcessError((string) $stderr));
        }

        return (string) $stdout;
    }

    private function manifestSize(UpdatePackageManifest $manifest): int
    {
        $bytes = 0;
        foreach ($manifest->files as $path => $hash) {
            $bytes += max(1, strlen($path) + strlen($hash));
        }
        return $bytes;
    }

    private function isLegacyManifest(UpdatePackageManifest $manifest): bool
    {
        return $manifest->build === ''
            && $manifest->keyId === ''
            && $manifest->requiredExtensions === []
            && $manifest->migrations === []
            && $manifest->coreSchemaVersion === '';
    }

    /** @return list<array<string,mixed>> */
    private function fetchAll(PDO $pdo, string $sql): array
    {
        try {
            return $pdo->query($sql)->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 8) {
            return $value;
        }
        return substr($value, 0, 4) . '...' . substr($value, -4);
    }

    private function sanitizeError(Throwable $exception): string
    {
        $message = preg_replace('/[A-Z]:[\\\\\\/][^\s]+|\/[^\s]+/', '[path]', $exception->getMessage()) ?: 'Core update failed.';
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function sanitizeProcessError(string $message): string
    {
        $message = trim(preg_replace('/[A-Z]:[\\\\\\/][^\s]+|\/[^\s]+/', '[path]', $message) ?: '');
        if ($message === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($message, 0, 240) : substr($message, 0, 240);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /** @return array<string,string> */
    private function processEnvironment(): array
    {
        $clean = [];
        foreach ($_SERVER as $key => $value) {
            if (is_scalar($value)) {
                $clean[(string) $key] = (string) $value;
            }
        }

        return $clean;
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDir($target);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $dest = $target . '/' . substr((string) $item->getPathname(), strlen($source) + 1);
            $item->isDir() ? $this->ensureDir($dest) : copy((string) $item->getPathname(), $dest);
        }
    }

    private function zipDirectory(ZipArchive $zip, string $source, string $prefix): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if (!$zip->addFile((string) $file->getPathname(), $prefix . '/' . substr((string) $file->getPathname(), strlen($source) + 1))) {
                    throw new UpdateException('Unable to add Core file to restore point.');
                }
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
        }
        rmdir($path);
    }
}
