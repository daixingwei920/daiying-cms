<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

/** A redirect policy bound to Core's persisted public provider configuration. */
interface PaymentProviderConfiguredRedirectPolicyInterface extends PaymentProviderRedirectPolicyInterface
{
    /** @param array<string,mixed> $publicConfig No decrypted credentials are exposed. */
    public function isSafeRedirectUrlForConfiguration(string $url, array $publicConfig): bool;
}
