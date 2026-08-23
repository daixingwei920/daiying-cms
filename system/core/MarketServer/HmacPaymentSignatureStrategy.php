<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class HmacPaymentSignatureStrategy implements PaymentSignatureStrategyInterface
{
    public function __construct(private readonly string $secret)
    {
    }

    public function verify(array $payload, string $signature): bool
    {
        return (new PaymentWebhookVerifier($this->secret))->verify($payload, $signature);
    }
}
