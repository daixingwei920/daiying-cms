<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;

final class PluginContentService
{
    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly ContentRepository $contents,
        private readonly mixed $contentsFactory = null,
    ) {
    }

    /** @param array<string,mixed> $draft @return array<string,mixed> */
    public function createDraft(array $draft): array
    {
        $this->assertCapability('content.write');
        $id = $this->repository()->create(
            $this->type($draft),
            $this->string($draft, 'title'),
            $this->optionalString($draft, 'slug'),
            $this->blocks($draft),
            'draft',
            $this->arrayValue($draft, 'meta'),
            $this->stringList($draft, 'categories'),
            $this->stringList($draft, 'tags'),
        );

        return $this->getWritten($id);
    }

    /** @return array<string,mixed>|null */
    public function get(int $contentId): ?array
    {
        $this->assertCapability('content.read');
        if ($contentId <= 0) {
            return null;
        }

        return $this->repository()->find($contentId);
    }

    /** @param array<string,mixed> $query @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function list(array $query = []): array
    {
        $this->assertCapability('content.read');
        $page = max(1, min(10000, (int) ($query['page'] ?? 1)));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? $query['limit'] ?? 50)));

        return [
            'items' => $this->repository()->adminList($page, $perPage),
            'total' => $this->repository()->adminCount(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,mixed> $patch @return array<string,mixed> */
    public function update(int $contentId, array $patch): array
    {
        $this->assertCapability('content.write');
        $existing = $this->repository()->find($contentId);
        if ($existing === null) {
            throw new ContentException('Content not found.');
        }
        $this->repository()->update(
            $contentId,
            (string) ($patch['type'] ?? $patch['content_type'] ?? $existing['content_type']),
            (string) ($patch['title'] ?? $existing['title']),
            (string) ($patch['slug'] ?? $existing['slug']),
            is_array($patch['blocks'] ?? null) ? $patch['blocks'] : (is_array($existing['blocks'] ?? null) ? $existing['blocks'] : []),
            (string) ($patch['status'] ?? $existing['status']),
            is_array($patch['meta'] ?? null) ? $patch['meta'] : (is_array($existing['meta'] ?? null) ? $existing['meta'] : []),
            $this->stringList($patch, 'categories'),
            $this->stringList($patch, 'tags'),
        );

        return $this->getWritten($contentId);
    }

    /** @return array<string,mixed> */
    public function publish(int $contentId): array
    {
        $this->assertCapability('content.write');
        $existing = $this->repository()->find($contentId);
        if ($existing === null) {
            throw new ContentException('Content not found.');
        }
        $this->repository()->update(
            $contentId,
            (string) $existing['content_type'],
            (string) $existing['title'],
            (string) $existing['slug'],
            is_array($existing['blocks'] ?? null) ? $existing['blocks'] : [],
            'published',
            is_array($existing['meta'] ?? null) ? $existing['meta'] : [],
        );

        return $this->getWritten($contentId);
    }

    private function assertCapability(string $capability): void
    {
        if (!in_array($capability, $this->manifest->capabilities, true)) {
            throw new PluginException('Plugin does not declare ' . $capability . ' capability.');
        }
    }

    /** @param array<string,mixed> $draft */
    private function type(array $draft): string
    {
        $type = (string) ($draft['type'] ?? $draft['content_type'] ?? 'article');
        return $type !== '' ? $type : 'article';
    }

    /** @param array<string,mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw new ContentException('Content ' . $key . ' is required.');
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $key): string
    {
        return trim((string) ($data[$key] ?? ''));
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    private function blocks(array $data): array
    {
        $blocks = $data['blocks'] ?? [];
        return is_array($blocks) ? array_values($blocks) : [];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function stringList(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $items), static fn (string $item): bool => $item !== ''));
    }

    /** @return array<string,mixed> */
    private function getWritten(int $contentId): array
    {
        $content = $this->repository()->find($contentId);
        if ($content === null) {
            throw new ContentException('Content not found after write.');
        }

        return $content;
    }

    private function repository(): ContentRepository
    {
        if (is_callable($this->contentsFactory)) {
            $repo = ($this->contentsFactory)();
            if ($repo instanceof ContentRepository) {
                return $repo;
            }
        }

        return $this->contents;
    }
}
