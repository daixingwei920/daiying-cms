<?php

declare(strict_types=1);

namespace Official\Restaurant;

use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Daiying\Commerce\CommerceRepository;
use PDO;
use RuntimeException;

final class RestaurantCheckoutService
{
    public function __construct(
        private readonly RestaurantRepository $restaurant,
        private readonly RestaurantTicketService $tickets,
        private readonly CommerceRepository $commerce,
        private readonly PDO $pdo,
        private readonly string $paymentSecret,
        private readonly ?PaymentService $payments = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function prepareCommerceOrder(string $token, string $providerId, array $customer = []): array
    {
        $summary = $this->restaurant->cartSummary($token);
        $cart = $summary['cart'] ?? null;
        $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
        if (!is_array($cart) || $items === []) {
            throw new RuntimeException('餐车为空，不能提交订单。');
        }
        if (!in_array((string) ($cart['status'] ?? ''), ['open', 'checkout_started'], true)) {
            throw new RuntimeException('餐车已经提交，不能重复下单。');
        }
        if ((int) ($summary['total_minor'] ?? 0) <= 0) {
            throw new RuntimeException('餐车金额无效。');
        }
        if ((int) ($cart['commerce_order_id'] ?? 0) === 0 && !($summary['tax']['configured'] ?? false)) {
            throw new RuntimeException('请先由门店确认并配置税率及配送费计税规则，不能默认按零税率收款。');
        }
        if (!method_exists($this->commerce, 'updatePendingOrderClaim') || !method_exists($this->commerce, 'orderPaymentState')) {
            throw new RuntimeException('餐厅结账需要官方 Commerce 1.0.8 或更新的兼容版本。');
        }
        if ((int) ($cart['commerce_order_id'] ?? 0) === 0) {
            $this->restaurant->assertCartSellable($cart, $items);
        }
        $providerId = trim($providerId);
        if ($providerId === '') {
            throw new RuntimeException('请选择支付方式。');
        }
        if ((int) ($cart['commerce_order_id'] ?? 0) > 0 && $providerId !== (string) $cart['payment_provider_id']) {
            throw new RuntimeException('已开始支付的订单不能更换支付方式。');
        }
        $mode = (string) ($customer['service_mode'] ?? $cart['service_mode'] ?? 'pickup');
        if ((int) ($cart['commerce_order_id'] ?? 0) > 0
            && trim((string) ($customer['delivery_address'] ?? '')) !== trim((string) ($cart['delivery_address'] ?? ''))) {
            throw new RuntimeException('已开始支付的订单不能更改配送地址。');
        }
        $fulfillment = $this->restaurant->validateFulfillment($mode, trim((string) ($customer['fulfillment_at'] ?? '')), (string) ($customer['delivery_address'] ?? ''), (string) ($customer['delivery_postal_code'] ?? ''));
        $this->restaurant->configureCartFulfillment($token, $mode, $fulfillment['due_at'], $fulfillment['fee_minor'], (string) ($customer['delivery_postal_code'] ?? ''));
        $summary = $this->restaurant->cartSummary($token);
        $cart = $summary['cart'];
        if (isset($customer['expected_total_minor']) && (filter_var($customer['expected_total_minor'], FILTER_VALIDATE_INT) === false || (int) $customer['expected_total_minor'] !== $summary['total_minor'])) {
            throw new RuntimeException('订单金额已变化，请刷新购物车并确认税费及总额。');
        }

        $commerceProductId = ((int) ($cart['commerce_product_id'] ?? 0)) > 0
            ? (int) $cart['commerce_product_id']
            : $this->createCommerceCartProduct($token, $summary, $customer);
        $commerceActionId = ((int) ($cart['commerce_action_id'] ?? 0)) > 0
            ? (int) $cart['commerce_action_id']
            : $this->commerce->saveAction([
                'product_id' => $commerceProductId,
                'action_type' => 'site_checkout',
                'label' => '餐厅订单支付',
                'fulfillment_mode' => 'none',
                'status' => 'active',
            ]);

        $idempotency = (string) ($cart['idempotency_key'] ?? '');
        if ($idempotency === '') {
            $idempotency = 'restaurant-' . substr(hash('sha256', $token . '|' . random_bytes(16)), 0, 40);
        }
        $claim = $this->claim(0, $idempotency);
        $order = (int) ($cart['commerce_order_id'] ?? 0) > 0
            ? $this->commerce->order((int) $cart['commerce_order_id'])
            : $this->commerce->createPendingOrder(
            $commerceProductId,
            null,
            $commerceActionId,
            1,
            $providerId,
            $idempotency,
            $claim,
            [
                'name' => (string) ($customer['customer_name'] ?? $cart['customer_name'] ?? ''),
                'email' => (string) ($customer['customer_email'] ?? $cart['customer_email'] ?? ''),
                'phone' => (string) ($customer['customer_phone'] ?? $cart['customer_phone'] ?? ''),
            ],
        );
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Commerce 订单创建失败。');
        }
        if ($order['status'] !== 'pending_payment' || (int) $order['product_id'] !== $commerceProductId
            || $order['idempotency_key'] !== $idempotency || $order['provider_id'] !== $providerId
            || $order['currency'] !== $summary['currency'] || (int) ($order['amount_minor'] ?? -1) !== $summary['total_minor']) {
            throw new RuntimeException('Commerce 订单金额与餐车含税总额不一致，已停止支付。');
        }
        $claim = $this->claim($orderId, $idempotency);
        $this->commerce->updatePendingOrderClaim($orderId, $claim);

        $this->restaurant->updateCartCheckout($token, [
            'status' => 'checkout_started',
            'service_mode' => (string) ($customer['service_mode'] ?? $cart['service_mode'] ?? 'pickup'),
            'customer_name' => (string) ($customer['customer_name'] ?? ''),
            'customer_phone' => (string) ($customer['customer_phone'] ?? ''),
            'customer_email' => (string) ($customer['customer_email'] ?? ''),
            'delivery_address' => (string) ($customer['delivery_address'] ?? ''),
            'payment_provider_id' => $providerId,
            'commerce_product_id' => $commerceProductId,
            'commerce_action_id' => $commerceActionId,
            'commerce_order_id' => $orderId,
            'idempotency_key' => $idempotency,
            'completion_claim' => $claim,
        ]);

        return [
            'cart' => $this->restaurant->cartByToken($token),
            'order' => $this->commerce->order($orderId) ?? $order,
            'payment_key' => $idempotency,
            'claim' => $claim,
            'success_url' => '/restaurant/checkout/complete?order_id=' . $orderId . '&claim=' . rawurlencode($claim),
            'cancel_url' => '/restaurant',
        ];
    }

