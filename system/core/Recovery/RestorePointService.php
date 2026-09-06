<?php

declare(strict_types=1);

namespace Cms\Core\Recovery;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class RestorePointService
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function create(string $reason): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RecoveryException('ZipArchive extension is required for restore points.');
        }

        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $dir = $this->rootPath . '/storage/recovery';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zipPath = $dir . '/restore-' . $id . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RecoveryException('Unable to create restore point archive.');
        }
        @chmod($zipPath, 0600);

        $checksums = [];
        $dbBackup = $this->databaseBackupPath();
        $manifest = [
            'id' => $id,
            'created_at' => gmdate('c'),
            'reason' => $reason,
            'includes' => ['config', 'content', 'core', 'core-manifest', 'database', 'market-extensions'],
            'database' => ['driver' => $dbBackup === '' ? 'unavailable' : $this->databaseDriver()],
        ];
        $this->addDirectory($zip, $this->rootPath . '/config', 'config', $checksums);
        $this->addDirectory($zip, $this->rootPath . '/content', 'content', $checksums);
        $this->addDirectory($zip, $this->rootPath . '/system/core', 'system/core', $checksums);
        $this->addDirectory($zip, $this->rootPath . '/system/migrations', 'system/migrations', $checksums);
        if (is_file($this->rootPath . '/system/core-manifest.json')) {
            $zip->addFile($this->rootPath . '/system/core-manifest.json', 'system/core-manifest.json');
            $checksums['system/core-manifest.json'] = hash_file('sha256', $this->rootPath . '/system/core-manifest.json');
        }
        if ($dbBackup !== '') {
            $name = $this->databaseDriver() === 'mysql' ? 'database/database.mysql.sql' : 'database/database.sqlite';
            $zip->addFile($dbBackup, $name);
            $checksums[$name] = hash_file('sha256', $dbBackup);
        } else {
            $zip->addFromString('database/README.txt', 'Database backup is unavailable for this configured driver.');
            $checksums['database/README.txt'] = hash('sha256', 'Database backup is unavailable for this configured driver.');
        }
        $extensionBackupJson = json_encode($this->marketExtensionBackup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($extensionBackupJson)) {
            throw new RecoveryException('Unable to create market extension backup manifest.');
        }
        $zip->addFromString('extensions/market-extensions.json', $extensionBackupJson);
        $checksums['extensions/market-extensions.json'] = hash('sha256', $extensionBackupJson);
        $manifest['checksums'] = $checksums;
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
        @chmod($zipPath, 0600);
        if ($dbBackup !== '') {
            @unlink($dbBackup);
        }
        $this->verify($zipPath);

        return $zipPath;
    }

    /** @return list<string> */
    public function list(): array
    {
        $files = glob($this->rootPath . '/storage/recovery/restore-*.zip') ?: [];
        sort($files);

        return $files;
    }

    /** @return array<string,mixed> */
    public function verify(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RecoveryException('Unable to open restore point.');
        }
        $manifestJson = $zip->getFromName('manifest.json');
        $manifest = json_decode(is_string($manifestJson) ? $manifestJson : '', true);
        if (!is_array($manifest) || !is_array($manifest['checksums'] ?? null)) {
            $zip->close();
            throw new RecoveryException('Restore point manifest is invalid.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($entry === 'manifest.json') {
                continue;
            }
            if (!$this->isSafeRestoreEntry(rtrim($entry, '/'))) {
                $zip->close();
                throw new RecoveryException('Restore point contains unsafe path.');
            }
            if (str_ends_with($entry, '/')) {
                continue;
            }
            if (!array_key_exists($entry, $manifest['checksums'])) {
                $zip->close();
                throw new RecoveryException('Restore point contains undeclared file.');
            }
        }
        foreach ($manifest['checksums'] as $path => $hash) {
            if (!$this->isSafeRestoreEntry((string) $path)) {
                $zip->close();
                throw new RecoveryException('Restore point contains unsafe path.');
            }
            $content = $zip->getFromName((string) $path);
            if (!is_string($content) || hash('sha256', $content) !== (string) $hash) {
                $zip->close();
                throw new RecoveryException('Restore point checksum mismatch.');
            }
        }
        $zip->close();
        return $manifest;
    }

    public function restoreDatabase(string $zipPath): void
    {
        $manifest = $this->verify($zipPath);
        if (($manifest['database']['driver'] ?? '') === 'mysql') {
            $this->restoreMysqlDatabase($zipPath, $manifest);
            return;
        }
        if (($manifest['database']['driver'] ?? '') !== 'sqlite') {
            throw new RecoveryException('Database restore is unavailable for this restore point.');
        }
        $target = $this->configuredSqlitePath();
        if ($target === '') {
            throw new RecoveryException('Configured database is not SQLite.');
        }
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $content = $zip->getFromName('database/database.sqlite');
        $zip->close();
        if (!is_string($content) || $content === '') {
            throw new RecoveryException('Restore point database backup is empty.');
        }
        $tmp = $target . '.restore-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content, LOCK_EX);
        @chmod($tmp, 0600);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RecoveryException('Database restore failed.');
        }
    }

    public function restoreCore(string $zipPath): void
    {
        $this->verify($zipPath);
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $staging = $this->rootPath . '/storage/recovery/core-restore-' . bin2hex(random_bytes(4));
        mkdir($staging, 0755, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (!$this->isSafeRestoreEntry($name)) {
                $zip->close();
                $this->removeDirectory($staging);
                throw new RecoveryException('Restore point contains unsafe path.');
            }
            if (!str_starts_with($name, 'system/core/') && !str_starts_with($name, 'system/migrations/') && $name !== 'system/core-manifest.json') {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                continue;
            }
            $target = $this->safeStagingTarget($staging, $name);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $content, LOCK_EX);
        }
        $zip->close();
        $this->replaceDirectory($staging . '/system/core', $this->rootPath . '/system/core');
        $this->replaceDirectory($staging . '/system/migrations', $this->rootPath . '/system/migrations');
        if (is_file($staging . '/system/core-manifest.json')) {
            copy($staging . '/system/core-manifest.json', $this->rootPath . '/system/core-manifest.json');
        }
        $this->removeDirectory($staging);
    }

    private function addDirectory(ZipArchive $zip, string $path, string $prefix, array &$checksums): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = $prefix . '/' . str_replace('\\', '/', substr($absolute, strlen($path) + 1));
            if (!$this->isSafeRestoreEntry($relative)) {
                continue;
            }
            $zip->addFile($absolute, $relative);
            $checksums[$relative] = hash_file('sha256', $absolute);
        }
    }

    private function databaseBackupPath(): string
    {
        if ($this->databaseDriver() === 'mysql') {
            return $this->mysqlBackupPath();
        }
        $db = $this->configuredSqlitePath();
        if ($db === '' || !is_file($db)) {
            return '';
        }
        $tmp = $this->rootPath . '/storage/recovery/db-backup-' . bin2hex(random_bytes(4)) . '.sqlite';
        if (!copy($db, $tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            throw new RecoveryException('Unable to create verified SQLite database backup.');
        }
        @chmod($tmp, 0600);
        return $tmp;
    }

    private function databaseDriver(): string
    {
        try {
            $settings = Settings::load($this->rootPath);
            $dsn = (string) $settings->get('database.dsn', '');
            if (str_starts_with($dsn, 'mysql:')) {
                return 'mysql';
            }
            if (str_starts_with($dsn, 'sqlite:')) {
                return 'sqlite';
            }
        } catch (\Throwable) {
            return 'unavailable';
        }

        return 'unavailable';
    }

    /** @return array<string,mixed> */
    private function marketExtensionBackup(): array
    {
        $backup = [
            'schema_version' => '1.0.0',
            'generated_at' => gmdate('c'),
            'sources_table_available' => false,
            'install_logs_table_available' => false,
            'sources' => [],
            'latest_installed' => [],
            'install_logs' => [],
            'installed_directories' => [
                'plugins' => $this->extensionDirectories($this->rootPath . '/content/plugins'),
                'themes' => $this->extensionDirectories($this->rootPath . '/content/themes'),
            ],
            'policy' => [
                'content_plugins_and_themes_included' => true,
                'runtime_market_cache_included' => false,
                'database_backup_contains_market_tables_when_available' => true,
            ],
        ];

        try {
            $pdo = ConnectionFactory::make(Settings::load($this->rootPath));
            if ($this->tableExists($pdo, 'cms_extension_sources')) {
                $backup['sources_table_available'] = true;
                $backup['sources'] = $pdo->query('SELECT extension_id, extension_type, source, market_id, version, installed_at, metadata_json FROM cms_extension_sources ORDER BY id')->fetchAll();
                $backup['latest_installed'] = $pdo->query('SELECT s.extension_id, s.extension_type, s.source, s.market_id, s.version, s.installed_at, s.metadata_json FROM cms_extension_sources s INNER JOIN (SELECT extension_id, extension_type, MAX(id) AS max_id FROM cms_extension_sources GROUP BY extension_id, extension_type) latest ON latest.max_id = s.id ORDER BY s.extension_type, s.extension_id')->fetchAll();
            }
            if ($this->tableExists($pdo, 'cms_market_install_logs')) {
                $backup['install_logs_table_available'] = true;
                $backup['install_logs'] = $pdo->query('SELECT market_id, extension_id, extension_type, status, plan_json, created_at FROM cms_market_install_logs ORDER BY id')->fetchAll();
            }
        } catch (\Throwable) {
            $backup['database_inspection'] = 'unavailable';
        }

        return $backup;
    }

    /** @return list<array{id:string,type:string,path:string}> */
    private function extensionDirectories(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }
        $type = str_ends_with(str_replace('\\', '/', $path), '/themes') ? 'theme' : 'plugin';
        $directories = [];
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($path . '/' . $name) || is_link($path . '/' . $name)) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
                continue;
            }
            $directories[] = [
                'id' => $name,
                'type' => $type,
                'path' => ($type === 'theme' ? 'content/themes/' : 'content/plugins/') . $name,
            ];
        }

        return $directories;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
            $stmt->execute([':name' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $pdo->prepare('SHOW TABLES LIKE :name');
        $stmt->execute([':name' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    private function mysqlBackupPath(): string
    {
        $settings = Settings::load($this->rootPath);
        $info = $this->mysqlConnectionInfo($settings);
        $tmp = $this->rootPath . '/storage/recovery/db-backup-' . bin2hex(random_bytes(4)) . '.mysql.sql';
        $binary = (string) $settings->get('updates.mysql_dump_binary', 'mysqldump');
        try {
            $this->runProcess($this->mysqlDumpArgs($binary, $info, $tmp, true), $info['password'] !== '' ? ['MYSQL_PWD' => $info['password']] : []);
        } catch (RecoveryException $exception) {
            if (!$this->isUnsupportedGtidDumpOption($exception->getMessage())) {
                throw $exception;
            }
            @unlink($tmp);
            $this->runProcess($this->mysqlDumpArgs($binary, $info, $tmp, false), $info['password'] !== '' ? ['MYSQL_PWD' => $info['password']] : []);
        }
        if (!is_file($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            throw new RecoveryException('Unable to create verified MySQL/MariaDB database backup.');
        }
        @chmod($tmp, 0600);

        return $tmp;
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

    /** @param array<string,mixed> $manifest */
    private function restoreMysqlDatabase(string $zipPath, array $manifest): void
    {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $content = $zip->getFromName('database/database.mysql.sql');
        $zip->close();
        if (!is_string($content) || $content === '' || hash('sha256', $content) !== (string) ($manifest['checksums']['database/database.mysql.sql'] ?? '')) {
            throw new RecoveryException('MySQL/MariaDB restore point verification failed.');
        }
        $settings = Settings::load($this->rootPath);
        $info = $this->mysqlConnectionInfo($settings);
        $this->runProcess([
            (string) $settings->get('updates.mysql_binary', 'mysql'),
            '--protocol=TCP',
            '--host=' . $info['host'],
            '--port=' . $info['port'],
            '--user=' . $info['username'],
            $info['database'],
        ], $info['password'] !== '' ? ['MYSQL_PWD' => $info['password']] : [], $content);
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
            throw new RecoveryException('MySQL/MariaDB database name is missing.');
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
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($args, $descriptor, $pipes, $this->rootPath, array_merge($this->processEnvironment(), $env));
        if (!is_resource($process)) {
            throw new RecoveryException('Unable to start database tool.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RecoveryException('Database tool failed: ' . preg_replace('/[^\r\n]{200,}/', '[redacted]', trim((string) $stderr)));
        }

        return (string) $stdout;
    }

    private function configuredSqlitePath(): string
    {
        try {
            $settings = Settings::load($this->rootPath);
            $dsn = (string) $settings->get('database.dsn', '');
            return str_starts_with($dsn, 'sqlite:') ? substr($dsn, 7) : '';
        } catch (\Throwable) {
            return '';
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

    private function replaceDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RecoveryException('Restore source directory is missing.');
        }
        $backup = $target . '.before-restore-' . bin2hex(random_bytes(4));
        if (is_dir($target) && !rename($target, $backup)) {
            throw new RecoveryException('Unable to preserve current Core before restore.');
        }
        if (!rename($source, $target)) {
            if (is_dir($backup)) {
                rename($backup, $target);
            }
            throw new RecoveryException('Unable to restore Core directory.');
        }
        $this->removeDirectory($backup);
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

    private function isSafeRestoreEntry(string $name): bool
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) === 1) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function safeStagingTarget(string $staging, string $name): string
    {
        $target = $staging . '/' . str_replace('\\', '/', $name);
        $base = rtrim(realpath($staging) ?: $staging, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        $resolvedParent = rtrim(realpath($parent) ?: $parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolvedParent, $base)) {
            throw new RecoveryException('Restore point target escapes staging directory.');
        }

        return $target;
    }
}
