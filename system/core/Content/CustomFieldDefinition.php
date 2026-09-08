<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class CustomFieldDefinition
{
    /** @param list<string> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly array $options = [],
        public readonly string $schemaVersion = '1.0',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new ContentException('Custom field name is invalid.');
        }
        if (!in_array($type, ['text', 'textarea', 'integer', 'decimal', 'boolean', 'select', 'date', 'datetime', 'url', 'media', 'relation'], true)) {
            throw new ContentException('Custom field type is invalid.');
        }
    }

    public function validate(mixed $value): mixed
    {
        if (($value === null || $value === '') && $this->required) {
            throw new ContentException('Required custom field is missing: ' . $this->name);
        }
        if ($value === null || $value === '') {
            return $this->default;
        }

        return match ($this->type) {
            'integer', 'media', 'relation' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'url' => $this->validateUrl((string) $value),
            'select' => $this->validateSelect((string) $value),
            default => $this->cleanString((string) $value),
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'schema_version' => $this->schemaVersion,
        ];
    }

    private function validateUrl(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new ContentException('Custom field URL is invalid.');
        }

        return $value;
    }

    private function validateSelect(string $value): string
    {
        if ($this->options !== [] && !in_array($value, $this->options, true)) {
            throw new ContentException('Custom field select value is invalid.');
        }

        return $this->cleanString($value);
    }

    private function cleanString(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '');
    }
}
