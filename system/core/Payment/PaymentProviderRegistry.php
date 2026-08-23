<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use InvalidArgumentException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private static array $providers = [];

    public static function register(string $providerId, PaymentProviderInterface $provider): void
    {
        $normalized = self::normalize($providerId);
        if ($providerId !== $normalized) {
            throw new InvalidArgumentException('Invalid payment provider id.');
        }
        if ($provider->providerId() !== $normalized) {
            throw new InvalidArgumentException('Payment provider id mismatch.');
        }

        self::$providers[$normalized] = $provider;
    }

    public static function get(string $providerId): ?PaymentProviderInterface
    {
        $normalized = self::normalize($providerId);
        if ($providerId !== $normalized) {
            throw new InvalidArgumentException('Invalid payment provider id.');
        }

        return self::$providers[$normalized] ?? null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::$providers);
    }

    public static function clear(): void
    {
        self::$providers = [];
    }

    public static function normalize(string $providerId): string
    {
        $providerId = strtolower(trim($providerId));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $providerId) !== 1) {
            throw new InvalidArgumentException('Invalid payment provider id.');
        }

        return $providerId;
    }
}
