<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class VersionConstraint
{
    public static function matches(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s*,\s*/', $constraint) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|=)?\s*(.+)$/', $part, $matches) !== 1) {
                return false;
            }
            $operator = $matches[1] !== '' ? $matches[1] : '=';
            if (!version_compare($version, $matches[2], $operator)) {
                return false;
            }
        }

        return true;
    }
}
