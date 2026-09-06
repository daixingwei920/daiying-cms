<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
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

    $addColumn($pdo, 'commerce_products', 'source_claim_text', 'VARCHAR(500) NULL');
    $addColumn($pdo, 'commerce_products', 'source_declared_at', 'DATETIME NULL');
    $addColumn($pdo, 'commerce_verification_records', 'record_type', "VARCHAR(32) NOT NULL DEFAULT 'provider_result'");
    $addColumn($pdo, 'commerce_verification_records', 'requested_status', 'VARCHAR(32) NULL');
    $addColumn($pdo, 'commerce_verification_records', 'effective_status', 'VARCHAR(32) NULL');
    $addColumn($pdo, 'commerce_verification_records', 'requester_id', 'BIGINT NULL');
    $addColumn($pdo, 'commerce_verification_records', 'source_fingerprint', 'VARCHAR(64) NULL');
    $addColumn($pdo, 'commerce_verification_records', 'product_fingerprint', 'VARCHAR(64) NULL');
    $addColumn($pdo, 'commerce_verification_records', 'related_change_ids_json', $json . ' NULL');
};

$rollback = static function (PDO $pdo): void {
    unset($pdo);
};

return [
    'id' => 'commerce_004_source_verification_governance',
    'affected_objects' => [
        'table:commerce_products',
        'table:commerce_verification_records',
    ],
    'up' => $up,
    'down' => $rollback,
];
