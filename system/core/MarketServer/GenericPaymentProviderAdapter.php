<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class GenericPaymentProviderAdapter implements PaymentProviderAdapterInterface
{
    public function __construct(private readonly string $provider = 'generic')
    {
    }

    public function normalize(array $payload): array
    {
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $status = (string) ($payload['status'] ?? $object['status'] ?? 'pending');
        $amount = $payload['amount_cents'] ?? $object['amount_received'] ?? $object['amount'] ?? 0;
        $currency = (string) ($payload['currency'] ?? $object['currency'] ?? 'USD');

        return [
            'event_id' => (string) ($payload['event_id'] ?? $payload['id'] ?? ''),
            'payment_id' => (int) ($payload['payment_id'] ?? $metadata['payment_id'] ?? 0),
            'market_id' => (string) ($payload['market_id'] ?? $metadata['market_id'] ?? ''),
            'site_id' => (string) ($payload['site_id'] ?? $metadata['site_id'] ?? ''),
            'amount_cents' => (int) $amount,
            'currency' => strtoupper($currency),
            'provider' => (string) ($payload['provider'] ?? $this->provider ?: 'generic'),
            'provider_reference' => (string) ($payload['provider_reference'] ?? $object['id'] ?? ''),
            'status' => match ($status) {
                'succeeded', 'paid', 'completed' => 'paid',
                'refunded' => 'refunded',
                'failed' => 'failed',
                'cancelled', 'canceled' => 'cancelled',
                default => $status,
            },
        ];
    }
}
