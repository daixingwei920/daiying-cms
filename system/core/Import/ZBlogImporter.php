<?php

declare(strict_types=1);

namespace Cms\Core\Import;

final class ZBlogImporter implements ImporterAdapterInterface
{
    public function platformId(): string
    {
        return 'zblogphp';
    }

    public function supports(string $sourceType, string $sample): bool
    {
        return $sourceType === 'json' && str_contains($sample, 'zblog');
    }

    public function parse(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new ImportException('Invalid Z-Blog JSON payload.');
        }

        $posts = $decoded['posts'] ?? $decoded['articles'] ?? [];
        if (!is_array($posts)) {
            return [];
        }

        $items = [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $title = trim((string) ($post['title'] ?? $post['log_Title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items[] = new UnifiedContent(
                'article',
                $title,
                (string) ($post['slug'] ?? $post['alias'] ?? ''),
                'draft',
                [['type' => 'paragraph', 'data' => ['text' => (string) ($post['content'] ?? $post['intro'] ?? '')]]],
                ['source_platform' => $this->platformId()],
                isset($post['url']) ? (string) $post['url'] : null,
            );
        }

        return $items;
    }
}
