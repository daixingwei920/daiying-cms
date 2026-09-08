<?php

declare(strict_types=1);

namespace Cms\Core\Webhook;

use Cms\Core\Queue\QueueJob;
use Cms\Core\Queue\QueueService;
use PDO;

final class WebhookService
{
    public function __construct(private readonly PDO $pdo, private readonly ?QueueService $queue = null)
    {
    }

    /** @param array<string,mixed> $payload @return list<int> */
    public function dispatch(string $eventId, array $payload, string $version = '1.0'): array
    {
        WebhookEventRegistry::register($eventId, $eventId, $version);
        $repo = new WebhookEndpointRepository($this->pdo);
        $deliveryIds = [];
        foreach ($repo->matching($eventId) as $endpoint) {
            $body = [
                'event' => $eventId,
                'version' => $version,
                'created_at' => gmdate('c'),
                'data' => $payload,
            ];
            $deliveryId = $repo->recordDelivery((int) $endpoint['id'], $eventId, $body);
            $deliveryIds[] = $deliveryId;
            if ($this->queue !== null) {
                $this->queue->enqueue(new QueueJob('webhook.deliver', ['delivery_id' => $deliveryId], 'core.webhook'));
            }
        }

        return $deliveryIds;
    }
}
