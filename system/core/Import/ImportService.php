<?php

declare(strict_types=1);

namespace Cms\Core\Import;

use Cms\Core\Content\ContentRepository;
use Cms\Core\UrlMapping\UrlMappingRepository;

final class ImportService
{
    /** @param list<ImporterAdapterInterface> $adapters */
    public function __construct(
        private readonly ContentRepository $content,
        private readonly UrlMappingRepository $urlMap,
        private readonly array $adapters,
    ) {
    }

    public function import(string $payload): int
    {
        $type = (new FormatDetector())->detect($payload);
        foreach ($this->adapters as $adapter) {
            if (!$adapter->supports($type, $payload)) {
                continue;
            }

            $count = 0;
            foreach ($adapter->parse($payload) as $item) {
                $id = $this->content->create($item->type, $item->title, $item->slug, $item->blocks, $item->status, $item->meta);
                if ($item->sourceUrl !== null) {
                    $this->recordUrlMappings($item->sourceUrl, $this->publicPathForCreatedContent($id, $item), $adapter->platformId());
                }
                $count++;
            }
            return $count;
        }

        throw new ImportException('No importer adapter supports this payload.');
    }

    private function publicPathForCreatedContent(int $id, UnifiedContent $fallback): string
    {
        $content = $this->content->find($id);
        $type = is_array($content) ? (string) ($content['content_type'] ?? $fallback->type) : $fallback->type;
        $slug = is_array($content) ? (string) ($content['slug'] ?? $fallback->slug) : $fallback->slug;
        $slug = trim($slug, '/');
        if ($slug === '') {
            return '/content/' . $id;
        }

        return $type === 'article' ? '/articles/' . $slug : '/' . $slug;
    }

    private function recordUrlMappings(string $sourceUrl, string $targetUrl, string $platformId): void
    {
        $sources = [];
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl !== '') {
            $sources[] = $sourceUrl;
        }
        $path = $this->sourcePath($sourceUrl);
        if ($path !== '' && !in_array($path, $sources, true)) {
            $sources[] = $path;
        }

        foreach ($sources as $source) {
            $this->urlMap->record($source, $targetUrl, 301, $platformId);
        }
    }

    private function sourcePath(string $sourceUrl): string
    {
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '') {
            return '';
        }
        if (str_starts_with($sourceUrl, '/')) {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
        } else {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
        }
        if (!is_string($path) || $path === '') {
            return '';
        }

        return '/' . ltrim($path, '/');
    }
}
