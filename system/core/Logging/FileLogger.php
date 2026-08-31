<?php

declare(strict_types=1);

namespace Cms\Core\Logging;

final class FileLogger
{
    public function __construct(private readonly string $path)
    {
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
            'message' => $message,
            'context' => $this->sanitize($context),
        ];

        file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $keyString = (string) $key;
                if (preg_match('/password|secret|token|session|private_key|dsn/i', $keyString)) {
                    $clean[$key] = '[redacted]';
                    continue;
                }
                $clean[$key] = $this->sanitize($item);
            }
            return $clean;
        }
        if (is_string($value)) {
            $value = preg_replace('/\b(password|secret|token|session|private_key|dsn)\b\s*[:=]\s*([^\s"\']+)/i', '$1=[redacted]', $value) ?: $value;
            $value = preg_replace('/[A-Z]:[\\\\\\/][^\s"]+|\/[^\s"]+/', '[path]', $value) ?: $value;
            return strlen($value) > 500 ? substr($value, 0, 500) : $value;
        }
        return $value;
    }
}
