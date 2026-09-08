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
        $registry->register('article', 'Article', ['title', 'slug', 'blocks', 'status'], ['taxonomy' => ['category', 'tag'], 'searchable' => true, 'rest_exposed' => true, 'revision_support' => true]);
        $registry->register('page', 'Page', ['title', 'slug', 'blocks', 'status'], ['taxonomy' => [], 'searchable' => true, 'rest_exposed' => true, 'revision_support' => true]);

        return $registry;
    }

    /** @param list<string> $fields @param array<string,mixed> $options */
    public function register(string $id, string $name, array $fields, array $options = []): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $id)) {
            throw new ContentException('Invalid content type id.');
        }

        $this->types[$id] = [
            'id' => $id,
            'name' => $name,
            'fields' => $fields,
            'capabilities' => array_values(array_filter(array_map('strval', $options['capabilities'] ?? []))),
            'taxonomy' => array_values(array_filter(array_map('strval', $options['taxonomy'] ?? []))),
            'searchable' => (bool) ($options['searchable'] ?? false),
            'rest_exposed' => (bool) ($options['rest_exposed'] ?? false),
            'revision_support' => (bool) ($options['revision_support'] ?? false),
            'field_schema' => is_array($options['field_schema'] ?? null) ? $options['field_schema'] : [],
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
