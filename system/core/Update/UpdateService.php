<?php

declare(strict_types=1);

namespace Cms\Core\Update;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Recovery\IntegrityChecker;
use Cms\Core\Recovery\RecoveryActions;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

final class UpdateService
{
    private const LOCK_FILE = '/storage/updates/core-update.lock';
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

        try {
            $this->recordOperation($pdo, $operationId, $adminId, $manifest, $zipPath, 'Running', 'preflight', $plan);
            $this->preflightChecks($manifest, $settings, true);
            $this->enterMaintenance($operationId);
            $this->recordStep($pdo, $operationId, 'restore_points');
            $restorePoints = $this->createRestorePoints($operationId, $pdo);
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
            $this->pruneOldReleases(2);
            $this->exitMaintenance();
            (new AuditLogger($pdo))->record('admin', $adminId, 'core.updated', [
                'operation_id' => $operationId,
                'release_id' => $manifest->releaseId,
                'from_version' => $this->currentVersion,
                'to_version' => $manifest->toVersion,
            ]);

            return ['status' => 'Completed', 'operation_id' => $operationId, 'release_id' => $manifest->releaseId, 'health' => $health];
        } catch (Throwable $exception) {
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
        $state = $this->lockState();
        if ($state === null) {
            if (!$this->pointerValid()) {
                (new RecoveryActions($this->rootPath))->enableRecoveryMode();
                return ['status' => 'RecoveryMode', 'reason' => 'current pointer is missing or invalid'];
            }
            return ['status' => 'NoInterruptedUpdate'];
        }
        if (in_array((string) ($state['step'] ?? ''), ['package_uploaded', 'unpacked', 'restore_points', 'prepare_release'], true)) {
            return ['status' => 'ManualRecoveryRequired', 'reason' => 'stale update lock requires administrator review', 'operation_id' => $state['operation_id'] ?? ''];
        }
        (new RecoveryActions($this->rootPath))->enableRecoveryMode();
        return ['status' => 'RecoveryMode', 'reason' => 'interrupted during dangerous update step', 'operation_id' => $state['operation_id'] ?? ''];
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
        $integrity = (new IntegrityChecker())->check($this->rootPath);
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
            throw new UpdateException('Release directory already exists.');
        }
        $this->copyDirectory($this->rootPath . '/system/core', $releaseDir . '/system/core');
        $this->copyDirectory($this->rootPath . '/system/migrations', $releaseDir . '/system/migrations');
        if (is_file($this->rootPath . '/system/core-manifest.json')) {
            $this->ensureDir($releaseDir . '/system');
            copy($this->rootPath . '/system/core-manifest.json', $releaseDir . '/system/core-manifest.json');
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

    private function preflightRelease(string $releaseDir): void
    {
        foreach (['system/core/Bootstrap/autoload.php', 'system/core/Bootstrap/Application.php'] as $file) {
            if (!is_file($releaseDir . '/' . $file)) {
                throw new UpdateException('Prepared release is missing required Core file.');
            }
        }
        foreach (glob($releaseDir . '/system/core/**/*.php', GLOB_BRACE) ?: [] as $file) {
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
                $up($pdo);
                $this->finishCoreMigration($pdo, $recordId, 'applied');
            } catch (Throwable $exception) {
                $this->failCoreMigration($pdo, $recordId, $this->sanitizeError($exception));
                $this->restoreDatabase($restorePoints);
                throw $exception;
            }
        }
    }

