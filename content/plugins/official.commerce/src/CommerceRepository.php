<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Support\CurrencyRegistry;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class CommerceRepository
{
    private const PRODUCT_STATUSES = ['draft', 'active', 'archived'];
    private const ACTION_TYPES = ['site_checkout', 'external_url', 'contact', 'digital_delivery'];
    private const ORDER_STATUSES = ['pending_payment', 'paid', 'fulfilled', 'cancelled', 'payment_failed'];
    private const LOGISTICS_STATUSES = ['pending_shipment', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'exception'];
    private const CHANGE_FIELDS = [
        'name',
        'sku',
        'price_minor',
        'currency',
        'region',
        'transaction_region',
        'shipping_fee_minor',
        'tax_fee_minor',
        'service_fee_minor',
        'discount_minor',
        'price_note',
        'brand',
        'model',
        'source_url',
        'source_claim_text',
        'primary_media_id',
        'specs_json',
        'requires_shipping',
        'auto_delivery_enabled',
    ];
    private const VERIFICATION_INVALIDATING_FIELDS = [
        'price_minor',
        'currency',
        'region',
        'transaction_region',
        'shipping_fee_minor',
        'tax_fee_minor',
        'service_fee_minor',
        'discount_minor',
        'brand',
        'model',
        'source_url',
        'source_claim_text',
        'primary_media_id',
        'specs_json',
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function products(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_products ORDER BY updated_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrateProduct($row), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function publicProducts(int $limit = 24): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM commerce_products WHERE status = 'active' ORDER BY published_at DESC, id DESC LIMIT :limit");
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrateProduct($row), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function product(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrateProduct($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function publicProduct(int $id): ?array
    {
        $product = $this->product($id);
        return is_array($product) && (string) ($product['status'] ?? '') === 'active' ? $product : null;
    }

    /** @param array<string,mixed> $input */
    public function saveProduct(array $input, ?int $actorId = null): int
    {
        $id = max(0, (int) ($input['id'] ?? 0));
        $name = $this->cleanText((string) ($input['name'] ?? ''), 191);
        if ($name === '') {
            throw new InvalidArgumentException('商品名称不能为空。');
        }
        $sku = $this->cleanCode((string) ($input['sku'] ?? ''));
        if ($sku === '') {
            $sku = 'SKU-' . strtoupper(substr(hash('sha256', $name . '|' . microtime(true)), 0, 10));
        }
        $slug = $this->slug((string) ($input['slug'] ?? ''), $name, $id);
        $status = $this->status((string) ($input['status'] ?? 'draft'), self::PRODUCT_STATUSES, 'draft');
        $currency = CurrencyRegistry::normalizeCode((string) ($input['currency'] ?? 'CNY'));
        $priceMinor = max(0, (int) ($input['price_minor'] ?? 0));
        if ($priceMinor <= 0) {
            throw new InvalidArgumentException('商品价格必须大于 0。');
        }
        $stock = max(0, (int) ($input['stock_quantity'] ?? 0));
        $now = gmdate('Y-m-d H:i:s');
        $payload = [
            'sku' => $sku,
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
            'summary' => $this->nullableText((string) ($input['summary'] ?? ''), 500),
            'description_content_id' => $this->nullableInt($input['description_content_id'] ?? null),
            'primary_media_id' => $this->nullableInt($input['primary_media_id'] ?? null),
            'gallery_media_ids_json' => $this->json($this->intList($input['gallery_media_ids'] ?? [])),
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'region' => strtoupper(substr($this->cleanCode((string) ($input['region'] ?? 'CN')), 0, 16)) ?: 'CN',
            'transaction_region' => $this->status((string) ($input['transaction_region'] ?? 'cn_domestic'), ['cn_domestic', 'cross_border', 'international'], 'cn_domestic'),
            'shipping_fee_minor' => max(0, (int) ($input['shipping_fee_minor'] ?? 0)),
            'tax_fee_minor' => max(0, (int) ($input['tax_fee_minor'] ?? 0)),
            'service_fee_minor' => max(0, (int) ($input['service_fee_minor'] ?? 0)),
            'discount_minor' => max(0, (int) ($input['discount_minor'] ?? 0)),
            'price_note' => $this->nullableText((string) ($input['price_note'] ?? ''), 500),
            'brand' => $this->nullableText((string) ($input['brand'] ?? ''), 191),
            'model' => $this->nullableText((string) ($input['model'] ?? ''), 191),
            'source_url' => $this->nullableUrl((string) ($input['source_url'] ?? '')),
            'source_claim_text' => $this->nullableText((string) ($input['source_claim_text'] ?? ''), 500),
            'specs_json' => $this->json($this->keyValueLines((string) ($input['specs'] ?? ''))),
            'requires_shipping' => !empty($input['requires_shipping']) ? 1 : 0,
            'auto_delivery_enabled' => !empty($input['auto_delivery_enabled']) ? 1 : 0,
            'stock_quantity' => $stock,
        ];
        $payload['key_fingerprint'] = $this->productFingerprint($payload);

        if ($id > 0) {
            $existing = $this->product($id);
            if ($existing === null) {
                throw new RuntimeException('商品不存在。');
            }
            $set = [];
            $params = [':id' => $id, ':updated_at' => $now, ':published_at' => $status === 'active' && empty($existing['published_at']) ? $now : ($existing['published_at'] ?? null)];
            foreach ($payload as $key => $value) {
                $set[] = $key . ' = :' . $key;
                $params[':' . $key] = $value;
            }
            if (($existing['source_url'] ?? null) != $payload['source_url'] || ($existing['source_claim_text'] ?? null) != $payload['source_claim_text']) {
                $params[':source_declared_at'] = $payload['source_url'] !== null || $payload['source_claim_text'] !== null ? $now : null;
                $sql = 'UPDATE commerce_products SET ' . implode(', ', $set) . ', source_declared_at = :source_declared_at, published_at = :published_at, updated_at = :updated_at WHERE id = :id';
            } else {
                $sql = 'UPDATE commerce_products SET ' . implode(', ', $set) . ', published_at = :published_at, updated_at = :updated_at WHERE id = :id';
            }
            $sourceChanged = ($existing['source_url'] ?? null) != $payload['source_url'] || ($existing['source_claim_text'] ?? null) != $payload['source_claim_text'];
            $this->pdo->prepare($sql)->execute($params);
            $updated = $this->product($id) ?? [];
            $this->recordProductChanges($id, $existing, $updated, $actorId);
            $this->refreshVerificationAfterProductChange($id, $existing, $updated);
            if ($sourceChanged) {
                $this->recordSourceDeclaration($id, $updated, $actorId);
            }
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO commerce_products
                (uuid, sku, name, slug, status, summary, description_content_id, primary_media_id, gallery_media_ids_json, price_minor, currency, region, transaction_region, shipping_fee_minor, tax_fee_minor, service_fee_minor, discount_minor, price_note, brand, model, source_url, source_claim_text, source_declared_at, specs_json, requires_shipping, auto_delivery_enabled, stock_quantity, reserved_quantity, sold_quantity, verification_status, key_fingerprint, published_at, created_at, updated_at)
             VALUES
                (:uuid, :sku, :name, :slug, :status, :summary, :description_content_id, :primary_media_id, :gallery_media_ids_json, :price_minor, :currency, :region, :transaction_region, :shipping_fee_minor, :tax_fee_minor, :service_fee_minor, :discount_minor, :price_note, :brand, :model, :source_url, :source_claim_text, :source_declared_at, :specs_json, :requires_shipping, :auto_delivery_enabled, :stock_quantity, 0, 0, :verification_status, :key_fingerprint, :published_at, :created_at, :updated_at)'
        );
        $insertParams = [];
        foreach ($payload as $key => $value) {
            $insertParams[':' . $key] = $value;
        }
        $stmt->execute($insertParams + [
            ':uuid' => $this->uuid(),
            ':source_declared_at' => $payload['source_url'] !== null || $payload['source_claim_text'] !== null ? $now : null,
            ':verification_status' => $payload['source_url'] !== null ? 'pending' : 'not_provided',
            ':published_at' => $status === 'active' ? $now : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $newId = (int) $this->pdo->lastInsertId();
        $created = $this->product($newId);
        if (is_array($created) && ((string) ($created['source_url'] ?? '') !== '' || (string) ($created['source_claim_text'] ?? '') !== '')) {
            $this->recordSourceDeclaration($newId, $created, $actorId);
        }

        return $newId;
    }

    public function setProductStatus(int $productId, string $status, ?int $actorId = null): void
    {
        $status = $this->status($status, self::PRODUCT_STATUSES, 'draft');
        $product = $this->product($productId);
        if ($product === null) {
            throw new RuntimeException('商品不存在。');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE commerce_products SET status = :status, published_at = CASE WHEN :status = \'active\' AND published_at IS NULL THEN :published_at ELSE published_at END, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $productId, ':status' => $status, ':published_at' => $now, ':updated_at' => $now]);
        $this->recordProductChanges($productId, $product, $this->product($productId) ?? [], $actorId, 'status_change');
    }

    /** @param array<string,mixed> $input */
    public function saveVariant(array $input): int
    {
        $productId = (int) ($input['product_id'] ?? 0);
        if ($this->product($productId) === null) {
            throw new RuntimeException('商品不存在。');
        }
        $title = $this->cleanText((string) ($input['title'] ?? ''), 191);
        if ($title === '') {
            throw new InvalidArgumentException('规格名称不能为空。');
        }
        $sku = $this->cleanCode((string) ($input['sku'] ?? ''));
        if ($sku === '') {
            $sku = 'VAR-' . strtoupper(substr(hash('sha256', $productId . '|' . $title . '|' . microtime(true)), 0, 10));
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO commerce_variants (product_id, sku, title, options_json, price_delta_minor, stock_quantity, reserved_quantity, sold_quantity, status, sort_order, created_at, updated_at)
             VALUES (:product_id, :sku, :title, :options_json, :price_delta_minor, :stock_quantity, 0, 0, :status, :sort_order, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':sku' => $sku,
            ':title' => $title,
            ':options_json' => $this->json($this->keyValueLines((string) ($input['options'] ?? ''))),
            ':price_delta_minor' => (int) ($input['price_delta_minor'] ?? 0),
            ':stock_quantity' => max(0, (int) ($input['stock_quantity'] ?? 0)),
            ':status' => $this->status((string) ($input['status'] ?? 'active'), ['active', 'disabled'], 'active'),
            ':sort_order' => (int) ($input['sort_order'] ?? 0),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $input */
    public function saveAction(array $input): int
    {
        $productId = (int) ($input['product_id'] ?? 0);
        if ($this->product($productId) === null) {
            throw new RuntimeException('商品不存在。');
        }
        $type = $this->status((string) ($input['action_type'] ?? 'site_checkout'), self::ACTION_TYPES, 'site_checkout');
        $label = $this->cleanText((string) ($input['label'] ?? ''), 191);
        if ($label === '') {
            $label = match ($type) {
                'external_url' => '外部购买',
                'contact' => '联系购买',
                'digital_delivery' => '购买后自动交付',
                default => '立即购买',
            };
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO commerce_actions (product_id, action_type, label, status, external_url, contact_text, fulfillment_mode, config_json, sort_order, created_at, updated_at)
             VALUES (:product_id, :action_type, :label, :status, :external_url, :contact_text, :fulfillment_mode, :config_json, :sort_order, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':action_type' => $type,
            ':label' => $label,
            ':status' => $this->status((string) ($input['status'] ?? 'active'), ['active', 'disabled'], 'active'),
            ':external_url' => $this->nullableUrl((string) ($input['external_url'] ?? '')),
            ':contact_text' => $this->nullableText((string) ($input['contact_text'] ?? ''), 500),
            ':fulfillment_mode' => $this->status((string) ($input['fulfillment_mode'] ?? 'none'), ['none', 'shipping', 'digital_card'], 'none'),
            ':config_json' => $this->json([]),
            ':sort_order' => (int) ($input['sort_order'] ?? 0),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function variants(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_variants WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':product_id' => $productId]);
        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['options_json']), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function activeActions(int $productId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM commerce_actions WHERE product_id = :product_id AND status = 'active' ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':product_id' => $productId]);
        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['config_json']), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function actions(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_actions WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':product_id' => $productId]);
        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['config_json']), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function action(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_actions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateJsonFields($row, ['config_json']) : null;
    }

    /** @return array<string,mixed>|null */
    public function variant(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_variants WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateJsonFields($row, ['options_json']) : null;
    }

    /** @param array<string,mixed> $buyer @return array<string,mixed> */
    public function createPendingOrder(int $productId, ?int $variantId, int $actionId, int $quantity, string $providerId, string $idempotencyKey, string $claim, array $buyer = []): array
    {
        $product = $this->publicProduct($productId);
        $action = $this->action($actionId);
        if ($product === null || $action === null || (int) ($action['product_id'] ?? 0) !== $productId || (string) ($action['status'] ?? '') !== 'active') {
            throw new RuntimeException('商品不可购买。');
        }
        if (!in_array((string) ($action['action_type'] ?? ''), ['site_checkout', 'digital_delivery'], true)) {
            throw new RuntimeException('这个购买动作不在本站成交。');
        }
        $variant = $variantId !== null ? $this->variant($variantId) : null;
        if ($variantId !== null && ($variant === null || (int) ($variant['product_id'] ?? 0) !== $productId || (string) ($variant['status'] ?? '') !== 'active')) {
            throw new RuntimeException('商品规格不可购买。');
        }
        $quantity = max(1, min($quantity, 99));
        $available = $variant !== null
            ? (int) $variant['stock_quantity'] - (int) $variant['reserved_quantity'] - (int) $variant['sold_quantity']
            : (int) $product['stock_quantity'] - (int) $product['reserved_quantity'] - (int) $product['sold_quantity'];
        if ($available < $quantity) {
            throw new RuntimeException('库存不足。');
        }
        $unit = (int) $product['price_minor'] + (int) ($variant['price_delta_minor'] ?? 0);
        if ($unit <= 0) {
            throw new RuntimeException('商品价格无效。');
        }
        $pricing = $this->pricingSnapshot($product, $unit, $quantity);
        $snapshot = [
            'product' => $this->snapshotProduct($product),
            'variant' => $variant !== null ? $this->snapshotVariant($variant) : null,
            'action' => [
                'id' => (int) $action['id'],
                'type' => (string) $action['action_type'],
                'label' => (string) $action['label'],
                'fulfillment_mode' => (string) $action['fulfillment_mode'],
            ],
            'pricing' => $pricing,
        ];
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO commerce_orders (uuid, order_number, product_id, variant_id, action_id, buyer_name, buyer_email, buyer_phone, quantity, status, fulfillment_status, shipping_required, amount_minor, currency, provider_id, idempotency_key, completion_claim, snapshot_json, created_at, updated_at)
                 VALUES (:uuid, :order_number, :product_id, :variant_id, :action_id, :buyer_name, :buyer_email, :buyer_phone, :quantity, :status, :fulfillment_status, :shipping_required, :amount_minor, :currency, :provider_id, :idempotency_key, :completion_claim, :snapshot_json, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':uuid' => $this->uuid(),
                ':order_number' => 'DC' . gmdate('YmdHis') . strtoupper(substr(hash('sha256', random_bytes(16)), 0, 6)),
                ':product_id' => $productId,
                ':variant_id' => $variantId,
                ':action_id' => $actionId,
                ':buyer_name' => $this->nullableText((string) ($buyer['name'] ?? ''), 191),
                ':buyer_email' => $this->nullableText((string) ($buyer['email'] ?? ''), 191),
                ':buyer_phone' => $this->nullableText((string) ($buyer['phone'] ?? ''), 64),
                ':quantity' => $quantity,
                ':status' => 'pending_payment',
                ':fulfillment_status' => (int) $product['requires_shipping'] === 1 ? 'pending' : 'not_required',
                ':shipping_required' => (int) $product['requires_shipping'],
                ':amount_minor' => (int) $pricing['total_minor'],
                ':currency' => (string) $product['currency'],
                ':provider_id' => $providerId,
                ':idempotency_key' => $idempotencyKey,
                ':completion_claim' => $claim,
                ':snapshot_json' => $this->json($snapshot),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();
            $item = $this->pdo->prepare(
                'INSERT INTO commerce_order_items (order_id, product_id, variant_id, product_name, variant_title, sku, unit_amount_minor, quantity, currency, snapshot_json, created_at)
                 VALUES (:order_id, :product_id, :variant_id, :product_name, :variant_title, :sku, :unit_amount_minor, :quantity, :currency, :snapshot_json, :created_at)'
            );
            $item->execute([
                ':order_id' => $orderId,
                ':product_id' => $productId,
                ':variant_id' => $variantId,
                ':product_name' => (string) $product['name'],
                ':variant_title' => $variant !== null ? (string) $variant['title'] : null,
                ':sku' => $variant !== null ? (string) $variant['sku'] : (string) $product['sku'],
                ':unit_amount_minor' => $unit,
                ':quantity' => $quantity,
                ':currency' => (string) $product['currency'],
                ':snapshot_json' => $this->json($snapshot),
                ':created_at' => $now,
            ]);
            $this->reserveInventory($productId, $variantId, $orderId, $quantity);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->order($orderId) ?? [];
    }

    public function attachPayment(int $orderId, int $paymentId): void
    {
        $this->pdo->prepare('UPDATE commerce_orders SET payment_id = :payment_id, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $orderId, ':payment_id' => $paymentId, ':updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function markOrderPaid(int $orderId): void
    {
        $order = $this->order($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在。');
        }
        if ((string) ($order['status'] ?? '') === 'paid' || (string) ($order['status'] ?? '') === 'fulfilled') {
            return;
        }
        if ((string) ($order['status'] ?? '') !== 'pending_payment') {
            throw new RuntimeException('订单状态不能标记为已支付。');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->completeInventorySale((int) $order['product_id'], isset($order['variant_id']) ? (int) $order['variant_id'] : null, $orderId, (int) $order['quantity']);
            $this->pdo->prepare("UPDATE commerce_orders SET status = 'paid', paid_at = :paid_at, updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $orderId, ':paid_at' => $now, ':updated_at' => $now]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markOrderPaymentFailed(int $orderId, string $note = ''): void
    {
        $order = $this->order($orderId);
        if ($order === null || (string) ($order['status'] ?? '') !== 'pending_payment') {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->releaseReservedInventory((int) $order['product_id'], isset($order['variant_id']) ? (int) $order['variant_id'] : null, $orderId, (int) $order['quantity'], $note);
            $this->pdo->prepare("UPDATE commerce_orders SET status = 'payment_failed', updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $orderId, ':updated_at' => $now]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelPendingOrder(int $orderId, string $note = ''): void
    {
        $order = $this->order($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在。');
        }
        if ((string) ($order['status'] ?? '') !== 'pending_payment') {
            throw new RuntimeException('只有待支付订单可以取消。');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->releaseReservedInventory((int) $order['product_id'], isset($order['variant_id']) ? (int) $order['variant_id'] : null, $orderId, (int) $order['quantity'], $note !== '' ? $note : 'cancelled by admin');
            $this->pdo->prepare("UPDATE commerce_orders SET status = 'cancelled', fulfillment_status = 'not_required', cancelled_at = :cancelled_at, updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $orderId, ':cancelled_at' => $now, ':updated_at' => $now]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markOrderFulfilled(int $orderId): void
    {
        $order = $this->order($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在。');
        }
        if ((string) ($order['status'] ?? '') === 'fulfilled') {
            return;
        }
        if ((string) ($order['status'] ?? '') !== 'paid') {
            throw new RuntimeException('只有已支付订单可以标记履约。');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE commerce_orders SET status = 'fulfilled', fulfillment_status = 'fulfilled', fulfilled_at = :fulfilled_at, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $orderId, ':fulfilled_at' => $now, ':updated_at' => $now]);
    }

    /** @return list<array<string,mixed>> */
    public function orders(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_orders ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['snapshot_json']), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function pendingPaymentOrders(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM commerce_orders WHERE status = 'pending_payment' AND payment_id IS NOT NULL ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['snapshot_json']), $stmt->fetchAll());
    }

    /** @return array{checked:int,marked:int,unpaid:int,errors:int} */
    public function markTrustedPaidOrders(PaymentRepository $payments, int $limit = 100): array
    {
        $result = ['checked' => 0, 'marked' => 0, 'unpaid' => 0, 'errors' => 0];
        foreach ($this->pendingPaymentOrders($limit) as $order) {
            $result['checked']++;
            try {
                $trusted = $payments->trustedStatus('commerce_order', 'order:' . (int) $order['id'], (string) ($order['currency'] ?? ''));
                if ((string) ($trusted['status'] ?? '') !== 'paid' || (int) ($trusted['net_paid_minor'] ?? 0) < (int) ($order['amount_minor'] ?? 0)) {
                    $result['unpaid']++;
                    continue;
                }
                $this->markOrderPaid((int) $order['id']);
                $result['marked']++;
            } catch (\Throwable) {
                $result['errors']++;
            }
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function order(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateJsonFields($row, ['snapshot_json']) : null;
    }

    /** @return list<array<string,mixed>> */
    public function productChanges(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_product_changes WHERE product_id = :product_id ORDER BY id DESC LIMIT 50');
        $stmt->execute([':product_id' => $productId]);
        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['old_value_json', 'new_value_json']), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $input */
    public function appendVerificationRecord(array $input, ?int $actorId = null): int
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $product = $this->product($productId);
        if ($product === null) {
            throw new RuntimeException('商品不存在。');
        }
        $status = $this->status((string) ($input['status'] ?? 'pending'), ['not_provided', 'pending', 'verified', 'failed'], 'pending');
        $sourceInput = trim((string) ($input['source_url'] ?? ''));
        $sourceUrl = $this->nullableUrl($sourceInput !== '' ? $sourceInput : (string) ($product['source_url'] ?? ''));
        if ($status !== 'not_provided' && $sourceUrl === null) {
            throw new InvalidArgumentException('来源 URL 不能为空。');
        }
        $provider = $this->cleanCode((string) ($input['provider'] ?? 'manual')) ?: 'manual';
        $recordType = $this->status((string) ($input['record_type'] ?? 'provider_result'), ['provider_result', 'system_invalidation'], 'provider_result');
        if ($recordType === 'provider_result' && in_array($provider, ['manual', 'seller', 'system'], true)) {
            throw new RuntimeException('卖家只能请求重新核验，不能直接修改核验结果。');
        }
        $checkedFacts = is_array($input['checked_facts'] ?? null)
            ? $this->cleanFactMap($input['checked_facts'])
            : $this->keyValueLines((string) ($input['checked_facts'] ?? ''));
        if ($actorId !== null) {
            $checkedFacts['_actor_id'] = (string) $actorId;
        }
        $rawEvidence = is_array($input['raw_evidence'] ?? null)
            ? $this->cleanFactMap($input['raw_evidence'])
            : $this->keyValueLines((string) ($input['raw_evidence'] ?? ''));
        $failureReason = $this->nullableText((string) ($input['failure_reason'] ?? ''), 500);
        $id = $this->insertVerificationRecord($product, [
            'status' => $status,
            'source_url' => $sourceUrl,
            'checked_facts' => $checkedFacts,
            'raw_evidence' => $rawEvidence,
            'failure_reason' => $failureReason,
            'provider' => $provider,
            'record_type' => $recordType,
            'effective_status' => $status,
            'requester_id' => $actorId,
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE commerce_products SET verification_status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $productId, ':status' => $status, ':updated_at' => $now]);

        return $id;
    }

    public function requestVerificationReview(int $productId, string $note = '', ?int $actorId = null): int
    {
        $product = $this->product($productId);
        if ($product === null) {
            throw new RuntimeException('商品不存在。');
        }
        $sourceUrl = $this->nullableUrl((string) ($product['source_url'] ?? ''));
        if ($sourceUrl === null) {
            throw new InvalidArgumentException('请先填写商品来源 URL。');
        }
        $id = $this->insertVerificationRecord($product, [
            'status' => 'pending',
            'source_url' => $sourceUrl,
            'checked_facts' => ['request_note' => $this->cleanText($note, 500)],
            'raw_evidence' => [],
            'failure_reason' => $note !== '' ? $this->nullableText($note, 500) : '卖家请求重新核验。',
            'provider' => 'seller',
            'record_type' => 'seller_request',
            'requested_status' => 'pending',
            'effective_status' => null,
            'requester_id' => $actorId,
        ]);
        $this->pdo->prepare("UPDATE commerce_products SET verification_status = 'pending', updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $productId, ':updated_at' => gmdate('Y-m-d H:i:s')]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function verificationRecords(int $productId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_verification_records WHERE product_id = :product_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['checked_facts_json', 'raw_evidence_json', 'related_change_ids_json']), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $input */
    public function appendLogisticsEvent(array $input): int
    {
        $orderId = (int) ($input['order_id'] ?? 0);
        $order = $this->order($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在。');
        }
        if ((int) ($order['shipping_required'] ?? 0) !== 1) {
            throw new RuntimeException('这个订单不需要物流。');
        }
        if (!in_array((string) ($order['status'] ?? ''), ['paid', 'fulfilled'], true)) {
            throw new RuntimeException('只有已支付订单可以记录物流。');
        }
        $status = $this->status((string) ($input['status'] ?? 'pending_shipment'), self::LOGISTICS_STATUSES, 'pending_shipment');
        $carrier = $this->nullableText((string) ($input['carrier'] ?? ''), 96);
        $tracking = $this->nullableText((string) ($input['tracking_number'] ?? ''), 128);
        $provider = $this->cleanCode((string) ($input['provider'] ?? 'manual')) ?: 'manual';
        $rawStatus = $this->nullableText((string) ($input['raw_status'] ?? ''), 191);
        $message = $this->nullableText((string) ($input['message'] ?? ''), 500);
        $rawPayload = $this->keyValueLines((string) ($input['raw_payload'] ?? ''));
        $occurredAt = $this->normalizeDateTime((string) ($input['occurred_at'] ?? ''));
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO commerce_logistics_events (order_id, status, carrier, tracking_number, provider, raw_status, raw_payload_json, message, occurred_at, created_at)
             VALUES (:order_id, :status, :carrier, :tracking_number, :provider, :raw_status, :raw_payload_json, :message, :occurred_at, :created_at)'
        );
        $stmt->execute([
            ':order_id' => $orderId,
            ':status' => $status,
            ':carrier' => $carrier,
            ':tracking_number' => $tracking,
            ':provider' => $provider,
            ':raw_status' => $rawStatus,
            ':raw_payload_json' => $this->json($rawPayload),
            ':message' => $message,
            ':occurred_at' => $occurredAt,
            ':created_at' => $now,
        ]);
        $updates = [
            ':id' => $orderId,
            ':fulfillment_status' => $status,
            ':updated_at' => $now,
        ];
        $sql = 'UPDATE commerce_orders SET fulfillment_status = :fulfillment_status, updated_at = :updated_at';
        if ($status === 'delivered' && (string) ($order['status'] ?? '') === 'paid') {
            $sql .= ", status = 'fulfilled', fulfilled_at = :fulfilled_at";
            $updates[':fulfilled_at'] = $occurredAt;
        }
        $sql .= ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($updates);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function logisticsEvents(int $orderId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commerce_logistics_events WHERE order_id = :order_id ORDER BY occurred_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrateJsonFields($row, ['raw_payload_json']), $stmt->fetchAll());
    }

    public function recordEvent(?int $productId, ?int $orderId, string $eventType, array $metadata = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO commerce_conversion_events (product_id, order_id, event_type, provider, metadata_json, created_at) VALUES (:product_id, :order_id, :event_type, :provider, :metadata_json, :created_at)');
        $stmt->execute([
            ':product_id' => $productId,
            ':order_id' => $orderId,
            ':event_type' => $this->cleanCode($eventType),
            ':provider' => is_string($metadata['provider'] ?? null) ? $this->cleanCode((string) $metadata['provider']) : null,
            ':metadata_json' => $this->json($metadata),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return [
            'products' => (int) $this->pdo->query('SELECT COUNT(*) FROM commerce_products')->fetchColumn(),
            'active_products' => (int) $this->pdo->query("SELECT COUNT(*) FROM commerce_products WHERE status = 'active'")->fetchColumn(),
            'orders' => (int) $this->pdo->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn(),
            'paid_orders' => (int) $this->pdo->query("SELECT COUNT(*) FROM commerce_orders WHERE status IN ('paid','fulfilled')")->fetchColumn(),
        ];
    }

    private function reserveInventory(int $productId, ?int $variantId, int $orderId, int $quantity): void
    {
        $table = $variantId !== null ? 'commerce_variants' : 'commerce_products';
        $where = $variantId !== null ? 'id = :id AND product_id = :product_id' : 'id = :id';
        $params = $variantId !== null ? [':id' => $variantId, ':product_id' => $productId] : [':id' => $productId];
        $sql = "UPDATE $table SET reserved_quantity = reserved_quantity + :quantity, updated_at = :updated_at WHERE $where";
        $this->pdo->prepare($sql)->execute($params + [':quantity' => $quantity, ':updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->inventoryMovement($productId, $variantId, $orderId, 0, $quantity, 0, 'reserve_order');
    }

    private function completeInventorySale(int $productId, ?int $variantId, int $orderId, int $quantity): void
    {
        $table = $variantId !== null ? 'commerce_variants' : 'commerce_products';
        $where = $variantId !== null ? 'id = :id AND product_id = :product_id' : 'id = :id';
        $params = $variantId !== null ? [':id' => $variantId, ':product_id' => $productId] : [':id' => $productId];
        $sql = "UPDATE $table SET reserved_quantity = CASE WHEN reserved_quantity >= :quantity THEN reserved_quantity - :quantity ELSE 0 END, sold_quantity = sold_quantity + :quantity, updated_at = :updated_at WHERE $where";
        $this->pdo->prepare($sql)->execute($params + [':quantity' => $quantity, ':updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->inventoryMovement($productId, $variantId, $orderId, 0, -$quantity, $quantity, 'paid_order');
    }

    private function releaseReservedInventory(int $productId, ?int $variantId, int $orderId, int $quantity, string $note = ''): void
    {
        $table = $variantId !== null ? 'commerce_variants' : 'commerce_products';
        $where = $variantId !== null ? 'id = :id AND product_id = :product_id' : 'id = :id';
        $params = $variantId !== null ? [':id' => $variantId, ':product_id' => $productId] : [':id' => $productId];
        $sql = "UPDATE $table SET reserved_quantity = CASE WHEN reserved_quantity >= :quantity THEN reserved_quantity - :quantity ELSE 0 END, updated_at = :updated_at WHERE $where";
        $this->pdo->prepare($sql)->execute($params + [':quantity' => $quantity, ':updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->inventoryMovement($productId, $variantId, $orderId, 0, -$quantity, 0, 'payment_failed', $note);
    }

    private function inventoryMovement(int $productId, ?int $variantId, ?int $orderId, int $deltaAvailable, int $deltaReserved, int $deltaSold, string $reason, string $note = ''): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO commerce_inventory_movements (product_id, variant_id, order_id, delta_available, delta_reserved, delta_sold, reason, note, created_at) VALUES (:product_id, :variant_id, :order_id, :delta_available, :delta_reserved, :delta_sold, :reason, :note, :created_at)');
        $stmt->execute([
            ':product_id' => $productId,
            ':variant_id' => $variantId,
            ':order_id' => $orderId,
            ':delta_available' => $deltaAvailable,
            ':delta_reserved' => $deltaReserved,
            ':delta_sold' => $deltaSold,
            ':reason' => $reason,
            ':note' => $note !== '' ? $this->cleanText($note, 500) : null,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $old @param array<string,mixed> $new */
    private function recordProductChanges(int $productId, array $old, array $new, ?int $actorId = null, string $reason = ''): void
    {
        foreach (self::CHANGE_FIELDS as $field) {
            if (($old[$field] ?? null) == ($new[$field] ?? null)) {
                continue;
            }
            $stmt = $this->pdo->prepare('INSERT INTO commerce_product_changes (product_id, field_name, old_value_json, new_value_json, actor_id, reason, created_at) VALUES (:product_id, :field_name, :old_value_json, :new_value_json, :actor_id, :reason, :created_at)');
            $stmt->execute([
                ':product_id' => $productId,
                ':field_name' => $field,
                ':old_value_json' => $this->json($old[$field] ?? null),
                ':new_value_json' => $this->json($new[$field] ?? null),
                ':actor_id' => $actorId,
                ':reason' => $reason !== '' ? $reason : null,
                ':created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @param array<string,mixed> $old @param array<string,mixed> $new */
    private function refreshVerificationAfterProductChange(int $productId, array $old, array $new): void
    {
        $newSource = $this->nullableUrl((string) ($new['source_url'] ?? ''));
        $currentStatus = (string) ($old['verification_status'] ?? 'not_provided');
        $changed = false;
        foreach (self::VERIFICATION_INVALIDATING_FIELDS as $field) {
            if (($old[$field] ?? null) != ($new[$field] ?? null)) {
                $changed = true;
                break;
            }
        }
        if ($newSource === null) {
            if ((string) ($new['verification_status'] ?? '') !== 'not_provided') {
                $this->pdo->prepare("UPDATE commerce_products SET verification_status = 'not_provided', updated_at = :updated_at WHERE id = :id")
                    ->execute([':id' => $productId, ':updated_at' => gmdate('Y-m-d H:i:s')]);
            }
            return;
        }
        if (!$changed) {
            return;
        }
        if ($currentStatus === 'not_provided' && $this->nullableUrl((string) ($old['source_url'] ?? '')) === null) {
            $this->pdo->prepare("UPDATE commerce_products SET verification_status = 'pending', updated_at = :updated_at WHERE id = :id")
                ->execute([':id' => $productId, ':updated_at' => gmdate('Y-m-d H:i:s')]);
            return;
        }
        if (!in_array($currentStatus, ['verified', 'failed'], true)) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE commerce_products SET verification_status = 'pending', updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $productId, ':updated_at' => $now]);
        $this->insertVerificationRecord($new, [
            'status' => 'pending',
            'source_url' => $newSource,
            'checked_facts' => ['reason' => 'product_key_facts_changed'],
            'raw_evidence' => [],
            'failure_reason' => '关键商品信息已变更，等待重新核验。',
            'provider' => 'system',
            'record_type' => 'system_invalidation',
            'requested_status' => null,
            'effective_status' => 'pending',
            'requester_id' => null,
        ]);
    }

    /** @param array<string,mixed> $product */
    private function recordSourceDeclaration(int $productId, array $product, ?int $actorId): void
    {
        $sourceUrl = $this->nullableUrl((string) ($product['source_url'] ?? ''));
        $claim = (string) ($product['source_claim_text'] ?? '');
        if ($sourceUrl === null && $claim === '') {
            return;
        }
        $this->insertVerificationRecord($product + ['id' => $productId], [
            'status' => $sourceUrl !== null ? 'pending' : 'not_provided',
            'source_url' => $sourceUrl,
            'checked_facts' => ['source_claim' => $claim],
            'raw_evidence' => [],
            'failure_reason' => $claim !== '' ? $claim : '卖家声明商品来源。',
            'provider' => 'seller',
            'record_type' => 'source_declaration',
            'requested_status' => 'pending',
            'effective_status' => null,
            'requester_id' => $actorId,
        ]);
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $record */
    private function insertVerificationRecord(array $product, array $record): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO commerce_verification_records
                (product_id, status, source_url, checked_facts_json, raw_evidence_json, failure_reason, provider, record_type, requested_status, effective_status, requester_id, source_fingerprint, product_fingerprint, related_change_ids_json, created_at)
             VALUES
                (:product_id, :status, :source_url, :checked_facts_json, :raw_evidence_json, :failure_reason, :provider, :record_type, :requested_status, :effective_status, :requester_id, :source_fingerprint, :product_fingerprint, :related_change_ids_json, :created_at)'
        );
        $stmt->execute([
            ':product_id' => (int) ($product['id'] ?? $record['product_id'] ?? 0),
            ':status' => (string) $record['status'],
            ':source_url' => $record['source_url'] ?? null,
            ':checked_facts_json' => $this->json($record['checked_facts'] ?? []),
            ':raw_evidence_json' => $this->json($record['raw_evidence'] ?? []),
            ':failure_reason' => $record['failure_reason'] ?? null,
            ':provider' => (string) ($record['provider'] ?? 'system'),
            ':record_type' => (string) ($record['record_type'] ?? 'provider_result'),
            ':requested_status' => $record['requested_status'] ?? null,
            ':effective_status' => $record['effective_status'] ?? null,
            ':requester_id' => $record['requester_id'] ?? null,
            ':source_fingerprint' => $this->sourceFingerprint($product),
            ':product_fingerprint' => (string) ($product['key_fingerprint'] ?? ''),
            ':related_change_ids_json' => $this->json($record['related_change_ids'] ?? []),
            ':created_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $row */
    private function hydrateProduct(array $row): array
    {
        $row = $this->hydrateJsonFields($row, ['gallery_media_ids_json', 'specs_json']);
        $row['available_quantity'] = max(0, (int) ($row['stock_quantity'] ?? 0) - (int) ($row['reserved_quantity'] ?? 0) - (int) ($row['sold_quantity'] ?? 0));
        return $row;
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    private function hydrateJsonFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            $decoded = json_decode((string) ($row[$field] ?? 'null'), true);
            $row[preg_replace('/_json$/', '', $field) ?: $field] = $decoded;
        }
        return $row;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function snapshotProduct(array $product): array
    {
        return [
            'id' => (int) $product['id'],
            'sku' => (string) $product['sku'],
            'name' => (string) $product['name'],
            'slug' => (string) $product['slug'],
            'price_minor' => (int) $product['price_minor'],
            'currency' => (string) $product['currency'],
            'region' => (string) ($product['region'] ?? 'CN'),
            'transaction_region' => (string) ($product['transaction_region'] ?? 'cn_domestic'),
            'shipping_fee_minor' => (int) ($product['shipping_fee_minor'] ?? 0),
            'tax_fee_minor' => (int) ($product['tax_fee_minor'] ?? 0),
            'service_fee_minor' => (int) ($product['service_fee_minor'] ?? 0),
            'discount_minor' => (int) ($product['discount_minor'] ?? 0),
            'price_note' => (string) ($product['price_note'] ?? ''),
            'brand' => (string) ($product['brand'] ?? ''),
            'model' => (string) ($product['model'] ?? ''),
            'source_url' => (string) ($product['source_url'] ?? ''),
            'source_claim_text' => (string) ($product['source_claim_text'] ?? ''),
            'primary_media_id' => isset($product['primary_media_id']) ? (int) $product['primary_media_id'] : null,
            'key_fingerprint' => (string) $product['key_fingerprint'],
        ];
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function pricingSnapshot(array $product, int $unit, int $quantity): array
    {
        $subtotal = $unit * $quantity;
        $shippingFee = (int) ($product['shipping_fee_minor'] ?? 0);
        $taxFee = (int) ($product['tax_fee_minor'] ?? 0);
        $serviceFee = (int) ($product['service_fee_minor'] ?? 0);
        $discount = (int) ($product['discount_minor'] ?? 0);
        $total = max(0, $subtotal + $shippingFee + $taxFee + $serviceFee - $discount);

        return [
            'unit_amount_minor' => $unit,
            'quantity' => $quantity,
            'subtotal_minor' => $subtotal,
            'shipping_fee_minor' => $shippingFee,
            'tax_fee_minor' => $taxFee,
            'service_fee_minor' => $serviceFee,
            'discount_minor' => $discount,
            'total_minor' => $total,
            'currency' => (string) $product['currency'],
            'region' => (string) ($product['region'] ?? 'CN'),
            'transaction_region' => (string) ($product['transaction_region'] ?? 'cn_domestic'),
            'price_note' => (string) ($product['price_note'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $variant @return array<string,mixed> */
    private function snapshotVariant(array $variant): array
    {
        return [
            'id' => (int) $variant['id'],
            'sku' => (string) $variant['sku'],
            'title' => (string) $variant['title'],
            'price_delta_minor' => (int) $variant['price_delta_minor'],
            'options' => $variant['options'] ?? [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function productFingerprint(array $payload): string
    {
        $facts = [];
        foreach (self::CHANGE_FIELDS as $field) {
            $facts[$field] = $payload[$field] ?? null;
        }

        return hash('sha256', $this->json($facts));
    }

    /** @param array<string,mixed> $product */
    private function sourceFingerprint(array $product): string
    {
        return hash('sha256', $this->json([
            'source_url' => $product['source_url'] ?? null,
            'source_claim_text' => $product['source_claim_text'] ?? null,
            'brand' => $product['brand'] ?? null,
            'model' => $product['model'] ?? null,
        ]));
    }

    private function slug(string $slug, string $fallback, int $ignoreId = 0): string
    {
        $slug = trim($slug) !== '' ? trim($slug) : $fallback;
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9\p{Han}_-]+/u', '-', $slug) ?? '', '-_'));
        if ($slug === '') {
            $slug = 'product-' . substr(hash('sha256', $fallback), 0, 10);
        }
        $base = substr($slug, 0, 160);
        $candidate = $base;
        $i = 2;
        while ($this->slugExists($candidate, $ignoreId)) {
            $candidate = $base . '-' . $i++;
        }
        return $candidate;
    }

    private function slugExists(string $slug, int $ignoreId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM commerce_products WHERE slug = :slug AND id <> :id LIMIT 1');
        $stmt->execute([':slug' => $slug, ':id' => $ignoreId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param list<string> $allowed */
    private function status(string $status, array $allowed, string $default): string
    {
        $status = trim($status);
        return in_array($status, $allowed, true) ? $status : $default;
    }

    private function cleanText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }

    private function nullableText(string $value, int $max): ?string
    {
        $value = $this->cleanText($value, $max);
        return $value !== '' ? $value : null;
    }

    private function cleanCode(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_.:-]+/', '', trim($value)) ?? '', 0, 128);
    }

    private function nullableUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL 必须是 http/https 地址。');
        }
        return substr($value, 0, 1024);
    }

    private function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return gmdate('Y-m-d H:i:s');
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('时间格式无效。');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function nullableInt(mixed $value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $items[] = $id;
            }
        }
        return array_values(array_unique($items));
    }

    /** @return array<string,string> */
    private function keyValueLines(string $value): array
    {
        $items = [];
        foreach (preg_split('/\R/u', trim($value)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$key, $val] = array_pad(preg_split('/[:：=]/u', $line, 2) ?: [], 2, '');
            $key = $this->cleanText((string) $key, 64);
            if ($key !== '') {
                $items[$key] = $this->cleanText((string) $val, 191);
            }
        }
        return $items;
    }

    /** @param array<mixed> $facts @return array<string,mixed> */
    private function cleanFactMap(array $facts): array
    {
        $items = [];
        foreach ($facts as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = $this->cleanText((string) $key, 96);
            if ($name === '') {
                continue;
            }
            if (is_array($value)) {
                $items[$name] = $this->cleanFactMap($value);
                continue;
            }
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $items[$name] = $value;
                continue;
            }
            $items[$name] = $this->cleanText((string) $value, 1000);
        }

        return $items;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
