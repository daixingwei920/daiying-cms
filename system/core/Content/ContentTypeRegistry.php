<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class ContentTypeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $types = [];

    public static function defaults(): self
    {
        $registry = new self();
        $registry->register('article', 'Article', ['title', 'slug', 'blocks', 'status']);
        $registry->register('page', 'Page', ['title', 'slug', 'blocks', 'status']);

        return $registry;
    }

    /** @param list<string> $fields */
    public function register(string $id, string $name, array $fields): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $id)) {
            throw new ContentException('Invalid content type id.');
        }

        $this->types[$id] = [
            'id' => $id,
            'name' => $name,
            'fields' => $fields,
        ];
    }

    public function has(string $id): bool
    {
        return isset($this->types[$id]);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->types;
    }
}
