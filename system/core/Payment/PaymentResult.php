<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

final class PaymentResult
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly string $message,
        public readonly bool $retryable = false,
        public readonly string $requestId = '',
        public readonly array $data = [],
    ) {
    }
}
