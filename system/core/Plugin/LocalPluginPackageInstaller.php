<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Audit\AuditLogger;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class LocalPluginPackageInstaller
{
    private const RESERVED_IDS = ['core', 'cms', 'admin', 'system', 'market', 'official', 'php_cms'];
    private const MAX_ZIP_BYTES = 10485760;
    private const MAX_EXTRACT_BYTES = 52428800;
    private const MAX_FILES = 500;
    private const MAX_DEPTH = 8;
    private const MIGRATION_STATUSES = ['pending', 'running', 'applied', 'rollback_running', 'rolled_back', 'failed_recoverable', 'rollback_failed'];

    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $pdo,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(string $zipPath, int $adminId): array
    {
        if (!is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP_BYTES) {
            throw new PluginException('Plugin ZIP is missing or exceeds size limit.');
        }
        $this->assertLocalUploadPackageShape($zipPath);
        $token = bin2hex(random_bytes(16));
        $staging = $this->rootPath . '/storage/plugin-installs/staging/' . $token;
        $this->ensureDir(dirname($staging));
        try {
            $this->safeExtract($zipPath, $staging);
            [$rootName, $manifest] = $this->readManifest($staging);
            $this->validateManifest($manifest, $staging . '/' . $rootName, false);
            $this->validateMigrations($staging . '/' . $rootName, $manifest);
            $scan = $this->scan($zipPath, $manifest);
            $status = $this->scanStatus($scan);
            $source = $this->isOfficialPackage($zipPath) ? 'official' : 'local_unreviewed';
            if ($source === 'official') {
                throw new PluginException('这是官方市场分发包，不能通过本地插件上传安装。请到“软件商店/官方市场”安装。');
            }
            $this->assertInstallable($manifest);
            $this->assertDependencies($manifest, (string) $manifest['plugin_id']);
            $stagingHash = $this->directoryFingerprint($staging);
        } catch (Throwable $exception) {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            throw $exception;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_plugin_install_previews
                (token, plugin_id, version, package_path, staging_dir, manifest_json, scan_json, source, created_at, expires_at)
             VALUES
                (:token, :plugin_id, :version, :package_path, :staging_dir, :manifest_json, :scan_json, :source, :created_at, :expires_at)'
        );
        $now = gmdate('c');
        $stmt->execute([
            ':token' => $token,
            ':plugin_id' => (string) $manifest['plugin_id'],
            ':version' => (string) $manifest['version'],
            ':package_path' => $zipPath,
            ':staging_dir' => $staging,
            ':manifest_json' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':scan_json' => json_encode(['status' => $status, 'findings' => $scan, 'staging_sha256' => $stagingHash], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':source' => $source,
            ':created_at' => $now,
            ':expires_at' => gmdate('c', time() + 900),
        ]);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.local_preview', ['plugin_id' => $manifest['plugin_id'], 'version' => $manifest['version'], 'scan' => $status]);
        $riskBoundary = PluginRiskBoundaryPolicy::describe($manifest, $source, 'unreviewed');

        return [
            'token' => $token,
            'plugin_id' => (string) $manifest['plugin_id'],
            'name' => (string) $manifest['name'],
            'version' => (string) $manifest['version'],
            'author' => (string) $manifest['author'],
            'capabilities' => $manifest['capabilities'] ?? [],
            'required_plugins' => $manifest['required_plugins'] ?? $manifest['dependencies'] ?? [],
            'optional_dependencies' => $manifest['optional_dependencies'] ?? [],
            'compatibility' => 'compatible',
            'scan' => ['status' => $status, 'findings' => $scan],
            'source' => $source,
            'risk_label' => '本地安装、未经官方市场审核',
            'risk_boundary' => $riskBoundary,
        ];
    }

    private function assertLocalUploadPackageShape(string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new PluginException('Plugin ZIP cannot be opened.');
        }

        $hasOfficialManifest = false;
        $hasProtectedPluginPath = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) ($zip->getNameIndex($i) ?: '');
            $name = str_replace('\\', '/', $name);
            if ($name === 'manifest.json' || $name === 'market-package.json' || $name === 'signature.json') {
                $hasOfficialManifest = true;
            }
            if (str_starts_with($name, 'content/plugins/')) {
                $hasProtectedPluginPath = true;
            }
        }
        $zip->close();

        if ($hasOfficialManifest) {
            throw new PluginException('这是官方市场/分发插件包，不能通过“插件管理 → 上传 ZIP”安装。请到“软件商店/官方市场”安装或使用官方更新安装流程。');
        }
        if ($hasProtectedPluginPath) {
            throw new PluginException('这个 ZIP 带有 content/plugins 路径，不能通过本地插件上传安装。本地插件包应为“插件ID/plugin.json”结构；官方插件请走软件商店安装。');
        }
    }

    /** @return array<string,mixed> */
    public function installBundled(string $pluginId, int $adminId = 0, bool $enable = false): array
    {
        $target = $this->rootPath . '/content/plugins/' . $pluginId;
        if (!is_dir($target)) {
            throw new PluginException('Bundled plugin directory is missing.');
        }
        $manifestFile = $target . '/plugin.json';
        $manifest = json_decode(is_file($manifestFile) ? (string) file_get_contents($manifestFile) : '', true);
        if (!is_array($manifest)) {
            throw new PluginException('Bundled plugin manifest is invalid.');
        }
        $official = new OfficialPluginRegistry($this->rootPath);
        if (!$official->isTrustedBundled((string) ($manifest['plugin_id'] ?? ''), $target)) {
            throw new PluginException('Bundled plugin is not in the official trusted source registry.');
        }
        $this->validateManifest($manifest, $target, true);
        $this->validateMigrations($target, $manifest, true);
        $existing = $this->pluginRow($pluginId);
        if ($existing !== null && !in_array((string) $existing['status'], [PluginLifecycle::INSTALLED, PluginLifecycle::DISABLED, PluginLifecycle::REMOVED, PluginLifecycle::DORMANT], true)) {
            throw new PluginException('Bundled plugin is already active or awaiting recovery.');
        }
        $this->assertDependencies($manifest, $pluginId);
        $lock = $this->lock();
        $this->acquireLock($lock);
        try {
            $this->registerPlugin($manifest, $enable, 'bundled_official', 'official_trusted', true);
            $this->runMigrations($target, $manifest, true);
            (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.bundled_installed', ['plugin_id' => $pluginId, 'enabled' => $enable]);

            return ['status' => 'Installed', 'plugin_id' => $pluginId, 'version' => (string) $manifest['version'], 'enabled' => $enable];
        } catch (Throwable $exception) {
            $recoverable = $this->hasRecoverableMigrationFailure($pluginId);
            if ($recoverable) {
                $this->setPluginRecoverable($pluginId, $this->sanitizeError($exception));
            } else {
                $this->pdo->prepare('DELETE FROM cms_plugins WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
            }
            throw $exception;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array<string,mixed> */
    public function install(string $token, int $adminId, bool $enable = false, bool $allowReview = false): array
    {
        $preview = $this->previewRow($token);
        if ($preview === null || strtotime((string) $preview['expires_at']) < time()) {
            throw new PluginException('Plugin install preview is expired.');
        }
        $scan = json_decode((string) $preview['scan_json'], true) ?: [];
        if (($scan['status'] ?? '') === 'blocked') {
            throw new PluginException('Plugin package is blocked by security scan.');
        }
        if (($scan['status'] ?? '') === 'needs_review' && !$allowReview) {
            throw new PluginException('Plugin package requires explicit administrator review confirmation.');
        }
        $manifest = json_decode((string) $preview['manifest_json'], true) ?: [];
        $pluginId = (string) $manifest['plugin_id'];
        $this->assertPreviewStagingUnchanged($preview, $manifest, $scan);
        $lock = $this->lock();
        $this->acquireLock($lock);
        $target = $this->rootPath . '/content/plugins/' . $pluginId;
        $source = (string) $preview['staging_dir'] . '/' . $pluginId;
        $moved = false;
        $hadDormantRow = $this->pluginRow($pluginId) !== null;
        try {
            if (is_dir($target)) {
                throw new PluginException('Plugin with same ID already exists. Use the upgrade flow.');
            }
            $this->assertDependencies($manifest, $pluginId);
            $this->ensureDir(dirname($target));
            if (!rename($source, $target)) {
                throw new PluginException('Unable to move plugin into place.');
            }
            $moved = true;
            $this->registerPlugin($manifest, $enable);
            $this->runMigrations($target, $manifest);
            $this->removeDirectory((string) $preview['staging_dir']);
            $this->pdo->prepare('DELETE FROM cms_plugin_install_previews WHERE token = :token')->execute([':token' => $token]);
            (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.local_installed', ['plugin_id' => $pluginId, 'enabled' => $enable]);

            return ['status' => 'Installed', 'plugin_id' => $pluginId, 'version' => (string) $manifest['version'], 'enabled' => $enable];
        } catch (Throwable $exception) {
            $recoverable = $this->hasRecoverableMigrationFailure($pluginId);
            if ($moved && is_dir($target) && !$recoverable) {
                $this->removeDirectory($target);
            }
            if ($recoverable) {
                $this->pdo->prepare('UPDATE cms_plugins SET status = :status, last_error = :last_error, updated_at = :updated_at WHERE plugin_id = :plugin_id')
                    ->execute([':plugin_id' => $pluginId, ':status' => PluginLifecycle::INSTALL_FAILED_RECOVERABLE, ':last_error' => $this->sanitizeError($exception), ':updated_at' => gmdate('c')]);
            } elseif (!$hadDormantRow) {
                $this->pdo->prepare('DELETE FROM cms_plugins WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
            } else {
                $this->pdo->prepare('UPDATE cms_plugins SET status = :status, last_error = :last_error, updated_at = :updated_at WHERE plugin_id = :plugin_id')
                    ->execute([':plugin_id' => $pluginId, ':status' => PluginLifecycle::DORMANT, ':last_error' => $this->sanitizeError($exception), ':updated_at' => gmdate('c')]);
            }
            throw $exception;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function disableWithDependents(string $pluginId, int $adminId, bool $confirm = false): array
    {
        $dependents = $this->enabledDependents($pluginId);
        if ($dependents !== [] && !$confirm) {
            throw new PluginException('Plugin is required by enabled plugins: ' . implode(', ', $dependents));
        }
        foreach ($dependents as $dependent) {
            $this->setPluginStatus($dependent, PluginLifecycle::DISABLED);
            $this->pauseTasks($dependent);
        }
        $this->setPluginStatus($pluginId, PluginLifecycle::DISABLED);
        $this->pauseTasks($pluginId);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.disabled', ['plugin_id' => $pluginId, 'dependents' => $dependents]);

        return ['plugin_id' => $pluginId, 'paused_dependents' => $dependents];
    }

    public function enable(string $pluginId, int $adminId): void
    {
        $row = $this->pluginRow($pluginId);
        if ($row === null) {
            throw new PluginException('Plugin is not installed.');
        }
        if (in_array((string) $row['status'], [PluginLifecycle::INSTALL_FAILED_RECOVERABLE, PluginLifecycle::QUARANTINED], true)) {
            throw new PluginException('Plugin cannot be enabled until recovery is completed.');
        }
        $deps = json_decode((string) ($row['dependencies_json'] ?? '[]'), true) ?: [];
        $this->assertDependencyRows($deps, $pluginId);
        $this->ensureBundledMigrationsBeforeEnable($pluginId, $row);
        $this->setPluginStatus($pluginId, PluginLifecycle::ENABLED);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.enabled', ['plugin_id' => $pluginId]);
    }

    public function uninstallCode(string $pluginId, int $adminId): void
    {
        $dir = $this->rootPath . '/content/plugins/' . $pluginId;
        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }
        $row = $this->pluginRow($pluginId);
        $dormant = [
            'settings' => $this->pluginData($pluginId),
            'blocks_preserved_as_missing_extension' => true,
            'removed_version' => (string) ($row['version'] ?? ''),
        ];
        $stmt = $this->pdo->prepare("UPDATE cms_plugins SET status = :status, dormant_data_json = :dormant, removed_at = :removed_at, updated_at = :updated_at WHERE plugin_id = :plugin_id");
        $stmt->execute([
            ':plugin_id' => $pluginId,
            ':status' => PluginLifecycle::DORMANT,
            ':dormant' => json_encode($dormant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':removed_at' => gmdate('c'),
            ':updated_at' => gmdate('c'),
        ]);
        $this->pauseTasks($pluginId);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.code_uninstalled', ['plugin_id' => $pluginId]);
    }

    public function purge(string $pluginId, int $adminId, string $confirmation): void
    {
        if ($confirmation !== 'PURGE ' . $pluginId) {
            throw new PluginException('Permanent purge requires second confirmation.');
        }
        $preview = $this->purgePreview($pluginId);
        $this->pdo->prepare('DELETE FROM cms_plugin_data WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
        $this->pdo->prepare('DELETE FROM cms_plugin_tasks WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
        $this->pdo->prepare('DELETE FROM cms_plugin_migrations WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
        if ($this->tableExists('cms_plugin_media_references')) {
            $this->pdo->prepare('DELETE FROM cms_plugin_media_references WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
        }
        $this->pdo->prepare('DELETE FROM cms_plugins WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.purged', ['plugin_id' => $pluginId, 'preview' => $preview, 'raw_blocks_retained' => true]);
    }

    /** @return array<string,mixed> */
    public function purgePreview(string $pluginId): array
    {
        $setting = $this->pdo->prepare("SELECT COUNT(*) FROM cms_plugin_data WHERE plugin_id = :plugin_id AND data_type = 'setting'");
        $setting->execute([':plugin_id' => $pluginId]);
        $data = $this->pdo->prepare('SELECT COUNT(*) FROM cms_plugin_data WHERE plugin_id = :plugin_id');
        $data->execute([':plugin_id' => $pluginId]);
        $tasks = $this->pdo->prepare('SELECT COUNT(*) FROM cms_plugin_tasks WHERE plugin_id = :plugin_id');
        $tasks->execute([':plugin_id' => $pluginId]);
        $mediaReferences = 0;
        if ($this->tableExists('cms_plugin_media_references')) {
            $media = $this->pdo->prepare('SELECT COUNT(*) FROM cms_plugin_media_references WHERE plugin_id = :plugin_id');
            $media->execute([':plugin_id' => $pluginId]);
            $mediaReferences = (int) $media->fetchColumn();
        }
        $migrations = $this->pdo->prepare('SELECT migration_id, status, affected_objects_json FROM cms_plugin_migrations WHERE plugin_id = :plugin_id ORDER BY id');
        $migrations->execute([':plugin_id' => $pluginId]);
        $migrationRows = $migrations->fetchAll();
        $tables = [];
        foreach ($migrationRows as $row) {
            foreach (json_decode((string) ($row['affected_objects_json'] ?? '[]'), true) ?: [] as $object) {
                if (is_string($object) && str_starts_with($object, 'table:')) {
                    $tables[] = substr($object, 6);
                }
            }
        }
        $contentCount = 0;
        if ($this->tableExists('cms_contents')) {
            $content = $this->pdo->prepare('SELECT COUNT(*) FROM cms_contents WHERE blocks_json LIKE :needle');
            $content->execute([':needle' => '%"plugin_id":"' . $pluginId . '"%']);
            $contentCount = (int) $content->fetchColumn();
        }

        return [
            'plugin_id' => $pluginId,
            'recommend_export_before_purge' => true,
            'confirmation' => 'PURGE ' . $pluginId,
            'plugin_data_records' => (int) $data->fetchColumn(),
            'plugin_tasks' => (int) $tasks->fetchColumn(),
            'plugin_media_references' => $mediaReferences,
            'plugin_settings' => (int) $setting->fetchColumn(),
            'migrations' => $migrationRows,
            'plugin_owned_tables' => array_values(array_unique($tables)),
            'plugin_block_content_count' => $contentCount,
            'dependents' => $this->dependentPlugins($pluginId),
            'contents_becoming_missing_extension' => $contentCount,
            'raw_block_data_retained_by_default' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function recoverFailedInstall(string $pluginId, int $adminId = 0): array
    {
        $row = $this->pluginRow($pluginId);
        if ($row === null) {
            return ['status' => 'nothing_to_recover', 'plugin_id' => $pluginId];
        }
        $dir = $this->rootPath . '/content/plugins/' . $pluginId;
        $records = $this->migrationRecordsForRollback($pluginId);
        $errors = [];
        foreach ($records as $record) {
            if ((string) $record['status'] === 'rolled_back') {
                continue;
            }
            try {
                $spec = $this->migrationSpecFromRecord($dir, $record);
                $this->rollbackMigration($spec, (int) $record['id'], new RuntimeException('manual recovery'));
            } catch (Throwable $exception) {
                $errors[] = $this->sanitizeError($exception);
            }
        }
        if ($errors !== []) {
            $this->setPluginRecoverable($pluginId, implode('; ', $errors));
            return ['status' => 'failed_recoverable', 'plugin_id' => $pluginId, 'errors' => $errors];
        }
        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }
        $this->pdo->prepare('DELETE FROM cms_plugins WHERE plugin_id = :plugin_id')->execute([':plugin_id' => $pluginId]);
        (new AuditLogger($this->pdo))->record('admin', $adminId, 'plugin.install_recovered', ['plugin_id' => $pluginId]);

        return ['status' => 'recovered', 'plugin_id' => $pluginId];
    }

    public function workerCheckpoint(string $pluginId, int $taskId): string
    {
        $stmt = $this->pdo->prepare('SELECT status, cancel_requested_at FROM cms_plugin_tasks WHERE id = :id AND plugin_id = :plugin_id LIMIT 1');
        $stmt->execute([':id' => $taskId, ':plugin_id' => $pluginId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new PluginException('Plugin task is missing.');
        }
        if ((string) ($row['status'] ?? '') === 'CancelRequested' || (string) ($row['cancel_requested_at'] ?? '') !== '') {
            $this->pdo->prepare("UPDATE cms_plugin_tasks SET status = 'Cancelled', updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $taskId, ':updated_at' => gmdate('c')]);
            return 'cancelled';
        }

        return 'continue';
    }

    public function cleanupPreview(string $token): void
    {
        $row = $this->previewRow($token);
        if ($row !== null && is_dir((string) $row['staging_dir'])) {
            $this->removeDirectory((string) $row['staging_dir']);
        }
        $this->pdo->prepare('DELETE FROM cms_plugin_install_previews WHERE token = :token')->execute([':token' => $token]);
    }

    /** @param array<string,mixed> $preview @param array<string,mixed> $manifest @param array<string,mixed> $scan */
    private function assertPreviewStagingUnchanged(array $preview, array $manifest, array $scan): void
    {
        $staging = (string) ($preview['staging_dir'] ?? '');
        $expectedHash = (string) ($scan['staging_sha256'] ?? '');
        if ($staging === '' || !is_dir($staging) || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new PluginException('Plugin install preview integrity is missing.');
        }
        if (!hash_equals($expectedHash, $this->directoryFingerprint($staging))) {
            throw new PluginException('Plugin install preview staging files changed.');
        }
        [$rootName, $currentManifest] = $this->readManifest($staging);
        if ((string) ($currentManifest['plugin_id'] ?? '') !== (string) ($manifest['plugin_id'] ?? '')
            || (string) ($currentManifest['version'] ?? '') !== (string) ($manifest['version'] ?? '')
        ) {
            throw new PluginException('Plugin install preview manifest changed.');
        }
        if (json_encode($currentManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) {
            throw new PluginException('Plugin install preview manifest changed.');
        }
        $root = $staging . '/' . $rootName;
        $this->validateManifest($currentManifest, $root, false);
        $this->validateMigrations($root, $currentManifest);
        $this->assertInstallable($currentManifest);
        $this->assertDependencies($currentManifest, (string) $currentManifest['plugin_id']);
    }

    private function directoryFingerprint(string $dir): string
    {
        if (!is_dir($dir)) {
            throw new PluginException('Plugin install staging directory is missing.');
        }
        $base = realpath($dir) ?: $dir;
        $records = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $path = (string) $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($base) + 1));
            if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '../') || str_contains($relative, '/..')) {
                throw new PluginException('Plugin install staging directory contains unsafe path.');
            }
            $records[] = $relative . "\0" . (string) $item->getSize() . "\0" . hash_file('sha256', $path);
        }
        sort($records, SORT_STRING);

        return hash('sha256', implode("\n", $records));
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function readManifest(string $staging): array
    {
        $dirs = array_values(array_filter(glob($staging . '/*') ?: [], 'is_dir'));
        if (count($dirs) !== 1) {
            throw new PluginException('Plugin ZIP must contain one root directory.');
        }
        $rootName = basename($dirs[0]);
        $manifestFile = $dirs[0] . '/plugin.json';
        $decoded = json_decode(is_file($manifestFile) ? (string) file_get_contents($manifestFile) : '', true);
        if (!is_array($decoded)) {
            throw new PluginException('Plugin manifest is missing or invalid.');
        }
        if (!in_array((string) ($decoded['package_type'] ?? 'plugin'), ['plugin', 'payment_provider'], true)) {
            throw new PluginException('Package type must be plugin or payment_provider.');
        }
        if ((string) ($decoded['plugin_id'] ?? '') !== $rootName) {
            throw new PluginException('Plugin manifest id must match root directory.');
        }

        return [$rootName, $decoded];
    }

    private function validateManifest(array $manifest, string $root, bool $trustedOfficial = false): void
    {
        $pluginManifest = PluginManifest::fromArray($manifest);
        $official = new OfficialPluginRegistry($this->rootPath);
        if (!$trustedOfficial) {
            PluginRiskBoundaryPolicy::assertLocalManifestAllowed($manifest);
        }
        if (!$trustedOfficial && ($pluginManifest->bundled || $pluginManifest->type === 'system-plugin' || $pluginManifest->trustLevel === 'trusted_php' || $official->isReservedOfficialId($pluginManifest->id))) {
            throw new PluginException('Local plugins cannot claim official, bundled, system-plugin, or trusted PHP status.');
        }
        $allowedNamespaces = $trustedOfficial ? $official->capabilityNamespaces($pluginManifest->id) : $pluginManifest->capabilityNamespaces;
        Capability::assertPluginAllowed($pluginManifest->id, $pluginManifest->capabilities, $allowedNamespaces);
        if (in_array($pluginManifest->id, self::RESERVED_IDS, true) || str_starts_with($pluginManifest->id, 'cms_')) {
            throw new PluginException('Plugin id is reserved.');
        }
        $ownership = new PluginTableOwnership($this->pdo, $official);
        $prefixes = $ownership->prefixesFor($manifest, $trustedOfficial);
        $ownership->assertAvailable($pluginManifest->id, $prefixes);
        if (!version_compare((string) $this->config('app.version', '0.0.0'), $pluginManifest->coreMin, '>=')) {
            throw new PluginException('Plugin requires a newer CMS core.');
        }
        if (!VersionConstraint::matches(PHP_VERSION, '>=' . $pluginManifest->phpMin)) {
            throw new PluginException('Plugin requires a different PHP version.');
        }
        if (!is_file($root . '/' . $pluginManifest->entry)) {
            throw new PluginException('Plugin entry file is missing.');
        }
        foreach (($manifest['files'] ?? []) as $path => $hash) {
            $file = $root . '/' . str_replace('\\', '/', (string) $path);
            if (!is_file($file) || hash_file('sha256', $file) !== (string) $hash) {
                throw new PluginException('Plugin file manifest hash mismatch.');
            }
        }
    }

    private function validateMigrations(string $root, array $manifest, bool $trustedOfficial = false): void
    {
        $ownership = new PluginTableOwnership($this->pdo, new OfficialPluginRegistry($this->rootPath));
        $prefixes = $ownership->prefixesFor($manifest, $trustedOfficial);
        $seen = [];
        foreach (($manifest['migrations'] ?? []) as $migration) {
            $spec = $this->loadMigrationSpec($root, (string) $migration, $manifest);
            if (isset($seen[$spec['id']])) {
                throw new PluginException('Plugin migration IDs must be unique.');
            }
            $seen[$spec['id']] = true;
            $this->assertPluginOwnedObjects($manifest, $spec['affected_objects'], $trustedOfficial);
            if (!$spec['reversible']) {
                throw new PluginException('Irreversible plugin migrations require a database restore point; none is available for local ZIP install.');
            }
        }
    }

    private function assertInstallable(array $manifest): void
    {
        $row = $this->pluginRow((string) $manifest['plugin_id']);
        if ($row !== null && !in_array((string) $row['status'], [PluginLifecycle::REMOVED, PluginLifecycle::DORMANT], true)) {
            throw new PluginException('Plugin with same ID already exists. Use the upgrade flow.');
        }
        if ($row !== null && version_compare((string) ($row['data_schema_version'] ?? '0'), (string) ($manifest['data_schema_version'] ?? '0'), '>')) {
            throw new PluginException('Dormant plugin data is newer than this package.');
        }
    }

    private function assertDependencies(array $manifest, string $pluginId): void
    {
        $deps = $manifest['required_plugins'] ?? $manifest['dependencies'] ?? [];
        $this->assertDependencyRows(is_array($deps) ? $deps : [], $pluginId);
        $graph = [$pluginId => array_map(static fn (mixed $dep): string => is_array($dep) ? (string) ($dep['plugin_id'] ?? '') : (string) $dep, is_array($deps) ? $deps : [])];
        foreach ($this->pdo->query('SELECT plugin_id, dependencies_json FROM cms_plugins')->fetchAll() as $row) {
            $graph[(string) $row['plugin_id']] = array_map(static fn (mixed $dep): string => is_array($dep) ? (string) ($dep['plugin_id'] ?? '') : (string) $dep, json_decode((string) ($row['dependencies_json'] ?? '[]'), true) ?: []);
        }
        if ($this->hasCycle($graph)) {
            throw new PluginException('Plugin dependencies contain a cycle.');
        }
    }

    private function assertDependencyRows(array $deps, string $pluginId): void
    {
        foreach ($deps as $dep) {
            $depId = is_array($dep) ? (string) ($dep['plugin_id'] ?? '') : (string) $dep;
            if ($depId === '' || $depId === $pluginId) {
                throw new PluginException('Invalid plugin dependency.');
            }
            $row = $this->pluginRow($depId);
            if ($row === null || (string) $row['status'] !== PluginLifecycle::ENABLED) {
                throw new PluginException('Required plugin is missing or disabled: ' . $depId);
            }
            $min = is_array($dep) ? (string) ($dep['min_version'] ?? $dep['min'] ?? '') : '';
            $max = is_array($dep) ? (string) ($dep['max_version'] ?? $dep['max'] ?? '') : '';
            if ($min !== '' && version_compare((string) $row['version'], $min, '<')) {
                throw new PluginException('Required plugin version is too old: ' . $depId);
            }
            if ($max !== '' && version_compare((string) $row['version'], $max, '>=')) {
                throw new PluginException('Required plugin version is too new: ' . $depId);
            }
        }
    }

    /** @return list<string> */
    private function enabledDependents(string $pluginId): array
    {
        $dependents = [];
        $stmt = $this->pdo->prepare("SELECT plugin_id, dependencies_json FROM cms_plugins WHERE status = 'Enabled'");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            foreach (json_decode((string) ($row['dependencies_json'] ?? '[]'), true) ?: [] as $dep) {
                $depId = is_array($dep) ? (string) ($dep['plugin_id'] ?? '') : (string) $dep;
                if ($depId === $pluginId) {
                    $dependents[] = (string) $row['plugin_id'];
                }
            }
        }
        return $dependents;
    }

    private function registerPlugin(array $manifest, bool $enable, string $source = 'local_unreviewed', string $reviewStatus = 'unreviewed', bool $trustedOfficial = false): void
    {
        $status = $enable ? PluginLifecycle::ENABLED : PluginLifecycle::INSTALLED;
        $existing = $this->pluginRow((string) $manifest['plugin_id']);
        $deps = $manifest['required_plugins'] ?? $manifest['dependencies'] ?? [];
        $now = gmdate('c');
        $prefixes = (new PluginTableOwnership($this->pdo, new OfficialPluginRegistry($this->rootPath)))->prefixesFor($manifest, $trustedOfficial);
        if ($existing !== null && ($trustedOfficial || in_array((string) $existing['status'], [PluginLifecycle::REMOVED, PluginLifecycle::DORMANT], true))) {
            $stmt = $this->pdo->prepare('UPDATE cms_plugins SET name = :name, version = :version, author = :author, status = :status, trust_level = :trust_level, capabilities_json = :capabilities_json, dependencies_json = :dependencies_json, optional_dependencies_json = :optional_dependencies_json, data_policy_json = :data_policy_json, data_schema_version = :data_schema_version, source = :source, review_status = :review_status, table_prefixes_json = :table_prefixes_json, last_error = NULL, updated_at = :updated_at WHERE plugin_id = :plugin_id');
            $params = [];
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO cms_plugins (plugin_id, name, version, author, status, trust_level, capabilities_json, installed_at, updated_at, source, review_status, dependencies_json, optional_dependencies_json, data_policy_json, data_schema_version, table_prefixes_json) VALUES (:plugin_id, :name, :version, :author, :status, :trust_level, :capabilities_json, :installed_at, :updated_at, :source, :review_status, :dependencies_json, :optional_dependencies_json, :data_policy_json, :data_schema_version, :table_prefixes_json)');
            $params = [':installed_at' => $now];
        }
        $stmt->execute($params + [
            ':plugin_id' => (string) $manifest['plugin_id'],
            ':name' => (string) $manifest['name'],
            ':version' => (string) $manifest['version'],
            ':author' => (string) $manifest['author'],
            ':status' => $status,
            ':trust_level' => (string) ($manifest['trust_level'] ?? 'api'),
            ':capabilities_json' => json_encode($manifest['capabilities'] ?? [], JSON_UNESCAPED_SLASHES),
            ':dependencies_json' => json_encode($deps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':optional_dependencies_json' => json_encode($manifest['optional_dependencies'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':data_policy_json' => json_encode($manifest['data_policy'] ?? ['uninstall' => 'retain'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':data_schema_version' => (string) ($manifest['data_schema_version'] ?? $manifest['version']),
            ':source' => $source,
            ':review_status' => $reviewStatus,
            ':table_prefixes_json' => json_encode($prefixes, JSON_UNESCAPED_SLASHES),
            ':updated_at' => $now,
        ]);
    }

    private function runMigrations(string $target, array $manifest, bool $trustedOfficial = false): void
    {
        $appliedThisBatch = [];
        try {
            foreach (($manifest['migrations'] ?? []) as $migration) {
                $spec = $this->loadMigrationSpec($target, (string) $migration, $manifest);
                $this->assertPluginOwnedObjects($manifest, $spec['affected_objects'], $trustedOfficial);
                $row = $this->migrationRow((string) $manifest['plugin_id'], $spec['id']);
                if ($row !== null && (string) $row['status'] === 'applied') {
                    if ((string) $row['checksum'] !== $spec['checksum']) {
                        throw new PluginException('Plugin migration checksum changed: ' . $spec['id']);
                    }
                    continue;
                }
                $recordId = $this->startMigrationRecord($manifest, $spec);
                $appliedThisBatch[] = ['record_id' => $recordId, 'spec' => $spec];
                ($spec['up'])($this->pdo);
                $this->finishMigrationRecord($recordId, 'applied');
            }
        } catch (Throwable $exception) {
            $rollbackErrors = [];
            foreach (array_reverse($appliedThisBatch) as $item) {
                try {
                    $this->rollbackMigration($item['spec'], (int) $item['record_id'], $exception);
                } catch (Throwable $rollbackException) {
                    $rollbackErrors[] = $this->sanitizeError($rollbackException);
                }
            }
            if ($rollbackErrors !== []) {
                $this->setPluginRecoverable((string) $manifest['plugin_id'], implode('; ', $rollbackErrors));
            }
            throw $exception;
        }
    }

    /** @return array{id:string,path:string,checksum:string,up:callable,down:?callable,reversible:bool,affected_objects:list<string>} */
    private function loadMigrationSpec(string $target, string $migration, array $manifest): array
    {
        $file = $target . '/' . str_replace('\\', '/', $migration);
        $targetReal = realpath($target) ?: $target;
        $fileReal = realpath($file) ?: '';
        if (!is_file($file) || !str_starts_with($fileReal, $targetReal)) {
            throw new PluginException('Plugin migration file is invalid.');
        }
        $definition = require $file;
        $id = pathinfo($file, PATHINFO_FILENAME);
        $affected = [];
        $up = null;
        $down = null;
        if (is_array($definition)) {
            $id = (string) ($definition['id'] ?? $id);
            $up = $definition['up'] ?? null;
            $down = $definition['down'] ?? null;
            $affected = is_array($definition['affected_objects'] ?? null) ? array_values($definition['affected_objects']) : [];
        } elseif (is_object($definition)) {
            $id = method_exists($definition, 'id') ? (string) $definition->id() : $id;
            $up = method_exists($definition, 'up') ? [$definition, 'up'] : (method_exists($definition, 'apply') ? [$definition, 'apply'] : null);
            $down = method_exists($definition, 'down') ? [$definition, 'down'] : (method_exists($definition, 'rollback') ? [$definition, 'rollback'] : null);
            if (method_exists($definition, 'affectedObjects')) {
                $objects = $definition->affectedObjects();
                $affected = is_array($objects) ? array_values($objects) : [];
            }
        } elseif (is_callable($definition)) {
            $up = $definition;
        }
        if ($id === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            throw new PluginException('Plugin migration ID is invalid.');
        }
        if (!is_callable($up)) {
            throw new PluginException('Plugin migration must define an up/apply callable.');
        }
        $affected = array_map(static fn (mixed $object): string => (string) $object, $affected);

        return [
            'id' => $id,
            'path' => $file,
            'checksum' => hash_file('sha256', $file) ?: '',
            'up' => $up,
            'down' => is_callable($down) ? $down : null,
            'reversible' => is_callable($down),
            'affected_objects' => $affected,
        ];
    }

    private function assertPluginOwnedObjects(array $manifest, array $objects, bool $trustedOfficial = false): void
    {
        $ownership = new PluginTableOwnership($this->pdo, new OfficialPluginRegistry($this->rootPath));
        $ownership->assertOwnsObjects($objects, $ownership->prefixesFor($manifest, $trustedOfficial));
    }

    /** @param array<string,mixed> $spec */
    private function startMigrationRecord(array $manifest, array $spec): int
    {
        $existing = $this->migrationRow((string) $manifest['plugin_id'], (string) $spec['id']);
        $now = gmdate('c');
        if ($existing !== null) {
            if (!in_array((string) $existing['status'], ['pending', 'failed_recoverable', 'rollback_failed', 'rolled_back'], true)) {
                throw new PluginException('Plugin migration is already active or applied: ' . $spec['id']);
            }
            if ((string) $existing['checksum'] !== (string) $spec['checksum'] && (string) $existing['status'] !== 'rolled_back') {
                throw new PluginException('Plugin migration checksum changed: ' . $spec['id']);
            }
            $this->pdo->prepare("UPDATE cms_plugin_migrations SET plugin_version = :plugin_version, checksum = :checksum, status = 'running', affected_objects_json = :affected_objects_json, started_at = :started_at, completed_at = NULL, rollback_at = NULL, error_code = NULL, error_summary = NULL, updated_at = :updated_at WHERE id = :id")
                ->execute([
                    ':id' => (int) $existing['id'],
                    ':plugin_version' => (string) $manifest['version'],
                    ':checksum' => (string) $spec['checksum'],
                    ':affected_objects_json' => json_encode($spec['affected_objects'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':started_at' => $now,
                    ':updated_at' => $now,
                ]);
            return (int) $existing['id'];
        }
        $stmt = $this->pdo->prepare("INSERT INTO cms_plugin_migrations (plugin_id, plugin_version, migration_id, checksum, status, affected_objects_json, started_at, created_at, updated_at) VALUES (:plugin_id, :plugin_version, :migration_id, :checksum, 'running', :affected_objects_json, :started_at, :created_at, :updated_at)");
        $stmt->execute([
            ':plugin_id' => (string) $manifest['plugin_id'],
            ':plugin_version' => (string) $manifest['version'],
            ':migration_id' => (string) $spec['id'],
            ':checksum' => (string) $spec['checksum'],
            ':affected_objects_json' => json_encode($spec['affected_objects'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':started_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $row = $this->migrationRow((string) $manifest['plugin_id'], (string) $spec['id']);
        if ($row === null) {
            throw new PluginException('Unable to record plugin migration state.');
        }

        return (int) $row['id'];
    }

    private function finishMigrationRecord(int $recordId, string $status): void
    {
        if (!in_array($status, self::MIGRATION_STATUSES, true)) {
            throw new PluginException('Invalid plugin migration status.');
        }
        $now = gmdate('c');
        $this->pdo->prepare('UPDATE cms_plugin_migrations SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $recordId, ':status' => $status, ':completed_at' => $now, ':updated_at' => $now]);
    }

    /** @param array<string,mixed> $spec */
    private function rollbackMigration(array $spec, int $recordId, Throwable $cause): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("UPDATE cms_plugin_migrations SET status = 'rollback_running', error_code = :error_code, error_summary = :error_summary, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $recordId, ':error_code' => substr(get_class($cause), 0, 64), ':error_summary' => $this->sanitizeError($cause), ':updated_at' => $now]);
        try {
            if (!is_callable($spec['down'] ?? null)) {
                throw new PluginException('Plugin migration has no rollback callable: ' . $spec['id']);
            }
            ($spec['down'])($this->pdo);
            $this->pdo->prepare("UPDATE cms_plugin_migrations SET status = 'rolled_back', rollback_at = :rollback_at, updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $recordId, ':rollback_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
        } catch (Throwable $exception) {
            $this->pdo->prepare("UPDATE cms_plugin_migrations SET status = 'rollback_failed', rollback_at = :rollback_at, error_code = :error_code, error_summary = :error_summary, updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $recordId, ':rollback_at' => gmdate('c'), ':error_code' => substr(get_class($exception), 0, 64), ':error_summary' => $this->sanitizeError($exception), ':updated_at' => gmdate('c')]);
            throw $exception;
        }
    }

    private function migrationRow(string $pluginId, string $migrationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_plugin_migrations WHERE plugin_id = :plugin_id AND migration_id = :migration_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId, ':migration_id' => $migrationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function ensureBundledMigrationsBeforeEnable(string $pluginId, array $row): void
    {
        if ((string) ($row['source'] ?? '') !== 'bundled_official' || (string) ($row['review_status'] ?? '') !== 'official_trusted') {
            return;
        }
        $target = $this->rootPath . '/content/plugins/' . $pluginId;
        $manifestFile = $target . '/plugin.json';
        $manifest = json_decode(is_file($manifestFile) ? (string) file_get_contents($manifestFile) : '', true);
        if (!is_array($manifest)) {
            throw new PluginException('Bundled plugin manifest is invalid.');
        }
        $official = new OfficialPluginRegistry($this->rootPath);
        if (!$official->isTrustedBundled($pluginId, $target)) {
            throw new PluginException('Bundled plugin is not in the official trusted source registry.');
        }
        $this->validateMigrations($target, $manifest, true);
        $lock = $this->lock();
        $this->acquireLock($lock);
        try {
            $this->runMigrations($target, $manifest, true);
        } catch (Throwable $exception) {
            if ($this->hasRecoverableMigrationFailure($pluginId)) {
                $this->setPluginRecoverable($pluginId, $this->sanitizeError($exception));
            }
            throw $exception;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return list<array<string,mixed>> */
    private function migrationRecordsForRollback(string $pluginId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cms_plugin_migrations WHERE plugin_id = :plugin_id AND status IN ('running','applied','failed_recoverable','rollback_failed','rollback_running') ORDER BY id DESC");
        $stmt->execute([':plugin_id' => $pluginId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function migrationSpecFromRecord(string $dir, array $record): array
    {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $manifest = ['plugin_id' => (string) $record['plugin_id'], 'version' => (string) $record['plugin_version']];
            $spec = $this->loadMigrationSpec($dir, basename($file), $manifest);
            if ((string) $spec['id'] === (string) $record['migration_id']) {
                return $spec;
            }
        }
        throw new PluginException('Migration recovery file is missing: ' . (string) $record['migration_id']);
    }

    private function hasRecoverableMigrationFailure(string $pluginId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cms_plugin_migrations WHERE plugin_id = :plugin_id AND status IN ('rollback_failed','failed_recoverable','rollback_running','running')");
        $stmt->execute([':plugin_id' => $pluginId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function setPluginRecoverable(string $pluginId, string $error): void
    {
        $this->pdo->prepare('UPDATE cms_plugins SET status = :status, last_error = :last_error, updated_at = :updated_at WHERE plugin_id = :plugin_id')
            ->execute([':plugin_id' => $pluginId, ':status' => PluginLifecycle::INSTALL_FAILED_RECOVERABLE, ':last_error' => $error, ':updated_at' => gmdate('c')]);
    }

    /** @return list<array<string,string>> */
    private function scan(string $zipPath, array $manifest): array
    {
        $findings = (new PackageScanner())->scanZip($zipPath);
        $declared = $manifest['capabilities'] ?? [];
        $zip = new ZipArchive();
        $zip->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $content = $zip->getFromIndex($i);
            if (preg_match('/\.(exe|dll|bin|dylib)$/i', $name)) {
                $findings[] = $this->finding('critical', 'binary', 'Package contains executable binary.', $name);
            }
            if (preg_match('/\.zip$/i', $name)) {
                $findings[] = $this->finding('critical', 'nested_zip', 'Nested ZIP files are not allowed.', $name);
            }
            if (is_string($content) && preg_match('/(curl_exec|file_get_contents\s*\(\s*[\'"]https?:|base64_decode|eval|shell_exec|passthru|proc_open|system)\s*\(/i', $content)) {
                $findings[] = $this->finding('high', 'risky_code', 'Package contains risky code that needs review.', $name);
            }
            if (is_string($content) && str_contains($content, 'http') && !in_array('network.external', $declared, true)) {
                $findings[] = $this->finding('high', 'undeclared_network', 'Package uses network capability without declaring it.', $name);
            }
        }
        $zip->close();

        return $findings;
    }

    private function scanStatus(array $findings): string
    {
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === 'critical') {
                return 'blocked';
            }
        }
        foreach ($findings as $finding) {
            if (in_array(($finding['severity'] ?? ''), ['high', 'medium'], true)) {
                return 'needs_review';
            }
        }

        return 'passed';
    }

    private function safeExtract(string $zipPath, string $staging): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PluginException('ZipArchive extension is required.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new PluginException('Unable to open plugin ZIP.');
        }
        $this->ensureDir($staging);
        $realBase = realpath($staging) ?: $staging;
        if ($zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new PluginException('Plugin ZIP contains too many files.');
        }
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $this->assertZipName($name, $seen);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $unixMode = ($attributes >> 16) & 0xF000;
            if (in_array($unixMode, [0xA000, 0x6000], true)) {
                $zip->close();
                throw new PluginException('Plugin ZIP contains links or special files.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_EXTRACT_BYTES) {
                $zip->close();
                throw new PluginException('Plugin ZIP uncompressed size exceeds limit.');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                $zip->close();
                throw new PluginException('Unable to read plugin ZIP entry.');
            }
            $target = $staging . '/' . $name;
            $this->ensureDir(dirname($target));
            if (!str_starts_with(realpath(dirname($target)) ?: dirname($target), $realBase)) {
                $zip->close();
                throw new PluginException('Plugin ZIP path escapes staging directory.');
            }
            file_put_contents($target, $content);
        }
        $zip->close();
    }

    private function assertZipName(string $name, array &$seen): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) || str_contains($name, '../') || str_contains($name, '/..')) {
            throw new PluginException('Plugin ZIP contains unsafe path.');
        }
        if (substr_count($name, '/') > self::MAX_DEPTH) {
            throw new PluginException('Plugin ZIP directory depth exceeds limit.');
        }
        if (str_starts_with($name, 'system/') || str_starts_with($name, 'config/') || str_starts_with($name, 'storage/') || str_starts_with($name, 'public/') || str_starts_with($name, 'content/plugins/')) {
            throw new PluginException('Plugin ZIP attempts to write protected CMS paths.');
        }
        $normalized = function_exists('normalizer_normalize') && class_exists('\Normalizer')
            ? (normalizer_normalize($name, \Normalizer::FORM_C) ?: $name)
            : $name;
        $lower = function_exists('mb_strtolower') ? mb_strtolower($normalized) : strtolower($normalized);
        if (isset($seen[$lower])) {
            throw new PluginException('Plugin ZIP contains filename collision.');
        }
        $seen[$lower] = true;
    }

    private function hasCycle(array $graph): bool
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $node) use (&$visit, &$graph, &$visiting, &$visited): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if ($next !== '' && $visit($next)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;
            return false;
        };
        foreach (array_keys($graph) as $node) {
            if ($visit((string) $node)) {
                return true;
            }
        }
        return false;
    }

    private function isOfficialPackage(string $zipPath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        $official = $zip->getFromName('market-package.json') !== false || $zip->getFromName('signature.json') !== false;
        $zip->close();
        return $official;
    }

    private function pluginRow(string $pluginId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_plugins WHERE plugin_id = :plugin_id LIMIT 1');
        $stmt->execute([':plugin_id' => $pluginId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function previewRow(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_plugin_install_previews WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function setPluginStatus(string $pluginId, string $status): void
    {
        $this->pdo->prepare('UPDATE cms_plugins SET status = :status, updated_at = :updated_at WHERE plugin_id = :plugin_id')
            ->execute([':plugin_id' => $pluginId, ':status' => $status, ':updated_at' => gmdate('c')]);
    }

    private function pauseTasks(string $pluginId): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("UPDATE cms_plugin_tasks SET status = 'Paused', updated_at = :updated_at WHERE plugin_id = :plugin_id AND status = 'Queued'")
            ->execute([':plugin_id' => $pluginId, ':updated_at' => $now]);
        $this->pdo->prepare("UPDATE cms_plugin_tasks SET status = 'CancelRequested', cancel_requested_at = :cancel_requested_at, cancel_reason = :cancel_reason, updated_at = :updated_at WHERE plugin_id = :plugin_id AND status = 'Running'")
            ->execute([':plugin_id' => $pluginId, ':cancel_requested_at' => $now, ':cancel_reason' => 'Plugin disabled; worker must exit at a safe checkpoint.', ':updated_at' => $now]);
    }

    private function pluginData(string $pluginId): array
    {
        $stmt = $this->pdo->prepare('SELECT data_type, data_key, payload_json FROM cms_plugin_data WHERE plugin_id = :plugin_id');
        $stmt->execute([':plugin_id' => $pluginId]);
        return $stmt->fetchAll();
    }

    /** @return list<string> */
    private function dependentPlugins(string $pluginId): array
    {
        $dependents = [];
        $stmt = $this->pdo->prepare('SELECT plugin_id, dependencies_json FROM cms_plugins WHERE plugin_id <> :plugin_id');
        $stmt->execute([':plugin_id' => $pluginId]);
        foreach ($stmt->fetchAll() as $row) {
            foreach (json_decode((string) ($row['dependencies_json'] ?? '[]'), true) ?: [] as $dep) {
                $depId = is_array($dep) ? (string) ($dep['plugin_id'] ?? '') : (string) $dep;
                if ($depId === $pluginId) {
                    $dependents[] = (string) $row['plugin_id'];
                }
            }
        }
        return array_values(array_unique($dependents));
    }

    private function tableExists(string $table): bool
    {
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
                $stmt->execute([':name' => $table]);
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE :name');
            $stmt->execute([':name' => $table]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function sanitizeError(Throwable $exception): string
    {
        $message = preg_replace('/[A-Z]:[\\\\\\/][^\s]+|\/[^\s]+/', '[path]', $exception->getMessage()) ?: 'Plugin migration failed.';
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function acquireLock(string $lock): void
    {
        $this->ensureDir(dirname($lock));
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw new PluginException('Another plugin install is already running.');
        }
        fclose($handle);
    }

    private function releaseLock(string $lock): void
    {
        if (is_file($lock)) {
            unlink($lock);
        }
    }

    private function lock(): string
    {
        return $this->rootPath . '/storage/plugin-installs/install.lock';
    }

    private function config(string $key, mixed $default): mixed
    {
        $config = is_file($this->rootPath . '/config/app.php') ? require $this->rootPath . '/config/app.php' : [];
        $value = is_array($config) ? $config : [];
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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

    private function finding(string $severity, string $code, string $message, string $path): array
    {
        return compact('severity', 'code', 'message', 'path');
    }
}
