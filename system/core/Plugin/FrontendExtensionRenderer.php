<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Logging\FileLogger;
use Throwable;

final class FrontendExtensionRenderer
{
    public function __construct(
        private readonly PluginRuntimeRegistry $runtime,
        private readonly FileLogger $logger,
    ) {
    }

    public function inject(Request $request, Response $response): Response
    {
        if (!$this->shouldInject($request, $response)) {
            return $response;
        }

        $snippets = $this->snippets();
        if ($snippets === []) {
            return $response;
        }

        $body = $response->body();
        $injection = "\n" . implode("\n", $snippets) . "\n";
        $count = 0;
        $body = preg_replace('/<\/body\s*>/i', $injection . '</body>', $body, 1, $count) ?? $body;
        if ($count === 0) {
            $body .= $injection;
        }

        return new Response($body, $response->status(), $response->headers());
    }

    private function shouldInject(Request $request, Response $response): bool
    {
        if (!in_array($request->method, ['GET'], true)) {
            return false;
        }
        if ($response->status() < 200 || $response->status() >= 300) {
            return false;
        }
        foreach ($response->headers() as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'location') {
                return false;
            }
            if ($lower === 'content-disposition') {
                return false;
            }
        }

        $path = $request->path;
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return false;
        }
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return false;
        }
        if ($path === '/extension-assets' || str_starts_with($path, '/extension-assets/')) {
            return false;
        }
        if (in_array($path, ['/health', '/sitemap.xml', '/robots.txt', '/ads.txt'], true)) {
            return false;
        }

        $contentType = '';
        foreach ($response->headers() as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $contentType = strtolower($value);
                break;
            }
        }

        return $contentType !== '' && str_contains($contentType, 'text/html');
    }

    /** @return list<string> */
    private function snippets(): array
    {
        $seen = [];
        $snippets = [];

        foreach ($this->runtime->frontendAssets() as $asset) {
            if (isset($seen[$asset->key])) {
                continue;
            }
            $seen[$asset->key] = true;
            $html = $this->assetHtml($asset);
            if ($html !== '') {
                $snippets[] = $html;
            }
        }

        foreach ($this->runtime->frontendBodyEndCallbacks() as $callback) {
            if (isset($seen[$callback['key']])) {
                continue;
            }
            $seen[$callback['key']] = true;
            try {
                $html = (string) ($callback['callback'])();
                if ($html !== '') {
                    $snippets[] = $html;
                }
            } catch (Throwable $exception) {
                $this->logger->error('Plugin frontend extension failed', [
                    'source' => 'Plugin',
                    'plugin_id' => (string) $callback['plugin_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $snippets;
    }

    private function assetHtml(FrontendExtensionAsset $asset): string
    {
        $url = htmlspecialchars($asset->url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($asset->type === 'style') {
            return '<link rel="stylesheet" href="' . $url . '">';
        }
        if ($asset->type !== 'script') {
            return '';
        }

        $attrs = '';
        if ((bool) ($asset->attributes['module'] ?? false)) {
            $attrs .= ' type="module"';
        }
        if ((bool) ($asset->attributes['async'] ?? false)) {
            $attrs .= ' async';
        }
        if (($asset->attributes['defer'] ?? true) !== false) {
            $attrs .= ' defer';
        }

        return '<script src="' . $url . '"' . $attrs . '></script>';
    }
}
