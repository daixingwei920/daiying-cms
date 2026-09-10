<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

final class PaymentProviderRedirectPolicyResolver
{
    public static function isSafe(string $providerId, string $url, callable $fallback): bool
    {
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider instanceof PaymentProviderRedirectPolicyInterface) {
            return $provider->isSafeRedirectUrl($url);
        }

        // Backwards compatibility for older Stripe Provider packages that predate
        // the redirect policy interface.
        if ($providerId === 'official.payment.stripe' && StripeCheckoutUrlValidator::isSafe($url)) {
            return true;
        }

        return (bool) $fallback($url);
    }
}
