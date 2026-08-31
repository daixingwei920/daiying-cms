<?php

declare(strict_types=1);

namespace Cms\Core\Database;

use Cms\Core\Config\Settings;
use PDO;

final class ConnectionFactory
{
    public static function make(Settings $settings): PDO
    {
        $dsn = (string) $settings->get('database.dsn', '');
        if ($dsn === '') {
            throw new DatabaseException('Database DSN is not configured.');
        }

        $options = $settings->get('database.options', []);
        if (!is_array($options)) {
            $options = [];
        }

        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        return new PDO(
            $dsn,
            (string) $settings->get('database.username', ''),
            (string) $settings->get('database.password', ''),
            $options + $defaults
        );
    }
}
