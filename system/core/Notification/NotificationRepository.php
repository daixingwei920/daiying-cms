<?php

declare(strict_types=1);

namespace Cms\Core\Notification;

use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_notifications
                (uuid, site_id, source_type, source_owner, source_id, severity, title, body, action_url, status, dedupe_key, payload_json, created_at, read_at, archived_at)
             VALUES
                (:uuid, :site_id, :source_type, :source_owner, :source_id, :severity, :title, :body, :action_url, :status, :dedupe_key, :payload_json, :created_at, :read_at, :archived_at)'
        );
        $stmt->execute([
            ':uuid' => (string) $data['uuid'],
            ':site_id' => (string) $data['site_id'],
            ':source_type' => (string) $data['source_type'],
            ':source_owner' => (string) $data['source_owner'],
            ':source_id' => (string) $data['source_id'],
            ':severity' => (string) $data['severity'],
            ':title' => (string) $data['title'],
            ':body' => (string) $data['body'],
            ':action_url' => (string) $data['action_url'],
            ':status' => (string) $data['status'],
            ':dedupe_key' => (string) $data['dedupe_key'],
            ':payload_json' => $data['payload_json'] === null ? null : (string) $data['payload_json'],
            ':created_at' => (string) $data['created_at'],
            ':read_at' => $data['read_at'] === null ? null : (string) $data['read_at'],
            ':archived_at' => $data['archived_at'] === null ? null : (string) $data['archived_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_notifications WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByDedupeKey(string $dedupeKey): ?array
    {
        if ($dedupeKey === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM cms_notifications WHERE dedupe_key = :dedupe_key ORDER BY id DESC LIMIT 1');
        $stmt->execute([':dedupe_key' => $dedupeKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function updateDedupe(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cms_notifications
             SET source_type = :source_type, source_owner = :source_owner, source_id = :source_id, severity = :severity,
                 title = :title, body = :body, action_url = :action_url, status = 'unread', payload_json = :payload_json,
                 read_at = NULL, archived_at = NULL
             WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $id,
            ':source_type' => (string) $data['source_type'],
            ':source_owner' => (string) $data['source_owner'],
            ':source_id' => (string) $data['source_id'],
            ':severity' => (string) $data['severity'],
            ':title' => (string) $data['title'],
            ':body' => (string) $data['body'],
            ':action_url' => (string) $data['action_url'],
            ':payload_json' => $data['payload_json'] === null ? null : (string) $data['payload_json'],
        ]);
    }

    /** @param array{status?:string,limit?:int,include_archived?:bool} $filters @return list<array<string,mixed>> */
    public function recent(array $filters = []): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 30)));
        $where = [];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        } elseif (empty($filters['include_archived'])) {
            $where[] = "status <> 'archived'";
        }
        $sql = 'SELECT * FROM cms_notifications' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function unreadCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM cms_notifications WHERE status = 'unread'");

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    public function markRead(int $id): void
    {
        $this->pdo->prepare("UPDATE cms_notifications SET status = 'read', read_at = COALESCE(read_at, :read_at) WHERE id = :id AND status <> 'archived'")
            ->execute([':id' => $id, ':read_at' => gmdate('c')]);
    }

    public function markAllRead(): int
    {
        $stmt = $this->pdo->prepare("UPDATE cms_notifications SET status = 'read', read_at = COALESCE(read_at, :read_at) WHERE status = 'unread'");
        $stmt->execute([':read_at' => gmdate('c')]);

        return $stmt->rowCount();
    }

    public function archive(int $id): void
    {
        $this->pdo->prepare("UPDATE cms_notifications SET status = 'archived', archived_at = COALESCE(archived_at, :archived_at) WHERE id = :id")
            ->execute([':id' => $id, ':archived_at' => gmdate('c')]);
    }
}
