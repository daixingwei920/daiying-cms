<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
    $createIndex = static function (PDO $pdo, string $table, string $index, string $columns) use ($driver): void {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . $columns);
            return;
        }
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . $columns);
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_logistics_events (
        id $id,
        order_id BIGINT NOT NULL,
        status VARCHAR(32) NOT NULL,
        carrier VARCHAR(96) NULL,
        tracking_number VARCHAR(128) NULL,
        provider VARCHAR(96) NOT NULL DEFAULT 'manual',
        raw_status VARCHAR(191) NULL,
        raw_payload_json $json NULL,
        message VARCHAR(500) NULL,
        occurred_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'commerce_logistics_events', 'idx_commerce_logistics_order', '(order_id, occurred_at)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS commerce_logistics_events');
};

return [
    'id' => 'commerce_002_logistics_events',
    'affected_objects' => [
        'table:commerce_logistics_events',
    ],
    'up' => $up,
    'down' => $rollback,
];
