<?php

declare(strict_types=1);

namespace Official\Restaurant;

final class RestaurantTicketService
{
    public function __construct(private readonly RestaurantRepository $repo, private readonly RestaurantPrintService $printer)
    {
    }

    /**
     * @param array<string,mixed> $ticket
     * @param list<array<string,mixed>> $items
     * @return array{ticket_id:int,print_job_ids:list<int>}
     */
    public function createTicketWithPrintJobs(array $ticket, array $items): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('订单至少需要一个菜品。');
        }
        return $this->repo->transaction(function () use ($ticket, $items): array {
            $ticketId = $this->repo->createTicket($ticket, $items);
            $storedTicket = $this->repo->ticket($ticketId);
            if ($storedTicket === null) {
                throw new \RuntimeException('厨房票据创建失败。');
            }
            $jobIds = $this->printer->enqueueStationJobs($storedTicket, $this->repo->ticketItems($ticketId));
            return ['ticket_id' => $ticketId, 'print_job_ids' => $jobIds];
        });
    }

    public function createPrinterTestTicket(int $printerId, string $actor): int
    {
        $device = $this->repo->printer($printerId);
        if ($device === null || $device['status'] !== 'enabled') {
            throw new \InvalidArgumentException('请先启用并绑定测试设备。');
        }
        return $this->repo->transaction(function () use ($device, $actor): int {
            $station = (string) ($device['station_codes'][0] ?? 'front');
            $ticketId = $this->repo->createTicket(['source' => 'bridge_test', 'customer_name' => 'TEST ONLY — 不出餐 — SOLO PRUEBA', 'service_mode' => 'pickup', 'payload' => ['is_test' => true]], [['item_name' => '测试炒饭 / Fried rice / Arroz frito', 'quantity' => 2, 'station_code' => $station, 'notes' => '不要葱 / No onion / Sin cebolla', 'modifiers' => [['name' => '加米饭 / Extra rice / Arroz extra']]]]);
            $jobId = $this->repo->enqueuePrintJob($ticketId, (int) $device['id'], $station, (string) $device['provider'], $this->printer->renderTicket($this->repo->ticket($ticketId), $station, $this->repo->ticketItems($ticketId)), null, 'test');
            $this->repo->printAudit($jobId, 'device.test_requested', $actor, 'TEST ONLY; no payment or food order.');
            return $jobId;
        });
    }

    /** @return array{ticket_id:int,print_job_ids:list<int>} */
    public function createDemoTicket(): array
    {
        return $this->createTicketWithPrintJobs([
            'customer_name' => '测试顾客',
            'customer_phone' => '000-0000',
            'service_mode' => 'pickup',
            'source' => 'manual',
        ], [
            ['item_name' => '炸鸡', 'quantity' => 1, 'station_code' => 'fry', 'notes' => '少盐'],
            ['item_name' => '三文鱼卷', 'quantity' => 2, 'station_code' => 'sushi', 'notes' => '加芥末'],
            ['item_name' => '牛肉炒饭', 'quantity' => 1, 'station_code' => 'wok', 'notes' => '不要葱'],
        ]);
    }
}
