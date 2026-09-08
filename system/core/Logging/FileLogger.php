<?php

declare(strict_types=1);

namespace Cms\Core\Logging;

use Cms\Core\Security\SecretRedactor;

final class FileLogger
{
    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = 5242880,
        private readonly int $maxArchives = 5,
    ) {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $record = [
            'time' => gmdate('c'),
            'level' => $level,
            'message' => $this->sanitize($message),
            'context' => $this->sanitize($context),
        ];

        $this->rotateIfNeeded();
        file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public function rotateIfNeeded(): bool
    {
        if ($this->maxBytes < 1024 || !is_file($this->path) || filesize($this->path) < $this->maxBytes) {
            return false;
        }

        for ($i = max(1, $this->maxArchives); $i >= 1; $i--) {
            $source = $this->path . '.' . $i;
            $target = $this->path . '.' . ($i + 1);
            if ($i >= $this->maxArchives && is_file($source)) {
                @unlink($source);
                continue;
            }
            if (is_file($source)) {
                @rename($source, $target);
            }
        }
        @rename($this->path, $this->path . '.1');

        return true;
    }

    public function cleanup(int $retentionDays = 30): int
    {
        $retentionDays = max(1, min(3650, $retentionDays));
        $cutoff = time() - ($retentionDays * 86400);
        $deleted = 0;
        foreach (glob($this->path . '.*') ?: [] as $archive) {
            if (!is_file($archive)) {
                continue;
            }
            $mtime = filemtime($archive);
            if ($mtime !== false && $mtime < $cutoff && @unlink($archive)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function sanitize(mixed $value): mixed
    {
        $value = SecretRedactor::redact($value);
        if (is_string($value)) {
            $value = preg_replace('/[A-Z]:[\\\\\\/][^\s"]+|\/[^\s"]+/', '[path]', $value) ?: $value;
            return strlen($value) > 500 ? substr($value, 0, 500) : $value;
        }

        return $value;
    }
}
