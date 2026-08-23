<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use PDO;

final class ExtensionUpdateJobRunner
{
    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $pdo,
        private readonly MarketJobRepositoryInterface $jobs,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function runNext(?MarketWorkerLock $lock = null): ?array
    {
        if ($lock !== null && !$lock->acquire()) {
            return ['status' => 'Locked', 'message' => 'Another market worker is already running.'];
        }

        $id = '';
        $job = $this->jobs->claimNext();
        try {
            if ($job === null) {
                return null;
            }

            $id = (string) $job['id'];
            $lock?->heartbeat();
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $result = match ((string) ($job['type'] ?? '')) {
                'extension_update' => $this->runExtensionUpdate($payload),
                'extension_rollback' => $this->runExtensionRollback($payload),
                default => throw new MarketException('Unsupported market job type.'),
            };
            $this->jobs->markComplete($id, $result);

            return $result + ['job_id' => $id];
        } catch (\Throwable $exception) {
            if ($id !== '') {
                $this->jobs->markFailed($id, $exception->getMessage());
            }

            return ['job_id' => $id, 'status' => 'Failed', 'error' => $exception->getMessage()];
        } finally {
            $lock?->release();
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function runExtensionUpdate(array $payload): array
    {
        $repo = new MarketInstallRepository($this->pdo);
        $preflight = (new ExtensionUpdatePreflight($repo))->check(
            (string) ($payload['extension_id'] ?? ''),
            (string) ($payload['type'] ?? 'plugin'),
            (string) ($payload['version'] ?? ''),
        );

        $progress = new DownloadProgressRepository($this->rootPath . '/storage/market/download-progress.json');
        $package = (new RemotePackageDownloader($this->rootPath . '/storage/market/tmp', [], $progress))->download(
            (string) ($payload['package_url'] ?? ''),
            (string) ($payload['package_sha256'] ?? ''),
        );
        $snapshot = $this->snapshotCurrentExtension($payload);

        $authorization = new InstallAuthorization(
            'queued-update',
            (string) ($payload['market_id'] ?? ''),
            gmdate('c', time() + 300),
            (string) ($payload['package_sha256'] ?? ''),
        );
        $result = (new MarketPackageInstaller($this->rootPath))->install($package, $authorization, $this->pdo);
        $this->recordRollback($result, $snapshot);

        return ['status' => 'Completed', 'preflight' => $preflight, 'install' => $result];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function runExtensionRollback(array $payload): array
    {
        $repo = new ExtensionRollbackRepository($this->rootPath . '/storage/market/rollback', $this->rootPath . '/storage/market/rollback-restores');
        $result = (new ExtensionRollbackExecutor($this->rootPath, $repo))->restore((string) ($payload['rollback_id'] ?? ''));

        return ['status' => 'Completed', 'rollback' => $result];
    }

    /** @param array<string, mixed> $payload */
    private function snapshotCurrentExtension(array $payload): ?string
    {
        $extensionId = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($payload['extension_id'] ?? '')) ?? '';
        $type = (string) ($payload['type'] ?? 'plugin');
        if ($extensionId === '') {
            return null;
        }
        $target = $this->rootPath . '/content/' . ($type === 'theme' ? 'themes' : 'plugins') . '/' . $extensionId;
        if (!is_dir($target)) {
            return null;
        }
        $snapshot = $this->rootPath . '/storage/market/rollback-snapshots/' . $extensionId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $this->copyDirectory($target, $snapshot);

        return $snapshot;
    }

    /** @param array<string, mixed> $result */
    private function recordRollback(array $result, ?string $snapshot): void
    {
        $dir = $this->rootPath . '/storage/market/rollback';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $id = (string) ($result['extension_id'] ?? 'extension') . '-' . date('YmdHis');
        file_put_contents($dir . '/' . $id . '.json', json_encode([
            'created_at' => gmdate('c'),
            'previous_snapshot' => $snapshot,
            'target_dir' => (string) ($result['target_dir'] ?? ''),
            'result' => $result,
            'note' => $snapshot === null ? 'No previous extension directory existed before update.' : 'Previous extension directory snapshot can be restored by rollback worker.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $destination = $target . DIRECTORY_SEPARATOR . $items->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }
                continue;
            }
            copy((string) $item->getPathname(), $destination);
        }
    }
}
