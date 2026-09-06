<?php

declare(strict_types=1);

namespace Cms\Core\Advertising;

use PDO;

final class AdRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function slots(): array
    {
        try {
            return $this->pdo->query('SELECT * FROM cms_ad_slots ORDER BY slot_key ASC')->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,array{label:string,html:string,enabled:bool}> */
    public function activeSlotsForRender(): array
    {
        $slots = [];
        foreach ($this->slots() as $row) {
            if ((int) ($row['enabled'] ?? 0) !== 1) {
                continue;
            }
            $slotKey = (string) ($row['slot_key'] ?? '');
            if ($slotKey === '') {
                continue;
            }
            $slots[$slotKey] = [
                'label' => (string) ($row['label'] ?? '广告位'),
                'html' => AdRenderer::renderSlot($slotKey, (string) ($row['html'] ?? '')),
                'enabled' => true,
            ];
        }

        return $slots;
    }

    /** @param list<array<string,mixed>> $slots */
    public function replaceSlots(array $slots): void
    {
        $existing = [];
        foreach ($this->slots() as $row) {
            $existing[(string) $row['slot_key']] = true;
        }
        $seen = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_ad_slots (slot_key, label, placement, html, enabled, created_at, updated_at)
             VALUES (:slot_key, :label, :placement, :html, :enabled, :created_at, :updated_at)'
        );
        $update = $this->pdo->prepare(
            'UPDATE cms_ad_slots SET label = :label, placement = :placement, html = :html, enabled = :enabled, updated_at = :updated_at WHERE slot_key = :slot_key'
        );
        $now = gmdate('c');
        foreach ($slots as $slot) {
            $key = $this->normalizeSlotKey((string) ($slot['slot_key'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $params = [
                ':slot_key' => $key,
                ':label' => $this->shortText((string) ($slot['label'] ?? '广告位'), 120),
                ':placement' => $this->shortText((string) ($slot['placement'] ?? ''), 64),
                ':html' => trim((string) ($slot['html'] ?? '')),
                ':enabled' => !empty($slot['enabled']) ? 1 : 0,
                ':updated_at' => $now,
            ];
            if (isset($existing[$key])) {
                $update->execute($params);
            } else {
                $stmt->execute($params + [':created_at' => $now]);
            }
        }
    }

    public function adsTxt(): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM cms_core_settings WHERE setting_key = 'advertising.ads_txt' LIMIT 1");
            $stmt->execute();
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable) {
            return '';
        }
    }

    public function saveAdsTxt(string $value): void
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $now = gmdate('c');
        $exists = false;
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cms_core_settings WHERE setting_key = 'advertising.ads_txt'");
            $stmt->execute();
            $exists = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            $exists = false;
        }
        if ($exists) {
            $this->pdo->prepare("UPDATE cms_core_settings SET setting_value = :value, updated_at = :updated_at WHERE setting_key = 'advertising.ads_txt'")
                ->execute([':value' => $value, ':updated_at' => $now]);
            return;
        }
        $this->pdo->prepare('INSERT INTO cms_core_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)')
            ->execute([':key' => 'advertising.ads_txt', ':value' => $value, ':updated_at' => $now]);
    }

    public function recordEvent(string $slotKey, string $eventType, string $path, string $referrer, string $userAgent, string $ip): void
    {
        $slotKey = $this->normalizeSlotKey($slotKey);
        if ($slotKey === '' || !in_array($eventType, ['impression', 'click'], true)) {
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO cms_ad_events (slot_key, event_type, request_path, referrer, user_agent_hash, ip_hash, created_at)
             VALUES (:slot_key, :event_type, :request_path, :referrer, :user_agent_hash, :ip_hash, :created_at)'
        )->execute([
            ':slot_key' => $slotKey,
            ':event_type' => $eventType,
            ':request_path' => $this->shortText($path, 512),
            ':referrer' => $this->shortText($referrer, 512),
            ':user_agent_hash' => $userAgent !== '' ? hash('sha256', $userAgent) : '',
            ':ip_hash' => $ip !== '' ? hash('sha256', $ip) : '',
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return array<string,array{impressions:int,clicks:int}> */
    public function stats(): array
    {
        $stats = [];
        foreach ($this->slots() as $slot) {
            $stats[(string) $slot['slot_key']] = ['impressions' => 0, 'clicks' => 0];
        }
        try {
            $rows = $this->pdo->query('SELECT slot_key, event_type, COUNT(*) AS total FROM cms_ad_events GROUP BY slot_key, event_type')->fetchAll();
        } catch (\Throwable) {
            return $stats;
        }
        foreach ($rows as $row) {
            $key = (string) $row['slot_key'];
            $stats[$key] ??= ['impressions' => 0, 'clicks' => 0];
            if ((string) $row['event_type'] === 'impression') {
                $stats[$key]['impressions'] = (int) $row['total'];
            } elseif ((string) $row['event_type'] === 'click') {
                $stats[$key]['clicks'] = (int) $row['total'];
            }
        }

        return $stats;
    }

    private function normalizeSlotKey(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_-]{1,48}$/', $value) === 1 ? $value : '';
    }

    private function shortText(string $value, int $limit): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }
}

