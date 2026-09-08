<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class CustomFieldRegistry
{
    /** @var array<string,array<string,CustomFieldDefinition>> */
    private array $fields = [];

    public function register(string $contentType, CustomFieldDefinition $field): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $contentType)) {
            throw new ContentException('Content type id is invalid.');
        }
        $this->fields[$contentType][$field->name] = $field;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    public function validate(string $contentType, array $values): array
    {
        $validated = [];
        foreach ($this->fields[$contentType] ?? [] as $name => $field) {
            $validated[$name] = $field->validate($values[$name] ?? null);
        }

        return $validated;
    }

    /** @return array<string,array<string,mixed>> */
    public function schema(string $contentType): array
    {
        $schema = [];
        foreach ($this->fields[$contentType] ?? [] as $name => $field) {
            $schema[$name] = $field->toArray();
        }

        return $schema;
    }
}
