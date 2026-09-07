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

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_distribution_channels (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        provider_type VARCHAR(32) NOT NULL DEFAULT 'manual_share',
        mode VARCHAR(32) NOT NULL DEFAULT 'manual',
        status VARCHAR(32) NOT NULL DEFAULT 'disabled',
        sort_order INT NOT NULL DEFAULT 0,
        config_json $json NULL,
        last_sync_status VARCHAR(32) NULL,
        last_sync_message VARCHAR(500) NULL,
        last_synced_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'commerce_distribution_channels', 'idx_commerce_distribution_channels_status', '(status, mode, sort_order)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_distribution_events (
        id $id,
        channel_id BIGINT NULL,
        product_id BIGINT NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        status VARCHAR(32) NOT NULL,
        external_listing_id VARCHAR(191) NULL,
        message VARCHAR(500) NULL,
        payload_json $json NULL,
        created_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'commerce_distribution_events', 'idx_commerce_distribution_events_product', '(product_id, created_at)');
    $createIndex($pdo, 'commerce_distribution_events', 'idx_commerce_distribution_events_channel', '(channel_id, created_at)');
};

$rollback = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS commerce_distribution_events');
    $pdo->exec('DROP TABLE IF EXISTS commerce_distribution_channels');
};

return [
    'id' => 'commerce_006_distribution_modules',
    'affected_objects' => [
        'table:commerce_distribution_channels',
        'table:commerce_distribution_events',
    ],
    'up' => $up,
    'down' => $rollback,
];
