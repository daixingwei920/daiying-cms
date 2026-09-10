<?php

declare(strict_types=1);

$up = static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $createIndex = static function (PDO $pdo, string $table, string $index, string $columns) use ($driver): void {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . ' ' . $columns);
            return;
        }
        $stmt = $pdo->query('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ' . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . ' ' . $columns);
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_connections (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        provider_id VARCHAR(96) NOT NULL,
        name VARCHAR(191) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'disabled',
        capabilities_json $json NULL,
        public_config_json $json NULL,
        last_test_status VARCHAR(32) NULL,
        last_test_message VARCHAR(500) NULL,
        last_tested_at DATETIME NULL,
        last_sync_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'affiliate_connections', 'idx_affiliate_connections_provider', '(provider_id, status)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_products (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        provider_id VARCHAR(96) NOT NULL,
        connection_id BIGINT NULL,
        advertiser_external_id VARCHAR(191) NULL,
        advertiser_name VARCHAR(191) NULL,
        catalog_external_id VARCHAR(191) NULL,
        external_product_id VARCHAR(191) NOT NULL,
        external_parent_id VARCHAR(191) NULL,
        name_original VARCHAR(500) NOT NULL,
        description_original $text NULL,
        display_title VARCHAR(500) NULL,
        display_summary VARCHAR(1000) NULL,
        display_description $text NULL,
        brand VARCHAR(191) NULL,
        manufacturer VARCHAR(191) NULL,
        sku VARCHAR(191) NULL,
        mpn VARCHAR(191) NULL,
        gtin VARCHAR(191) NULL,
        category_original VARCHAR(191) NULL,
        subcategory_original VARCHAR(191) NULL,
        local_category_id BIGINT NULL,
        image_url VARCHAR(1024) NULL,
        additional_image_urls_json $json NULL,
        price_current DECIMAL(18,4) NULL,
        price_original DECIMAL(18,4) NULL,
        currency VARCHAR(3) NULL,
        discount_percent DECIMAL(7,4) NULL,
        availability VARCHAR(64) NULL,
        condition_text VARCHAR(64) NULL,
        destination_url VARCHAR(1024) NOT NULL,
        country VARCHAR(8) NULL,
        language VARCHAR(16) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        indexable INT NOT NULL DEFAULT 0,
        source_payload_hash VARCHAR(64) NOT NULL,
        source_updated_at DATETIME NULL,
        last_synced_at DATETIME NULL,
        last_seen_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(provider_id, external_product_id)
    )");
    $createIndex($pdo, 'affiliate_products', 'idx_affiliate_products_status', '(status, indexable, updated_at)');
    $createIndex($pdo, 'affiliate_products', 'idx_affiliate_products_provider', '(provider_id, advertiser_name)');
    $createIndex($pdo, 'affiliate_products', 'idx_affiliate_products_price', '(currency, price_current)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_offers (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        product_id BIGINT NOT NULL,
        provider_id VARCHAR(96) NOT NULL,
        connection_id BIGINT NULL,
        advertiser_external_id VARCHAR(191) NULL,
        advertiser_name VARCHAR(191) NULL,
        affiliate_url VARCHAR(2048) NOT NULL,
        destination_url VARCHAR(2048) NOT NULL,
        price_current DECIMAL(18,4) NULL,
        currency VARCHAR(3) NULL,
        availability VARCHAR(64) NULL,
        commission_note VARCHAR(500) NULL,
        disclosure_text VARCHAR(500) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        policy_json $json NULL,
        source_payload_hash VARCHAR(64) NOT NULL,
        last_synced_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'affiliate_offers', 'idx_affiliate_offers_product', '(product_id, status, sort_order)');
    $createIndex($pdo, 'affiliate_offers', 'idx_affiliate_offers_provider', '(provider_id, advertiser_name)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_clicks (
        id $id,
        offer_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        provider_id VARCHAR(96) NOT NULL,
        click_ref VARCHAR(64) NOT NULL,
        path VARCHAR(500) NULL,
        referrer VARCHAR(1024) NULL,
        ip_hash VARCHAR(64) NULL,
        user_agent_hash VARCHAR(64) NULL,
        created_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'affiliate_clicks', 'idx_affiliate_clicks_offer', '(offer_id, created_at)');
    $createIndex($pdo, 'affiliate_clicks', 'idx_affiliate_clicks_provider', '(provider_id, created_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_import_jobs (
        id $id,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        provider_id VARCHAR(96) NOT NULL,
        connection_id BIGINT NULL,
        source_type VARCHAR(64) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        checkpoint_json $json NULL,
        settings_json $json NULL,
        processed_count BIGINT NOT NULL DEFAULT 0,
        created_count BIGINT NOT NULL DEFAULT 0,
        updated_count BIGINT NOT NULL DEFAULT 0,
        failed_count BIGINT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");
    $createIndex($pdo, 'affiliate_import_jobs', 'idx_affiliate_import_jobs_status', '(status, updated_at)');
};

$down = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS affiliate_import_jobs');
    $pdo->exec('DROP TABLE IF EXISTS affiliate_clicks');
    $pdo->exec('DROP TABLE IF EXISTS affiliate_offers');
    $pdo->exec('DROP TABLE IF EXISTS affiliate_products');
    $pdo->exec('DROP TABLE IF EXISTS affiliate_connections');
};

return [
    'id' => 'official_affiliate_hub_001',
    'affected_objects' => [
        'table:affiliate_connections',
        'table:affiliate_products',
        'table:affiliate_offers',
        'table:affiliate_clicks',
        'table:affiliate_import_jobs',
    ],
    'up' => $up,
    'down' => $down,
];
