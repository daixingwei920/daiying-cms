<?php

declare(strict_types=1);

namespace Cms\Core\Recovery;

use Cms\Core\Plugin\PluginLifecycle;
use PDO;

final class RecoveryActions
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?PDO $pdo = null,
    ) {
    }

    public function enableSafeMode(): void
    {
        $path = $this->rootPath . '/storage/safe.mode';
        file_put_contents($path, gmdate('c') . PHP_EOL, LOCK_EX);
        @chmod($path, 0600);
    }

    public function disableSafeMode(): void
    {
        @unlink($this->rootPath . '/storage/safe.mode');
    }

    public function enableRecoveryMode(): void
    {
        $path = $this->rootPath . '/storage/recovery.mode';
        file_put_contents($path, gmdate('c') . PHP_EOL, LOCK_EX);
        @chmod($path, 0600);
    }

    public function disableRecoveryMode(): void
    {
        @unlink($this->rootPath . '/storage/recovery.mode');
    }

    public function disableThirdPartyPlugins(): int
    {
        if ($this->pdo === null) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cms_plugins SET status = :disabled, updated_at = :updated_at
             WHERE plugin_id NOT LIKE :official AND status = :enabled'
        );
        $stmt->execute([
            ':disabled' => PluginLifecycle::DISABLED,
            ':enabled' => PluginLifecycle::ENABLED,
            ':official' => 'core_%',
            ':updated_at' => gmdate('c'),
        ]);

        return $stmt->rowCount();
    }

    public function clearCache(): int
    {
        return $this->clearDirectory($this->rootPath . '/storage/cache');
    }

    private function clearDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
                $count++;
            }
        }

        return $count;
    }
}
