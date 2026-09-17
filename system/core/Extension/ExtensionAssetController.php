<?php

declare(strict_types=1);

namespace Cms\Core\Extension;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;

final class ExtensionAssetController
{
    /** @var array<string,string> */
    private const MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    /** @var array<string,true> */
    private const PUBLIC_THEME_ASSET_EXTENSIONS = [
        'css' => true,
        'js' => true,
        'mjs' => true,
        'png' => true,
        'jpg' => true,
        'jpeg' => true,
        'gif' => true,
        'webp' => true,
        'svg' => true,
        'ico' => true,
        'woff' => true,
        'woff2' => true,
        'ttf' => true,
    ];

    /** @param 'plugin'|'theme' $type */
    public static function url(string $type, string $extensionId, string $relativePath, string $version = ''): string
    {
        $path = self::normalizeRelativePath($relativePath);
        $query = ['file' => $path];
        if ($version !== '') {
            $query['v'] = $version;
        }

        return '/extension-assets/' . rawurlencode($type) . '/' . rawurlencode($extensionId) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function __construct(private readonly string $rootPath)
    {
    }

    public function show(Request $request): Response
    {
        if (preg_match('#^/extension-assets/([^/]+)/([^/]+)$#', $request->path, $matches) !== 1) {
            return Response::text('Asset not found.', 404);
        }

        $type = rawurldecode($matches[1]);
        $extensionId = rawurldecode($matches[2]);
        $relativePath = (string) $request->input('file', '');

        if (!in_array($type, ['plugin', 'theme'], true) || preg_match('/^[A-Za-z0-9._-]{1,96}$/', $extensionId) !== 1) {
            return Response::text('Asset not found.', 404);
        }

        try {
            $relativePath = self::normalizeRelativePath($relativePath);
        } catch (\InvalidArgumentException) {
            return Response::text('Asset not found.', 404);
        }

        if (!$this->isAllowedAssetPath($relativePath) || !$this->isAllowedExtension($relativePath)) {
            return Response::text('Asset not found.', 404);
        }

        $base = $this->extensionBasePath($type, $extensionId);
        if ($base === null) {
            return Response::text('Asset not found.', 404);
        }

        $file = realpath($base . '/' . $relativePath);
        if (!is_string($file) || !is_file($file) || !is_readable($file) || !$this->isWithin($file, $base)) {
            return Response::text('Asset not found.', 404);
        }

        $body = (string) file_get_contents($file);
        $mtime = (string) (filemtime($file) ?: time());
        $etag = '"' . hash('sha256', $type . '|' . $extensionId . '|' . $relativePath . '|' . $mtime) . '"';
        if ((string) ($request->server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return new Response('', 304, [
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'ETag' => $etag,
            ]);
        }

        return new Response($body, 200, [
            'Content-Type' => self::MIME_TYPES[strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function showThemeContentAsset(Request $request): Response
    {
        if (preg_match('#^/content/themes/([^/]+)/assets/(.+)$#', $request->path, $matches) !== 1) {
            return Response::text('Asset not found.', 404);
        }

        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return Response::text('请求方法不被允许。', 405)
                ->withHeaders(['Allow' => 'GET, HEAD, OPTIONS', 'Cache-Control' => 'private, no-store']);
        }

        $themeId = rawurldecode($matches[1]);
        $assetPath = rawurldecode($matches[2]);
        if (preg_match('/^[A-Za-z0-9._-]{1,96}$/', $themeId) !== 1) {
            return Response::text('Asset not found.', 404);
        }

        try {
            $relativePath = 'assets/' . self::normalizeRelativePath($assetPath);
        } catch (\InvalidArgumentException) {
            return Response::text('Asset not found.', 404);
        }

        if (!$this->isAllowedPublicThemeAsset($relativePath)) {
            return Response::text('Asset not found.', 404);
        }

        return $this->serveAsset('theme', $themeId, $relativePath);
    }

    public static function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid extension asset path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid extension asset path.');
            }
        }
        return $path;
    }

    private function isAllowedAssetPath(string $path): bool
    {
        return str_starts_with($path, 'assets/')
            || str_starts_with($path, 'admin-assets/')
            || str_starts_with($path, 'public/');
    }

    private function isAllowedExtension(string $path): bool
    {
        return array_key_exists(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MIME_TYPES);
    }

    private function isAllowedPublicThemeAsset(string $path): bool
    {
        if (!str_starts_with($path, 'assets/') || $this->hasHiddenOrSensitiveSegment($path)) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return isset(self::PUBLIC_THEME_ASSET_EXTENSIONS[$extension]);
    }

    private function hasHiddenOrSensitiveSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            $lower = strtolower($segment);
            if ($segment === '' || str_starts_with($segment, '.')) {
                return true;
            }
            if (in_array($lower, ['theme.json', 'plugin.json', 'market-package.json', 'composer.json', 'composer.lock'], true)) {
                return true;
            }
            if (str_starts_with($lower, '.env') || str_starts_with($lower, '.git')) {
                return true;
            }
        }

        return false;
    }

    private function serveAsset(string $type, string $extensionId, string $relativePath): Response
    {
        if (!$this->isAllowedAssetPath($relativePath) || !$this->isAllowedExtension($relativePath)) {
            return Response::text('Asset not found.', 404);
        }

        $base = $this->extensionBasePath($type, $extensionId);
        if ($base === null) {
            return Response::text('Asset not found.', 404);
        }

        $file = realpath($base . '/' . $relativePath);
        if (!is_string($file) || !is_file($file) || !is_readable($file) || !$this->isWithin($file, $base)) {
            return Response::text('Asset not found.', 404);
        }

        $body = (string) file_get_contents($file);
        $mtime = (string) (filemtime($file) ?: time());
        $etag = '"' . hash('sha256', $type . '|' . $extensionId . '|' . $relativePath . '|' . $mtime) . '"';

        return new Response($body, 200, [
            'Content-Type' => self::MIME_TYPES[strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function extensionBasePath(string $type, string $extensionId): ?string
    {
        $dir = $this->rootPath . ($type === 'plugin' ? '/content/plugins/' : '/content/themes/') . $extensionId;
        $base = realpath($dir);
        if (!is_string($base) || !is_dir($base)) {
            return null;
        }
        $manifest = $base . ($type === 'plugin' ? '/plugin.json' : '/theme.json');
        return is_file($manifest) ? $base : null;
    }

    private function isWithin(string $file, string $base): bool
    {
        return $file === $base || str_starts_with($file, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