    /** @return array<string,mixed> */
    public function beginHostedPayment(string $token, string $providerId, array $customer = []): array
    {
        if ($this->payments === null) {
            throw new RuntimeException('支付服务不可用。');
        }
        $summary = $this->restaurant->cartSummary($token);
        $currency = (string) ($summary['currency'] ?? '');
        $available = array_column($this->payments->enabledProviders($currency), 'id');
        if (!in_array($providerId, $available, true)) {
            throw new RuntimeException('支付方式不可用。');
        }
        $prepared = $this->prepareCommerceOrder($token, $providerId, $customer);
        $order = is_array($prepared['order'] ?? null) ? $prepared['order'] : [];
        $orderId = (int) ($order['id'] ?? 0);
        $payment = $this->payments->createProviderPayment(
            'commerce_order',
            'order:' . $orderId,
            $providerId,
            (int) ($order['amount_minor'] ?? 0),
            (string) ($order['currency'] ?? 'CNY'),
            (string) $prepared['payment_key'],
            'success',
            [
                'commerce_order_id' => $orderId,
                'success_url' => (string) $prepared['success_url'],
                'cancel_url' => (string) $prepared['cancel_url'],
            ],
        );
        if (isset($payment['id'])) {
            $this->commerce->attachPayment($orderId, (int) $payment['id']);
        }
        if ((string) ($payment['status'] ?? '') === 'paid') {
            $this->commerce->markOrderPaid($orderId);
            $this->createKitchenTicketForOrder($orderId);
        }
        $prepared['payment'] = $payment;

        return $prepared;
    }

