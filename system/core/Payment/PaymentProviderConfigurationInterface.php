<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

interface PaymentProviderConfigurationInterface
{
    /** @param array<string,mixed> $publicConfig @param array<string,string> $maskedSecrets */
    public function isConfigured(array $publicConfig, array $maskedSecrets): bool;

    /** @param array<string,mixed> $publicConfig @param array<string,string> $maskedSecrets @return list<string> */
    public function diagnostics(array $publicConfig, array $maskedSecrets): array;
}
