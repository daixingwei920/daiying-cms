<?php

declare(strict_types=1);

namespace Cms\Core\UrlMapping;

use PDO;

final class UrlMappingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $sourceUrl, string $targetUrl, int $statusCode = 301, string $sourcePlatform = 'unknown'): void
    {
        if (!$this->safeSourceUrl($sourceUrl) || !$this->safeTargetUrl($targetUrl) || !in_array($statusCode, [301, 302, 307, 308], true) || !$this->safeSourcePlatform($sourcePlatform)) {
            throw new \InvalidArgumentException('URL mapping value is invalid.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_url_mappings (source_url, target_url, status_code, source_platform, created_at)
             VALUES (:source_url, :target_url, :status_code, :source_platform, :created_at)'
        );
        $stmt->execute([
            ':source_url' => $sourceUrl,
            ':target_url' => $targetUrl,
            ':status_code' => $statusCode,
            ':source_platform' => $sourcePlatform,
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->query(
            'SELECT id, source_url, target_url, status_code, source_platform, created_at
             FROM cms_url_mappings
             ORDER BY id DESC
             LIMIT ' . $limit
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM cms_url_mappings')->fetchColumn();

        return (int) $value;
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM cms_url_mappings WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function safeSourceUrl(string $sourceUrl): bool
    {
        if (!$this->safeUrlValue($sourceUrl)) {
            return false;
        }
        if (str_starts_with($sourceUrl, '/')) {
            return !str_starts_with($sourceUrl, '//')
                && !str_contains($sourceUrl, '..')
                && !str_contains($sourceUrl, '\\')
                && !str_contains($sourceUrl, '?')
                && !str_contains($sourceUrl, '#');
        }
        $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($sourceUrl, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    private function safeTargetUrl(string $targetUrl): bool
    {
        return $this->safeUrlValue($targetUrl)
            && str_starts_with($targetUrl, '/')
            && !str_starts_with($targetUrl, '//')
            && !str_contains($targetUrl, '..')
            && !str_contains($targetUrl, '\\')
            && !str_contains($targetUrl, '?')
            && !str_contains($targetUrl, '#');
    }

    private function safeUrlValue(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= 512
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function safeSourcePlatform(string $sourcePlatform): bool
    {
        return $sourcePlatform !== ''
            && $sourcePlatform === trim($sourcePlatform)
            && strlen($sourcePlatform) <= 64
            && preg_match('/^[a-zA-Z0-9._-]+$/', $sourcePlatform) === 1;
    }
}
