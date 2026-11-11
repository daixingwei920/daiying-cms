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

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_locations (
        id $id,
        name VARCHAR(191) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        phone VARCHAR(64) NULL,
        address VARCHAR(500) NULL,
        timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai',
        settings_json $json NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_kitchen_stations (
        id $id,
        location_id BIGINT NULL,
        name VARCHAR(191) NOT NULL,
        code VARCHAR(64) NOT NULL UNIQUE,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_kitchen_stations', 'idx_restaurant_stations_status', '(status, sort_order)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_print_devices (
        id $id,
        location_id BIGINT NULL,
        name VARCHAR(191) NOT NULL,
        provider VARCHAR(32) NOT NULL DEFAULT 'manual',
        status VARCHAR(32) NOT NULL DEFAULT 'enabled',
        station_codes_json $json NULL,
        config_json $json NULL,
        last_test_status VARCHAR(32) NULL,
        last_test_message VARCHAR(500) NULL,
        last_tested_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_print_devices', 'idx_restaurant_printers_provider', '(provider, status)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_menu_items (
        id $id,
        commerce_product_id BIGINT NULL,
        name VARCHAR(191) NOT NULL,
        category VARCHAR(191) NULL,
        price_minor BIGINT NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'CNY',
        station_code VARCHAR(64) NULL,
        prep_minutes INT NOT NULL DEFAULT 10,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        modifiers_json $json NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_menu_items', 'idx_restaurant_menu_station', '(station_code, status)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_order_tickets (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        commerce_order_id BIGINT NULL,
        ticket_number VARCHAR(64) NOT NULL UNIQUE,
        location_id BIGINT NULL,
        customer_name VARCHAR(191) NULL,
        customer_phone VARCHAR(64) NULL,
        service_mode VARCHAR(32) NOT NULL DEFAULT 'pickup',
        status VARCHAR(32) NOT NULL DEFAULT 'new',
        source VARCHAR(32) NOT NULL DEFAULT 'manual',
        due_at DATETIME NULL,
        payload_json $text NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_order_tickets', 'idx_restaurant_tickets_status', '(status, created_at)');
    $createIndex($pdo, 'restaurant_order_tickets', 'idx_restaurant_tickets_order', '(commerce_order_id)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_ticket_items (
        id $id,
        ticket_id BIGINT NOT NULL,
        menu_item_id BIGINT NULL,
        commerce_product_id BIGINT NULL,
        station_code VARCHAR(64) NOT NULL,
        item_name VARCHAR(191) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        notes VARCHAR(500) NULL,
        modifiers_json $json NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_ticket_items', 'idx_restaurant_items_ticket', '(ticket_id, station_code)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_print_jobs (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        ticket_id BIGINT NOT NULL,
        printer_id BIGINT NULL,
        station_code VARCHAR(64) NOT NULL,
        provider VARCHAR(32) NOT NULL DEFAULT 'manual',
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        attempts INT NOT NULL DEFAULT 0,
        payload_text $text NOT NULL,
        last_error VARCHAR(500) NULL,
        printed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'restaurant_print_jobs', 'idx_restaurant_print_jobs_status', '(status, created_at)');
    $createIndex($pdo, 'restaurant_print_jobs', 'idx_restaurant_print_jobs_ticket', '(ticket_id, station_code)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS restaurant_print_jobs');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_ticket_items');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_order_tickets');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_menu_items');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_print_devices');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_kitchen_stations');
    $pdo->exec('DROP TABLE IF EXISTS restaurant_locations');
};

return [
    'id' => 'official_restaurant_001_core',
    'affected_objects' => [
        'table:restaurant_locations',
        'table:restaurant_kitchen_stations',
        'table:restaurant_print_devices',
        'table:restaurant_menu_items',
        'table:restaurant_order_tickets',
        'table:restaurant_ticket_items',
        'table:restaurant_print_jobs',
    ],
    'up' => $up,
    'down' => $rollback,
];
