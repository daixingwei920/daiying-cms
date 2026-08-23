<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

interface NotificationChannelInterface
{
    /** @param array<string, mixed> $notification */
    public function send(array $notification): bool;
}
