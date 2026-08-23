<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use PDO;
use Throwable;

final class ContentScheduler
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{status:string,published:int,events:int,content_ids:list<int>,now:string} */
    public function publishDue(?string $now = null, int $limit = 50): array
    {
        $now = $this->normalizeTime($now ?? gmdate('c'));
        $limit = max(1, min($limit, 200));
        $stmt = $this->pdo->prepare(
            "SELECT id FROM cms_contents
             WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now
             ORDER BY scheduled_at ASC, id ASC LIMIT :limit"
        );
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $published = 0;
        $events = 0;
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result = $this->publishOne($id, $now);
            if ($result['published']) {
                $published++;
                $ids[] = $id;
            }
            if ($result['event']) {
                $events++;
            }
        }

        return [
            'status' => 'Completed',
            'published' => $published,
            'events' => $events,
            'content_ids' => $ids,
            'now' => $now,
        ];
    }

    /** @return array{published:bool,event:bool} */
    private function publishOne(int $id, string $now): array
    {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                "UPDATE cms_contents
                 SET status = 'published', published_at = COALESCE(published_at, :published_at), updated_at = :updated_at
                 WHERE id = :id AND status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now"
            );
            $update->execute([
                ':id' => $id,
                ':now' => $now,
                ':published_at' => $now,
                ':updated_at' => $now,
            ]);
            $published = $update->rowCount() === 1;
            $event = false;
            if ($published) {
                $event = $this->recordEvent($id, $now);
            }
            $this->pdo->commit();

            return ['published' => $published, 'event' => $event];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function recordEvent(int $id, string $now): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_content_events (event_type, content_id, payload_json, created_at)
                 VALUES (:event_type, :content_id, :payload_json, :created_at)'
            );
            $stmt->execute([
                ':event_type' => 'content.scheduled_published',
                ':content_id' => $id,
                ':payload_json' => json_encode(['published_at' => $now], JSON_UNESCAPED_SLASHES),
                ':created_at' => $now,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ContentException('Scheduler time is invalid.');
        }

        return gmdate('c', $timestamp);
    }
}
