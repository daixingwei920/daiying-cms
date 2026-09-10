<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

interface PaymentProviderRedirectPolicyInterface
{
    public function isSafeRedirectUrl(string $url): bool;
}
