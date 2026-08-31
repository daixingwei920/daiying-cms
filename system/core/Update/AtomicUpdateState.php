<?php

declare(strict_types=1);

namespace Cms\Core\Update;

final class AtomicUpdateState
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @param array<string, mixed> $plan */
    public function markPrepared(array $plan): void
    {
        $this->write('prepared.json', $plan);
    }

    /** @param array<string, mixed> $plan */
    public function markSwitched(array $plan): void
    {
        $this->write('switched.json', $plan + ['switched_at' => gmdate('c')]);
    }

    public function markRollback(string $packageId, string $reason): void
    {
        $this->write('rollback-' . $packageId . '.json', [
            'package_id' => $packageId,
            'reason' => $reason,
            'rolled_back_at' => gmdate('c'),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function write(string $name, array $payload): void
    {
        $dir = $this->rootPath . '/storage/updates/history';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $name;
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0600);
    }
}
