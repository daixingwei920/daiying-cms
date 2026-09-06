<?php

declare(strict_types=1);

namespace Cms\Core\Security;

final class PasswordHasher
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
