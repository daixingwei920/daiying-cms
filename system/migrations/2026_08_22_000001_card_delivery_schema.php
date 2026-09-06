<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_22_000001_card_delivery_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_card_products (
                id ' . $idColumn . ',
                name VARCHAR(191) NOT NULL,
                description ' . $longText . ' NULL,
                price_minor INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                status VARCHAR(32) NOT NULL,
                max_quantity_per_order INTEGER NOT NULL DEFAULT 1,
                commerce_product_id INTEGER NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $this->createIndexIfMissing($pdo, 'cms_card_products', 'cms_card_products_status_idx', 'CREATE INDEX cms_card_products_status_idx ON cms_card_products (status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_card_inventory (
                id ' . $idColumn . ',
                product_id INTEGER NOT NULL,
                secret_value ' . $longText . ' NOT NULL,
                secret_hash VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                order_id VARCHAR(191) NULL,
                delivery_id INTEGER NULL,
                created_at VARCHAR(64) NOT NULL,
                delivered_at VARCHAR(64) NULL
            )'
        );
        $this->createIndexIfMissing($pdo, 'cms_card_inventory', 'cms_card_inventory_secret_hash_unique', 'CREATE UNIQUE INDEX cms_card_inventory_secret_hash_unique ON cms_card_inventory (secret_hash)');
        $this->createIndexIfMissing($pdo, 'cms_card_inventory', 'cms_card_inventory_product_status_idx', 'CREATE INDEX cms_card_inventory_product_status_idx ON cms_card_inventory (product_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_card_inventory', 'cms_card_inventory_order_idx', 'CREATE INDEX cms_card_inventory_order_idx ON cms_card_inventory (order_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_card_orders (
                id ' . $idColumn . ',
                product_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                amount_minor INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                status VARCHAR(32) NOT NULL,
                payment_id INTEGER NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                paid_at VARCHAR(64) NULL,
                metadata_json ' . $longText . ' NULL
            )'
        );
        $this->createIndexIfMissing($pdo, 'cms_card_orders', 'cms_card_orders_idempotency_unique', 'CREATE UNIQUE INDEX cms_card_orders_idempotency_unique ON cms_card_orders (idempotency_key)');
        $this->createIndexIfMissing($pdo, 'cms_card_orders', 'cms_card_orders_product_status_idx', 'CREATE INDEX cms_card_orders_product_status_idx ON cms_card_orders (product_id, status)');
        $this->createIndexIfMissing($pdo, 'cms_card_orders', 'cms_card_orders_payment_idx', 'CREATE INDEX cms_card_orders_payment_idx ON cms_card_orders (payment_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_card_deliveries (
                id ' . $idColumn . ',
                product_id INTEGER NOT NULL,
                inventory_id INTEGER NULL,
                order_id VARCHAR(191) NOT NULL,
                order_item_index INTEGER NOT NULL DEFAULT 1,
                transaction_id VARCHAR(191) NULL,
                status VARCHAR(32) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                delivered_at VARCHAR(64) NULL,
                metadata_json ' . $longText . ' NULL
            )'
        );
        $this->createIndexIfMissing($pdo, 'cms_card_deliveries', 'cms_card_deliveries_order_product_item_unique', 'CREATE UNIQUE INDEX cms_card_deliveries_order_product_item_unique ON cms_card_deliveries (order_id, product_id, order_item_index)');
        $this->createIndexIfMissing($pdo, 'cms_card_deliveries', 'cms_card_deliveries_status_idx', 'CREATE INDEX cms_card_deliveries_status_idx ON cms_card_deliveries (status)');
    }

    private function createIndexIfMissing(\PDO $pdo, string $table, string $index, string $sql): void
    {
        if ($this->indexExists($pdo, $table, $index)) {
            return;
        }

        $pdo->exec($sql);
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name");
            $stmt->execute([':name' => $index]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name');
        $stmt->execute([':table' => $table, ':name' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
