<?php

declare(strict_types=1);

namespace Cms\Core\Security;

final class CsrfToken
{
    public static function get(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function verify(mixed $token): bool
    {
        return is_string($token) && hash_equals(self::get(), $token);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::escape(self::get()) . '">';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
