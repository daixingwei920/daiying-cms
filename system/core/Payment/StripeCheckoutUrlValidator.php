<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

final class StripeCheckoutUrlValidator
{
    private const CHECKOUT_HOSTS = ['checkout.stripe.com'];
    private const FAKE_CHECKOUT_HOSTS = ['checkout.stripe.test'];

    public static function isSafe(string $url, bool $allowFakeTransport = false): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 262144 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $host = self::canonicalHost((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $isFake = $allowFakeTransport && in_array($host, self::FAKE_CHECKOUT_HOSTS, true);
        if (!$isFake && !in_array($host, self::CHECKOUT_HOSTS, true)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        $pattern = $isFake
            ? '#^/pay/cs_(?:test|live)_[A-Za-z0-9_=-]+$#'
            : '#^/c/pay/cs_(?:test|live)_[A-Za-z0-9_=-]+$#';
        if (preg_match($pattern, $path) !== 1) {
            return false;
        }

        return self::stripeOwnedUrlPartSafe((string) ($parts['query'] ?? ''), 65536)
            && self::stripeOwnedUrlPartSafe((string) ($parts['fragment'] ?? ''), 196608);
    }

    private static function canonicalHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || preg_match('/[^a-z0-9.-]/', $host) === 1) {
            return '';
        }

        return $host;
    }

    private static function stripeOwnedUrlPartSafe(string $value, int $maxLength): bool
    {
        return strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && preg_match('/(?:bearer\s+|sk_(?:test|live)?_|api[_-]?key=|access[_-]?key=|secret=|authorization=)/i', rawurldecode($value)) !== 1;
    }
}
