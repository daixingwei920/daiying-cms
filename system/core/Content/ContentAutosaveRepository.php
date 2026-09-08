<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use PDO;

final class ContentAutosaveRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<array<string,mixed>> $blocks @param array<string,mixed> $meta */
    public function save(?int $contentId, int $actorId, string $contentType, string $title, string $slug, array $blocks, array $meta = []): int
    {
        if ($actorId <= 0) {
            throw new ContentException('Autosave requires an actor.');
        }
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_content_autosaves (content_id, actor_id, content_type, title, slug, blocks_json, meta_json, created_at, updated_at)
             VALUES (:content_id, :actor_id, :content_type, :title, :slug, :blocks_json, :meta_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':content_id' => $contentId,
            ':actor_id' => $actorId,
            ':content_type' => $contentType,
            ':title' => $title,
            ':slug' => $slug,
            ':blocks_json' => $this->json($blocks),
            ':meta_json' => $this->json($meta),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function latest(?int $contentId, int $actorId): ?array
    {
        if ($contentId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM cms_content_autosaves WHERE actor_id = :actor_id AND content_id IS NULL ORDER BY id DESC LIMIT 1');
            $stmt->execute([':actor_id' => $actorId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM cms_content_autosaves WHERE actor_id = :actor_id AND content_id = :content_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':actor_id' => $actorId, ':content_id' => $contentId]);
        }
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['blocks'] = json_decode((string) ($row['blocks_json'] ?? '[]'), true) ?: [];
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        $row['meta'] = is_array($meta) ? $meta : [];

        return $row;
    }

    public function discard(int $autosaveId, int $actorId): void
    {
        $this->pdo->prepare('DELETE FROM cms_content_autosaves WHERE id = :id AND actor_id = :actor_id')
            ->execute([':id' => $autosaveId, ':actor_id' => $actorId]);
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ContentException('Autosave JSON is invalid.', 0, $exception);
        }
    }
}
