<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class PaymentProviderAdapterFactory
{
    public function make(string $provider): PaymentProviderAdapterInterface
    {
        return new GenericPaymentProviderAdapter(strtolower(trim($provider)));
    }
}
