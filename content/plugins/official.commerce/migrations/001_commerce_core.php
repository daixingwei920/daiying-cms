<?php

declare(strict_types=1);

$migration = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_products (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        sku VARCHAR(96) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        slug VARCHAR(191) NOT NULL UNIQUE,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        summary VARCHAR(500) NULL,
        description_content_id BIGINT NULL,
        primary_media_id BIGINT NULL,
        gallery_media_ids_json $json NULL,
        price_minor BIGINT NOT NULL,
        currency VARCHAR(3) NOT NULL,
        region VARCHAR(16) NOT NULL DEFAULT 'CN',
        brand VARCHAR(191) NULL,
        model VARCHAR(191) NULL,
        source_url VARCHAR(1024) NULL,
        specs_json $json NULL,
        requires_shipping INT NOT NULL DEFAULT 0,
        auto_delivery_enabled INT NOT NULL DEFAULT 0,
        stock_quantity BIGINT NOT NULL DEFAULT 0,
        reserved_quantity BIGINT NOT NULL DEFAULT 0,
        sold_quantity BIGINT NOT NULL DEFAULT 0,
        verification_status VARCHAR(32) NOT NULL DEFAULT 'not_provided',
        key_fingerprint VARCHAR(64) NOT NULL,
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_products_status ON commerce_products(status, updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_products_currency ON commerce_products(currency, price_minor)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_variants (
        id $id,
        product_id BIGINT NOT NULL,
        sku VARCHAR(96) NOT NULL,
        title VARCHAR(191) NOT NULL,
        options_json $json NULL,
        price_delta_minor BIGINT NOT NULL DEFAULT 0,
        stock_quantity BIGINT NOT NULL DEFAULT 0,
        reserved_quantity BIGINT NOT NULL DEFAULT 0,
        sold_quantity BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(product_id, sku)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_variants_product ON commerce_variants(product_id, status, sort_order)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_actions (
        id $id,
        product_id BIGINT NOT NULL,
        action_type VARCHAR(32) NOT NULL,
        label VARCHAR(191) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        external_url VARCHAR(1024) NULL,
        contact_text VARCHAR(500) NULL,
        fulfillment_mode VARCHAR(32) NOT NULL DEFAULT 'none',
        config_json $json NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_actions_product ON commerce_actions(product_id, status, sort_order)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_orders (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        order_number VARCHAR(64) NOT NULL UNIQUE,
        product_id BIGINT NOT NULL,
        variant_id BIGINT NULL,
        action_id BIGINT NOT NULL,
        buyer_name VARCHAR(191) NULL,
        buyer_email VARCHAR(191) NULL,
        buyer_phone VARCHAR(64) NULL,
        quantity INT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
        fulfillment_status VARCHAR(32) NOT NULL DEFAULT 'not_required',
        shipping_required INT NOT NULL DEFAULT 0,
        amount_minor BIGINT NOT NULL,
        currency VARCHAR(3) NOT NULL,
        payment_id BIGINT NULL,
        provider_id VARCHAR(96) NULL,
        idempotency_key VARCHAR(128) NOT NULL UNIQUE,
        completion_claim VARCHAR(128) NOT NULL,
        snapshot_json $json NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        paid_at DATETIME NULL,
        fulfilled_at DATETIME NULL,
        cancelled_at DATETIME NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_orders_status ON commerce_orders(status, updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_orders_product ON commerce_orders(product_id, created_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_order_items (
        id $id,
        order_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        variant_id BIGINT NULL,
        product_name VARCHAR(191) NOT NULL,
        variant_title VARCHAR(191) NULL,
        sku VARCHAR(96) NOT NULL,
        unit_amount_minor BIGINT NOT NULL,
        quantity INT NOT NULL,
        currency VARCHAR(3) NOT NULL,
        snapshot_json $json NOT NULL,
        created_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_order_items_order ON commerce_order_items(order_id)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_inventory_movements (
        id $id,
        product_id BIGINT NOT NULL,
        variant_id BIGINT NULL,
        order_id BIGINT NULL,
        delta_available BIGINT NOT NULL DEFAULT 0,
        delta_reserved BIGINT NOT NULL DEFAULT 0,
        delta_sold BIGINT NOT NULL DEFAULT 0,
        reason VARCHAR(64) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_inventory_product ON commerce_inventory_movements(product_id, created_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_product_changes (
        id $id,
        product_id BIGINT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value_json $json NULL,
        new_value_json $json NULL,
        actor_id BIGINT NULL,
        reason VARCHAR(191) NULL,
        created_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_changes_product ON commerce_product_changes(product_id, created_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_verification_records (
        id $id,
        product_id BIGINT NOT NULL,
        status VARCHAR(32) NOT NULL,
        source_url VARCHAR(1024) NULL,
        checked_facts_json $json NULL,
        raw_evidence_json $json NULL,
        failure_reason VARCHAR(500) NULL,
        provider VARCHAR(64) NOT NULL DEFAULT 'manual',
        created_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_verification_product ON commerce_verification_records(product_id, created_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS commerce_conversion_events (
        id $id,
        product_id BIGINT NULL,
        order_id BIGINT NULL,
        event_type VARCHAR(64) NOT NULL,
        provider VARCHAR(96) NULL,
        metadata_json $json NULL,
        ip_hash VARCHAR(64) NULL,
        user_agent_hash VARCHAR(64) NULL,
        created_at DATETIME NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commerce_events_product ON commerce_conversion_events(product_id, event_type, created_at)');
};

$rollback = static function (PDO $pdo): void {
    foreach ([
        'commerce_conversion_events',
        'commerce_verification_records',
        'commerce_product_changes',
        'commerce_inventory_movements',
        'commerce_order_items',
        'commerce_orders',
        'commerce_actions',
        'commerce_variants',
        'commerce_products',
    ] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
};

return [
    'id' => 'commerce_001_core',
    'affected_objects' => [
        'table:commerce_products',
        'table:commerce_variants',
        'table:commerce_actions',
        'table:commerce_orders',
        'table:commerce_order_items',
        'table:commerce_inventory_movements',
        'table:commerce_product_changes',
        'table:commerce_verification_records',
        'table:commerce_conversion_events',
    ],
    'up' => $migration,
    'down' => $rollback,
];
