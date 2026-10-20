<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use PDO;

final class MemberDraftService
{
    public function __construct(private readonly PDO $pdo, private readonly string $rootPath)
    {
    }

    public function drafts(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, updated_at FROM cms_contents
             WHERE owner_member_id = :member_id AND status = 'draft' AND content_type = 'article'
             ORDER BY id DESC LIMIT 100"
        );
        $stmt->execute([':member_id' => $memberId]);

        return $stmt->fetchAll();
    }

    public function draft(int $memberId, int $contentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, blocks_json FROM cms_contents
             WHERE id = :id AND owner_member_id = :member_id AND status = 'draft' AND content_type = 'article' LIMIT 1"
        );
        $stmt->execute([':id' => $contentId, ':member_id' => $memberId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $blocks = json_decode((string) $row['blocks_json'], true);
        if (!is_array($blocks) || count($blocks) !== 1 || ($blocks[0]['type'] ?? '') !== 'paragraph' || !is_string($blocks[0]['data']['text'] ?? null)) {
            return null;
        }
        $row['body'] = $blocks[0]['data']['text'];

        return $row;
    }

    public function create(int $memberId, string $title, string $body): int
    {
        [$title, $body] = $this->validate($title, $body);
        $this->pdo->beginTransaction();
        try {
            $repo = new ContentRepository($this->pdo, ContentTypeRegistry::defaults(), [], $this->rootPath);
            $id = $repo->create('article', $title, 'member-draft-' . bin2hex(random_bytes(12)), $this->blocks($body), 'draft');
            $this->pdo->prepare("UPDATE cms_contents SET owner_member_id = :member_id WHERE id = :id AND status = 'draft'")
                ->execute([':member_id' => $memberId, ':id' => $id]);
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function update(int $memberId, int $contentId, string $title, string $body): bool
    {
        [$title, $body] = $this->validate($title, $body);
        $draft = $this->draft($memberId, $contentId);
        if ($draft === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE cms_contents SET title = :title, blocks_json = :blocks_json, updated_at = :updated_at
             WHERE id = :id AND owner_member_id = :member_id AND status = 'draft'
               AND content_type = 'article' AND blocks_json = :prior_blocks_json"
        );
        $stmt->execute([
            ':title' => $title,
            ':blocks_json' => json_encode($this->blocks($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),
            ':id' => $contentId,
            ':member_id' => $memberId,
            ':prior_blocks_json' => $draft['blocks_json'],
        ]);

        return $stmt->rowCount() === 1;
    }

    private function validate(string $title, string $body): array
    {
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255 || mb_strlen($body, 'UTF-8') > 50000) {
            throw new \InvalidArgumentException('标题或正文长度不符合要求。');
        }

        return [$title, $body];
    }

    private function blocks(string $body): array
    {
        return [['type' => 'paragraph', 'data' => ['text' => $body]]];
    }
}
