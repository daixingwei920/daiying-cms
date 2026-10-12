<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use PDO;
use WeakMap;

final class PaymentTransaction
{
    private static ?WeakMap $sqliteTransactions = null;

    public static function isActive(PDO $pdo): bool
    {
        return $pdo->inTransaction() || (self::$sqliteTransactions[$pdo] ?? false);
    }

    public static function beginImmediate(PDO $pdo): void
    {
        if (self::isActive($pdo)) {
            return;
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
            self::$sqliteTransactions ??= new WeakMap();
            self::$sqliteTransactions[$pdo] = true;
            return;
        }
        $pdo->beginTransaction();
    }

    public static function commit(PDO $pdo): void
    {
        if (self::$sqliteTransactions[$pdo] ?? false) {
            $pdo->exec('COMMIT');
            unset(self::$sqliteTransactions[$pdo]);
            return;
        }
        $pdo->commit();
    }

    public static function rollback(PDO $pdo): void
    {
        if (self::$sqliteTransactions[$pdo] ?? false) {
            $pdo->exec('ROLLBACK');
            unset(self::$sqliteTransactions[$pdo]);
            return;
        }
        $pdo->rollBack();
    }
}
