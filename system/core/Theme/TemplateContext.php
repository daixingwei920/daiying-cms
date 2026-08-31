<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

final class TemplateContext
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly ThemeRuntime $theme,
        private readonly array $data,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->theme->settings[$key] ?? $default;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
