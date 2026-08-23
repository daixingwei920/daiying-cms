<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class PaymentWebhookVerifier
{
    public function __construct(private readonly string $secret)
    {
    }

    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $signature): bool
    {
        if ($this->secret === '' || $signature === '') {
            return false;
        }

        return hash_equals($this->sign($payload), $signature);
    }

    /** @param array<string, mixed> $payload */
    public function sign(array $payload): string
    {
        ksort($payload);
        $base = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', is_string($base) ? $base : '', $this->secret);
    }
}
