<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class NotificationDispatcher
{
    public function dispatch(MarketServerRepository $repository, NotificationChannelInterface $channel, int $limit = 50): int
    {
        $sent = 0;
        foreach ($repository->pendingNotifications($limit) as $notification) {
            if ($channel->send($notification)) {
                $repository->recordNotificationDispatchAttempt((int) $notification['id'], $channel::class, 'Dispatched');
                $repository->markNotificationDispatched((int) $notification['id']);
                $sent++;
                continue;
            }
            $repository->recordNotificationDispatchAttempt((int) $notification['id'], $channel::class, 'Failed', 'Channel returned false.');
            $repository->markNotificationDispatchFailed((int) $notification['id']);
        }

        return $sent;
    }
}
