<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class WebhookNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $secret = '',
    ) {
    }

    public function send(array $notification): bool
    {
        if ($this->endpoint === '') {
            return false;
        }
        $body = json_encode($notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = "Content-Type: application/json\r\n";
        if ($this->secret !== '' && is_string($body)) {
            $headers .= 'X-CMS-Signature: ' . hash_hmac('sha256', $body, $this->secret) . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 8,
                'header' => $headers,
                'content' => is_string($body) ? $body : '{}',
            ],
        ]);

        return @file_get_contents($this->endpoint, false, $context) !== false;
    }
}
