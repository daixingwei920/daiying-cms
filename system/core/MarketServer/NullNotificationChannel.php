<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class NullNotificationChannel implements NotificationChannelInterface
{
    public function send(array $notification): bool
    {
        return $notification !== [];
    }
}
