<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

final class PaymentProviderRedirectPolicyResolver
{
    public static function isSafe(string $providerId, string $url, callable $fallback, ?PaymentProviderSettingsRepository $settings = null): bool
    {
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider instanceof PaymentProviderConfiguredRedirectPolicyInterface) {
            // Never fall back to a broader URL policy when configuration is absent
            // or invalid. Read the current persisted public settings on every call,
            // including a new request restoring an encrypted pending checkout.
            try {
                $setting = $settings?->setting($providerId);
                if (($setting['status'] ?? '') !== 'enabled') {
                    return false;
                }
                $public = json_decode((string) ($setting['public_config_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
                return is_array($public) && $provider->isSafeRedirectUrlForConfiguration($url, $public);
            } catch (\Throwable) {
                return false;
            }
        }
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
