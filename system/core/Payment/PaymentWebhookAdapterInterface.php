<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

interface PaymentWebhookAdapterInterface
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $publicConfig
     * @param array<string,string> $secretConfig
     * @return array{event_id:string,payload:string,metadata?:array<string,mixed>}
     */
    public function adaptWebhook(string $rawPayload, array $server, array $publicConfig, array $secretConfig): array;
}
