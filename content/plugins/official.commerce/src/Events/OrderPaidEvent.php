<?php

declare(strict_types=1);

namespace Daiying\Commerce\Events;

final class OrderPaidEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly ?int $frontUserId,
        public readonly ?string $buyerEmail,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $providerId,
        public readonly ?int $paymentId,
        public readonly ?string $transactionId,
        public readonly string $paidAt,
        public readonly string $idempotencyKey,
    ) {
    }
}
