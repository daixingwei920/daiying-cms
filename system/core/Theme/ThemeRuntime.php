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
        $templateFile = $this->templateFile($template);
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
            $html = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        // Core updates preserve theme files. Supply the global footer for existing
        // full-page themes; themes may place the public helper themselves.
        $footer = $context->siteIcpFooter();
        $bodyEnd = strripos($html, '</body>');
        if ($footer !== '' && $bodyEnd !== false && !str_contains($html, 'data-cms-site-icp="1"')) {
            $html = substr_replace($html, $footer . "\n", $bodyEnd, 0);
        }

        return $html;
    }

    private function templateFile(string $template): string
    {
        $template = ThemeViewModel::cleanRelativePath($template);
        if ($template === '' || str_ends_with($template, '.php')) {
            throw new ThemeException('Invalid template name.');
        }

        return $this->path . '/templates/' . $template . '.php';
    }
}
