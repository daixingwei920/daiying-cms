<?php

declare(strict_types=1);

namespace Cms\Core\CardDelivery;

use Cms\Core\Config\Settings;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use PDO;
use Throwable;

final class CardDeliveryService
{
    private const SUBJECT_TYPE = 'card_delivery_order';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Settings $settings = null,
        private readonly string $encryptionKey = '',
    )
    {
    }

    /** @return array<string,mixed> */
    public function checkout(int $productId, string $providerId, string $idempotencyKey = '', int $quantity = 1): array
    {
        $repo = $this->repository();
        $product = $repo->product($productId);
        if ($product === null || (string) ($product['status'] ?? '') !== 'active') {
            throw new CardDeliveryException('Card product is not available.');
        }
        if ((int) ($product['price_minor'] ?? 0) <= 0) {
            throw new CardDeliveryException('Card product price is invalid.');
        }
        $quantity = $this->normalizeQuantity($quantity, (int) ($product['max_quantity_per_order'] ?? 1));
        $idempotencyKey = $idempotencyKey !== '' ? $this->normalizeCompletionKey($idempotencyKey) : $this->newIdempotencyKey($productId);
        $existingOrder = $repo->orderByIdempotency($idempotencyKey);
        if ($existingOrder !== null && (int) ($existingOrder['product_id'] ?? 0) === $productId && (int) ($existingOrder['quantity'] ?? 0) === $quantity) {
            $existingDelivery = $repo->deliveryForOrder($productId, (string) ($existingOrder['id'] ?? ''));
            if ($existingDelivery !== null) {
                $existingDelivery = $this->deliverySummary($repo->deliveriesForOrder($productId, (string) ($existingOrder['id'] ?? '')), true);
                return [
                    'order' => $existingOrder,
                    'product' => $product,
                    'payment' => [],
                    'delivery' => $existingDelivery,
                    'provider_redirect' => false,
                    'pending_confirmation' => false,
                ];
            }
            $existingPayment = (new PaymentRepository($this->pdo))->paymentByIdempotency($idempotencyKey);
            if (is_array($existingPayment) && (int) ($existingOrder['id'] ?? 0) > 0) {
                if (in_array((string) ($existingPayment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
                    return $this->completeHostedCheckout((int) $existingOrder['id'], $idempotencyKey, $this->completionClaim((int) $existingOrder['id'], $idempotencyKey));
                }
                if (in_array((string) ($existingPayment['status'] ?? ''), ['pending', 'authorized'], true)) {
                    $successPath = '/card-delivery/orders/' . (int) $existingOrder['id'] . '/complete';
                    $claim = $this->completionClaim((int) $existingOrder['id'], $idempotencyKey);
                    $checkoutUrl = $this->providerCheckoutUrl($existingPayment);
                    return [
                        'order' => $existingOrder,
                        'product' => $product,
                        'payment' => $existingPayment,
                        'delivery' => null,
                        'provider_redirect' => $checkoutUrl !== '',
                        'checkout_url' => $checkoutUrl,
                        'pending_confirmation' => $checkoutUrl === '',
                        'completion_url' => $successPath . '?payment_key=' . rawurlencode($idempotencyKey) . '&claim=' . rawurlencode($claim),
                        'instructions' => $this->pendingInstructions($existingPayment),
                    ];
                }
            }
        }
        if ((int) ($product['available_count'] ?? 0) <= 0) {
            throw new CardDeliveryException('Card product is out of stock.');
        }

        $settings = $this->requireSettings();
        $currency = (string) $product['currency'];
        $providerId = $providerId !== ''
            ? (new PaymentProviderSelector($this->pdo, $settings))->requireEnabled($providerId, $currency)
            : (new PaymentProviderSelector($this->pdo, $settings))->defaultProviderId($currency);
        $order = $repo->createOrder(
            $productId,
            (int) $product['price_minor'] * $quantity,
            $currency,
            $idempotencyKey,
            $quantity,
        );
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            throw new CardDeliveryException('Card order could not be created.');
        }
        $successPath = '/card-delivery/orders/' . $orderId . '/complete';
        $claim = $this->completionClaim($orderId, $idempotencyKey);
        $payment = (new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->secret()))->createProviderPayment(
            self::SUBJECT_TYPE,
            $this->subjectId($orderId),
            $providerId,
            (int) $product['price_minor'] * $quantity,
            (string) $product['currency'],
            $idempotencyKey,
            'success',
            [
                'card_product_id' => $productId,
                'card_order_id' => $orderId,
                'success_url' => $successPath . '?payment_key=' . rawurlencode($idempotencyKey) . '&claim=' . rawurlencode($claim),
                'cancel_url' => '/',
            ],
        );
        if (isset($payment['id'])) {
            $repo->attachPayment($orderId, (int) $payment['id']);
        }

        if ((string) ($payment['status'] ?? '') === 'paid') {
            return $this->completeHostedCheckout($orderId, $idempotencyKey, $claim);
        }
        if (in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true)) {
            $checkoutUrl = $this->providerCheckoutUrl($payment);
            return [
                'order' => $repo->order($orderId) ?? $order,
                'product' => $product,
                'payment' => $payment,
                'delivery' => null,
                'provider_redirect' => $checkoutUrl !== '',
                'checkout_url' => $checkoutUrl,
                'pending_confirmation' => $checkoutUrl === '',
                'completion_url' => $successPath . '?payment_key=' . rawurlencode($idempotencyKey) . '&claim=' . rawurlencode($claim),
                'instructions' => $this->pendingInstructions($payment),
            ];
        }

        throw new PaymentException('Card delivery payment was not completed.');
    }

    /** @return array<string,mixed> */
    public function completeHostedCheckout(int $orderId, string $idempotencyKey, string $claim): array
    {
        $repo = $this->repository();
        $idempotencyKey = $this->normalizeCompletionKey($idempotencyKey);
        $claim = $this->normalizeCompletionClaim($claim);
        $order = $repo->order($orderId);
        if ($order === null) {
            throw new CardDeliveryException('Card order was not found.');
        }
        $productId = (int) ($order['product_id'] ?? 0);
        $product = $repo->product($productId);
        if ($product === null) {
            throw new CardDeliveryException('Card product was not found.');
        }
        if (!$this->completionClaimValid($orderId, $idempotencyKey, $claim)) {
            throw new PaymentException('Card delivery completion claim is invalid.');
        }

        $paymentRepo = new PaymentRepository($this->pdo);
        $payment = $paymentRepo->paymentByIdempotency($idempotencyKey);
        if (is_array($payment) && in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true)) {
            $payment = (new PaymentService($this->pdo, $paymentRepo, $this->secret()))->settleHostedCheckoutPayment((int) $payment['id'], $idempotencyKey);
            if (in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true)) {
                $checkoutUrl = $this->providerCheckoutUrl($payment);
                return [
                    'order' => $order,
                    'product' => $product,
                    'payment' => $payment,
                    'delivery' => null,
                    'provider_redirect' => $checkoutUrl !== '',
                    'checkout_url' => $checkoutUrl,
                    'pending_confirmation' => $checkoutUrl === '',
                    'completion_url' => '/card-delivery/orders/' . $orderId . '/complete?payment_key=' . rawurlencode($idempotencyKey) . '&claim=' . rawurlencode($claim),
                    'instructions' => $this->pendingInstructions($payment),
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $payment = $paymentRepo->paymentByIdempotencyForUpdate($idempotencyKey);
            if (!is_array($payment)
                || (string) ($payment['subject_type'] ?? '') !== self::SUBJECT_TYPE
                || (string) ($payment['subject_id'] ?? '') !== $this->subjectId($orderId)
            ) {
                throw new PaymentException('Card delivery payment is not complete.');
            }
            if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
                throw new PaymentException('Card delivery payment is not complete.');
            }
            $trusted = $paymentRepo->trustedStatus(self::SUBJECT_TYPE, $this->subjectId($orderId), (string) ($payment['currency'] ?? ''));
            if ((string) ($trusted['status'] ?? '') !== 'paid') {
                throw new PaymentException('Card delivery payment is not trusted.');
            }
            $repo->markOrderPaid($orderId, (int) $payment['id']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $delivery = $this->deliverPaidOrder($productId, (string) $orderId, (string) ($payment['remote_id'] ?? $payment['id'] ?? ''), (int) ($order['quantity'] ?? 1));
        $fulfillmentStatus = (string) ($delivery['status'] ?? '') === 'delivered' ? 'delivered' : 'out_of_stock';
        $repo->markOrderFulfilled($orderId, $fulfillmentStatus, isset($delivery['id']) ? (int) $delivery['id'] : null, $this->deliveryIds($delivery));

        return [
            'order' => $repo->order($orderId) ?? $order,
            'product' => $product,
            'payment' => $payment,
            'delivery' => $delivery,
            'provider_redirect' => false,
            'pending_confirmation' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function deliverPaidOrder(int $productId, string $orderId, string $transactionId = '', int $quantity = 1): array
    {
        if ($productId <= 0 || $orderId === '' || $orderId !== trim($orderId) || strlen($orderId) > 191) {
            throw new CardDeliveryException('Card delivery order context is invalid.');
        }
        if ($transactionId !== '' && ($transactionId !== trim($transactionId) || strlen($transactionId) > 191)) {
            throw new CardDeliveryException('Card delivery transaction context is invalid.');
        }
        $quantity = max(1, min(999, $quantity));

        $this->pdo->beginTransaction();
        try {
            $existingItems = $this->existingDeliveries($productId, $orderId);
            if ($this->deliveredCount($existingItems) >= $quantity) {
                $this->pdo->commit();
                return $this->deliverySummary($existingItems, true);
            }

            $items = $existingItems;
            $now = gmdate('c');
            $existingIndexes = [];
            $retryDeliveryIds = [];
            foreach ($items as $item) {
                $index = (int) ($item['order_item_index'] ?? 1);
                if ((string) ($item['status'] ?? '') === 'delivered') {
                    $existingIndexes[$index] = true;
                } elseif ((string) ($item['status'] ?? '') === 'out_of_stock' && isset($item['id'])) {
                    $retryDeliveryIds[$index] = (int) $item['id'];
                }
            }
            for ($index = 1; $index <= $quantity; $index++) {
                if (isset($existingIndexes[$index])) {
                    continue;
                }
                $inventory = $this->reserveInventory($productId);
                if ($inventory === null) {
                    if (isset($retryDeliveryIds[$index])) {
                        continue;
                    }
                    $deliveryId = $this->createDelivery($productId, null, $orderId, $index, $transactionId, 'out_of_stock', $now, null, ['reason' => 'inventory_unavailable']);
                    $items[] = ['id' => $deliveryId, 'status' => 'out_of_stock', 'inventory_id' => null, 'order_item_index' => $index];
                    continue;
                }

                $deliveryId = $retryDeliveryIds[$index] ?? 0;
                if ($deliveryId > 0) {
                    $this->updateDeliveryToDelivered($deliveryId, (int) $inventory['id'], $transactionId, $now);
                } else {
                    $deliveryId = $this->createDelivery($productId, (int) $inventory['id'], $orderId, $index, $transactionId, 'delivered', $now, $now, []);
                }
                $this->pdo->prepare("UPDATE cms_card_inventory SET status = 'delivered', order_id = :order_id, delivery_id = :delivery_id, delivered_at = :delivered_at WHERE id = :id AND status = 'reserved'")
                    ->execute([
                        ':id' => (int) $inventory['id'],
                        ':order_id' => $orderId,
                        ':delivery_id' => $deliveryId,
                        ':delivered_at' => $now,
                    ]);
                $secret = $this->repository()->secretForInventory((int) $inventory['id']) ?? (string) $inventory['secret_value'];
                $items[] = ['id' => $deliveryId, 'status' => 'delivered', 'inventory_id' => (int) $inventory['id'], 'order_item_index' => $index, 'secret' => $secret];
            }
            $this->pdo->commit();

            return $this->deliverySummary($this->existingDeliveries($productId, $orderId), false);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function existingDeliveries(int $productId, string $orderId): array
    {
        return $this->repository()->deliveriesForOrder($productId, $orderId);
    }

    /** @return array<string,mixed>|null */
    private function reserveInventory(int $productId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cms_card_inventory WHERE product_id = :product_id AND status = 'available' ORDER BY id LIMIT 1");
        $stmt->execute([':product_id' => $productId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $updated = $this->pdo->prepare("UPDATE cms_card_inventory SET status = 'reserved' WHERE id = :id AND status = 'available'");
        $updated->execute([':id' => (int) $row['id']]);
        if ($updated->rowCount() !== 1) {
            return null;
        }

        return $row;
    }

    /** @param array<string,mixed> $metadata */
    private function createDelivery(int $productId, ?int $inventoryId, string $orderId, int $orderItemIndex, string $transactionId, string $status, string $createdAt, ?string $deliveredAt, array $metadata): int
    {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_card_deliveries
                (product_id, inventory_id, order_id, order_item_index, transaction_id, status, created_at, delivered_at, metadata_json)
             VALUES
                (:product_id, :inventory_id, :order_id, :order_item_index, :transaction_id, :status, :created_at, :delivered_at, :metadata_json)'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':inventory_id' => $inventoryId,
            ':order_id' => $orderId,
            ':order_item_index' => $orderItemIndex,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':status' => $status,
            ':created_at' => $createdAt,
            ':delivered_at' => $deliveredAt,
            ':metadata_json' => $json,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateDeliveryToDelivered(int $deliveryId, int $inventoryId, string $transactionId, string $deliveredAt): void
    {
        $json = json_encode(['resolved_from' => 'out_of_stock'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare(
            "UPDATE cms_card_deliveries
             SET inventory_id = :inventory_id, transaction_id = :transaction_id, status = 'delivered', delivered_at = :delivered_at, metadata_json = :metadata_json
             WHERE id = :id AND status = 'out_of_stock'"
        )->execute([
            ':id' => $deliveryId,
            ':inventory_id' => $inventoryId,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':delivered_at' => $deliveredAt,
            ':metadata_json' => is_string($json) ? $json : '{}',
        ]);
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function deliverySummary(array $items, bool $idempotent): array
    {
        usort($items, static fn (array $a, array $b): int => ((int) ($a['order_item_index'] ?? 1)) <=> ((int) ($b['order_item_index'] ?? 1)));
        $first = $items[0] ?? ['id' => 0, 'status' => 'out_of_stock', 'inventory_id' => null];
        $deliveredCount = 0;
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') === 'delivered') {
                $deliveredCount++;
            }
        }
        $status = $deliveredCount === count($items) ? 'delivered' : 'out_of_stock';

        return array_merge($first, [
            'status' => $status,
            'items' => $items,
            'requested_quantity' => count($items),
            'delivered_count' => $deliveredCount,
            'idempotent' => $idempotent,
        ]);
    }

    /** @param list<array<string,mixed>> $items */
    private function deliveredCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') === 'delivered') {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string,mixed> $delivery @return list<int> */
    private function deliveryIds(array $delivery): array
    {
        $ids = [];
        foreach (($delivery['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['id']) && (int) $item['id'] > 0) {
                $ids[] = (int) $item['id'];
            }
        }
        if ($ids === [] && isset($delivery['id']) && (int) $delivery['id'] > 0) {
            $ids[] = (int) $delivery['id'];
        }

        return $ids;
    }

    private function normalizeQuantity(int $quantity, int $maxQuantity): int
    {
        $maxQuantity = max(1, min(999, $maxQuantity));
        if ($quantity < 1 || $quantity > $maxQuantity) {
            throw new CardDeliveryException('Card order quantity is invalid.');
        }

        return $quantity;
    }

    private function repository(): CardDeliveryRepository
    {
        return new CardDeliveryRepository($this->pdo, $this->secret(false));
    }

    private function subjectId(int $orderId): string
    {
        return 'order:' . $orderId;
    }

    private function requireSettings(): Settings
    {
        if ($this->settings === null) {
            throw new CardDeliveryException('Card delivery payment settings are unavailable.');
        }

        return $this->settings;
    }

    private function secret(bool $required = true): string
    {
        $secret = $this->encryptionKey !== ''
            ? $this->encryptionKey
            : (string) ($this->settings?->get('security.encryption_key', '') ?? '');
        if (!$required) {
            return $secret;
        }
        if (
            $secret === ''
            || $secret !== trim($secret)
            || strlen($secret) < 16
            || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1
        ) {
            throw new PaymentException('Payment token signing key is not configured.');
        }

        return $secret;
    }

    private function newIdempotencyKey(int $productId): string
    {
        return 'card-' . $productId . '-' . bin2hex(random_bytes(16));
    }

    private function completionClaim(int $orderId, string $idempotencyKey): string
    {
        return hash_hmac('sha256', 'card_delivery|' . $orderId . '|' . $idempotencyKey, $this->secret());
    }

    private function completionClaimValid(int $orderId, string $idempotencyKey, string $claim): bool
    {
        return hash_equals($this->completionClaim($orderId, $idempotencyKey), $claim);
    }

    private function normalizeCompletionKey(string $idempotencyKey): string
    {
        if (
            $idempotencyKey === ''
            || $idempotencyKey !== trim($idempotencyKey)
            || strlen($idempotencyKey) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1
        ) {
            throw new PaymentException('Card delivery completion key is invalid.');
        }

        return $idempotencyKey;
    }

    private function normalizeCompletionClaim(string $claim): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $claim) !== 1) {
            throw new PaymentException('Card delivery completion claim is invalid.');
        }

        return $claim;
    }

    /** @param array<string,mixed> $payment */
    private function providerCheckoutUrl(array $payment): string
    {
        $providerId = is_string($payment['provider_id'] ?? null) ? (string) $payment['provider_id'] : '';
        $transientUrl = $payment['_provider_checkout_url'] ?? '';
        if (is_string($transientUrl) && $this->isSafeProviderRedirectUrlForProvider($providerId, $transientUrl)) {
            return $transientUrl;
        }
        $metadata = $this->paymentMetadata($payment);
        foreach (['checkout_url', 'payment_url', 'redirect_url'] as $key) {
            $url = $metadata[$key] ?? '';
            if (is_string($url) && $this->isSafeProviderRedirectUrlForProvider($providerId, $url)) {
                return $url;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $payment */
    private function pendingInstructions(array $payment): string
    {
        $metadata = $this->paymentMetadata($payment);
        $instructions = $metadata['manual_instructions'] ?? '';
        if (!is_string($instructions) || $instructions !== trim($instructions) || strlen($instructions) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instructions) === 1) {
            return '';
        }

        return $instructions;
    }

    /** @param array<string,mixed> $payment @return array<string,mixed> */
    private function paymentMetadata(array $payment): array
    {
        $raw = $payment['metadata_json'] ?? '{}';
        if (!is_string($raw)) {
            return [];
        }
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function isSafeProviderRedirectUrlForProvider(string $providerId, string $url): bool
    {
        if ($providerId === 'official.payment.stripe' && $this->isSafeStripeCheckoutRedirectUrl($url)) {
            return true;
        }

        return $this->isSafeProviderRedirectUrl($url);
    }

    private function isSafeStripeCheckoutRedirectUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 262144 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'checkout.stripe.com'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }
        if (!str_starts_with((string) ($parts['path'] ?? ''), '/c/pay/')) {
            return false;
        }
        if (preg_match('#cs_(?:test|live)_[A-Za-z0-9_=-]+#', $url) !== 1) {
            return false;
        }
        if (!$this->isSafeStripeOwnedUrlPart((string) ($parts['query'] ?? ''), 65536)
            || !$this->isSafeStripeOwnedUrlPart((string) ($parts['fragment'] ?? ''), 196608)
        ) {
            return false;
        }

        return true;
    }

    private function isSafeStripeOwnedUrlPart(string $value, int $maxLength): bool
    {
        return strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function providerRedirectPartContainsSecret(string $value): bool
    {
        return preg_match('/(?:bearer\s+|sk_(test|live)?_|api[_-]?key=|access[_-]?key=|secret=|authorization=)/i', rawurldecode($value)) === 1;
    }

    private function isSafeProviderRedirectUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        return true;
    }
}
