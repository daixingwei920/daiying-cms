<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_carts (
        id $id,
        token VARCHAR(64) NOT NULL UNIQUE,
        location_id BIGINT NULL,
        service_mode VARCHAR(32) NOT NULL DEFAULT 'pickup',
        status VARCHAR(32) NOT NULL DEFAULT 'open',
        customer_name VARCHAR(191) NULL,
        customer_phone VARCHAR(64) NULL,
        customer_email VARCHAR(191) NULL,
        delivery_address VARCHAR(500) NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'CNY',
        subtotal_minor BIGINT NOT NULL DEFAULT 0,
        fee_minor BIGINT NOT NULL DEFAULT 0,
        total_minor BIGINT NOT NULL DEFAULT 0,
        commerce_product_id BIGINT NULL,
        commerce_action_id BIGINT NULL,
        commerce_order_id BIGINT NULL,
        payment_provider_id VARCHAR(96) NULL,
        idempotency_key VARCHAR(128) NULL,
        completion_claim VARCHAR(128) NULL,
        payload_json $text NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        submitted_at DATETIME NULL,
        paid_at DATETIME NULL
    )");
    $createIndex($pdo, 'restaurant_carts', 'idx_restaurant_carts_status', '(status, updated_at)');
    $createIndex($pdo, 'restaurant_carts', 'idx_restaurant_carts_order', '(commerce_order_id)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_cart_items (
        id $id,
        cart_id BIGINT NOT NULL,
        menu_item_id BIGINT NOT NULL,
        item_name VARCHAR(191) NOT NULL,
        category VARCHAR(191) NULL,
        station_code VARCHAR(64) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price_minor BIGINT NOT NULL DEFAULT 0,
        modifiers_total_minor BIGINT NOT NULL DEFAULT 0,
        line_total_minor BIGINT NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'CNY',
        notes VARCHAR(500) NULL,
        modifiers_json $json NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_cart_items', 'idx_restaurant_cart_items_cart', '(cart_id, id)');
    $createIndex($pdo, 'restaurant_cart_items', 'idx_restaurant_cart_items_menu', '(menu_item_id)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS restaurant_cart_items');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_carts');
};

return [
    'id' => 'official_restaurant_002_checkout',
    'affected_objects' => [
        'table:restaurant_carts',
        'table:restaurant_cart_items',
    ],
    'up' => $up,
    'down' => $rollback,
];
