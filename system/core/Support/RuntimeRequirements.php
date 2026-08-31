<?php

declare(strict_types=1);

namespace Cms\Core\Support;

final class RuntimeRequirements
{
    public const PHP_MIN = '8.3.0';

    /** @return list<string> */
    public static function requiredExtensions(): array
    {
        return ['pdo', 'json', 'openssl', 'fileinfo', 'zip'];
    }
}
