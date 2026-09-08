<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiToolRisk
{
    public const READ = 'READ';
    public const WRITE = 'WRITE';
    public const DESTRUCTIVE = 'DESTRUCTIVE';
    public const SENSITIVE = 'SENSITIVE';

    public static function isValid(string $risk): bool
    {
        return in_array($risk, [self::READ, self::WRITE, self::DESTRUCTIVE, self::SENSITIVE], true);
    }
}
