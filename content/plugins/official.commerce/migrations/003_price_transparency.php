<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columnExists = static function (PDO $pdo, string $table, string $column) use ($driver): bool {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            if ($stmt === false) {
                return false;
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $pdo->quote($column));
        return $stmt !== false && $stmt->fetch() !== false;
    };
    $addColumn = static function (PDO $pdo, string $table, string $column, string $definition) use ($columnExists): void {
        if ($columnExists($pdo, $table, $column)) {
            return;
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    };

    $addColumn($pdo, 'commerce_products', 'transaction_region', "VARCHAR(32) NOT NULL DEFAULT 'cn_domestic'");
    $addColumn($pdo, 'commerce_products', 'shipping_fee_minor', 'BIGINT NOT NULL DEFAULT 0');
    $addColumn($pdo, 'commerce_products', 'tax_fee_minor', 'BIGINT NOT NULL DEFAULT 0');
    $addColumn($pdo, 'commerce_products', 'service_fee_minor', 'BIGINT NOT NULL DEFAULT 0');
    $addColumn($pdo, 'commerce_products', 'discount_minor', 'BIGINT NOT NULL DEFAULT 0');
    $addColumn($pdo, 'commerce_products', 'price_note', 'VARCHAR(500) NULL');
};

$rollback = static function (PDO $pdo): void {
    unset($pdo);
};

return [
    'id' => 'commerce_003_price_transparency',
    'affected_objects' => [
        'table:commerce_products',
    ],
    'up' => $up,
    'down' => $rollback,
];
