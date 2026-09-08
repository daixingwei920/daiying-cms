<?php

declare(strict_types=1);

namespace Cms\Core\Webhook;

use Cms\Core\Security\SecretRedactor;
use PDO;

final class WebhookEndpointRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $events */
    public function create(string $url, string $secret, array $events, string $owner = 'core'): int
    {
        $this->assertUrl($url);
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "INSERT INTO cms_webhook_endpoints (owner, url, secret_hash, events_json, status, created_at, updated_at)
             VALUES (:owner, :url, :secret_hash, :events_json, 'enabled', :created_at, :updated_at)"
        );
        $stmt->execute([
            ':owner' => $owner,
            ':url' => $url,
            ':secret_hash' => hash('sha256', $secret),
            ':events_json' => $this->json(array_values($events)),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function matching(string $eventId): array
    {
        $rows = $this->pdo->query("SELECT * FROM cms_webhook_endpoints WHERE status = 'enabled' ORDER BY id ASC")->fetchAll();
        return array_values(array_filter($rows, static function (array $row) use ($eventId): bool {
            $events = json_decode((string) ($row['events_json'] ?? '[]'), true);
            return is_array($events) && (in_array('*', $events, true) || in_array($eventId, $events, true));
        }));
    }

    /** @param array<string,mixed> $payload */
    public function recordDelivery(int $endpointId, string $eventId, array $payload, string $status = 'pending', string $error = ''): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_webhook_deliveries (endpoint_id, event_id, payload_json, status, attempts, max_attempts, last_error, created_at, updated_at)
             VALUES (:endpoint_id, :event_id, :payload_json, :status, 0, 3, :last_error, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':endpoint_id' => $endpointId,
            ':event_id' => $eventId,
            ':payload_json' => $this->json($payload),
            ':status' => $status,
            ':last_error' => substr((string) SecretRedactor::redact($error), 0, 1000),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{signature:string,timestamp:string} */
    public static function sign(string $payloadJson, string $secret, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        return [
            'timestamp' => (string) $timestamp,
            'signature' => hash_hmac('sha256', (string) $timestamp . '.' . $payloadJson, $secret),
        ];
    }

    private function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new WebhookException('Webhook endpoint must be an HTTPS URL.');
        }
    }

    /** @param mixed $value */
    private function json($value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookException('Webhook payload cannot be encoded.', 0, $exception);
        }
    }
}
