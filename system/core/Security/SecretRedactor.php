<?php

declare(strict_types=1);

namespace Cms\Core\Security;

final class SecretRedactor
{
    private const SECRET_KEY_PATTERN = '/password|secret|token|session|private_key|dsn|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|cookie/i';

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (preg_match(self::SECRET_KEY_PATTERN, (string) $key) === 1) {
                    $clean[$key] = '[redacted]';
                    continue;
                }
                $clean[$key] = self::redact($item);
            }

            return $clean;
        }
        if (is_string($value)) {
            $value = preg_replace('/\b(password|secret|token|session|private_key|dsn|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|cookie)\b\s*[:=]\s*([^\s"\']+)/i', '$1=[redacted]', $value) ?: $value;
            $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $value) ?: $value;
            $value = preg_replace('/(sk|pk|whsec|xox[baprs]|gh[pousr])_[A-Za-z0-9_=-]{4,}/i', '$1_[redacted]', $value) ?: $value;

            return strlen($value) > 1000 ? substr($value, 0, 1000) : $value;
        }

        return $value;
    }
}
