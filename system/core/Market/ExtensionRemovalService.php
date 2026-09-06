<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use PDO;

final class ExtensionRemovalService
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function uninstall(string $extensionId, string $type, PDO $pdo): void
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $extensionId)) {
            throw new MarketException('Invalid extension id.');
        }
        if (!in_array($type, ['plugin', 'theme'], true)) {
            throw new MarketException('Extension type must be plugin or theme.');
        }

        $lock = new MarketInstallLock($this->rootPath . '/storage/market/install.lock');
        $lock->acquire();
        try {
            $conflicts = (new ExtensionConflictDetector(new MarketInstallRepository($pdo)))->uninstallConflicts($extensionId, $type);
            if ($conflicts !== []) {
                throw new MarketException('Extension is required by: ' . implode(', ', $conflicts));
            }
            $target = $type === 'plugin'
                ? $this->rootPath . '/content/plugins/' . $extensionId
                : $this->rootPath . '/content/themes/' . $extensionId;
            if (is_dir($target)) {
                $this->removeDirectory($target);
            }
            (new MarketInstallRepository($pdo))->markUninstalled($extensionId, $type);
        } finally {
            $lock->release();
        }
    }

    private function removeDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
