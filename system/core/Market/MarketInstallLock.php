<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketInstallLock
{
    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(private readonly string $lockPath)
    {
    }

    public function acquire(): void
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($this->lockPath, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new MarketException('Another market install is already running.');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        @unlink($this->lockPath);
        $this->handle = null;
    }
}
