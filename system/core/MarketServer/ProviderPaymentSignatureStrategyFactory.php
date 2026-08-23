<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class ProviderPaymentSignatureStrategyFactory
{
    /** @param array<string, string> $providerSecrets */
    public function __construct(private readonly string $defaultSecret, private readonly array $providerSecrets = [])
    {
    }

    public function make(string $provider): PaymentSignatureStrategyInterface
    {
        $key = strtolower(trim($provider));
        $secret = $this->providerSecrets[$key] ?? $this->defaultSecret;

        return new HmacPaymentSignatureStrategy($secret);
    }
}
