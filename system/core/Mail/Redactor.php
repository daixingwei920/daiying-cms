<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class Redactor
{
    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (preg_match('/password|secret|token|authorization|oauth|api[_-]?key|app[_-]?password|cookie|private/i', (string) $key) === 1) {
                    $clean[$key] = '[redacted]';
                    continue;
                }
                $clean[$key] = self::redact($item);
            }
            return $clean;
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = preg_replace('/(?:bearer\s+|sk-[A-Za-z0-9_-]+|api[_-]?key=|access[_-]?token=|refresh[_-]?token=|password=|secret=|authorization=)[^\s"\']*/i', '[redacted]', $value) ?: $value;
        return strlen($value) > 1000 ? substr($value, 0, 1000) : $value;
    }
}
