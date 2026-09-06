<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

final class ThemeRuntime
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public readonly ThemeManifest $manifest,
        public readonly string $path,
        public readonly array $settings,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $templateFile = $this->path . '/templates/' . $template . '.php';
        if (!is_file($templateFile)) {
            throw new ThemeException('Template not found: ' . $template);
        }

        $data += [
            'theme_settings' => $this->settings,
            'settings' => $this->settings,
        ];

        $context = new TemplateContext($this, $data);
        ob_start();
        try {
            require $templateFile;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
