<?php

declare(strict_types=1);

namespace Cms\Core\SiteVault;

use Cms\Core\Config\Settings;
use Cms\Core\Recovery\RestorePointService;
use Cms\Core\Timeline\SiteTimelineService;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

final class SiteVaultService
{
    public const FORMAT = 'daiying-site-v1';
    public const EXTENSION = '.daiying-site';

    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $pdo,
        private readonly ?SiteTimelineService $timeline = null,
    ) {
    }

    public function export(string $targetPath, ?int $actorId = null): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new SiteVaultException('ZipArchive extension is required for Site Vault export.');
        }
        if (!str_ends_with($targetPath, self::EXTENSION)) {
            throw new SiteVaultException('Site Vault package must use the .daiying-site extension.');
        }
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $vaultId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        $checksums = [];
        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new SiteVaultException('Unable to create Site Vault package.');
        }
        @chmod($targetPath, 0600);

        $database = $this->databaseSnapshot();
        $this->addString($zip, 'database/site-data.json', $this->json($database), $checksums);
        $this->addDirectory($zip, $this->rootPath . '/config', 'config', $checksums);
        $this->addDirectory($zip, $this->rootPath . '/content', 'content', $checksums);
        if (is_file($this->rootPath . '/system/core-manifest.json')) {
            $zip->addFile($this->rootPath . '/system/core-manifest.json', 'system/core-manifest.json');
            $checksums['system/core-manifest.json'] = hash_file('sha256', $this->rootPath . '/system/core-manifest.json');
        }

        $settings = Settings::load($this->rootPath);
        $manifest = [
            'package_format' => self::FORMAT,
            'package_format_version' => 1,
            'vault_id' => $vaultId,
            'created_at' => gmdate('c'),
            'cms_version' => (string) $settings->get('app.version', 'unknown'),
            'database_driver' => (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'migration_version' => $this->latestMigrationId(),
            'includes' => [
                'database data',
                'articles',
                'pages',
                'block content',
                'media references/files',
                'theme',
                'enabled plugins',
                'plugin configuration',
                'CMS configuration',
                'route/permalink settings',
                'menu/navigation',
                'member/comment related existing data',
            ],
            'plugins' => $this->pluginVersions(),
            'theme' => $this->themeVersion(),
            'checksums' => $checksums,
        ];
        $zip->addFromString('manifest.json', $this->json($manifest));
        $zip->close();
        $sha = (string) hash_file('sha256', $targetPath);
        $this->recordPackage($vaultId, $targetPath, $manifest, $sha, 'exported');
        $this->timeline?->record('admin', $actorId, 'site_vault.export', 'site_vault', $vaultId, null, $sha, 'snapshot', $vaultId, [
            'package' => basename($targetPath),
            'cms_version' => $manifest['cms_version'],
        ]);

        return $targetPath;
    }

    /** @return array<string,mixed> */
    public function inspect(string $packagePath): array
    {
        return $this->verify($packagePath);
    }

    /** @return array<string,mixed> */
    public function verify(string $packagePath): array
    {
        $zip = $this->openPackage($packagePath);
        $manifestJson = $zip->getFromName('manifest.json');
        $manifest = json_decode(is_string($manifestJson) ? $manifestJson : '', true);
        if (!is_array($manifest) || ($manifest['package_format'] ?? '') !== self::FORMAT) {
            $zip->close();
            throw new SiteVaultException('Site Vault manifest is invalid or unsupported.');
        }
        $checksums = $manifest['checksums'] ?? null;
        if (!is_array($checksums) || !isset($checksums['database/site-data.json'])) {
            $zip->close();
            throw new SiteVaultException('Site Vault integrity manifest is incomplete.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($entry === 'manifest.json' || str_ends_with($entry, '/')) {
                continue;
            }
            if (!$this->safeEntry($entry) || !array_key_exists($entry, $checksums)) {
                $zip->close();
                throw new SiteVaultException('Site Vault package contains undeclared or unsafe file.');
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || hash('sha256', $content) !== (string) $checksums[$entry]) {
                $zip->close();
                throw new SiteVaultException('Site Vault checksum mismatch.');
            }
        }
        $zip->close();
        $manifest['compatibility'] = $this->compatibility($manifest);

        return $manifest;
    }

    /** @return array<string,mixed> */
    public function restore(string $packagePath, ?int $actorId = null): array
    {
        $manifest = $this->verify($packagePath);
        if (($manifest['compatibility']['status'] ?? '') === 'BLOCKED') {
            throw new SiteVaultException((string) ($manifest['compatibility']['message'] ?? 'Site Vault package is incompatible.'));
        }

        $restorePoint = (new RestorePointService($this->rootPath))->create('pre-site-vault-restore');
        $vaultId = (string) ($manifest['vault_id'] ?? bin2hex(random_bytes(6)));
        try {
            $zip = $this->openPackage($packagePath);
            $databaseJson = $zip->getFromName('database/site-data.json');
            $database = json_decode(is_string($databaseJson) ? $databaseJson : '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($database)) {
                throw new SiteVaultException('Site Vault database payload is invalid.');
            }
            $this->restoreDatabaseSnapshot($database);
            $this->restoreDirectoryFromZip($zip, 'config/', $this->rootPath . '/config');
            $this->restoreDirectoryFromZip($zip, 'content/', $this->rootPath . '/content');
            $zip->close();
            $this->markRestored($vaultId);
            $this->timeline?->record('admin', $actorId, 'site_vault.restore', 'site_vault', $vaultId, $restorePoint, basename($packagePath), 'snapshot', $vaultId, [
                'compatibility' => $manifest['compatibility'],
            ]);

            return ['status' => 'PASS', 'vault_id' => $vaultId, 'rollback_snapshot' => $restorePoint];
        } catch (Throwable $exception) {
            try {
                (new RestorePointService($this->rootPath))->restoreDatabase($restorePoint);
            } catch (Throwable) {
            }
            $this->timeline?->record('admin', $actorId, 'site_vault.restore_failed', 'site_vault', $vaultId, $restorePoint, null, 'snapshot', $vaultId, [
                'error' => $this->safeError($exception),
            ]);
            throw new SiteVaultException('Site Vault restore failed and rollback was attempted: ' . $this->safeError($exception), 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function databaseSnapshot(): array
    {
        $tables = $this->tableNames();
        $snapshot = ['tables' => []];
        foreach ($tables as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $rows = $this->pdo->query('SELECT * FROM ' . $this->quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
            $snapshot['tables'][$table] = array_values($rows);
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function restoreDatabaseSnapshot(array $snapshot): void
    {
        $tables = $snapshot['tables'] ?? null;
        if (!is_array($tables)) {
            throw new SiteVaultException('Site Vault database tables payload is missing.');
        }
        $this->pdo->beginTransaction();
        try {
            foreach ($tables as $table => $rows) {
                if (!$this->safeIdentifier((string) $table) || !is_array($rows)) {
                    throw new SiteVaultException('Site Vault database table payload is unsafe.');
                }
                $tableName = $this->quoteIdentifier((string) $table);
                $this->pdo->exec('DELETE FROM ' . $tableName);
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        throw new SiteVaultException('Site Vault database row payload is invalid.');
                    }
                    $columns = array_keys($row);
                    foreach ($columns as $column) {
                        if (!$this->safeIdentifier((string) $column)) {
                            throw new SiteVaultException('Site Vault database column payload is unsafe.');
                        }
                    }
                    if ($columns === []) {
                        continue;
                    }
                    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
                    $sql = 'INSERT INTO ' . $tableName . ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ') VALUES (' . implode(', ', $placeholders) . ')';
                    $stmt = $this->pdo->prepare($sql);
                    foreach ($row as $column => $value) {
                        $stmt->bindValue(':' . (string) $column, $value);
                    }
                    $stmt->execute();
                }
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
        }
        $rows = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);

        return array_values(array_map(static fn (array $row): string => (string) $row[0], $rows));
    }

    private function recordPackage(string $vaultId, string $path, array $manifest, string $sha, string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO cms_site_vault_packages (vault_id, package_path, package_format, cms_version, status, manifest_json, sha256, created_at)
             VALUES (:vault_id, :package_path, :package_format, :cms_version, :status, :manifest_json, :sha256, :created_at)'
        )->execute([
            ':vault_id' => $vaultId,
            ':package_path' => $path,
            ':package_format' => self::FORMAT,
            ':cms_version' => (string) ($manifest['cms_version'] ?? 'unknown'),
            ':status' => $status,
            ':manifest_json' => $this->json($manifest),
            ':sha256' => $sha,
            ':created_at' => gmdate('c'),
        ]);
    }

    private function markRestored(string $vaultId): void
    {
        $this->pdo->prepare('UPDATE cms_site_vault_packages SET status = :status, restored_at = :restored_at WHERE vault_id = :vault_id')
            ->execute([':status' => 'restored', ':restored_at' => gmdate('c'), ':vault_id' => $vaultId]);
    }

    /** @return array<string,mixed> */
    private function compatibility(array $manifest): array
    {
        $current = (string) Settings::load($this->rootPath)->get('app.version', '0.0.0');
        $source = (string) ($manifest['cms_version'] ?? '0.0.0');
        $currentMajor = explode('.', $current)[0] ?? '0';
        $sourceMajor = explode('.', $source)[0] ?? '0';
        if ($currentMajor !== $sourceMajor) {
            return ['status' => 'BLOCKED', 'message' => 'Site Vault major CMS version is incompatible.', 'current' => $current, 'source' => $source];
        }
        if (version_compare($source, $current, '>')) {
            return ['status' => 'WARNING', 'message' => 'Site Vault was created by a newer minor version.', 'current' => $current, 'source' => $source];
        }

        return ['status' => 'PASS', 'message' => 'Site Vault package is compatible.', 'current' => $current, 'source' => $source];
    }

    private function addDirectory(ZipArchive $zip, string $path, string $prefix, array &$checksums): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = $prefix . '/' . str_replace('\\', '/', substr($absolute, strlen($path) + 1));
            if (!$this->safeEntry($relative) || $this->skipRuntimeFile($relative)) {
                continue;
            }
            $zip->addFile($absolute, $relative);
            $checksums[$relative] = hash_file('sha256', $absolute);
        }
    }

    private function addString(ZipArchive $zip, string $name, string $content, array &$checksums): void
    {
        $zip->addFromString($name, $content);
        $checksums[$name] = hash('sha256', $content);
    }

    private function restoreDirectoryFromZip(ZipArchive $zip, string $prefix, string $targetRoot): void
    {
        $staging = $this->rootPath . '/storage/tmp/site-vault-' . bin2hex(random_bytes(4));
        mkdir($staging, 0755, true);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                    continue;
                }
                if (!$this->safeEntry($name)) {
                    throw new SiteVaultException('Site Vault restore path is unsafe.');
                }
                $content = $zip->getFromIndex($i);
                if (!is_string($content)) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                $target = $staging . '/' . $relative;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                file_put_contents($target, $content, LOCK_EX);
            }
            $this->replaceDirectory($staging, $targetRoot);
        } finally {
            $this->removeDirectory($staging);
        }
    }

    private function replaceDirectory(string $source, string $target): void
    {
        if (is_dir($target)) {
            $this->removeDirectory($target);
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        if (!rename($source, $target)) {
            throw new SiteVaultException('Unable to replace restored directory.');
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }

    private function openPackage(string $packagePath): ZipArchive
    {
        if (!is_file($packagePath)) {
            throw new SiteVaultException('Site Vault package is missing.');
        }
        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new SiteVaultException('Unable to open Site Vault package.');
        }

        return $zip;
    }

    private function safeEntry(string $entry): bool
    {
        return $entry !== ''
            && !str_starts_with($entry, '/')
            && !str_contains($entry, '../')
            && !str_contains($entry, '..\\')
            && !preg_match('/(^|\/)\.\.?($|\/)/', $entry);
    }

    private function skipRuntimeFile(string $relative): bool
    {
        return str_contains($relative, '/logs/')
            || str_contains($relative, '/cache/')
            || str_contains($relative, '/tmp/')
            || str_contains($relative, '/recovery/')
            || str_ends_with($relative, '.log')
            || str_ends_with($relative, '.sqlite');
    }

    private function safeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!$this->safeIdentifier($identifier)) {
            throw new SiteVaultException('Unsafe database identifier.');
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function latestMigrationId(): string
    {
        try {
            $stmt = $this->pdo->query('SELECT migration_id FROM cms_migrations ORDER BY migration_id DESC LIMIT 1');
            $value = $stmt->fetchColumn();
            return is_string($value) ? $value : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<array<string,string>> */
    private function pluginVersions(): array
    {
        try {
            $rows = $this->pdo->query("SELECT plugin_id, version, status FROM cms_plugins ORDER BY plugin_id")->fetchAll(PDO::FETCH_ASSOC);
            return array_values(array_map(static fn (array $row): array => [
                'plugin_id' => (string) $row['plugin_id'],
                'version' => (string) $row['version'],
                'status' => (string) $row['status'],
            ], $rows));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,string> */
    private function themeVersion(): array
    {
        $settings = Settings::load($this->rootPath);
        return ['active' => (string) $settings->get('theme.active', 'default')];
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new SiteVaultException('Unable to encode Site Vault JSON.');
        }

        return $json . "\n";
    }

    private function safeError(Throwable $exception): string
    {
        return substr(str_replace($this->rootPath, '[root]', $exception->getMessage()), 0, 500);
    }
}
