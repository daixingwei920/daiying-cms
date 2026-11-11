<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(restaurant_print_jobs)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');
    } else {
        $columns = $pdo->query('SHOW COLUMNS FROM restaurant_print_jobs')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'Field');
    }
    if (!in_array('claim_token_hash', $names, true)) {
        $pdo->exec('ALTER TABLE restaurant_print_jobs ADD COLUMN claim_token_hash VARCHAR(64) NULL');
    }
    if (!in_array('claim_expires_at', $names, true)) {
        $pdo->exec('ALTER TABLE restaurant_print_jobs ADD COLUMN claim_expires_at DATETIME NULL');
    }
    $duplicates = $pdo->query('SELECT commerce_order_id FROM restaurant_order_tickets WHERE commerce_order_id IS NOT NULL GROUP BY commerce_order_id HAVING COUNT(*) > 1 LIMIT 1');
    if ($duplicates !== false && $duplicates->fetch() !== false) {
        throw new RuntimeException('已有重复 Commerce 厨房票，须人工核对后才能升级。');
    }
    if ($driver === 'sqlite') {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_restaurant_ticket_commerce_order ON restaurant_order_tickets (commerce_order_id)');
    } else {
        $index = $pdo->query("SHOW INDEX FROM restaurant_order_tickets WHERE Key_name = 'uq_restaurant_ticket_commerce_order'");
        if ($index !== false && $index->fetch() === false) {
            $pdo->exec('CREATE UNIQUE INDEX uq_restaurant_ticket_commerce_order ON restaurant_order_tickets (commerce_order_id)');
        }
    }
};

return [
    'id' => 'official_restaurant_003_agent_claims',
    'affected_objects' => ['table:restaurant_print_jobs'],
    'up' => $up,
    'down' => static function (PDO $pdo): void {},
];
