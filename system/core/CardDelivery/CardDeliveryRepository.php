<?php

declare(strict_types=1);

namespace Cms\Core\CardDelivery;

use PDO;

final class CardDeliveryRepository
{
    private const PRODUCT_STATUSES = ['draft', 'active', 'disabled'];
    private const INVENTORY_STATUSES = ['available', 'reserved', 'delivered', 'disabled'];
    private const ORDER_STATUSES = ['pending_payment', 'paid', 'delivered', 'out_of_stock', 'cancelled', 'manual_review'];
    private const ENCRYPTED_PREFIX = 'enc:v1:';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $encryptionKey = '',
    )
    {
    }

    /** @return list<array<string,mixed>> */
    public function products(): array
    {
        $stmt = $this->pdo->query(
            "SELECT p.*,
                SUM(CASE WHEN i.status = 'available' THEN 1 ELSE 0 END) AS available_count,
                SUM(CASE WHEN i.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count
             FROM cms_card_products p
             LEFT JOIN cms_card_inventory i ON i.product_id = p.id
             GROUP BY p.id
             ORDER BY p.id DESC"
        );

        return array_map([$this, 'hydrateProduct'], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function activeProducts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT p.*,
                SUM(CASE WHEN i.status = 'available' THEN 1 ELSE 0 END) AS available_count,
                SUM(CASE WHEN i.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count
             FROM cms_card_products p
             LEFT JOIN cms_card_inventory i ON i.product_id = p.id
             WHERE p.status = 'active'
             GROUP BY p.id
             ORDER BY p.name"
        );

        return array_map([$this, 'hydrateProduct'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function product(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*,
                SUM(CASE WHEN i.status = 'available' THEN 1 ELSE 0 END) AS available_count,
                SUM(CASE WHEN i.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count
             FROM cms_card_products p
             LEFT JOIN cms_card_inventory i ON i.product_id = p.id
             WHERE p.id = :id
             GROUP BY p.id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrateProduct($row) : null;
    }

    public function saveProduct(?int $id, string $name, int $priceMinor, string $currency, string $status, int $maxQuantityPerOrder, ?int $commerceProductId = null, string $description = ''): int
    {
        $name = $this->plain($name, 191);
        $description = $this->plain($description, 2000);
        if ($name === '') {
            throw new CardDeliveryException('Card product name is required.');
        }
        if ($priceMinor < 0) {
            throw new CardDeliveryException('Card product price must be non-negative.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new CardDeliveryException('Card product currency must be a three-letter uppercase code.');
        }
        if (!in_array($status, self::PRODUCT_STATUSES, true)) {
            throw new CardDeliveryException('Card product status is invalid.');
        }
        $maxQuantityPerOrder = max(1, min(999, $maxQuantityPerOrder));
        $commerceProductId = $commerceProductId !== null && $commerceProductId > 0 ? $commerceProductId : null;
        $now = gmdate('c');

        if ($id !== null && $id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE cms_card_products SET name = :name, description = :description, price_minor = :price_minor, currency = :currency,
                    status = :status, max_quantity_per_order = :max_quantity_per_order, commerce_product_id = :commerce_product_id, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $description,
                ':price_minor' => $priceMinor,
                ':currency' => $currency,
                ':status' => $status,
                ':max_quantity_per_order' => $maxQuantityPerOrder,
                ':commerce_product_id' => $commerceProductId,
                ':updated_at' => $now,
            ]);

            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_card_products
                (name, description, price_minor, currency, status, max_quantity_per_order, commerce_product_id, created_at, updated_at)
             VALUES
                (:name, :description, :price_minor, :currency, :status, :max_quantity_per_order, :commerce_product_id, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':price_minor' => $priceMinor,
            ':currency' => $currency,
            ':status' => $status,
            ':max_quantity_per_order' => $maxQuantityPerOrder,
            ':commerce_product_id' => $commerceProductId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function importInventory(int $productId, string $text): int
    {
        if ($this->product($productId) === null) {
            throw new CardDeliveryException('Card product was not found.');
        }
        $count = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_card_inventory (product_id, secret_value, secret_hash, status, created_at)
             VALUES (:product_id, :secret_value, :secret_hash, :status, :created_at)'
        );
        $now = gmdate('c');
        foreach ($this->secretImportRows($text) as $secret) {
            if ($secret === '') {
                continue;
            }
            if (strlen($secret) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $secret) === 1) {
                throw new CardDeliveryException('Card secret contains unsafe text.');
            }
            try {
                $stmt->execute([
                    ':product_id' => $productId,
                    ':secret_value' => $this->protectSecret($secret),
                    ':secret_hash' => hash('sha256', $secret),
                    ':status' => 'available',
                    ':created_at' => $now,
                ]);
                $count++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function secretImportRows(string $text): array
    {
        $secrets = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $secret = $line;
            if (str_contains($line, ',') || str_contains($line, '"')) {
                $columns = str_getcsv($line);
                $secret = trim((string) ($columns[0] ?? ''));
                $header = strtolower($secret);
                if (in_array($header, ['secret', 'card_secret', 'card', 'value', '卡密'], true)) {
                    continue;
                }
            }
            $secrets[] = $secret;
        }

        return $secrets;
    }

    /** @return list<array<string,mixed>> */
    public function inventory(int $productId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_card_inventory WHERE product_id = :product_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrateInventory'], $stmt->fetchAll());
    }

    public function disableInventory(int $id): void
    {
        $this->pdo->prepare("UPDATE cms_card_inventory SET status = 'disabled' WHERE id = :id AND status IN ('available', 'reserved')")
            ->execute([':id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function deliveries(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, p.name AS product_name FROM cms_card_deliveries d
             LEFT JOIN cms_card_products p ON p.id = d.product_id
             ORDER BY d.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    public function createOrder(int $productId, int $amountMinor, string $currency, string $idempotencyKey, int $quantity = 1): array
    {
        if ($productId <= 0 || $amountMinor <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new CardDeliveryException('Card order amount is invalid.');
        }
        $idempotencyKey = $this->plain($idempotencyKey, 191);
        if ($idempotencyKey === '' || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1) {
            throw new CardDeliveryException('Card order idempotency key is invalid.');
        }
        $quantity = max(1, min(999, $quantity));
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_card_orders
                (product_id, quantity, amount_minor, currency, status, idempotency_key, created_at, updated_at, metadata_json)
             VALUES
                (:product_id, :quantity, :amount_minor, :currency, :status, :idempotency_key, :created_at, :updated_at, :metadata_json)'
        );
        try {
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':amount_minor' => $amountMinor,
                ':currency' => $currency,
                ':status' => 'pending_payment',
                ':idempotency_key' => $idempotencyKey,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':metadata_json' => '{}',
            ]);
        } catch (\Throwable) {
            $existing = $this->orderByIdempotency($idempotencyKey);
            if ($existing !== null
                && (int) ($existing['product_id'] ?? 0) === $productId
                && (int) ($existing['amount_minor'] ?? -1) === $amountMinor
                && (string) ($existing['currency'] ?? '') === $currency
                && (int) ($existing['quantity'] ?? 0) === $quantity
            ) {
                return $existing;
            }
            throw new CardDeliveryException('Card order idempotency key was reused with different content.');
        }

        return $this->order((int) $this->pdo->lastInsertId()) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function order(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, p.name AS product_name
             FROM cms_card_orders o
             LEFT JOIN cms_card_products p ON p.id = o.product_id
             WHERE o.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrateOrder($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function orderByIdempotency(string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, p.name AS product_name
             FROM cms_card_orders o
             LEFT JOIN cms_card_products p ON p.id = o.product_id
             WHERE o.idempotency_key = :idempotency_key LIMIT 1'
        );
        $stmt->execute([':idempotency_key' => $idempotencyKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrateOrder($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function orders(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, p.name AS product_name
             FROM cms_card_orders o
             LEFT JOIN cms_card_products p ON p.id = o.product_id
             ORDER BY o.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrateOrder'], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function ordersNeedingAttention(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.*, p.name AS product_name
             FROM cms_card_orders o
             LEFT JOIN cms_card_products p ON p.id = o.product_id
             WHERE o.status IN ('paid','out_of_stock','manual_review')
             ORDER BY o.id DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrateOrder'], $stmt->fetchAll());
    }

    public function attachPayment(int $orderId, int $paymentId): void
    {
        if ($orderId <= 0 || $paymentId <= 0) {
            throw new CardDeliveryException('Card order payment context is invalid.');
        }
        $this->pdo->prepare('UPDATE cms_card_orders SET payment_id = :payment_id, updated_at = :updated_at WHERE id = :id AND (payment_id IS NULL OR payment_id = :payment_id)')
            ->execute([':id' => $orderId, ':payment_id' => $paymentId, ':updated_at' => gmdate('c')]);
    }

    public function markOrderPaid(int $orderId, int $paymentId): void
    {
        $now = gmdate('c');
        $this->pdo->prepare("UPDATE cms_card_orders SET status = 'paid', payment_id = :payment_id, paid_at = COALESCE(paid_at, :paid_at), updated_at = :updated_at WHERE id = :id AND status IN ('pending_payment','paid','manual_review')")
            ->execute([':id' => $orderId, ':payment_id' => $paymentId, ':paid_at' => $now, ':updated_at' => $now]);
    }

    /** @param list<int> $deliveryIds */
    public function markOrderFulfilled(int $orderId, string $status, ?int $deliveryId = null, array $deliveryIds = []): void
    {
        if (!in_array($status, ['delivered', 'out_of_stock', 'manual_review'], true)) {
            throw new CardDeliveryException('Card order fulfillment status is invalid.');
        }
        $deliveryIds = array_values(array_filter(array_map('intval', $deliveryIds), static fn (int $id): bool => $id > 0));
        if ($deliveryId !== null && $deliveryId > 0 && !in_array($deliveryId, $deliveryIds, true)) {
            array_unshift($deliveryIds, $deliveryId);
        }
        $metadata = $deliveryIds !== [] ? ['delivery_ids' => $deliveryIds, 'delivery_id' => $deliveryIds[0]] : [];
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare('UPDATE cms_card_orders SET status = :status, metadata_json = :metadata_json, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $orderId, ':status' => $status, ':metadata_json' => is_string($json) ? $json : '{}', ':updated_at' => gmdate('c')]);
    }

    /** @return array<string,mixed>|null */
    public function deliveryForOrder(int $productId, string $orderId): ?array
    {
        $items = $this->deliveriesForOrder($productId, $orderId);
        if ($items === []) {
            return null;
        }
        $row = $items[0];
        $row['items'] = $items;

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function deliveriesForOrder(int $productId, string $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_card_deliveries WHERE product_id = :product_id AND order_id = :order_id ORDER BY order_item_index, id');
        $stmt->execute([':product_id' => $productId, ':order_id' => $orderId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['inventory_id']) && $row['inventory_id'] !== null) {
                $secret = $this->secretForInventory((int) $row['inventory_id']);
                if ($secret !== null) {
                    $row['secret'] = $secret;
                }
            }
            $row['id'] = (int) $row['id'];
            $row['product_id'] = (int) $row['product_id'];
            $row['inventory_id'] = $row['inventory_id'] === null ? null : (int) $row['inventory_id'];
            $row['order_item_index'] = (int) ($row['order_item_index'] ?? 1);
            $items[] = $row;
        }

        return $items;
    }

    public function secretForInventory(int $inventoryId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT secret_value FROM cms_card_inventory WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $inventoryId]);
        $value = $stmt->fetchColumn();
        if (!is_string($value)) {
            return null;
        }

        return $this->revealSecret($value);
    }

    /** @return array<string,mixed> */
    private function hydrateProduct(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['price_minor'] = (int) $row['price_minor'];
        $row['max_quantity_per_order'] = (int) $row['max_quantity_per_order'];
        $row['commerce_product_id'] = $row['commerce_product_id'] === null ? null : (int) $row['commerce_product_id'];
        $row['available_count'] = (int) ($row['available_count'] ?? 0);
        $row['delivered_count'] = (int) ($row['delivered_count'] ?? 0);

        return $row;
    }

    /** @return array<string,mixed> */
    private function hydrateInventory(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['secret_masked'] = self::maskSecret($this->revealSecret((string) ($row['secret_value'] ?? '')));
        unset($row['secret_value']);

        return $row;
    }

    /** @return array<string,mixed> */
    private function hydrateOrder(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['quantity'] = (int) $row['quantity'];
        $row['amount_minor'] = (int) $row['amount_minor'];
        $row['payment_id'] = $row['payment_id'] === null ? null : (int) $row['payment_id'];

        return $row;
    }

    public static function maskSecret(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 8) {
            return substr($secret, 0, 1) . str_repeat('*', max(3, strlen($secret) - 2)) . substr($secret, -1);
        }

        return substr($secret, 0, 4) . str_repeat('*', max(8, strlen($secret) - 8)) . substr($secret, -4);
    }

    private function plain(string $value, int $max): string
    {
        $value = trim(strip_tags(str_replace("\0", '', $value)));

        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function protectSecret(string $secret): string
    {
        $key = $this->binaryEncryptionKey();
        if ($key === null || !function_exists('openssl_encrypt')) {
            return $secret;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext) || $tag === '') {
            return $secret;
        }

        return self::ENCRYPTED_PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    private function revealSecret(string $stored): string
    {
        if (!str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            return $stored;
        }
        $key = $this->binaryEncryptionKey();
        if ($key === null || !function_exists('openssl_decrypt')) {
            return '[encrypted]';
        }
        $raw = base64_decode(substr($stored, strlen(self::ENCRYPTED_PREFIX)), true);
        if (!is_string($raw) || strlen($raw) < 29) {
            return '[encrypted]';
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return is_string($plaintext) ? $plaintext : '[encrypted]';
    }

    private function binaryEncryptionKey(): ?string
    {
        $key = trim($this->encryptionKey);
        if ($key === '' || strlen($key) < 16 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            return null;
        }

        return hash('sha256', $key, true);
    }
}
