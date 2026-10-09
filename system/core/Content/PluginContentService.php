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
        private readonly ?\Cms\Core\Events\EventDispatcher $events = null,
        private readonly ?\Cms\Core\Config\Settings $settings = null,
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

        $this->recordWrite($id, 'content.created', null, $draft);
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
        foreach (['categories', 'tags'] as $field) {
            if (array_key_exists($field, $patch)) {
                if ($patch[$field] === null) { throw new ContentException($field . ' cannot be null.'); }
                $patch[$field] = $this->stringList($patch, $field);
            }
        }
        $this->repository()->patch($contentId, $patch, $existing);
        $this->recordWrite($contentId, 'content.updated', $existing, $patch);

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
        $this->repository()->patch($contentId, ['status' => 'published'], $existing);
        $this->recordWrite($contentId, 'content.updated', $existing, []);

        return $this->getWritten($contentId);
    }

    private function recordWrite(int $id, string $action, ?array $before, array $input): void
    {
        $repo = $this->repository();
        $repo->auditWrite($id, 'plugin', null, $action, ['plugin_id' => $this->manifest->id, 'task_id' => $input['task_id'] ?? null]);
        if ($this->settings !== null && $before !== null) {
            ContentPublishedEvent::notify($repo, $id, $this->events, $this->settings,
                $before === null ? 'created' : 'updated', $before, 'plugin', null,
                is_string($input['task_id'] ?? null) ? $input['task_id'] : null);
        }
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
