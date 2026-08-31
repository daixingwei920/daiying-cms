<?php

declare(strict_types=1);

namespace Cms\Core\Recovery;

final class RunMode
{
    public const NORMAL = 'NORMAL';
    public const SAFE = 'SAFE';
    public const RECOVERY = 'RECOVERY';

    public static function detect(string $rootPath, string $configured): string
    {
        if (is_file($rootPath . '/storage/recovery.mode')) {
            return self::RECOVERY;
        }

        if (is_file($rootPath . '/storage/safe.mode')) {
            return self::SAFE;
        }

        return in_array($configured, [self::NORMAL, self::SAFE, self::RECOVERY], true) ? $configured : self::NORMAL;
    }

    public static function safeLoadsPlugins(string $mode): bool
    {
        return $mode === self::NORMAL;
    }
}
