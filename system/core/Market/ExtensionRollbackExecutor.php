<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionRollbackExecutor
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ExtensionRollbackRepository $repository,
    ) {
    }

    /** @return array<string, mixed> */
    public function restore(string $id): array
    {
        $artifact = $this->repository->find($id);
        if ($artifact === null) {
            throw new MarketException('Rollback artifact was not found.');
        }

        $snapshot = (string) ($artifact['previous_snapshot'] ?? '');
        $target = (string) ($artifact['target_dir'] ?? ($artifact['result']['target_dir'] ?? ''));
        if ($snapshot === '' || !is_dir($snapshot)) {
            throw new MarketException('Rollback snapshot is missing.');
        }
        if (!$this->isInsideRoot($snapshot) || !$this->isAllowedExtensionTarget($target)) {
            throw new MarketException('Rollback paths are outside allowed boundaries.');
        }

        $backup = $this->rootPath . '/storage/market/rollback-active-backups/' . basename($target) . '-' . bin2hex(random_bytes(4));
        if (!is_dir(dirname($backup))) {
            mkdir(dirname($backup), 0755, true);
        }

        try {
            if (is_dir($target) && !rename($target, $backup)) {
                throw new MarketException('Unable to backup current extension before rollback.');
            }
            $this->copyDirectory($snapshot, $target);
            $result = [
                'status' => 'Restored',
                'rollback_id' => $id,
                'target_dir' => $target,
                'backup_dir' => $backup,
                'restored_at' => gmdate('c'),
            ];
            $this->repository->markRestore($id, 'Restored', $result);

            return $result;
        } catch (\Throwable $exception) {
            if (!is_dir($target) && is_dir($backup)) {
                rename($backup, $target);
            }
            $this->repository->markRestore($id, 'Failed', ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function isInsideRoot(string $path): bool
    {
        $realRoot = realpath($this->rootPath);
        $realPath = realpath($path);

        return is_string($realRoot) && is_string($realPath) && str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
    }

    private function isAllowedExtensionTarget(string $path): bool
    {
        $realRoot = realpath($this->rootPath);
        $parent = realpath(dirname($path));
        if (!is_string($realRoot) || !is_string($parent)) {
            return false;
        }
        $plugins = $realRoot . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'plugins';
        $themes = $realRoot . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'themes';

        return $parent === $plugins || $parent === $themes;
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
