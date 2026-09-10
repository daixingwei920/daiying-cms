<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = [];
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(affiliate_connections)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['name']] = true;
        }
    } else {
        $stmt = $pdo->query('SHOW COLUMNS FROM affiliate_connections');
        foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['Field']] = true;
        }
    }

    if (!isset($columns['last_rate_limited_at'])) {
        $pdo->exec('ALTER TABLE affiliate_connections ADD COLUMN last_rate_limited_at DATETIME NULL');
    }
};

$down = static function (PDO $pdo): void {
    unset($pdo);
};

return [
    'id' => 'official_affiliate_hub_002_cj_adapter',
    'affected_objects' => [
        'table:affiliate_connections',
    ],
    'up' => $up,
    'down' => $down,
];
