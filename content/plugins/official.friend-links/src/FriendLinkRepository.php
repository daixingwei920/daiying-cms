<?php

declare(strict_types=1);

namespace Official\FriendLinks;

use PDO;
use RuntimeException;

final class FriendLinkRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $publicOnly = false, string $query = '', string $status = 'all'): array
    {
        return $this->list($publicOnly, $query, $status, 0, 0);
    }

    /** @return list<array<string,mixed>> */
    public function list(bool $publicOnly = false, string $query = '', string $status = 'all', int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT * FROM cms_friend_links_links';
        [$where, $params] = $this->filterSql($publicOnly, $query, $status);
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if ($limit > 0) {
            $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function count(bool $publicOnly = false, string $query = '', string $status = 'all'): int
    {
        $sql = 'SELECT COUNT(*) FROM cms_friend_links_links';
        [$where, $params] = $this->filterSql($publicOnly, $query, $status);
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function save(array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $url = $this->normalizeUrl((string) ($input['url'] ?? ''));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 80) {
            throw new RuntimeException('友情链接名称不能为空，且不能超过 80 个字符。');
        }
        if ($url === '') {
            throw new RuntimeException('友情链接网址必须是有效的 HTTP/HTTPS 地址。');
        }

        $id = max(0, (int) ($input['id'] ?? 0));
        if ($this->urlExists($url, $id)) {
            throw new RuntimeException('该友情链接网址已经存在，请不要重复添加。');
        }
        $description = trim((string) ($input['description'] ?? ''));
        $sort = (int) ($input['sort_order'] ?? 0);
        $status = ((string) ($input['status'] ?? 'enabled')) === 'disabled' ? 'disabled' : 'enabled';
        $rel = $this->normalizeRel((string) ($input['rel'] ?? 'noopener noreferrer'));
        $now = gmdate('c');

        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE cms_friend_links_links SET name = :name, url = :url, description = :description, sort_order = :sort_order, status = :status, rel = :rel, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':url' => $url,
                ':description' => $description,
                ':sort_order' => $sort,
                ':status' => $status,
                ':rel' => $rel,
                ':updated_at' => $now,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO cms_friend_links_links (name, url, description, sort_order, status, rel, created_at, updated_at) VALUES (:name, :url, :description, :sort_order, :status, :rel, :created_at, :updated_at)');
        $stmt->execute([
            ':name' => $name,
            ':url' => $url,
            ':description' => $description,
            ':sort_order' => $sort,
            ':status' => $status,
            ':rel' => $rel,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_friend_links_links WHERE id = :id');
        $stmt->execute([':id' => max(0, $id)]);
    }

    private function urlExists(string $url, int $excludingId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_friend_links_links WHERE url = :url AND id <> :id');
        $stmt->execute([':url' => $url, ':id' => $excludingId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array{0:list<string>,1:array<string,string>} */
    private function filterSql(bool $publicOnly, string $query, string $status): array
    {
        $where = [];
        $params = [];
        if ($publicOnly) {
            $where[] = "status = 'enabled'";
        } elseif (in_array($status, ['enabled', 'disabled'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }
        $query = trim($query);
        if ($query !== '') {
            $where[] = '(name LIKE :query OR url LIKE :query OR description LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        return [$where, $params];
    }

    private function normalizeRel(string $rel): string
    {
        return match ($rel) {
            'noopener noreferrer nofollow' => 'noopener noreferrer nofollow',
            'noopener noreferrer sponsored' => 'noopener noreferrer sponsored',
            default => 'noopener noreferrer',
        };
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            return '';
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }
        if ($this->isUnsafeHost($host)) {
            return '';
        }
        return $url;
    }

    private function isUnsafeHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        if (!str_contains($host, '.')) {
            return true;
        }

        return false;
    }
}
