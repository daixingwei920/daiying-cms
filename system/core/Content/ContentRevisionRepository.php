<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use PDO;

final class ContentRevisionRepository
{
    public function __construct(private readonly PDO $pdo, private readonly int $maxRevisions = 50)
    {
    }

    /** @param array<string,mixed> $content */
    public function recordFromContent(array $content, ?int $actorId = null, string $reason = 'manual'): int
    {
        $contentId = (int) ($content['id'] ?? 0);
        if ($contentId <= 0) {
            throw new ContentException('Content revision requires a content id.');
        }
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_content_revisions
                (content_id, content_type, title, slug, status, blocks_json, meta_json, actor_id, reason, created_at)
             VALUES
                (:content_id, :content_type, :title, :slug, :status, :blocks_json, :meta_json, :actor_id, :reason, :created_at)'
        );
        $stmt->execute([
            ':content_id' => $contentId,
            ':content_type' => (string) ($content['content_type'] ?? ''),
            ':title' => (string) ($content['title'] ?? ''),
            ':slug' => (string) ($content['slug'] ?? ''),
            ':status' => (string) ($content['status'] ?? ''),
            ':blocks_json' => (string) ($content['blocks_json'] ?? json_encode($content['blocks'] ?? [], JSON_UNESCAPED_SLASHES)),
            ':meta_json' => (string) ($content['meta_json'] ?? json_encode($content['meta'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ':actor_id' => $actorId,
            ':reason' => $this->reason($reason),
            ':created_at' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->prune($contentId);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function listForContent(int $contentId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_content_revisions WHERE content_id = :content_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':content_id', $contentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countForContent(int $contentId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_content_revisions WHERE content_id = :content_id');
        $stmt->execute([':content_id' => $contentId]);

        return (int) $stmt->fetchColumn();
    }

    public function restore(int $revisionId, ContentRepository $contents): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_content_revisions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $revisionId]);
        $revision = $stmt->fetch();
        if (!is_array($revision)) {
            throw new ContentException('Content revision not found.');
        }
        $contents->update(
            (int) $revision['content_id'],
            (string) $revision['content_type'],
            (string) $revision['title'],
            (string) $revision['slug'],
            json_decode((string) ($revision['blocks_json'] ?? '[]'), true) ?: [],
            (string) $revision['status'],
            json_decode((string) ($revision['meta_json'] ?? '{}'), true) ?: [],
        );
    }

    private function prune(int $contentId): void
    {
        $max = max(1, $this->maxRevisions);
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_content_revisions
             WHERE content_id = :content_id
               AND id NOT IN (SELECT id FROM cms_content_revisions WHERE content_id = :content_id_recent ORDER BY id DESC LIMIT ' . $max . ')'
        );
        $stmt->execute([':content_id' => $contentId, ':content_id_recent' => $contentId]);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 64 || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $reason) !== 1) {
            return 'manual';
        }

        return $reason;
    }
}
