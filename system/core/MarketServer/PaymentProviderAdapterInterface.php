<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

interface PaymentProviderAdapterInterface
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function normalize(array $payload): array;
}
