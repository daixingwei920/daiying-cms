<?php

declare(strict_types=1);

namespace Official\Restaurant;

final class RestaurantPrintService
{
    public function __construct(private readonly RestaurantRepository $repo, private readonly mixed $mail = null)
    {
    }

    /**
     * @param array<string,mixed> $ticket
     * @param list<array<string,mixed>> $items
     * @return list<int>
     */
    public function enqueueStationJobs(array $ticket, array $items): array
    {
        $jobs = [];
        foreach ($this->groupByStation($items) as $stationCode => $stationItems) {
            $payload = $this->renderTicket($ticket, $stationCode, $stationItems);
            $printers = $this->repo->printersForStation($stationCode);
            if ($printers === []) {
                $jobs[] = $this->repo->enqueuePrintJob((int) $ticket['id'], null, $stationCode, 'manual', $payload);
                continue;
            }
            foreach ($printers as $printer) {
                $jobs[] = $this->repo->enqueuePrintJob((int) $ticket['id'], (int) $printer['id'], $stationCode, (string) $printer['provider'], $payload);
            }
        }
        return $jobs;
    }

    public function retry(int $jobId, string $reason = '', string $actor = 'system', string $requestKey = ''): int
    {
        return $this->repo->reprintJob($jobId, $reason, $actor, $requestKey);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,list<array<string,mixed>>>
     */
    public function groupByStation(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $station = (string) ($item['station_code'] ?? 'front');
            $groups[$station][] = $item;
        }
        ksort($groups);
        return $groups;
    }

    /**
     * @param array<string,mixed> $ticket
     * @param list<array<string,mixed>> $items
     */
    public function renderTicket(array $ticket, string $stationCode, array $items): string
    {
        $lines = [];
        $lines[] = 'Daiying 餐厅小票 / Kitchen / Cocina';
        if (($ticket['source'] ?? '') === 'bridge_test') {
            $lines[] = 'TEST ONLY / 测试票，不出餐 / SOLO PRUEBA';
        }
        $lines[] = '岗位: ' . $stationCode;
        $lines[] = '票号: ' . (string) ($ticket['ticket_number'] ?? '');
        $lines[] = '订单 / Order / Pedido: ' . (string) ($ticket['commerce_order_id'] ?? 'TEST');
        $lines[] = '方式: ' . (string) ($ticket['service_mode'] ?? 'pickup');
        if (!empty($ticket['due_at'])) {
            $lines[] = '预约时间: ' . $this->repo->formatDueLocal((string) $ticket['due_at']);
        }
        $payload = json_decode((string) ($ticket['payload_json'] ?? '{}'), true);
        if ((string) ($ticket['service_mode'] ?? '') === 'delivery' && $stationCode === 'front' && is_array($payload)) {
            $lines[] = '配送地址: ' . preg_replace('/\s+/', ' ', (string) ($payload['delivery_address'] ?? ''));
            $lines[] = '配送邮编: ' . (string) ($payload['delivery_postal_code'] ?? '');
        }
        $lines[] = '顾客: ' . (string) ($ticket['customer_name'] ?? '');
        if (!empty($ticket['customer_phone'])) {
            $lines[] = '电话: ' . (string) $ticket['customer_phone'];
        }
        $lines[] = '------------------------';
        foreach ($items as $item) {
            $line = ((int) ($item['quantity'] ?? 1)) . ' x ' . (string) ($item['item_name'] ?? $item['name'] ?? '菜品');
            $lines[] = $line;
            $modifiers = $item['modifiers'] ?? json_decode((string) ($item['modifiers_json'] ?? '[]'), true);
            foreach (is_array($modifiers) ? $modifiers : [] as $modifier) {
                if (is_array($modifier) && !empty($modifier['name'])) {
                    $lines[] = '  选项: ' . preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', (string) $modifier['name']);
                }
            }
            if (!empty($item['notes'])) {
                $lines[] = '  备注: ' . preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', (string) $item['notes']);
            }
        }
        $lines[] = '------------------------';
        $lines[] = '打印时间: ' . gmdate('Y-m-d H:i:s') . ' UTC';
        return implode("\n", array_map(static fn (string $line): string => preg_replace('/[\x00-\x1F\x7F]/u', ' ', $line) ?? '', $lines)) . "\n";
    }
}
