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
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MarketException('Market install lock directory is unavailable.');
        }

        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new MarketException('Market install lock file is unavailable.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
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

        ftruncate($this->handle, 0);
        fflush($this->handle);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
