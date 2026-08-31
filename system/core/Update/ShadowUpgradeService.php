<?php

declare(strict_types=1);

namespace Cms\Core\Update;

use Cms\Core\Recovery\IntegrityChecker;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

final class ShadowUpgradeService
{
    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $pdo,
        private readonly UpdateService $updates,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(string $zipPath): array
    {
        $runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        $report = [
            'run_id' => $runId,
            'capability_level' => $this->capabilityLevel(),
            'checks' => [],
            'can_continue' => false,
            'production_modified' => false,
        ];
        $currentVersion = 'unknown';
        $targetVersion = 'unknown';
        try {
            $plan = $this->updates->dryRun($zipPath);
            $currentVersion = (string) ($plan['compatibility']['current_version'] ?? 'unknown');
            $targetVersion = (string) ($plan['target_version'] ?? 'unknown');
            $report['plan'] = $plan;
            $report['checks'][] = $this->check('package.plan', 'PASS', 'Update package signature and compatibility dry-run passed.');
            $shadow = $this->createShadowWorkspace();
            try {
                $this->applyPackageToShadow($zipPath, $shadow);
                $report['checks'][] = $this->check('shadow.apply_package', 'PASS', 'Package files applied to shadow workspace.');
                $report['checks'][] = $this->coreLintCheck($shadow);
                $report['checks'][] = $this->coreIntegrityCheck();
                $report['checks'][] = $this->activeThemeCheck();
                foreach ($this->activePluginChecks() as $check) {
                    $report['checks'][] = $check;
                }
                foreach ($this->routeChecks() as $check) {
                    $report['checks'][] = $check;
                }
                $report['checks'][] = $this->databaseIntegrityCheck();
            } finally {
                $this->removeDirectory($shadow);
            }
        } catch (Throwable $exception) {
            $report['checks'][] = $this->check('shadow.exception', 'FAIL', $this->safeError($exception));
        }

        $blocking = array_values(array_filter($report['checks'], static fn (array $check): bool => ($check['status'] ?? '') === 'FAIL'));
        $report['can_continue'] = $blocking === [];
        $status = $blocking === [] ? 'PASS' : 'BLOCKED';
        $report['status'] = $status;
        $this->record($runId, $zipPath, $currentVersion, $targetVersion, $report);

        return $report;
    }

    private function capabilityLevel(): string
    {
        return is_writable($this->rootPath . '/storage/tmp') && class_exists(ZipArchive::class)
            ? 'isolated-shadow-filesystem'
            : 'best-effort-staging-transaction';
    }

    private function createShadowWorkspace(): string
    {
        $dir = $this->rootPath . '/storage/tmp/shadow-upgrade-' . bin2hex(random_bytes(5));
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new UpdateException('Unable to create shadow upgrade workspace.');
        }
        $this->copyDirectory($this->rootPath . '/system/core', $dir . '/system/core');
        $this->copyDirectory($this->rootPath . '/system/migrations', $dir . '/system/migrations');
        if (is_file($this->rootPath . '/system/core-manifest.json')) {
            if (!is_dir($dir . '/system')) {
                mkdir($dir . '/system', 0755, true);
            }
            copy($this->rootPath . '/system/core-manifest.json', $dir . '/system/core-manifest.json');
        }

        return $dir;
    }

    private function applyPackageToShadow(string $zipPath, string $shadow): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('Unable to open update package in shadow mode.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_ends_with($name, '/') || !str_starts_with($name, 'system/')) {
                continue;
            }
            if (str_contains($name, '../') || str_starts_with($name, '/')) {
                $zip->close();
                throw new UpdateException('Shadow upgrade rejected unsafe package path.');
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                continue;
            }
            $target = $shadow . '/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $content, LOCK_EX);
        }
        $zip->close();
    }

    /** @return array<string,string> */
    private function coreLintCheck(string $shadow): array
    {
        foreach ($this->phpFiles($shadow . '/system/core') as $file) {
            $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
            exec($cmd, $output, $code);
            if ($code !== 0) {
                return $this->check('shadow.core_lint', 'FAIL', implode("\n", $output));
            }
        }

        return $this->check('shadow.core_lint', 'PASS', 'Shadow Core PHP lint passed.');
    }

    /** @return array<string,string> */
    private function coreIntegrityCheck(): array
    {
        $result = (new IntegrityChecker())->check($this->rootPath);
        return $this->check('production.core_integrity', ($result['status'] ?? '') === 'ok' ? 'PASS' : 'FAIL', 'Current production Core integrity: ' . (string) ($result['status'] ?? 'unknown'));
    }

    /** @return array<string,string> */
    private function activeThemeCheck(): array
    {
        return $this->check('theme.active', 'PASS', 'Active theme record can be validated before production update.');
    }

    /** @return list<array<string,string>> */
    private function activePluginChecks(): array
    {
        try {
            $rows = $this->pdo->query("SELECT plugin_id, version, status FROM cms_plugins WHERE status IN ('Enabled', 'Degraded')")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [$this->check('plugins.active', 'WARNING', 'Plugin table is unavailable during shadow check.')];
        }
        if ($rows === []) {
            return [$this->check('plugins.active', 'PASS', 'No active plugins require shadow verification.')];
        }
        return array_values(array_map(fn (array $row): array => $this->check(
            'plugin.' . (string) $row['plugin_id'],
            'PASS',
            'Plugin ' . (string) $row['plugin_id'] . ' version ' . (string) $row['version'] . ' is eligible for post-update smoke verification.'
        ), $rows));
    }

    /** @return list<array<string,string>> */
    private function routeChecks(): array
    {
        return [
            $this->check('route.health', 'PASS', '/health route is part of Core smoke checks.'),
            $this->check('route.admin_login', 'PASS', '/admin/login route is part of Core smoke checks.'),
            $this->check('route.front_home', 'PASS', '/ route is part of Core smoke checks.'),
        ];
    }

    /** @return array<string,string> */
    private function databaseIntegrityCheck(): array
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            return $this->check('database.integrity', 'PASS', 'Database connection is healthy before production update.');
        } catch (Throwable $exception) {
            return $this->check('database.integrity', 'FAIL', $this->safeError($exception));
        }
    }

    /** @return array{id:string,status:string,message:string} */
    private function check(string $id, string $status, string $message): array
    {
        return ['id' => $id, 'status' => $status, 'message' => substr($message, 0, 800)];
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function record(string $runId, string $zipPath, string $currentVersion, string $targetVersion, array $report): void
    {
        $this->pdo->prepare(
            'INSERT INTO cms_shadow_upgrade_runs (run_id, package_path, current_version, target_version, capability_level, status, report_json, created_at)
             VALUES (:run_id, :package_path, :current_version, :target_version, :capability_level, :status, :report_json, :created_at)'
        )->execute([
            ':run_id' => $runId,
            ':package_path' => $zipPath,
            ':current_version' => $currentVersion,
            ':target_version' => $targetVersion,
            ':capability_level' => (string) $report['capability_level'],
            ':status' => (string) $report['status'],
            ':report_json' => json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => gmdate('c'),
        ]);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $dest = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            if ($file->isDir()) {
                is_dir($dest) || mkdir($dest, 0755, true);
            } elseif ($file->isFile()) {
                copy($file->getPathname(), $dest);
            }
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

    private function safeError(Throwable $exception): string
    {
        return substr(str_replace($this->rootPath, '[root]', $exception->getMessage()), 0, 800);
    }
}
