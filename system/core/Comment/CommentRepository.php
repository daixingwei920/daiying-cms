<?php

declare(strict_types=1);

namespace Cms\Core\Comment;

use PDO;

final class CommentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $contentId = max(0, (int) ($data['content_id'] ?? 0));
        $body = $this->cleanBody((string) ($data['body'] ?? ''));
        $authorName = $this->cleanName((string) ($data['author_name'] ?? ''));
        $authorEmail = strtolower(trim((string) ($data['author_email'] ?? '')));
        if ($contentId <= 0) {
            throw new \InvalidArgumentException('评论内容不存在。');
        }
        if ($authorName === '') {
            throw new \InvalidArgumentException('请填写昵称。');
        }
        if ($authorEmail !== '' && !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('邮箱格式不正确。');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('评论内容不能为空。');
        }
        if ($this->textLength($body) > 2000) {
            throw new \InvalidArgumentException('评论最多 2000 个字符。');
        }

        $status = in_array((string) ($data['status'] ?? 'pending'), ['pending', 'approved', 'spam', 'trash'], true)
            ? (string) $data['status']
            : 'pending';
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_comments
                (content_id, parent_id, user_id, author_name, author_email, author_url, body, status, ip_hash, user_agent_hash, created_at, updated_at)
             VALUES
                (:content_id, :parent_id, :user_id, :author_name, :author_email, :author_url, :body, :status, :ip_hash, :user_agent_hash, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':content_id' => $contentId,
            ':parent_id' => null,
            ':user_id' => (int) ($data['user_id'] ?? 0) > 0 ? (int) $data['user_id'] : null,
            ':author_name' => $authorName,
            ':author_email' => $authorEmail !== '' ? $authorEmail : null,
            ':author_url' => null,
            ':body' => $body,
            ':status' => $status,
            ':ip_hash' => $this->hash((string) ($data['ip'] ?? '')),
            ':user_agent_hash' => $this->hash((string) ($data['user_agent'] ?? '')),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function approvedForContent(int $contentId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cms_comments WHERE content_id = :content_id AND status = 'approved' ORDER BY created_at ASC, id ASC");
        $stmt->execute([':content_id' => $contentId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function adminList(string $status = '', int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        if (in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            $stmt = $this->pdo->prepare(
                'SELECT c.*, ct.title AS content_title, ct.slug AS content_slug, ct.content_type
                 FROM cms_comments c LEFT JOIN cms_contents ct ON ct.id = c.content_id
                 WHERE c.status = :status ORDER BY c.created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare(
            'SELECT c.*, ct.title AS content_title, ct.slug AS content_slug, ct.content_type
             FROM cms_comments c LEFT JOIN cms_contents ct ON ct.id = c.content_id
             ORDER BY c.created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(string $status = ''): int
    {
        if (in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_comments WHERE status = :status');
            $stmt->execute([':status' => $status]);
            return (int) $stmt->fetchColumn();
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM cms_comments')->fetchColumn();
    }

    public function setStatus(int $id, string $status): void
    {
        if ($id <= 0 || !in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            throw new \InvalidArgumentException('评论状态无效。');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_comments SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':id' => $id, ':status' => $status, ':updated_at' => gmdate('c')]);
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('评论不存在。');
        }
        $stmt = $this->pdo->prepare('DELETE FROM cms_comments WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function cleanBody(string $body): string
    {
        $body = trim(strip_tags($body));
        return preg_replace("/[\\r\\n]{3,}/", "\n\n", $body) ?: '';
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?: '');
        return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function hash(string $value): string
    {
        return $value !== '' ? hash('sha256', $value) : '';
    }
}