    /** @return array<string,mixed> */
    public function completeHostedPayment(int $orderId, string $claim): array
    {
        $cart = $this->restaurant->cartByCommerceOrder($orderId);
        $order = $this->commerce->order($orderId);
        $paymentKey = (string) ($cart['idempotency_key'] ?? '');
        if ($cart === null || $order === null || $paymentKey === '' || $claim === '') {
            throw new RuntimeException('餐厅订单无效。');
        }
        if (!hash_equals((string) ($cart['completion_claim'] ?? ''), $claim)
            || !hash_equals((string) ($order['completion_claim'] ?? ''), $claim)
            || !hash_equals($this->claim($orderId, $paymentKey), $claim)) {
            throw new RuntimeException('订单校验未通过。');
        }
        if ($this->payments !== null && !empty($order['payment_id'])) {
            $payment = $this->payments->settleHostedCheckoutPayment((int) $order['payment_id'], $paymentKey);
            $trusted = (new PaymentRepository($this->pdo))->trustedStatus('commerce_order', 'order:' . $orderId, (string) ($order['currency'] ?? ''));
            if ((string) ($trusted['status'] ?? '') === 'paid' && (string) ($payment['status'] ?? '') === 'paid') {
                $this->commerce->markOrderPaid($orderId);
            }
        }

        $trusted = (new PaymentRepository($this->pdo))->trustedStatus('commerce_order', 'order:' . $orderId, (string) $order['currency']);
        $order = $this->commerce->order($orderId);
        $ticketId = $this->restaurant->ticketIdForCommerceOrder($orderId);
        if ($order === null || !in_array($order['status'], ['paid', 'fulfilled'], true)
            || $cart['currency'] !== $order['currency'] || (int) $cart['total_minor'] !== (int) $order['amount_minor']
            || ($trusted['status'] ?? '') !== 'paid' || (int) ($trusted['net_paid_minor'] ?? 0) < (int) $order['amount_minor']
            || (int) ($trusted['refunded_minor'] ?? 0) !== 0
            || ($ticketId !== null && ($this->restaurant->ticket($ticketId)['status'] ?? '') === 'cancelled')) {
            throw new RuntimeException('付款未确认，或订单已取消／退款；请联系门店核实。');
        }

        return $this->createKitchenTicketForOrder($orderId);
    }

    /** @return array<string,mixed> */
    public function createKitchenTicketForOrder(int $orderId): array
    {
        $existing = $this->restaurant->ticketIdForCommerceOrder($orderId);
        if ($existing !== null) {
            $existingCart = $this->restaurant->cartByCommerceOrder($orderId);
            $existingOrder = $this->commerce->order($orderId);
            if ($existingCart !== null && $existingOrder !== null && in_array($existingOrder['status'], ['paid', 'fulfilled'], true) && in_array($existingCart['status'], ['checkout_started', 'paid'], true) && $existingCart['currency'] === $existingOrder['currency'] && (int) $existingCart['total_minor'] === (int) $existingOrder['amount_minor']) {
                $this->restaurant->markCartPaid((string) $existingCart['token']);
                $this->restaurant->markCartTicketed((string) $existingCart['token'], $existing);
            }
            return [
                'created' => false,
                'ticket_id' => $existing,
                'print_job_ids' => [],
                'cart' => $this->restaurant->cartByCommerceOrder($orderId),
                'order' => $this->commerce->order($orderId),
            ];
        }
        $cart = $this->restaurant->cartByCommerceOrder($orderId);
        $order = $this->commerce->order($orderId);
        if ($cart === null || $order === null) {
            throw new RuntimeException('找不到餐厅餐车或 Commerce 订单。');
        }
        if (!in_array((string) ($order['status'] ?? ''), ['paid', 'fulfilled'], true)) {
            throw new RuntimeException('Commerce 订单尚未支付，不能生成厨房票。');
        }
        $items = array_map(static function (array $item): array {
            return [
                'menu_item_id' => (int) ($item['menu_item_id'] ?? 0),
                'commerce_product_id' => null,
                'station_code' => (string) ($item['station_code'] ?? 'front'),
                'item_name' => (string) ($item['item_name'] ?? '菜品'),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'notes' => (string) ($item['notes'] ?? ''),
                'modifiers' => is_array($item['modifiers'] ?? null) ? $item['modifiers'] : [],
            ];
        }, $this->restaurant->cartItems((int) $cart['id']));

        if ((string) $cart['currency'] !== (string) $order['currency'] || (int) $cart['total_minor'] !== (int) $order['amount_minor']) {
            throw new RuntimeException('餐车金额或币种与 Commerce 订单不一致，停止出餐。');
        }
        try {
            $result = $this->tickets->createTicketWithPrintJobs([
            'commerce_order_id' => $orderId,
            'customer_name' => (string) ($cart['customer_name'] ?? $order['buyer_name'] ?? ''),
            'customer_phone' => (string) ($cart['customer_phone'] ?? $order['buyer_phone'] ?? ''),
            'service_mode' => (string) ($cart['service_mode'] ?? 'pickup'),
            'due_at' => $cart['payload']['fulfillment_at'] ?? null,
            'source' => 'commerce',
            'payload' => [
                'commerce_order_number' => (string) ($order['order_number'] ?? ''),
                'currency' => (string) ($cart['currency'] ?? $order['currency'] ?? 'CNY'),
                'total_minor' => (int) ($cart['total_minor'] ?? $order['amount_minor'] ?? 0),
                'delivery_address' => (string) ($cart['delivery_address'] ?? ''),
                'delivery_postal_code' => (string) ($cart['payload']['delivery_postal_code'] ?? ''),
                'fee_minor' => (int) ($cart['fee_minor'] ?? 0),
            ],
            ], $items);
        } catch (\PDOException $exception) {
            $concurrentTicket = $this->restaurant->ticketIdForCommerceOrder($orderId);
            if ($concurrentTicket === null) {
                throw $exception;
            }
            $this->restaurant->markCartPaid((string) $cart['token']);
            $this->restaurant->markCartTicketed((string) $cart['token'], $concurrentTicket);
            return ['created' => false, 'ticket_id' => $concurrentTicket, 'print_job_ids' => []];
        }
        $this->restaurant->markCartPaid((string) $cart['token']);
        $this->restaurant->markCartTicketed((string) $cart['token'], (int) $result['ticket_id']);

        return $result + [
            'created' => true,
            'cart' => $this->restaurant->cartByToken((string) $cart['token']),
            'order' => $this->commerce->order($orderId),
        ];
    }

