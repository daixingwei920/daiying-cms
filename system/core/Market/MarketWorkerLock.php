<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketWorkerLock
{
    /** @var resource|null */
    private $handle = null;
    private string $owner = '';

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->handle = fopen($this->path, 'c+');
        if ($this->handle === false) {
            $this->handle = null;
            return false;
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }
        $this->owner = (string) getmypid();
        $this->heartbeat();

        return true;
    }

    public function heartbeat(): void
    {
        if ($this->handle === null) {
            return;
        }
        ftruncate($this->handle, 0);
        rewind($this->handle);
        fwrite($this->handle, json_encode([
            'owner' => $this->owner,
            'heartbeat_at' => gmdate('c'),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        fflush($this->handle);
    }

    /** @return array{locked:bool,stale:bool,owner:string,heartbeat_at:string,age_seconds:int} */
    public function status(int $timeoutSeconds = 300): array
    {
        if (!is_file($this->path)) {
            return ['locked' => false, 'stale' => false, 'owner' => '', 'heartbeat_at' => '', 'age_seconds' => 0];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        $heartbeat = is_array($decoded) ? (string) ($decoded['heartbeat_at'] ?? '') : '';
        $owner = is_array($decoded) ? (string) ($decoded['owner'] ?? '') : trim((string) file_get_contents($this->path));
        $time = $heartbeat === '' ? (int) @filemtime($this->path) : (int) strtotime($heartbeat);
        $age = max(0, time() - $time);

        return [
            'locked' => true,
            'stale' => $age > max(1, $timeoutSeconds),
            'owner' => $owner,
            'heartbeat_at' => $heartbeat,
            'age_seconds' => $age,
        ];
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function reclaimIfStale(int $timeoutSeconds = 300): bool
    {
        $status = $this->status($timeoutSeconds);
        if (!$status['locked'] || !$status['stale']) {
            return false;
        }
        @unlink($this->path);

        return true;
    }
}
