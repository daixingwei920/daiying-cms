<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

trait JsonMigrationAdapterTrait
{
    /** @return array<string,mixed> */
    private function packageFromGenericJson(string $payload, string $system, string $defaultTitle = '迁移站点'): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new MigrationException('JSON 迁移文件解析失败。');
        }
        if (($decoded['migration_package_version'] ?? '') === '1') {
            return $this->normalizePackage($decoded, $system, $payload);
        }
        $contents = [];
        foreach (array_values((array) ($decoded['contents'] ?? $decoded['posts'] ?? $decoded['articles'] ?? [])) as $item) {
            if (is_array($item)) {
                $contents[] = $this->normalizeContent($item, $system);
            }
        }

        return [
            'migration_package_version' => '1',
            'source_system' => $system,
            'source_version' => (string) ($decoded['version'] ?? ''),
            'site' => [
                'source_site_id' => (string) ($decoded['site_id'] ?? $system . ':' . substr(hash('sha256', $payload), 0, 16)),
                'title' => (string) ($decoded['site']['title'] ?? $decoded['title'] ?? $defaultTitle),
            ],
            'users' => array_values((array) ($decoded['users'] ?? $decoded['authors'] ?? [])),
            'categories' => array_values((array) ($decoded['categories'] ?? [])),
            'tags' => array_values((array) ($decoded['tags'] ?? [])),
            'contents' => $contents,
            'media' => array_values((array) ($decoded['media'] ?? $decoded['attachments'] ?? [])),
            'comments' => array_values((array) ($decoded['comments'] ?? [])),
            'redirects' => array_values((array) ($decoded['redirects'] ?? [])),
            'metadata' => is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizePackage(array $decoded, string $system, string $payload): array
    {
        $decoded['source_system'] = (string) ($decoded['source_system'] ?? $system);
        $decoded['site'] = is_array($decoded['site'] ?? null) ? $decoded['site'] : [];
        $decoded['site']['source_site_id'] = (string) ($decoded['site']['source_site_id'] ?? $system . ':' . substr(hash('sha256', $payload), 0, 16));
        $decoded['contents'] = array_map(fn (mixed $item): array => is_array($item) ? $this->normalizeContent($item, $system) : [], array_values((array) ($decoded['contents'] ?? [])));
        $decoded['contents'] = array_values(array_filter($decoded['contents']));

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function normalizeContent(array $item, string $system): array
    {
        $id = (string) ($item['source_id'] ?? $item['id'] ?? '');
        $title = trim((string) ($item['title'] ?? $item['post_title'] ?? $item['log_Title'] ?? ''));
        $type = strtolower((string) ($item['type'] ?? $item['post_type'] ?? 'article'));
        if (in_array($type, ['post', 'blog', 'article'], true)) {
            $type = 'article';
        } elseif (in_array($type, ['page', 'single'], true)) {
            $type = 'page';
        } else {
            $type = 'article';
        }
        $status = strtolower((string) ($item['status'] ?? $item['post_status'] ?? 'draft'));
        $status = in_array($status, ['publish', 'published', 'public', '1'], true) ? 'published' : 'draft';
        $content = (string) ($item['content_html'] ?? $item['content'] ?? $item['html'] ?? $item['markdown'] ?? '');

        return [
            'source_id' => $id !== '' ? $id : substr(hash('sha256', $title . $content), 0, 16),
            'type' => $type,
            'title' => $title,
            'slug' => (string) ($item['slug'] ?? $item['alias'] ?? $item['name'] ?? ''),
            'excerpt' => (string) ($item['excerpt'] ?? $item['summary'] ?? ''),
            'content_html' => $content,
            'status' => $status,
            'published_at' => $this->timeValue($item['published_at'] ?? $item['created_at'] ?? $item['date'] ?? null),
            'updated_at' => $this->timeValue($item['updated_at'] ?? $item['modified_at'] ?? null),
            'author_ref' => (string) ($item['author_ref'] ?? $item['author_id'] ?? $item['author'] ?? ''),
            'categories' => is_array($item['categories'] ?? null) ? array_values($item['categories']) : [],
            'tags' => is_array($item['tags'] ?? null) ? array_values($item['tags']) : [],
            'featured_image_ref' => (string) ($item['featured_image_ref'] ?? $item['cover'] ?? ''),
            'source_url' => (string) ($item['source_url'] ?? $item['url'] ?? $item['permalink'] ?? ''),
            'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : ['source_platform' => $system, 'source_id' => $id],
        ];
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $time = (int) $value;
            return $time > 0 ? gmdate('c', $time) : null;
        }
        $time = strtotime((string) $value);

        return $time === false ? null : gmdate('c', $time);
    }
}