    /** @return array<string,mixed> */
    private function postSwitchHealth(UpdatePackageManifest $manifest, PDO $pdo): array
    {
        $started = time();
        $checks = [
            'pointer' => $this->pointerValid(),
            'database' => (bool) $pdo->query('SELECT 1')->fetchColumn(),
            'version' => $this->readPointer()['version'] ?? '',
            'migrations' => !$this->hasUnresolvedMigrationFailures($pdo),
            'admin_login' => is_file($this->rootPath . '/system/core/Admin/AdminController.php'),
            'recovery' => is_file($this->rootPath . '/system/core/Recovery/RecoveryController.php'),
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
        (new RecoveryActions($this->rootPath))->enableRecoveryMode();
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
            $oldHealth = ['database' => (bool) $pdo->query('SELECT 1')->fetchColumn(), 'pointer' => $this->pointerValid() || $previousPointer === []];
            if (!$oldHealth['database'] || !$oldHealth['pointer']) {
                throw new UpdateException('Old Core health check failed after rollback.');
            }
            $this->exitMaintenance();
            (new RecoveryActions($this->rootPath))->disableRecoveryMode();
            (new AtomicUpdateState($this->rootPath))->markRollback($operationId, $reason);
            return ['status' => 'RolledBack', 'health' => $oldHealth];
        } catch (Throwable $exception) {
            (new RecoveryActions($this->rootPath))->enableRecoveryMode();
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
            $zip->addFile($this->rootPath . '/system/core-manifest.json', 'system/core-manifest.json');
        }
        $zip->close();
        if (!is_file($coreZip) || filesize($coreZip) <= 0) {
            throw new UpdateException('Core restore point is empty.');
        }
        $db = $this->createDatabaseRestorePoint($dir, $pdo);
        $pointer = $dir . '/current-release.json';
        file_put_contents($pointer, json_encode($this->readPointer(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $migrationState = $dir . '/migration-state.json';
        file_put_contents($migrationState, json_encode($this->fetchAll($pdo, 'SELECT * FROM cms_core_migrations ORDER BY id'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $configSnapshot = $dir . '/app-config.php.snapshot';
        copy($this->rootPath . '/config/app.php', $configSnapshot);
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
                $zip->addFile($source, $relative);
                $manifest[$relative] = ['exists' => true, 'sha256' => hash_file('sha256', $source)];
            } else {
                $manifest[$relative] = ['exists' => false, 'sha256' => ''];
            }
        }
        $zip->addFromString('operational-support-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

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
        if (!copy($dbFile, $target) || filesize($target) <= 0) {
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
        if (!copy($source, $target)) {
            throw new UpdateException('Database restore failed.');
        }
    }

    /** @param array<string,mixed> $restorePoints */
    private function restoreOperationalSupportFiles(array $restorePoints): void
    {
        $restore = $restorePoints['operational_support'] ?? [];
        $path = (string) ($restore['path'] ?? '');
        if ($path === '' || !is_file($path) || hash_file('sha256', $path) !== (string) ($restore['sha256'] ?? '')) {
            return;
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
                @unlink($target);
                continue;
            }
            $content = $zip->getFromName($relative);
            if (!is_string($content) || hash('sha256', $content) !== (string) ($meta['sha256'] ?? '')) {
                $zip->close();
                throw new UpdateException('Operational support restore hash mismatch.');
            }
            $this->ensureDir(dirname($target));
            file_put_contents($target, $content, LOCK_EX);
            @chmod($target, str_ends_with($relative, '.php') ? 0755 : 0644);
        }
        $zip->close();
    }

    private function acquireLock(string $operationId, int $adminId, UpdatePackageManifest $manifest): string
    {
        $lock = $this->rootPath . self::LOCK_FILE;
        $this->ensureDir(dirname($lock));
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw new UpdateException('Another Core update is already running or needs manual recovery.');
        }
        fwrite($handle, json_encode([
            'operation_id' => $operationId,
            'admin_id' => $adminId,
            'target_version' => $manifest->toVersion,
            'release_id' => $manifest->releaseId,
            'step' => 'package_uploaded',
            'started_at' => gmdate('c'),
            'heartbeat_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($lock, 0600);
        fclose($handle);
        return $lock;
    }

    private function releaseLock(string $lock): void
    {
        if (is_file($lock)) {
            unlink($lock);
        }
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
        @chmod($file, 0600);
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
        $pointer = $this->readPointer();
        if ($pointer === []) {
            return true;
        }
        $path = (string) ($pointer['path'] ?? '');
        return $path !== '' && is_file($path . '/system/core/Bootstrap/autoload.php');
    }

    private function releaseDir(string $releaseId): string
    {
        return $this->rootPath . '/storage/updates/releases/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $releaseId);
    }

    private function pruneOldReleases(int $keep): void
    {
        $dirs = array_values(array_filter(glob($this->rootPath . '/storage/updates/releases/*') ?: [], 'is_dir'));
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($dirs, max(0, $keep)) as $dir) {
            $this->removeDirectory($dir);
        }
    }

    private function phpLint(string $file): void
    {
        if (!function_exists('proc_open')) {
            return;
        }

        $this->runPreflightProcess([PHP_BINARY, '-l', $file], 'PHP syntax preflight failed.');
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
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
        $pdo->prepare('UPDATE cms_core_update_operations SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE operation_id = :operation_id')->execute($params);
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

        return [
            'driver' => 'mysql',
            'path' => $target,
            'sha256' => hash_file('sha256', $target),
            'database' => $info['database'],
            'host' => $info['host'],
            'port' => $info['port'],
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
                $zip->addFile((string) $file->getPathname(), $prefix . '/' . substr((string) $file->getPathname(), strlen($source) + 1));
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
