<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

interface PaymentSignatureStrategyInterface
{
    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $signature): bool;
}
