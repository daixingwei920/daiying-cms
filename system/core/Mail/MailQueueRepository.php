<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

use PDO;

final class MailQueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(MailMessage $message, string $providerId, string $eventId = '', int $notBefore = 0): int
    {
        $now = gmdate('c');
        $scheduled = $notBefore > 0 ? gmdate('c', $notBefore) : $now;
        $payload = json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new MailException('Unable to encode mail queue payload.');
        }
        $this->pdo->prepare(
            'INSERT INTO cms_mail_queue (provider_id, event_id, status, payload_json, retry_count, max_attempts, last_error, not_before, created_at, updated_at, sent_at)
             VALUES (:provider_id, :event_id, "pending", :payload_json, 0, 3, "", :not_before, :created_at, :updated_at, NULL)'
        )->execute([
            ':provider_id' => $providerId,
            ':event_id' => $eventId,
            ':payload_json' => $payload,
            ':not_before' => $scheduled,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function pending(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->query("SELECT * FROM cms_mail_queue WHERE status = 'pending' AND (not_before IS NULL OR not_before <= '" . gmdate('c') . "') ORDER BY id ASC LIMIT " . $limit);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function markSending(int $id): void
    {
        $this->pdo->prepare("UPDATE cms_mail_queue SET status = 'sending', updated_at = :updated_at WHERE id = :id AND status = 'pending'")
            ->execute([':id' => $id, ':updated_at' => gmdate('c')]);
    }

    public function markSent(int $id, string $messageId = ''): void
    {
        $this->pdo->prepare("UPDATE cms_mail_queue SET status = 'sent', provider_message_id = :message_id, last_error = '', sent_at = :sent_at, updated_at = :updated_at WHERE id = :id")
            ->execute([':id' => $id, ':message_id' => $messageId, ':sent_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
    }

    public function markFailed(int $id, string $error): void
    {
        $row = $this->find($id);
        $retry = (int) ($row['retry_count'] ?? 0) + 1;
        $max = (int) ($row['max_attempts'] ?? 3);
        $status = $retry >= $max ? 'failed' : 'pending';
        $delay = min(3600, 60 * (2 ** max(0, $retry - 1)));
        $this->pdo->prepare(
            'UPDATE cms_mail_queue SET status = :status, retry_count = :retry_count, last_error = :last_error, not_before = :not_before, updated_at = :updated_at WHERE id = :id'
        )->execute([
            ':id' => $id,
            ':status' => $status,
            ':retry_count' => $retry,
            ':last_error' => (string) Redactor::redact($error),
            ':not_before' => gmdate('c', time() + $delay),
            ':updated_at' => gmdate('c'),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_mail_queue WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