    /** @return array{checked:int,created:int,skipped:int,errors:int,held:int,next_cursor:int} */
    public function syncPaidOrders(int $limit = 50, int $afterId = 0): array
    {
        $result = ['checked' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0, 'held' => 0, 'next_cursor' => 0];
        foreach ($this->restaurant->paidCartsNeedingTickets($limit, $afterId) as $cart) {
            $result['checked']++;
            $result['next_cursor'] = (int) $cart['id'];
            try {
                $ticket = $this->reconcileOrder((int) ($cart['commerce_order_id'] ?? 0));
                if (($ticket['held'] ?? false) === true) {
                    $result['held']++;
                }
                if (($ticket['created'] ?? false) === true) {
                    $result['created']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable) {
                $result['errors']++;
            }
        }

        return $result;
    }

    public function orderReceiptForCart(string $token): ?array
    {
        $cart = $this->restaurant->cartByToken($token);
        $orderId = (int) ($cart['commerce_order_id'] ?? 0);
        $order = $orderId > 0 ? $this->commerce->order($orderId) : null;
        if ($cart === null || $order === null || $cart['currency'] !== $order['currency'] || (int) $cart['total_minor'] !== (int) $order['amount_minor']) {
            return null;
        }
        $trusted = (new PaymentRepository($this->pdo))->trustedStatus('commerce_order', 'order:' . $orderId, (string) $order['currency']);
        $refundedMinor = (int) ($trusted['refunded_minor'] ?? 0);
        $paid = ($trusted['status'] ?? '') === 'paid'
            && (int) ($trusted['net_paid_minor'] ?? 0) >= (int) $order['amount_minor']
            && $refundedMinor === 0
            && in_array($order['status'], ['paid', 'fulfilled'], true);
        $paymentStatus = $paid ? 'paid' : 'unconfirmed';
        if ($refundedMinor > 0) {
            $paymentStatus = (int) ($trusted['net_paid_minor'] ?? 0) <= 0 ? 'refunded' : 'partially_refunded';
        } elseif (in_array($order['status'], ['cancelled', 'refunded'], true)) {
            $paymentStatus = $order['status'];
        }
        $ticketId = $this->restaurant->ticketIdForCommerceOrder($orderId);
        $ticket = $ticketId === null ? null : $this->restaurant->ticket($ticketId);
        $ticketStatus = (string) ($ticket['status'] ?? '');
        return [
            'order_id' => $orderId,
            'ticket_number' => (string) ($ticket['ticket_number'] ?? ''),
            'payment_status' => $paymentStatus,
            'ticket_status' => $ticketStatus,
            'service_mode' => (string) $cart['service_mode'],
            'total' => $this->restaurant->minorToMoney((int) $order['amount_minor']),
            'currency' => (string) $order['currency'],
            'due_local' => $this->restaurant->formatDueLocal((string) ($ticket['due_at'] ?? '')),
            'pickup_eligible' => $paid && $cart['service_mode'] === 'pickup' && $ticketStatus === 'ready',
            'completed' => $ticketStatus === 'completed',
        ];
    }

    public function reconcileOrder(int $orderId): array
    {
        $cart = $this->restaurant->cartByCommerceOrder($orderId);
        $order = $this->commerce->order($orderId);
        if ($cart === null || $order === null || $cart['currency'] !== $order['currency'] || (int) $cart['total_minor'] !== (int) $order['amount_minor']) {
            throw new RuntimeException('餐厅订单映射、金额或币种未通过验证。');
        }
        $ticketId = $this->restaurant->ticketIdForCommerceOrder($orderId);
        $trusted = (new PaymentRepository($this->pdo))->trustedStatus('commerce_order', 'order:' . $orderId, (string) $order['currency']);
        $cancelled = in_array($order['status'], ['cancelled', 'refunded'], true);
        $refunded = (int) ($trusted['refunded_minor'] ?? 0) > 0;
        if ($cancelled || $refunded) {
            if ($ticketId !== null) {
                if ($cancelled || (int) ($trusted['net_paid_minor'] ?? 0) === 0) {
                    if (($this->restaurant->ticket($ticketId)['status'] ?? '') !== 'cancelled') {
                        $this->restaurant->updateTicketStatus($ticketId, 'cancelled');
                    }
                } else {
                    $this->restaurant->holdTicketPrintJobs($ticketId, 'REFUND_REQUIRES_MANUAL_REVIEW');
                }
            }
            return ['created' => false, 'held' => true, 'print_allowed' => false, 'ticket_id' => $ticketId];
        }
        if (($trusted['status'] ?? '') !== 'paid' || (int) ($trusted['net_paid_minor'] ?? 0) < (int) $order['amount_minor']) {
            return ['created' => false, 'held' => false, 'print_allowed' => false, 'ticket_id' => $ticketId];
        }
        if ($order['status'] === 'pending_payment') {
            $this->commerce->markOrderPaid($orderId);
        }
        return $this->createKitchenTicketForOrder($orderId) + ['print_allowed' => true];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $customer */
    private function createCommerceCartProduct(string $token, array $summary, array $customer): int
    {
        $location = $this->restaurant->storefrontSettings();
        $settings = json_decode((string) ($location['settings_json'] ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        $merchantRegion = strtoupper(trim((string) ($settings['merchant_region'] ?? '')));
        $transactionRegion = trim((string) ($settings['transaction_region'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $merchantRegion) !== 1
            || !in_array($transactionRegion, ['cn_domestic', 'cross_border', 'international'], true)) {
            throw new RuntimeException('请先在店面设置中配置商户国家与 Commerce 交易区域。');
        }
        $short = strtoupper(substr(hash('sha256', $token), 0, 10));
        $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
        $lines = [];
        foreach ($items as $item) {
            $lines[] = ((int) ($item['quantity'] ?? 1)) . ' x ' . (string) ($item['item_name'] ?? '菜品');
        }

        return $this->commerce->saveProduct([
            'sku' => 'REST-CART-' . $short,
            'slug' => 'restaurant-order-' . strtolower($short),
            'name' => '餐厅订单 ' . $short,
            'status' => 'active',
            'summary' => implode('；', array_slice($lines, 0, 8)),
            'price' => $this->restaurant->minorToMoney((int) ($summary['subtotal_minor'] ?? 0)),
            'shipping_fee_minor' => (int) ($summary['fee_minor'] ?? 0),
            'tax_fee_minor' => (int) ($summary['tax_minor'] ?? 0),
            'currency' => (string) ($summary['currency'] ?? 'CNY'),
            'region' => $merchantRegion,
            'transaction_region' => $transactionRegion,
            'price_note' => 'Restaurant checkout aggregate. Kitchen details are stored in Restaurant tickets.',
            'brand' => 'Restaurant',
            'model' => (string) ($customer['service_mode'] ?? $summary['cart']['service_mode'] ?? 'pickup'),
            'specs' => "purchase_mode: restaurant\nitems: " . count($items),
            'requires_shipping' => 0,
            'auto_delivery_enabled' => 0,
            'stock_quantity' => 1,
            'purchase_mode' => 'local',
        ]);
    }

    private function claim(int $orderId, string $paymentKey): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentKey, $this->paymentSecret);
    }
}
